<?php

declare(strict_types=1);

namespace CVS\Ai;

use CVS\Alerts\AlertRepository;
use CVS\Alerts\AlertService;
use CVS\Alerts\PriceAlertRepository;
use CVS\Api\FinancialDataFetcher;
use CVS\Api\FundamentalFieldRegistry;
use CVS\Api\FundamentalOverrideMerger;
use CVS\Api\FundamentalOverrideRepository;
use CVS\Api\PayloadCompleteness;
use CVS\Api\SuspectFieldDetector;
use CVS\Auth\AuthController;
use CVS\Auth\UserRepository;
use CVS\Core\Request;
use CVS\Core\Response;
use CVS\CVS\CVSModel;
use CVS\CVS\Valuation\MedianResolver;
use CVS\CVS\Valuation\PeerBucketOverrideRepository;
use CVS\CVS\Valuation\PeerMedianRepository;
use CVS\Mail\MailService;
use CVS\TrackRecord\CvsSnapshotRepository;
use CVS\TrackRecord\SingleTickerRescorer;
use CVS\TrackRecord\SnapshotWriter;

/**
 * Handles POST /analysis/{ticker}/validate-fundamentals,
 * GET /analysis/{ticker}/validate-fundamentals/status, and
 * POST /analysis/{ticker}/validate-fundamentals/confirm — change:
 * fundamentals-validation.
 *
 * trigger(): auth → admin → CSRF → not-already-pending → compute field list
 *   → markPending → fire bin/validate_fundamentals.php in the background → 202.
 * status(): auth → admin → read fundamental_validation_runs row → respond per status.
 * confirm(): auth → admin → CSRF → require a completed run → upsert overrides
 *   → merge → PayloadCompleteness gate → SingleTickerRescorer → flushDigests → respond.
 * Always returns JSON; never throws.
 */
class FundamentalsValidationController
{
    private FundamentalsValidationRunRepository $runRepo;
    private FundamentalOverrideRepository       $overrideRepo;
    private UserRepository                      $users;
    private FinancialDataFetcher                $fetcher;
    private ?SingleTickerRescorer                $rescorer;
    private ?AlertService                        $alertService;
    /** @var array<string, mixed> */
    private array $cvsConfig;

    /**
     * Optional parameters allow injecting test doubles without hitting the
     * database. When ALL six are provided, the heavy production wiring
     * (CVSModel/SnapshotWriter/MedianResolver/PriceAlertRepository/AlertService/
     * SingleTickerRescorer) is skipped entirely — same "all doubles or none"
     * seam AiAnalysisController uses for its PRO gate / critical-review repo.
     */
    public function __construct(
        ?FundamentalsValidationRunRepository $runRepo = null,
        ?FundamentalOverrideRepository $overrideRepo = null,
        ?UserRepository $users = null,
        ?FinancialDataFetcher $fetcher = null,
        ?SingleTickerRescorer $rescorer = null,
        ?AlertService $alertService = null,
    ) {
        $this->cvsConfig = require dirname(__DIR__, 2) . '/config/cvs-weights.php';

        $this->runRepo      = $runRepo      ?? new FundamentalsValidationRunRepository();
        $this->overrideRepo = $overrideRepo ?? new FundamentalOverrideRepository();
        $this->users        = $users        ?? new UserRepository();
        $this->fetcher      = $fetcher      ?? new FinancialDataFetcher($this->cvsConfig['data_source']);

        if ($runRepo === null && $overrideRepo === null && $users === null
            && $fetcher === null && $rescorer === null && $alertService === null) {
            $peerMedianRepo = new PeerMedianRepository();
            $model          = new CVSModel($this->cvsConfig, $peerMedianRepo);
            $writer         = new SnapshotWriter();
            $medianResolver = MedianResolver::fromConfig($this->cvsConfig, $peerMedianRepo);
            $priceAlertRepo = new PriceAlertRepository();
            $peerOverrides  = (new PeerBucketOverrideRepository())->findBucketMap();
            $atrZonesConfig = is_array($this->cvsConfig['atr_zones'] ?? null) ? $this->cvsConfig['atr_zones'] : [];
            $mailConfig     = require dirname(__DIR__, 2) . '/config/mail.php';
            $trajectoryCfg  = is_array($this->cvsConfig['trajectory'] ?? null) ? $this->cvsConfig['trajectory'] : [];

            $this->alertService = new AlertService(
                new AlertRepository(),
                new MailService(null, $mailConfig),
                new UserRepository(),
                new CvsSnapshotRepository(),
                $trajectoryCfg
            );
            $this->rescorer = new SingleTickerRescorer(
                $model,
                $writer,
                $medianResolver,
                $priceAlertRepo,
                $this->alertService,
                $atrZonesConfig,
                $peerOverrides
            );
        } else {
            $this->rescorer     = $rescorer;
            $this->alertService = $alertService;
        }
    }

