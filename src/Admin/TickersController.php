<?php

declare(strict_types=1);

namespace CVS\Admin;

use CVS\Api\FinancialDataFetcher;
use CVS\Auth\AuthController;
use CVS\Auth\UserRepository;
use CVS\Core\Request;
use CVS\Core\Response;
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
    private FinancialDataFetcher $fetcher;

    /** @var array{default_label?: string, labels?: array<string, string>} */
    private array $marketsConfig;

    public function __construct()
    {
        $this->users  = new UserRepository();
        $config       = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $this->fetcher = new FinancialDataFetcher($config['data_source']);
        $this->marketsConfig = $config['markets'] ?? [];
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
