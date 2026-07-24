<?php

declare(strict_types=1);

/**
 * Lab — Experimental Portfolios Configuration (change: cvs-experimental-portfolios).
 *
 * Nine deterministic, paper-only portfolios (P0-P8) testing documented
 * execution-policy research. P0-P6 each differ from the P1 baseline by
 * exactly ONE rule; P7/P8 (added under experiment_version '2', research
 * axis 5 — rebalance frequency) instead mirror P6's rules (sector cap 30%)
 * and differ from P6 by exactly ONE rule — the rebalance cadence — to
 * isolate the effect of the rebalance cycle itself. See
 * context/changes/cvs-experimental-portfolios/koncepcja.md for the full
 * research catalogue and rationale.
 *
 * The variant list is CLOSED per experiment_version: this defends against
 * the multiple-comparisons problem (with enough variants, one "wins" by pure
 * chance). Adding, removing, or changing a variant's rules requires bumping
 * experiment_version (mirrors model_version's semantics for CVS scoring) —
 * never edit a live variant's rules in place. Because LabRepository::initPortfolio
 * only stamps experiment_version on a portfolio's FIRST-ever registration
 * (idempotent insert, duplicate = no-op), bumping this value here only tags
 * newly-added codes (P7, P8) — P0-P6's already-persisted rows keep '1'.
 *
 * FR-010 spirit: every knob lives here, never hardcoded in src/Lab/*.
 */
