<?php

declare(strict_types=1);

namespace CVS\Lab;

use CVS\Auth\AuthController;
use CVS\Core\Database;
use CVS\Core\Request;
use CVS\Core\Response;

/**
 * Read-only /lab view controller (change: cvs-experimental-portfolios).
 *
 * Renders the NAV chart, metrics table, and hypothesis cards for the seven
 * deterministic paper portfolios (P0-P6), plus a for-reference LLM series
 * pulled read-only from the (separate) Portfolio module's rebalance_cycle
 * table. Never touches CVS\Portfolio\* classes — see LabRepository::getLlmValueSeries().
 */
class LabController
{
    public function index(Request $req): void
    {
        AuthController::requireAuth();

        $labConfig = require dirname(__DIR__, 2) . '/config/lab-portfolios.php';
        $repo      = new LabRepository(Database::connection());

        $navSeries  = $repo->getNavSeries();
        $portfolios = $repo->getAllPortfolios();
        $tradeStats = $repo->getTradeStats();

        /** @var list<string> $codes */
        $codes = array_keys($labConfig['portfolios']);

        $d0 = null;
        foreach ($navSeries as $series) {
            if ($series === []) {
                continue;
            }
            $first = $series[0]['date'];
            if ($d0 === null || $first < $d0) {
                $d0 = $first;
            }
        }

        $llmSeries = $d0 !== null ? $repo->getLlmValueSeries($d0) : [];

        $chartSeries = [];
        foreach ($codes as $code) {
            $chartSeries[$code] = LabMetrics::normaliseToBase100($navSeries[$code] ?? []);
        }
        $chartSeries['LLM'] = LabMetrics::normaliseToBase100($llmSeries, 'value');

        $p0Return = LabMetrics::totalReturnPct($navSeries['P0'] ?? []);
        $p1Return = LabMetrics::totalReturnPct($navSeries['P1'] ?? []);

        $statsCfg = $labConfig['stats'];
        $hypothesisStatuses = [];
        foreach ($codes as $code) {
            $hypothesis = $labConfig['portfolios'][$code]['hypothesis'] ?? null;
            if ($hypothesis === null) {
                continue;
            }

            $variantReturns   = LabStats::dailyReturns($navSeries[$code] ?? []);
            $referenceReturns = LabStats::dailyReturns($navSeries[$hypothesis['versus']] ?? []);
            $diffs            = LabStats::pairedDiffs($variantReturns, $referenceReturns);
            $n                = count($diffs);
            $ci               = LabStats::bootstrapCiOfMeanDiff($diffs, (int) $statsCfg['bootstrap_iterations'], (int) $statsCfg['bootstrap_seed']);

            $hypothesisStatuses[$code] = [
                'status'       => LabStats::hypothesisStatus($ci, $n, $hypothesis, $statsCfg),
                'ci'           => $ci,
                'n'            => $n,
                'min_sessions' => (int) $statsCfg['min_sessions'],
            ];
        }

        $positions = [];
        foreach ($codes as $code) {
            $positions[$code] = $repo->getPositions($code);
        }

        $metrics = [];
        foreach ($codes as $code) {
            $series      = $navSeries[$code] ?? [];
            $totalReturn = LabMetrics::totalReturnPct($series);

            $metrics[$code] = [
                'total_return_pct' => $totalReturn,
                'vs_spy_pp'        => ($totalReturn !== null && $p0Return !== null) ? round($totalReturn - $p0Return, 2) : null,
                'vs_p1_pp'         => ($totalReturn !== null && $p1Return !== null) ? round($totalReturn - $p1Return, 2) : null,
                'max_drawdown_pct' => LabMetrics::maxDrawdownPct($series),
                'fee_total'        => (float) ($tradeStats[$code]['fee_total'] ?? 0.0),
                'tx_count'         => (int) ($tradeStats[$code]['tx_count'] ?? 0),
                'sessions'         => count($series),
            ];
        }

        Response::view('lab', [
            'portfolioDefs'       => $labConfig['portfolios'],
            'portfolios'          => $portfolios,
            'chartSeries'         => $chartSeries,
            'metrics'             => $metrics,
            'positions'           => $positions,
            'd0'                  => $d0,
            'hypothesisStatuses'  => $hypothesisStatuses,
        ]);
    }
}
