<?php

declare(strict_types=1);

namespace CVS\Admin;

use CVS\Api\FinancialDataFetcher;
use CVS\Auth\AuthController;
use CVS\Auth\UserRepository;
use CVS\Core\Request;
use CVS\Core\Response;
use CVS\CVS\Valuation\MedianResolver;
use CVS\CVS\Valuation\PeerBucketOverrideRepository;
use CVS\CVS\Valuation\PeerMedianRepository;
use CVS\TrackRecord\CvsSnapshotRepository;
use CVS\Screener\MarketResolver;

/**
 * Admin panel: add tickers to the screener universe (public/data/tickers.json)
 * by pasting a Yahoo Finance quote URL or a bare ticker symbol.
 *
 * Routes:
 *   GET  /admin/tickers       — list view + add form
 *   POST /admin/tickers/add   — parse input, verify via Yahoo, append + persist
 */
class TickersController
{
    private const TICKERS_FILE = '/public/data/tickers.json';

    private UserRepository       $users;
    private PeerBucketOverrideRepository $overrides;
    private string $modelVersion;
    private int    $minSampleCount;
    private FinancialDataFetcher $fetcher;

    /** @var array{default_label?: string, labels?: array<string, string>} */
    private array $marketsConfig;

    public function __construct()
    {
        $config       = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $this->users     = new UserRepository();
        $this->overrides = new PeerBucketOverrideRepository();
        $this->fetcher   = new FinancialDataFetcher($config['data_source']);
        $this->marketsConfig  = $config['markets'] ?? [];
        $this->modelVersion   = (string) ($config['model_version'] ?? '');
        $this->minSampleCount = (int) ($config['peer_group']['min_sample_count'] ?? 5);
    }

    public function index(Request $req): void
    {
        AuthController::requireAuth();
        $this->requireAdmin();

        $tickers = $this->loadTickers();
        $flash   = $_SESSION['_flash'] ?? null;
        unset($_SESSION['_flash']);

        Response::view('admin/tickers', [
            'tickers'      => $tickers,
            'flash'        => $flash,
            'marketsConfig' => $this->marketsConfig,
            // Admin-defined peer groups (migration 037).
            'overrides'    => $this->overrides->findAll(),
            'dueForReview' => $this->overrides->findDueForReview(date('Y-m-d')),
            // Current Yahoo classification per ticker, so the list shows what a
            // company is filed under before anyone decides to override it.
            'classification' => (new CvsSnapshotRepository())->findClassificationMap(),
            // Selectable peer groups. Free text was the wrong control: a typo
            // silently creates a fresh bucket with n=1, which then falls back to
            // the sector and quietly does nothing at all.
            'bucketOptions'  => $this->buildBucketOptions(),
            'minSampleCount' => $this->minSampleCount,
        ]);
    }

    public function add(Request $req): void
    {
        AuthController::requireAuth();
        $this->requireAdmin();

        if (!$req->verifyCsrf()) {
            Response::redirect('/admin/tickers');
            return;
        }

        $input  = trim((string) ($req->input('url') ?? ''));
        $symbol = self::extractSymbol($input);

        if ($symbol === null) {
            $_SESSION['_flash'] = 'Nie rozpoznano tickera w podanym adresie/wartości.';
            Response::redirect('/admin/tickers');
            return;
        }

        $tickers = $this->loadTickers();

        foreach ($tickers as $existing) {
            if (strcasecmp((string) $existing['symbol'], $symbol) === 0) {
                $_SESSION['_flash'] = "Ticker $symbol już jest na liście.";
                Response::redirect('/admin/tickers');
                return;
            }
        }

        $financials = $this->fetcher->fetch($symbol);
        if ($financials === null) {
            $_SESSION['_flash'] = "Nie udało się pobrać danych z Yahoo Finance dla $symbol — sprawdź ticker.";
            Response::redirect('/admin/tickers');
            return;
        }

        $name = is_string($financials['long_name'] ?? null) && $financials['long_name'] !== ''
            ? $financials['long_name']
            : $symbol;

        $tickers = self::appendTicker($tickers, $symbol, $name);
        $this->saveTickers($tickers);

        $marketLabel = MarketResolver::labelForTicker($symbol, $this->marketsConfig);
        $_SESSION['_flash'] = self::formatAddedFlash($symbol, $name, $marketLabel);
        Response::redirect('/admin/tickers');
    }

    // ------------------------------------------------------------------
    // Pure helpers (unit-testable, no I/O)
    // ------------------------------------------------------------------

    /**
     * Extract a ticker symbol from a Yahoo Finance quote URL
     * (e.g. https://finance.yahoo.com/quote/PKN.WA/) or a bare symbol.
     */
    /**
     * Every peer bucket an admin may pick, with how many companies back it.
     *
     * Union of the buckets that actually exist: Yahoo industries seen in the
     * snapshot population, plus any custom group already in use. Sample counts
     * come from peer_medians so the operator can see, at the moment of
     * choosing, which buckets clear min_sample_count and which will fall back
     * to the sector regardless.
     *
     * @return array<int, array{key: string, count: int, custom: bool}>
     */
    private function buildBucketOptions(): array
    {
        $counts = (new PeerMedianRepository())->findIndustrySampleCounts(
            $this->modelVersion,
            MedianResolver::VALUATION_METRICS
        );

        $custom = [];
        foreach ($this->overrides->findAll() as $o) {
            $custom[(string) $o['bucket_key']] = true;
        }

        $keys = array_unique(array_merge(array_keys($counts), array_keys($custom)));
        sort($keys, SORT_NATURAL | SORT_FLAG_CASE);

        $out = [];
        foreach ($keys as $k) {
            $out[] = [
                'key'    => (string) $k,
                'count'  => (int) ($counts[$k] ?? 0),
                'custom' => isset($custom[$k]) && !isset($counts[$k]),
            ];
        }
        return $out;
    }

