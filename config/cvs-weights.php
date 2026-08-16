<?php

declare(strict_types=1);

/**
 * CVS Model Configuration — weights and thresholds.
 *
 * Weights must sum to 1.0.
 * Modify here to recalibrate the model without touching business logic.
 * FR-010: config change requires no code modification.
 */
return [

    // --- Model versioning (Phase 3) ---
    // Bump model_version whenever the scoring methodology changes so that
    // track-record rows from different methodologies are never mixed.
    // FR-010: never hardcode this in business logic — always read from here.
    'model_version' => '4.0',

    // --- Overlay penalties (Phase 5, slice 1) ---
    // Two deterministic post-aggregation penalties applied on top of the base CVS,
    // computed in SHADOW mode under shadow_version (displayed recommendation stays at
    // model_version until the recalibration slice — guardrail FR-016).
    //
    //   Overlay A (revision): trap = clamp((valScore-50)/50, 0, 1);
    //                         penalty = max(-cap, slope * eps_revision_pct * trap)   [eps_revision_pct < 0 only]
    //   Overlay B (target):   penalty = max(-cap, analyst_target_upside * slope)     [upside < 0 only]
    //
    // Default slope/cap values are illustrative (from sim_overlay.php) — finalised in
    // the recalibration slice. FR-010: never hardcode in business logic; always read here.
    'overlays' => [
        'enabled'        => true,
        'shadow_version' => '3.1',
        'revision'       => ['slope' => 120.0, 'cap' => 18.0],
        'target_gate'    => ['slope' => 60.0,  'cap' => 18.0],
    ],

    // --- Earnings-proximity guard (Phase 5, slice 2) ---
    // Deterministic tempering penalty applied near a company's earnings date —
    // momentum-driven conversion is less trustworthy in the ~K-session window
    // around an earnings event (volatility spikes, gap risk). Computed from
    // days_since_earnings/days_to_earnings (injected at fetch-time — FR-015
    // determinism seam, see EarningsCalendarParser) and added to the shadow
    // overlay's penalties.total alongside revision/target (Phase 5, slice 1).
    //
    //   proximity = max(0, (window_sessions - nearest_in_window_days) / window_sessions) ∈ [0, 1]
    //   penalty   = round(max(-cap, -slope * proximity), 1)
    //
    // window_sessions (K): symmetric before/after window in sessions (OQ-1 decision: K=5).
    // Default slope/cap are illustrative — finalised in the recalibration slice,
    // deliberately gentler than overlays.{revision,target_gate} (this tempers, it
    // doesn't punish). FR-010: never hardcode in business logic; always read here.
    //
    // Also drives the always-present `earnings_timing` badge in CVSResult (FR-010) —
    // `enabled` here gates ONLY the shadow penalty, never the badge (FR-017: the
    // badge must work for every user regardless of overlay/shadow-mode flags).
    'earnings_guard' => [
        'enabled'         => true,
        'window_sessions' => 5,
        'penalty'         => ['slope' => 10.0, 'cap' => 10.0],
    ],

    // --- Predictive signals (Phase 7, slice 2) — SHADOW model_version 3.2 ---
    // Symmetric companion to the 3.1 penalty-only shadow: reuses the 3.1
    // revision/target penalties, replaces the symmetric earnings_guard penalty
    // with a directional PEAD guard, and layers three additional symmetric
    // signals (revision breadth, 52w-high proximity, beat consistency).
    //
    // Kill-switch hierarchy (plan-review F3): 3.2 rides on top of the 3.1
    // penalties, so `overlays.enabled = false` disables the entire shadow
    // stack including 3.2 — `signals_32.enabled` only toggles 3.2 on top of
    // an already-enabled 3.1.
    //
    //   PEAD guard (directional, post-earnings 'after'/'in_transit' only):
    //     surprise > 0  -> pead.beat_bonus (default 0 = neutralise the 3.1 guard penalty)
    //     surprise < 0  -> max(-cap, basePenalty * pead.miss_multiplier)  [cap = earnings_guard.penalty.cap]
    //     surprise null or state 'before'/null -> basePenalty unchanged (parity with 3.1)
    //
    //   breadth:    clamp(breadth.weight * eps_revision_breadth, ±breadth.cap)
    //   high_52w:   proximity = current_price / fifty_two_week_high
    //               clamp(high_52w.weight * (proximity - high_52w.baseline) / (1 - high_52w.baseline),
    //                     -high_52w.cap_down, +high_52w.cap_up)
    //               (asymmetric caps — plan-review F4: the negative arm is steep near
    //               baseline 0.85 and partially duplicates the Overlay A "trap" penalty,
    //               so cap_down is set lower than cap_up)
    //   consistency: clamp(consistency.weight * (eps_beat_count_4q - 2) / 2, ±consistency.cap)
    //               (2/4 beats = neutral; weight defaults to 0 -> always 0.0, FR-008)
    //
    // Default values are illustrative — finalised in the recalibration slice.
    // FR-010: never hardcode in business logic; always read from here.
    'signals_32' => [
        'enabled'        => true,
        'shadow_version' => '3.2',
        'pead' => [
            'miss_multiplier' => 1.5,
            'beat_bonus'      => 0.0,
        ],
        'breadth' => [
            'weight' => 4.0,
            'cap'    => 4.0,
        ],
        'high_52w' => [
            'weight'   => 8.0,
            'cap_up'   => 8.0,
            'cap_down' => 4.0,
            'baseline' => 0.85,
        ],
        'consistency' => [
            'weight' => 0.0,
            'cap'    => 4.0,
        ],
    ],

    // --- Valuation pillar — FCF normalization (Phase 5 plaster 3, FR-011) ---
    // Controls the forward-FCF-estimate path in ValuationPillar.
    //
    // use_forward_fcf_estimate: when true, ValuationPillar uses
    //   forward_fcf_est = forward_eps × (trailing_fcf / trailing_eps)
    //   as the EV/FCF denominator instead of trailing_fcf × (1+g)².
    //   This corrects trough-capex distortions (e.g. MU during HBM build-out)
    //   where trailing FCF is depressed but analyst EPS estimates show recovery.
    //   Set false to fall back to the pre-normalization formula everywhere.
    //
    // fcf_to_eps_ratio_min/max: bounds on the trailing FCF/EPS conversion ratio.
    //   If free_cash_flow / trailing_eps falls outside [min, max], the estimate
    //   is discarded and the formula falls back to trailing_fcf × (1+g)².
    //   Prevents pathological cases (near-zero EPS, outlier capex ratios).
    //   FR-010: never hardcode these bounds in business logic; always read here.
    'valuation' => [
        'use_forward_fcf_estimate' => true,
        'fcf_to_eps_ratio_min'     => 0.3,   // below → ratio too small, skip estimate
        'fcf_to_eps_ratio_max'     => 3.0,   // above → ratio too large, skip estimate
    ],

    // --- Peer-group configuration (Phase 3) ---
    // Controls empirical subsector median lookups in MedianResolver.
    //
    // min_sample_count (N): minimum number of tickers in a bucket before the
    //   subsector median is trusted. Below N → fall back to sector median.
    //   Set conservatively: a median from 3 tickers is noisy.
    //
    // anchor_blend: rule used to combine subsector score with sector anchor.
    //   'min'      — final score = min(subsectorScore, sectorScore).
    //                Kotwica can only pull the score DOWN, never up.
    //                Default safe start — tune on real data in Phase 3 manual verification.
    //   'weighted' — reserved for future tuning; MedianResolver will read
    //                anchor_weight when this mode is active.
    //
    // anchor_weight: weight of the sector anchor when anchor_blend='weighted'.
    //   0.0 = pure subsector, 1.0 = pure sector anchor.
    //
    // enabled: master switch. false = Phase 3 code loaded but resolver always
    //   falls back to legacy benchmarks (safe rollout / kill-switch).
    'peer_group' => [
        'enabled'          => true,
        'min_sample_count' => 5,
        'anchor_blend'     => 'min',
        'anchor_weight'    => 0.3,
    ],

    // --- Batch schedule (Phase 3) ---
    // Maps day-of-week (1=Mon…7=Sun) to the list of sectors refreshed that day.
    // This spreads the ~477-ticker population crawl across the week to stay
    // well within Yahoo Finance's unofficial rate limits.
    // Sector names must match values returned by Yahoo Finance assetProfile.sector.
    'batch_schedule' => [
        1 => ['Technology', 'Communication Services'],
        2 => ['Healthcare', 'Financial Services'],
        3 => ['Consumer Cyclical', 'Consumer Defensive'],
        4 => ['Industrials', 'Basic Materials'],
        5 => ['Energy', 'Utilities', 'Real Estate'],
        6 => [],
        7 => [],
    ],

    // --- Dual-mode scoring profiles (S-05) ---
    // Each mode defines pillar weights and ROC composite weights for MomentumPillar.
    // Pillar raw scores (Valuation, Quality) are identical in both modes.
    // Only MomentumPillar uses roc_weights — it returns different composites per mode.
    // FR-010: weights must never be hardcoded in business logic; always read from here.
    'modes' => [
        'swing' => [
            'label'            => 'Swing (1–4 mies.)',
            'valuation_weight' => 0.40,
            'momentum_weight'  => 0.45,
            'quality_weight'   => 0.15,
            'roc_weights'      => ['1m' => 0.50, '3m' => 0.30, '6m' => 0.20],
            'sigmoid_k'        => 3.0,
            'momentum_cap_min' => 5.0,
            'momentum_cap_max' => 95.0,
            'momentum_divisor' => 40.0,
        ],
        'fundamental' => [
            'label'            => 'Fundamentalny (6–12 mies.)',
            'valuation_weight' => 0.65,
            'momentum_weight'  => 0.15,
            'quality_weight'   => 0.20,
            'roc_weights'      => ['3m' => 0.30, '6m' => 0.40, '12m' => 0.30],
            'sigmoid_k'        => 3.0,
            'momentum_cap_min' => 5.0,
            'momentum_cap_max' => 95.0,
            'momentum_divisor' => 40.0,
        ],
    ],

    // --- Sector benchmark medians (hardcoded from Python cvs_analyze.py v1.6 BENCHMARKS dict) ---
    // Used by SectorBenchmarkPillar to score EV/FCF or EV/Sales relative to sector norms.
    // median_ev_fcf:  sector median EV/forward FCF
    // median_ev_sales: sector median EV/forward Sales
    // median_gm:      sector median gross margin (%)
    // max_growth:     sector max growth cap applied to forward estimates (%)
    'benchmarks' => [
        'Technology'             => ['median_ev_fcf' => 32, 'median_ev_sales' =>  8.0, 'median_gm' => 55, 'max_growth' => 60],
        'Healthcare'             => ['median_ev_fcf' => 28, 'median_ev_sales' =>  5.0, 'median_gm' => 60, 'max_growth' => 30],
        'Communication Services' => ['median_ev_fcf' => 22, 'median_ev_sales' =>  4.0, 'median_gm' => 50, 'max_growth' => 25],
        'Consumer Cyclical'      => ['median_ev_fcf' => 20, 'median_ev_sales' =>  1.5, 'median_gm' => 35, 'max_growth' => 20],
        'Consumer Defensive'     => ['median_ev_fcf' => 18, 'median_ev_sales' =>  1.0, 'median_gm' => 40, 'max_growth' =>  8],
        'Industrials'            => ['median_ev_fcf' => 20, 'median_ev_sales' =>  2.0, 'median_gm' => 35, 'max_growth' => 12],
        'Energy'                 => ['median_ev_fcf' => 12, 'median_ev_sales' =>  1.5, 'median_gm' => 30, 'max_growth' => 15],
        'Basic Materials'        => ['median_ev_fcf' => 14, 'median_ev_sales' =>  2.0, 'median_gm' => 35, 'max_growth' => 12],
        'Real Estate'            => ['median_ev_fcf' => 22, 'median_ev_sales' =>  8.0, 'median_gm' => 55, 'max_growth' => 10],
        'Utilities'              => ['median_ev_fcf' => 14, 'median_ev_sales' =>  2.0, 'median_gm' => 30, 'max_growth' =>  5],
        'Financial Services'     => ['median_ev_fcf' => 18, 'median_ev_sales' =>  3.0, 'median_gm' => 70, 'max_growth' => 12, 'median_pb' => 1.2],
        'DEFAULT'                => ['median_ev_fcf' => 20, 'median_ev_sales' =>  3.0, 'median_gm' => 40, 'max_growth' => 20],
    ],

    // --- Quality Gate thresholds (binary filter, applied before CVS) ---
    'quality_gate' => [
        'min_gross_margin'      => 0.04,  // < 4% gross margin → FAIL (covers legit low-margin IT/hardware distributors: AB SA, ALSO, ASBIS)
        'max_debt_to_equity'    => 5.0,   // > 5x D/E → FAIL
        'min_current_ratio'     => 0.5,   // < 0.5 current ratio → FAIL
        'require_positive_revenue' => true, // Revenue must be > 0

        // Sectors where "gross profit" is not a reported concept, so Yahoo hands
        // back 0 and the margin check rejects every constituent on a metric that
        // was never applicable. Banks are the clear case: ING and ING.WA were
        // rejected daily on "Marża brutto 0.0%" despite $26.7bn and 3.0bn PLN of
        // revenue respectively. Exempting the check lets them be scored — with
        // the standing caveat (CLAUDE.md) that the pillars are calibrated for
        // industrials, so financial-sector scores carry lower confidence.
        'skip_gross_margin_sectors' => ['Financial Services'],
    ],

    // --- Financial sector (variant C) ---
    // A bank's free cash flow and gross profit are not measures of anything an
    // investor prices, so EV/FCF and EV/Sales score them on noise. The sector is
    // valued on price/book and judged on returns instead. Observed live
    // 2026-08-16: Polish banks sit at P/B 2.07-2.96 with ROE 14-24%, US regionals
    // at 1.68-1.79 with ROE ~12.6% — which is also why the peer bucket for these
    // wants to be regional rather than Yahoo's single global "Banks - Regional".
    'financials' => [
        // Sectors scored through variant C. Real Estate is deliberately NOT here:
        // REITs do report meaningful cash flow, and CLAUDE.md's accuracy caveat
        // for them is a separate matter from the metric being inapplicable.
        'sectors' => ['Financial Services'],

        // Quality scoring for financials, replacing gross margin / leverage /
        // growth — none of which mean for a bank what they mean elsewhere.
        // Thresholds are the conventional reading of a solid regional bank:
        // ROE above ~12% and ROA above ~1%.
        'quality' => [
            'roe_good'    => 0.12,
            'roe_strong'  => 0.18,
            'roa_good'    => 0.010,
            'roa_strong'  => 0.015,
            // A payout above this is a caution, not a virtue: it can mean the
            // bank has no capital left for growth or loss absorption.
            'payout_max'  => 0.80,
        ],
    ],

    // --- CVS recommendation thresholds ---
    'thresholds' => [
        'strong_buy'  => 72, // ⬆⬆ SILNE KUPUJ
        'accumulate'  => 58, // ⬆  AKUMULUJ
        'neutral'     => 42, // →  NEUTRALNIE
        'reduce'      => 28, // ⬇  REDUKUJ
        // below 28   → ⬇⬇ UNIKAJ
    ],

    // --- Analyst consensus label thresholds (S-09) ---
    // Maps Yahoo Finance recommendationMean (1 = Strong Buy … 5 = Strong Sell) to a
    // Polish label. Inclusive upper bounds; mean > 'sell' → "Silna Sprzedaż".
    // FR-010: thresholds live in config, never hardcoded in business logic.
    'analyst_consensus' => [
        'strong_buy' => 1.5, // mean ≤ 1.5 → Silne Kupuj
        'buy'        => 2.5, // mean ≤ 2.5 → Kupuj
        'hold'       => 3.5, // mean ≤ 3.5 → Trzymaj
        'sell'       => 4.5, // mean ≤ 4.5 → Sprzedaj
    ],

    // --- CVS trajectory (Phase 8, slice 1) ---
    // Sparkline of headline CVS Swing over time on the analysis page.
    'trajectory' => [
        'window_days'     => 90, // how far back the sparkline reaches
        'min_points'      => 2,  // fewer points → empty-state instead of a chart
        // change: cvs-screener-trend — screener's "near boundary" filter:
        // a ticker is flagged when its CVS Swing sits within this many points
        // of ANY recommendation threshold (thresholds.* above).
        'boundary_margin' => 5,
    ],

    // --- Snapshot freshness ---
    // findAllLatest() returns each ticker's newest snapshot with no upper bound
    // on its age, so a ticker whose rescore keeps failing (thin Yahoo coverage
    // is the common cause — several .WA small caps went 5-6 weeks without a
    // usable payload) silently keeps presenting month-old numbers that look
    // exactly like today's. These knobs put an age on that.
    'snapshot_freshness' => [
        // Screener shows an age badge once a snapshot is older than this many
        // calendar days. Human-facing only — nothing is hidden.
        'warn_after_days'  => 3,

        // Hard cutoff for the autonomous wallets' candidate universe: a model
        // cannot judge how old its inputs are, so stale rows are withheld from
        // it rather than badged. Tickers ALREADY HELD are exempt — dropping a
        // held position from the universe strands it (the executor prices
        // trades only from screener rows), which is exactly how MU became
        // unsellable on 2026-08-13/14.
        'llm_max_age_days' => 7,
    ],

    // --- ATR entry zones + stops (Phase 8, slice 2) ---
    // Knobs for AtrZoneCalculator. Illustrative defaults — tune later.
    'atr_zones' => [
        'atr_period'     => 14,   // Wilder ATR lookback
        'support_window' => 20,   // sessions for support = min low
        'zone_atr_mult'  => 1.0,  // zone = [support, support + mult×ATR]
        'fallback_k'     => 1.0,  // fallback zone = [price − k×ATR, price]
        'stop_mult'      => [
            'swing' => 1.5,       // tighter stop for swing
            'fund'  => 3.0,       // wider stop for fundamental
        ],
    ],

    // --- Price-threshold alerts (Phase 8, slice 3) ---
    'price_alert' => [
        // Hysteresis margin as a fraction of zone width: price must leave the zone by
        // this buffer before the alert re-arms. 0.0 = re-arm on any exit.
        'hysteresis_margin_frac' => 0.0,
        'live_price_range'       => '1d', // chart range for the light price read
    ],

    // --- Data source ---
    'data_source' => [
        'provider'        => 'yahoo_finance',
        'max_tickers'     => 10,    // soft cap; enforced by response-time guardrail
        'timeout_seconds' => 25,    // API call timeout per ticker
        'cache_ttl'       => 3600,  // seconds; cache raw API response per ticker
        'max_watchlist'   => 150,   // max watchlist entries per user (S-06)
        'max_history'     => 20,    // max analysis-history entries shown on dashboard (S-08)

        // SEC EDGAR — the regulator's own diluted share count, used ONLY where
        // Yahoo returns none. That is 28 tickers (4.8% of the universe: MU, HD,
        // CRM, LOW, TGT, ADI, EL, HEI...), which otherwise fall back to
        // revenue/revenuePerShare — a figure measured 32.7% low on EL and 28.9%
        // high on KR, because it is a period average rather than a point count.
        //
        // Free and keyless; the SEC asks only for a User-Agent identifying the
        // caller and caps requests at 10/second. Restricted to US domestic
        // primary listings on purpose — see SecFacts::isUsDomesticPrimary() for
        // why ADRs must NOT use it.
        'sec_edgar' => [
            'enabled'         => true,
            // SEC policy requires a real contact. A generic agent gets blocked.
            'user_agent'      => 'CVS Composite Valuation Score admin@amjsystem.eu',
            'timeout_seconds' => 20,
            // null => <repo>/tmp. Share counts change quarterly, so the cache
            // is deliberately long-lived (7 days per company, 30 for the CIK map).
            'cache_dir'       => null,
        ],

        // MomentumPillar benchmark per ticker's home market (compare apples to
        // apples — a WSE-listed construction company shouldn't be scored on
        // momentum vs. the US market). Matched by ticker suffix; 'default' is
        // used for US tickers (no suffix) and any suffix not yet mapped here.
        // Adding a new market later is a one-line addition, no code change.
        'momentum_benchmark' => [
            'default'   => 'SPY',
            'by_suffix' => [
                '.WA' => 'ETFBW20TR.WA', // Beta ETF WIG20TR — Warsaw Stock Exchange
                '.KS' => '069500.KS',    // Samsung KODEX 200 — Korea Exchange (KOSPI 200)
                '.DE' => 'EXS1.DE',      // iShares Core DAX — Xetra (Germany)
                '.L'  => 'ISF.L',        // iShares Core FTSE 100 — London Stock Exchange
                '.PA' => 'CAC.PA',       // Amundi CAC 40 — Euronext Paris
            ],
            // Human-readable name shown on the analysis chart/tooltip — keyed by
            // the resolved benchmark ticker itself, not by suffix.
            'labels' => [
                'SPY'          => 'S&P 500',
                'ETFBW20TR.WA' => 'WIG20TR',
                '069500.KS'    => 'KOSPI 200',
                'EXS1.DE'      => 'DAX',
                'ISF.L'        => 'FTSE 100',
                'CAC.PA'       => 'CAC 40',
            ],
        ],
    ],

    // Human-readable market/exchange name per ticker suffix — screener "Rynek"
    // filter (CVS\Screener\MarketResolver) and the admin/tickers add confirmation.
    // Deliberately separate from data_source.momentum_benchmark above: that config
    // picks a benchmark ETF (a research decision), this one is purely a display
    // label keyed directly by suffix. 'default_label' covers US tickers (no
    // suffix); an unmapped suffix falls back to the raw suffix itself (see
    // MarketResolver::labelForSuffix) so a brand-new market still renders
    // something sensible the moment a ticker from it is added, before anyone
    // gets around to naming it here.
    'markets' => [
        'default_label' => 'USA (NYSE/NASDAQ)',
        'labels' => [
            '.WA' => 'GPW (Warszawa)',
            '.KS' => 'Giełda Korei (KOSPI)',
            '.DE' => 'Niemcy (Xetra)',
            '.L'  => 'Wielka Brytania (LSE)',
            '.PA' => 'Francja (Euronext)',
            '.F'  => 'Niemcy (Frankfurt)',
            '.MI' => 'Włochy (Borsa Italiana)',
            '.OL' => 'Norwegia (Oslo Børs)',
            '.SW' => 'Szwajcaria (SIX)',
            '.TO' => 'Kanada (TSX)',
        ],
    ],

];
