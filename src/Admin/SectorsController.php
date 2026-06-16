<?php

declare(strict_types=1);

namespace CVS\Admin;

use CVS\Auth\AuthController;
use CVS\Auth\UserRepository;
use CVS\Core\Request;
use CVS\Core\Response;
use CVS\CVS\Valuation\PeerMedianRepository;

/**
 * Sector peer-median indexing status, history charts, and manual refresh.
 *
 * Routes:
 *   GET  /sectors               — public view (auth-only, no refresh button)
 *   GET  /sectors/history       — history JSON (auth-only)
 *   GET  /admin/sectors         — admin view (with refresh button)
 *   GET  /admin/sectors/history — history JSON (admin-only)
 *   POST /admin/sectors/refresh — fire-and-forget exec() trigger (admin AJAX)
 */
class SectorsController
{
    private UserRepository      $users;
    private PeerMedianRepository $medians;
    /** @var array<string, mixed> */
    private array $config;

    public function __construct()
    {
        $this->users   = new UserRepository();
        $this->medians = new PeerMedianRepository();
        $this->config  = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
    }

    public function index(Request $req): void
    {
        $this->requireAdmin();
        $this->renderSectors(true);
    }

    public function publicIndex(Request $req): void
    {
        AuthController::requireAuth();
        $this->renderSectors(false);
    }

    public function refresh(Request $req): void
    {
        $this->requireAdmin();

        if (!$req->verifyCsrf()) {
            Response::json(['ok' => false, 'error' => 'csrf_invalid'], 403);
            return;
        }

        if (!function_exists('exec')) {
            Response::json(['ok' => false, 'error' => 'exec_disabled'], 500);
            return;
        }

        $sector = strip_tags((string) ($req->input('sector') ?? ''));

        // Whitelist — only sectors defined in batch_schedule are accepted.
        $schedule   = $this->config['batch_schedule'] ?? [];
        $validSectors = array_values(array_unique(array_merge(
            ...array_values(array_filter($schedule, static fn($s) => !empty($s)))
        )));

        if (!in_array($sector, $validSectors, true)) {
            Response::json(['ok' => false, 'error' => 'unknown_sector'], 400);
            return;
        }

        $phpBin  = '/usr/local/bin/php84';
        $script  = dirname(__DIR__, 2) . '/bin/refresh_peer_medians.php';
        $logFile = '/home/amjsystem/cron_rescore.txt';
        $cmd     = $phpBin . ' ' . escapeshellarg($script)
                 . ' --sector=' . escapeshellarg($sector)
                 . ' >> ' . escapeshellarg($logFile) . ' 2>&1';

        exec($cmd . ' &');

        Response::json(['ok' => true, 'message' => "Odświeżanie $sector uruchomiono"]);
    }

    public function history(Request $req): void
    {
        $this->requireAdmin();
        $this->renderHistory($req);
    }

    public function publicHistory(Request $req): void
    {
        AuthController::requireAuth();
        $this->renderHistory($req);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function renderSectors(bool $isAdmin): void
    {
        $schedule     = $this->config['batch_schedule'] ?? [];
        $modelVersion = (string) ($this->config['model_version'] ?? '3.0');

        // Build canonical sector list from batch_schedule (days 1–5 only).
        $allSectors = array_values(array_unique(array_merge(
            ...array_values(array_filter($schedule, static fn($s) => !empty($s)))
        )));
        sort($allSectors);

        // Day-of-week labels per sector (for tooltip).
        $dayNames  = [1 => 'Pon', 2 => 'Wt', 3 => 'Śr', 4 => 'Czw', 5 => 'Pt'];
        $sectorDay = [];
        foreach ($schedule as $day => $sectors) {
            foreach ($sectors as $sector) {
                $sectorDay[$sector] = $dayNames[$day] ?? '?';
            }
        }

        $stats = $this->medians->findSectorStats($modelVersion);

        Response::view('admin/sectors', [
            'allSectors'      => $allSectors,
            'sectorDay'       => $sectorDay,
            'sectorStats'     => $stats['sector'],
            'industryStats'   => $stats['industry'],
            'modelVersion'    => $modelVersion,
            'isAdmin'         => $isAdmin,
            'historyEndpoint' => $isAdmin ? '/admin/sectors/history' : '/sectors/history',
        ]);
    }

    private function renderHistory(Request $req): void
    {
        $level        = (string) ($req->query('level') ?? '');
        $bucketKey    = (string) ($req->query('bucket_key') ?? '');
        $modelVersion = (string) ($this->config['model_version'] ?? '3.0');

        if (!in_array($level, ['sector', 'industry'], true)) {
            Response::json(['ok' => false, 'message' => 'Nieprawidłowy parametr level'], 400);
            return;
        }

        if ($bucketKey === '' || strlen($bucketKey) > 150) {
            Response::json(['ok' => false, 'message' => 'Nieprawidłowy parametr bucket_key'], 400);
            return;
        }

        $data = $this->medians->findHistory($level, $bucketKey, $modelVersion);
        Response::json(['ok' => true, 'data' => $data]);
    }

    private function requireAdmin(): void
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $user   = $this->users->findById($userId);

        if (!$user || !(bool) $user['is_admin']) {
            Response::redirect('/dashboard');
        }
    }
}
