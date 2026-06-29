<?php

declare(strict_types=1);

namespace CVS\Portfolio;

use CVS\Auth\AuthController;
use CVS\Core\Database;
use CVS\Core\Request;
use CVS\Core\Response;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Read-only portfolio view controller.
 *
 * Renders the global virtual portfolio page at GET /portfolio.
 * Accessible to all authenticated users (FR-017: global portfolio).
 */
class PortfolioController
{
    public function index(Request $req): void
    {
        AuthController::requireAuth();

        $cvsConfig       = require dirname(__DIR__, 2) . '/config/cvs-weights.php';
        $portfolioConfig = require dirname(__DIR__, 2) . '/config/portfolio.php';
        $liveModelVersion = (string) ($cvsConfig['model_version'] ?? '4.0');

        $db            = Database::connection();
        $portfolioRepo = new PortfolioRepository($db);
        $calendar      = new MarketCalendar($portfolioConfig);

        $state      = $portfolioRepo->getCurrentState();
        $holdings   = $portfolioRepo->getCurrentHoldingsWithPrice($liveModelVersion);
        $latestCycle = $portfolioRepo->getLatestCycle();

        $totalValue = round(
            (float) $state['cash'] + array_sum(array_column($holdings, 'value_usd')),
            2
        );

        // Find next NYSE trading day (up to 7 days ahead) for the empty-cycle message.
        $nextTradingDay = null;
        $check = new DateTimeImmutable('today', new DateTimeZone('Europe/Warsaw'));
        for ($i = 0; $i < 7; $i++) {
            if ($calendar->isMarketDay($check)) {
                $nextTradingDay = $check;
                break;
            }
            $check = $check->modify('+1 day');
        }

        Response::view('portfolio', compact(
            'state',
            'holdings',
            'latestCycle',
            'totalValue',
            'nextTradingDay',
            'portfolioConfig',
        ));
    }
}