return [

    'experiment_version'  => '2',
    'initial_capital_usd' => 100000.0,
    'cost_per_side_frac'  => 0.0005, // 0.05% fee on each BUY/SELL notional (both sides)

    // Common candidate ranking + rebalance cadence — shared by every variant
    // except P0 (which ignores selection entirely; see benchmark_ticker below).
    'selection' => [
        'top_n'   => 10,
        'rank_by' => 'cvs_swing', // ties broken by cvs_fund desc (LabEngine::selectTargets)
    ],
    'rebalance' => [
        'frequency' => 'monthly', // default cadence: first NYSE trading session of the calendar month.
        // A portfolio can override this via rules.rebalance_frequency ('daily' | 'weekly' | 'monthly') —
        // see P7/P8 below. LabTickService::shouldRebalanceToday() resolves the per-portfolio value.
    ],

    // Statistical inference knobs (Phase 4 — LabStats). Fixed bootstrap seed keeps
    // the confidence interval identical across repeated page loads (determinism).
    'stats' => [
        'bootstrap_iterations' => 2000,
        'bootstrap_seed'       => 20260705,
        'min_sessions'         => 40, // below this, hypothesis status is always 'too_early'
    ],

    // --- Portfolio variants (P0-P8) ---
    // rules.stops shape: null | ['type' => 'atr_swing'] | ['type' => 'fixed_pct', 'pct' => float]
    // rules.rebalance_frequency: optional override of the global rebalance.frequency above
    //   ('daily' | 'weekly' | 'monthly'); omitted = inherit the global default.
    // hypothesis shape:  null | ['claim', 'source', 'versus' => <portfolio code>, 'direction' => 'above'|'below']
    //   direction: 'above' means THIS portfolio is hypothesized to outperform `versus`;
    //              'below' means THIS portfolio is hypothesized to underperform `versus`.
    'portfolios' => [

        'P0' => [
            'name'  => 'Benchmark SPY',
            'rules' => [
                'execution'        => 'close',
                'weighting'        => 'equal',
                'stops'            => null,
                'sector_cap_pct'   => null,
                'benchmark_ticker' => 'SPY', // short-circuits LabEngine::selectTargets to 100% SPY
            ],
            'hypothesis' => null, // control / reference point, not itself a research claim
        ],

        'P1' => [
            'name'  => 'Bazowy CVS',
            'rules' => [
                'execution'        => 'close',
                'weighting'        => 'equal',
                'stops'            => null,
                'sector_cap_pct'   => null,
                'benchmark_ticker' => null,
            ],
            'hypothesis' => null, // baseline — every other variant's hypothesis compares against this
        ],

        'P2' => [
            'name'  => 'Egzekucja na otwarciu',
            'rules' => [
                'execution'        => 'open',
                'weighting'        => 'equal',
                'stops'            => null,
                'sector_cap_pct'   => null,
                'benchmark_ticker' => null,
            ],
            'hypothesis' => [
                'claim'     => 'Egzekucja na CLOSE pobije egzekucję na OPEN — premia momentum realizuje się overnight, a otwarcie ma szersze spready i wyższą zmienność.',
                'source'    => 'Lou, Polk, Skouras — A tug of war: Overnight versus intraday expected returns (Journal of Financial Economics, 2019)',
                'versus'    => 'P1',
                'direction' => 'below', // P2 hypothesized to underperform P1 (close execution)
            ],
        ],

        'P3' => [
            'name'  => 'Stopy ATR',
            'rules' => [
                'execution'        => 'close',
                'weighting'        => 'equal',
                'stops'            => ['type' => 'atr_swing'], // ticker_zone.stop_swing (1.5x Wilder ATR)
                'sector_cap_pct'   => null,
                'benchmark_ticker' => null,
            ],
            'hypothesis' => [
                'claim'     => 'Stopy oparte o ATR poprawiają wynik względem portfela bez stopów (P1) w reżimie z momentum, ograniczając ogon strat.',
                'source'    => 'Kaminski, Lo — When Do Stop-Loss Rules Stop Losses? (Journal of Financial Markets, 2014)',
                'versus'    => 'P1',
                'direction' => 'above',
            ],
        ],

        'P4' => [
            'name'  => 'Ciasny SL -5%',
            'rules' => [
                'execution'        => 'close',
                'weighting'        => 'equal',
                'stops'            => ['type' => 'fixed_pct', 'pct' => 5.0],
                'sector_cap_pct'   => null,
                'benchmark_ticker' => null,
            ],
            'hypothesis' => [
                'claim'     => 'Ciasny sztywny stop-loss -5% pogorszy wynik względem P1 — ucina prawy ogon rozkładu (efekt dyspozycji) i sprzedaje w szumie mean-reversion na pojedynczych akcjach.',
                'source'    => 'Odean — Are Investors Reluctant to Realize Their Losses? (Journal of Finance, 1998); Kaminski & Lo (2014)',
                'versus'    => 'P1',
                'direction' => 'below',
            ],
        ],

        'P5' => [
            'name'  => 'Wagi proporcjonalne do score',
            'rules' => [
                'execution'        => 'close',
                'weighting'        => 'score', // weight_i proportional to cvs_swing_i (clamped >= 0)
                'stops'            => null,
                'sector_cap_pct'   => null,
                'benchmark_ticker' => null,
            ],
            'hypothesis' => [
                'claim'     => 'Wagi proporcjonalne do score CVS pobiją równe wagi — test, czy score niesie informację alokacyjną, nie tylko rankingową.',
                'source'    => 'Plyakha, Uppal, Vilkov — Why Does an Equal-Weighted Portfolio Outperform Value- and Price-Weighted Portfolios? (SSRN, 2012)',
                'versus'    => 'P1',
                'direction' => 'above',
            ],
        ],

        'P6' => [
            'name'  => 'Cap sektorowy',
            'rules' => [
                'execution'        => 'close',
                'weighting'        => 'equal',
                'stops'            => null,
                'sector_cap_pct'   => 30.0, // max 30% of the top_n slots in one sector
                'benchmark_ticker' => null,
            ],
            'hypothesis' => [
                'claim'     => 'Cap sektorowy 30% nie pogorszy zwrotu względem P1, a obniży zmienność (lepszy zwrot/ryzyko) — broni przed koncentracją na wspólnym czynniku sektorowym.',
                'source'    => 'Moskowitz, Grinblatt — Do Industries Explain Momentum? (Journal of Finance, 1999)',
                'versus'    => 'P1',
                'direction' => 'above',
            ],
        ],

        // P7/P8 — research axis 5 (rebalance frequency). Both mirror P6's rules
        // (execution close, equal weights, no stops, sector cap 30%) verbatim and
        // change ONLY the rebalance cadence, so any difference in outcome vs P6
        // isolates the effect of the rebalance cycle itself rather than mixing
        // it with a second changed rule.
        'P7' => [
            'name'  => 'Cap sektorowy — rebalans dzienny',
            'rules' => [
                'execution'           => 'close',
                'weighting'           => 'equal',
                'stops'               => null,
                'sector_cap_pct'      => 30.0,
                'benchmark_ticker'    => null,
                'rebalance_frequency' => 'daily',
            ],
            'hypothesis' => [
                'claim'     => 'Rebalans dzienny nie pobije rebalansu miesięcznego (P6) po uwzględnieniu kosztów — codzienna rewizja składu mnoży liczbę transakcji i opłaty, a sam moment rebalansu ("rebalance timing luck") nie niesie dodatkowej informacji ponad już comiesięczny ranking CVS.',
                'source'    => 'Hoffstein i in. — Rebalance Timing Luck: The Difference Between Hired and Fired (Journal of Index Investing, badania praktyków)',
                'versus'    => 'P6',
                'direction' => 'below',
            ],
        ],

        'P8' => [
            'name'  => 'Cap sektorowy — rebalans tygodniowy',
            'rules' => [
                'execution'           => 'close',
                'weighting'           => 'equal',
                'stops'               => null,
                'sector_cap_pct'      => 30.0,
                'benchmark_ticker'    => null,
                'rebalance_frequency' => 'weekly',
            ],
            'hypothesis' => [
                'claim'     => 'Rebalans tygodniowy nie pobije rebalansu miesięcznego (P6) po uwzględnieniu kosztów — z tych samych powodów co wariant dzienny (P7), choć w mniejszej skali (niższa częstotliwość transakcji).',
                'source'    => 'Hoffstein i in. — Rebalance Timing Luck: The Difference Between Hired and Fired (Journal of Index Investing, badania praktyków)',
                'versus'    => 'P6',
                'direction' => 'below',
            ],
        ],

    ],

];