    /**
     * Assigns a ticker to an admin-defined peer bucket.
     *
     * The bucket is a free-text key on purpose: typing an existing Yahoo
     * industry reclassifies the company into it, typing a new name creates a
     * custom group. One mechanism covers both, because both are the same
     * operation — choosing which median this company is measured against.
     */
    public function setOverride(Request $req): void
    {
        AuthController::requireAuth();
        $this->requireAdmin();

        if (!$req->verifyCsrf()) {
            Response::redirect('/admin/tickers');
            return;
        }

        $symbol = self::extractSymbol(trim((string) ($req->input('ticker') ?? '')));
        $bucket = trim((string) ($req->input('bucket_key') ?? ''));
        // The dropdown's "create new" option defers to a free-text field; every
        // other path picks an existing bucket, so a typo can no longer invent a
        // dead one-member group by accident.
        if ($bucket === '__new__') {
            $bucket = trim((string) ($req->input('bucket_key_new') ?? ''));
        }
        $reason = trim((string) ($req->input('reason') ?? ''));
        $review = trim((string) ($req->input('review_date') ?? ''));

        if ($symbol === null || $bucket === '') {
            $_SESSION['_flash'] = 'Podaj ticker oraz nazwę grupy porównawczej.';
            Response::redirect('/admin/tickers');
            return;
        }

        // A reason is mandatory: the override is a classification decision, and
        // the next person to look at it (including a later you) needs to know
        // what the claim was in order to judge whether it still holds.
        if ($reason === '') {
            $_SESSION['_flash'] = 'Uzasadnienie jest wymagane — nadpisanie grupy to decyzja modelowa, nie kosmetyka.';
            Response::redirect('/admin/tickers');
            return;
        }

        $this->overrides->upsert(
            $symbol,
            $bucket,
            $reason,
            $review !== '' ? $review : null,
            (int) ($_SESSION['user_id'] ?? 0) ?: null
        );

        $_SESSION['_flash'] = sprintf(
            '%s przypisany do grupy „%s". Zadziała po najbliższym przeliczeniu median i rescore.',
            $symbol,
            $bucket
        );
        Response::redirect('/admin/tickers');
    }

    public function deleteOverride(Request $req): void
    {
        AuthController::requireAuth();
        $this->requireAdmin();

        if (!$req->verifyCsrf()) {
            Response::redirect('/admin/tickers');
            return;
        }

        $symbol = self::extractSymbol(trim((string) ($req->input('ticker') ?? '')));
        if ($symbol !== null) {
            $this->overrides->delete($symbol);
            $_SESSION['_flash'] = sprintf('Nadpisanie grupy dla %s usunięte — wraca klasyfikacja Yahoo.', $symbol);
        }
        Response::redirect('/admin/tickers');
    }

    public static function extractSymbol(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        if (preg_match('#/quote/([A-Za-z0-9.\-]+)#', $input, $m) === 1) {
            return strtoupper($m[1]);
        }

        if (preg_match('/^[A-Za-z0-9.\-]{1,10}$/', $input) === 1) {
            return strtoupper($input);
        }

        return null;
    }

    /**
     * Confirmation flash shown after a successful add — names the resolved
     * market (via MarketResolver) so a brand-new suffix (e.g. the first ever
     * .WA ticker) is visible immediately, not just later on /screener once
     * the rescore cron has run.
     */
    public static function formatAddedFlash(string $symbol, string $name, string $marketLabel): string
    {
        return "Dodano $symbol ($name) do listy — rynek: $marketLabel.";
    }

    /**
     * @param array<int, array{symbol: string, name: string}> $tickers
     * @return array<int, array{symbol: string, name: string}>
     */
    public static function appendTicker(array $tickers, string $symbol, string $name): array
    {
        $tickers[] = ['symbol' => $symbol, 'name' => $name];

        usort($tickers, static fn(array $a, array $b) => strcmp((string) $a['symbol'], (string) $b['symbol']));

        return $tickers;
    }

    // ------------------------------------------------------------------
    // I/O
    // ------------------------------------------------------------------

    /** @return array<int, array{symbol: string, name: string}> */
    private function loadTickers(): array
    {
        $path = dirname(__DIR__, 2) . self::TICKERS_FILE;
        $json = file_get_contents($path);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<int, array{symbol: string, name: string}> $tickers */
    private function saveTickers(array $tickers): void
    {
        $path = dirname(__DIR__, 2) . self::TICKERS_FILE;
        $json = json_encode($tickers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            file_put_contents($path, $json . "\n");
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function requireAdmin(): void
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $user   = $this->users->findById($userId);

        if (!$user || !(bool) $user['is_admin']) {
            Response::redirect('/dashboard');
        }
    }
}