    // ------------------------------------------------------------------
    // POST /analysis/{ticker}/validate-fundamentals
    // ------------------------------------------------------------------

    public function trigger(Request $req): void
    {
        AuthController::requireAuth();

        if (!$this->isCurrentUserAdmin()) {
            Response::json(['ok' => false, 'message' => 'Brak uprawnień.'], 403);
            return;
        }
        if (!$req->verifyCsrf()) {
            Response::json(['ok' => false, 'message' => 'Błąd CSRF.'], 403);
            return;
        }

        $ticker = strtoupper(trim((string) $req->param('ticker', '')));
        if ($ticker === '') {
            Response::json(['ok' => false, 'message' => 'Brak tickera.'], 400);
            return;
        }

        $mode = (string) $req->input('mode', '');
        if (!in_array($mode, ['all', 'missing'], true)) {
            Response::json(['ok' => false, 'message' => 'Nieprawidłowy tryb.'], 400);
            return;
        }

        if ($this->runRepo->isPending($ticker)) {
            Response::json(['ok' => false, 'message' => 'Walidacja tej spółki już trwa.'], 409);
            return;
        }

        $financials = $this->fetcher->fetch($ticker);
        if ($financials === null) {
            Response::json(['ok' => false, 'message' => 'Nie udało się pobrać danych rynkowych.'], 502);
            return;
        }

        $fields = $mode === 'all'
            ? array_values(array_unique(array_merge(
                FundamentalFieldRegistry::SCORING_FIELDS,
                FundamentalFieldRegistry::LOCALLY_COMPUTED
            )))
            : array_keys(SuspectFieldDetector::detect($financials));

        if ($fields === []) {
            Response::json(['ok' => false, 'message' => 'Brak podejrzanych pól do sprawdzenia.'], 422);
            return;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $this->runRepo->markPending($ticker, $mode, $fields, $userId);

        $phpBin = '/usr/local/bin/php82';
        $script = dirname(__DIR__, 2) . '/bin/validate_fundamentals.php';
        $logDir = dirname(__DIR__, 2) . '/logs';
        $cmd    = $phpBin . ' ' . escapeshellarg($script)
                . ' ' . escapeshellarg($ticker)
                . ' ' . escapeshellarg((string) $userId)
                . ' ' . escapeshellarg($mode)
                . ' >> ' . escapeshellarg($logDir . '/validate_fundamentals.log')
                . ' 2>&1';
        exec($cmd . ' &');

        Response::json(['ok' => true, 'status' => 'pending'], 202);
    }

    // ------------------------------------------------------------------
    // GET /analysis/{ticker}/validate-fundamentals/status
    // ------------------------------------------------------------------

    public function status(Request $req): void
    {
        AuthController::requireAuth();
        if (!$this->isCurrentUserAdmin()) {
            Response::json(['ok' => false, 'message' => 'Brak uprawnień.'], 403);
            return;
        }

        $ticker = strtoupper(trim((string) $req->param('ticker', '')));
        $run    = $this->runRepo->findByTicker($ticker);

        if ($run === null) {
            Response::json(['ok' => true, 'status' => 'none']);
            return;
        }

        $payload = ['ok' => true, 'status' => (string) $run['status']];
        if ($run['status'] === 'completed') {
            $decodedDiff       = json_decode((string) $run['diff'], true);
            $payload['diff']   = is_array($decodedDiff) ? $decodedDiff : [];
            $payload['notes']  = (string) ($run['notes'] ?? '');
            $payload['model']  = $run['model'] ?? null;
        } elseif ($run['status'] === 'failed') {
            $payload['error_message'] = (string) ($run['error_message'] ?? '');
        }

        Response::json($payload);
    }

    // ------------------------------------------------------------------
    // POST /analysis/{ticker}/validate-fundamentals/confirm
    // ------------------------------------------------------------------

    public function confirm(Request $req): void
    {
        AuthController::requireAuth();
        if (!$this->isCurrentUserAdmin()) {
            Response::json(['ok' => false, 'message' => 'Brak uprawnień.'], 403);
            return;
        }
        if (!$req->verifyCsrf()) {
            Response::json(['ok' => false, 'message' => 'Błąd CSRF.'], 403);
            return;
        }

        $ticker = strtoupper(trim((string) $req->param('ticker', '')));
        $run    = $this->runRepo->findByTicker($ticker);

        if ($run === null || $run['status'] !== 'completed') {
            Response::json(['ok' => false, 'message' => 'Brak gotowego wyniku walidacji do zatwierdzenia.'], 409);
            return;
        }

        $diff = json_decode((string) $run['diff'], true);
        if (!is_array($diff) || $diff === []) {
            Response::json(['ok' => false, 'message' => 'Brak danych do zastosowania.'], 422);
            return;
        }

        $userId = (int) ($_SESSION['user_id'] ?? 0);
        foreach ($diff as $field => $entry) {
            $status = ((string) ($entry['status'] ?? '')) === 'validated' ? 'validated' : 'checked_no_data';
            $value  = $entry['new'] ?? null;
            $source = in_array((string) $field, FundamentalFieldRegistry::LOCALLY_COMPUTED, true)
                ? 'local_calculation'
                : 'gemini_validation';

            $this->overrideRepo->upsert(
                $ticker,
                (string) $field,
                $value !== null ? (string) $value : null,
                $status,
                $source,
                $userId
            );
        }

        $financials = $this->fetcher->fetch($ticker);
        if ($financials === null) {
            Response::json(['ok' => false, 'message' => 'Nadpisania zapisane, ale przeliczenie się nie powiodło (brak danych rynkowych).'], 502);
            return;
        }

        $merged  = FundamentalOverrideMerger::merge($financials, $this->overrideRepo->findByTicker($ticker));
        $missing = PayloadCompleteness::missingEssentialFields($merged);
        if ($missing !== []) {
            Response::json(['ok' => false, 'message' => 'Dane po scaleniu nie przechodzą bramki kompletności.'], 422);
            return;
        }

        $result = $this->rescorer->rescore($ticker, $merged, $this->cvsConfig);
        $this->alertService->flushDigests();

        Response::json([
            'ok'                   => true,
            'quality_gate_passed'  => $result->qualityGatePassed,
            'cvs_swing'            => $result->cvsResult->swingCvs,
            'cvs_fund'             => $result->cvsResult->fundamentalCvs,
            'fair_value'           => $result->fairValue,
        ]);
    }

    // ------------------------------------------------------------------
    // Private
    // ------------------------------------------------------------------

    private function isCurrentUserAdmin(): bool
    {
        $user = $this->users->findById((int) ($_SESSION['user_id'] ?? 0));
        return $user !== null && (bool) $user['is_admin'];
    }
}
