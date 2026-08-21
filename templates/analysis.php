<section class="analysis-detail">
    <div class="analysis-detail__heading">
        <h1>Analiza: <?= htmlspecialchars($ticker) ?>
            <?php if (!empty($financials['long_name'])): ?>
                <span style="font-size:var(--text-base);font-weight:400;color:var(--c-muted);margin-left:.5rem;">
                    <?= htmlspecialchars((string) $financials['long_name']) ?>
                </span>
            <?php endif; ?>
        </h1>
        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
            <a href="/track-record/<?= urlencode($ticker) ?>" class="btn btn--ghost btn--sm">Historia CVS</a>
            <?php
            $alertGlobalOn     = $alertsEnabled ?? false;
            $alertTickerOff    = $tickerAlertDisabled ?? false;
            $alertBtnLabel     = $alertTickerOff ? '🔕 Alerty OFF' : '🔔 Alerty ON';
            $alertBtnClass     = $alertTickerOff ? 'btn--ghost' : 'btn--secondary';
            $alertBtnTitle     = !$alertGlobalOn
                ? 'Włącz alerty globalnie na dashboardzie'
                : ($alertTickerOff ? 'Włącz alerty dla tej spółki' : 'Wycisz alerty dla tej spółki');
            ?>
            <button id="btn-alert-ticker"
                    class="btn btn--sm <?= $alertBtnClass ?>"
                    data-ticker="<?= htmlspecialchars($ticker) ?>"
                    data-disabled="<?= $alertTickerOff ? '1' : '0' ?>"
                    <?= !$alertGlobalOn ? 'disabled title="' . htmlspecialchars($alertBtnTitle) . '"' : 'title="' . htmlspecialchars($alertBtnTitle) . '"' ?>>
                <?= $alertBtnLabel ?>
            </button>
            <button id="btn-company-info" class="btn btn--ghost btn--sm">
                Informacje o spółce
            </button>
            <button class="watchlist-detail-btn<?= ($isWatched ?? false) ? ' is-watched' : '' ?>"
                    data-ticker="<?= htmlspecialchars($ticker) ?>"
                    data-watched="<?= ($isWatched ?? false) ? '1' : '0' ?>">
                <?= ($isWatched ?? false) ? '× Usuń z obserwowanych' : '⭐ Obserwuj' ?>
            </button>
        </div>
    </div>

    <!-- Company info modal -->
    <div id="company-modal" class="ai-modal" hidden>
        <div class="ai-modal__inner company-modal__inner">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin-bottom:1rem;">
                <div>
                    <h3 style="font-size:var(--text-lg);margin:0;">
                        <?= htmlspecialchars((string) ($financials['long_name'] ?? $ticker)) ?>
                    </h3>
                    <p style="color:var(--c-muted);font-size:var(--text-sm);margin:.25rem 0 0;">
                        <?= htmlspecialchars($ticker) ?>
                        <?php if (!empty($financials['sector'])): ?> · <?= htmlspecialchars((string) $financials['sector']) ?><?php endif; ?>
                        <?php if (!empty($financials['industry'])): ?> · <?= htmlspecialchars((string) $financials['industry']) ?><?php endif; ?>
                        <?php if (!empty($financials['country'])): ?> · <?= htmlspecialchars((string) $financials['country']) ?><?php endif; ?>
                    </p>
                </div>
                <div style="display:flex;gap:.5rem;flex-shrink:0;">
                    <?php if (!empty($financials['long_description'])): ?>
                    <button id="btn-translate-desc" class="btn btn--ghost btn--sm" type="button"
                            data-lang="en" title="Przetłumacz opis na polski (tłumaczenie on-device w Chrome)">
                        EN ⇄ PL
                    </button>
                    <?php endif; ?>
                    <button id="btn-company-close" class="btn btn--ghost btn--sm">✕</button>
                </div>
            </div>

            <p class="company-modal__desc">
                <?php if (!empty($financials['long_description'])): ?>
                    <span id="company-desc-text"
                          data-en="<?= htmlspecialchars((string) $financials['long_description']) ?>"
                          <?php if ($cachedDescriptionPl ?? null): ?>data-pl="<?= htmlspecialchars((string) $cachedDescriptionPl) ?>"<?php endif; ?>
                    ><?= htmlspecialchars((string) $financials['long_description']) ?></span>
                <?php else: ?>
                    <em style="color:var(--c-muted);">Opis spółki zostanie załadowany przy następnym odświeżeniu danych (cache wygaśnie po 1h).</em>
                <?php endif; ?>
            </p>

            <div class="company-modal__meta">
                <?php if (!empty($financials['employees'])): ?>
                <span>👥 <?= number_format((int) $financials['employees']) ?> pracowników</span>
                <?php endif; ?>
                <?php if (!empty($financials['website'])): ?>
                <a href="<?= htmlspecialchars((string) $financials['website']) ?>"
                   target="_blank" rel="noopener noreferrer" style="color:var(--c-primary);">
                    🌐 <?= htmlspecialchars((string) $financials['website']) ?>
                </a>
                <?php endif; ?>
            </div>

            <p class="disclaimer-inline" style="margin-top:1rem;">
                Dane: Yahoo Finance. Treść w języku angielskim pochodzi bezpośrednio ze źródła.
            </p>
        </div>
    </div>
    <script>
    document.getElementById('btn-company-info')?.addEventListener('click', function () {
        document.getElementById('company-modal').hidden = false;
    });
    document.getElementById('btn-company-close')?.addEventListener('click', function () {
        document.getElementById('company-modal').hidden = true;
    });
    document.getElementById('company-modal')?.addEventListener('click', function (e) {
        if (e.target === this) this.hidden = true;
    });

    // On-device translation (Chrome Translator API / Built-in AI, Gemini Nano).
    // Falls back to a cached server-side translation (data-pl) saved by an
    // earlier user whose browser did support the API.
    (function () {
        const btn  = document.getElementById('btn-translate-desc');
        const text = document.getElementById('company-desc-text');
        if (!btn || !text) return;

        btn.addEventListener('click', async function () {
            if (btn.dataset.lang === 'en') {
                // EN → PL
                if (text.dataset.pl) {
                    text.textContent = text.dataset.pl;
                    btn.dataset.lang = 'pl';
                    return;
                }

                if (!('Translator' in self)) {
                    alert('Tłumaczenie on-device wymaga aktualnego Chrome (Translator API / Built-in AI). Ta przeglądarka go nie wspiera, a tłumaczenie nie jest jeszcze dostępne w cache.');
                    return;
                }

                btn.disabled = true;
                btn.textContent = 'Tłumaczenie…';
                try {
                    const availability = await Translator.availability({ sourceLanguage: 'en', targetLanguage: 'pl' });
                    if (availability === 'unavailable') {
                        alert('Tłumaczenie EN → PL nie jest dostępne w tej przeglądarce.');
                        return;
                    }

                    const translator = await Translator.create({ sourceLanguage: 'en', targetLanguage: 'pl' });
                    const translated = await translator.translate(text.dataset.en);

                    text.dataset.pl  = translated;
                    text.textContent = translated;
                    btn.dataset.lang = 'pl';

                    // Cache the result so users without Translator API benefit too.
                    const csrf = getCsrfTokenForTranslation();
                    fetch('/api/translation/save', {
                        method:  'POST',
                        headers: {
                            'Content-Type':     'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token':     csrf,
                        },
                        body: new URLSearchParams({
                            ticker: <?= json_encode($ticker) ?>,
                            lang:   'pl',
                            field:  'long_description',
                            text:   translated,
                            _csrf:  csrf,
                        }),
                    }).catch(function () {});
                } catch (e) {
                    alert('Tłumaczenie nie powiodło się: ' + e.message);
                } finally {
                    btn.disabled = false;
                    btn.textContent = 'EN ⇄ PL';
                }
                return;
            }

            // PL → EN
            text.textContent = text.dataset.en;
            btn.dataset.lang = 'en';
        });

        function getCsrfTokenForTranslation() {
            return document.querySelector('meta[name="csrf-token"]')?.content
                ?? document.getElementById('csrf-token')?.value
                ?? '';
        }
    })();
    </script>

    <?php if (!empty($error)): ?>
        <p class="alert alert--error"><?= htmlspecialchars($error) ?></p>
    <?php elseif ($result !== null): ?>

        <?php if (!$result['quality_gate']): ?>
            <div class="card card--fail">
                <h2>Odrzucono przez Quality Gate</h2>
                <p>Spółka nie spełnia minimalnych wymagań jakościowych. CVS nie zostało wyliczone.</p>
                <ul class="failure-list">
                    <?php foreach ($result['gate_failures'] as $fail): ?>
                        <li><?= htmlspecialchars($fail) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else: ?>

            <?php
            // Dual-currency display helpers (Phase 4 multi-currency-fx)
            $nativeCcy   = (string) ($financials['native_currency'] ?? '');
            $fxToUsd     = isset($financials['fx_rate_to_usd']) && (float) $financials['fx_rate_to_usd'] > 0
                ? (float) $financials['fx_rate_to_usd'] : null;
            $isDualCcy   = $nativeCcy !== '' && $nativeCcy !== 'USD' && $fxToUsd !== null;

            $ccySymbol = static function (string $code): string {
                return match ($code) {
                    'KRW' => '₩', 'EUR' => '€', 'JPY' => '¥', 'GBP' => '£',
                    'TWD' => 'NT$', 'CNY' => '¥', 'HKD' => 'HK$', 'SGD' => 'S$',
                    'CAD' => 'C$', 'AUD' => 'A$', 'CHF' => 'CHF ',
                    default => $code . ' ',
                };
            };

            /** Format USD value; if dual-currency, append "(SYMBOL native)" in parentheses. */
            $fmtDual = static function (?float $usdVal, ?float $nativeVal = null) use ($isDualCcy, $nativeCcy, $fxToUsd, $ccySymbol): string {
                if ($usdVal === null) return '–';
                $usdStr = '$' . number_format($usdVal, 2);
                if (!$isDualCcy || $fxToUsd === null) return $usdStr;
                $native = $nativeVal ?? ($usdVal / $fxToUsd);
                $sym    = $ccySymbol($nativeCcy);
                $dec    = in_array($nativeCcy, ['KRW', 'JPY', 'IDR', 'VND', 'CLP', 'HUF'], true) ? 0 : 2;
                return $usdStr . ' (' . $sym . number_format($native, $dec) . ')';
            };

            // Helper: format raw financial values (B/M/K + ratio %)
            $ratioKeys = ['gross_margins', 'revenue_growth', 'return_on_equity', 'operating_margin', 'profit_margin', 'short_pct_float', 'institutional_ownership'];
            $fmtRaw = static function (string $key, $val) use ($ratioKeys): string {
                if (is_bool($val)) return $val ? 'tak' : 'nie';
                if (!is_numeric($val)) return htmlspecialchars((string)$val);
                $f = (float) $val;
                if (in_array($key, $ratioKeys, true)) {
                    return number_format($f * 100, 1) . '%';
                }
                $abs = abs($f);
                if ($abs >= 1_000_000_000) return number_format($f / 1_000_000_000, 2) . ' B';
                if ($abs >= 1_000_000)     return number_format($f / 1_000_000, 1) . ' M';
                if ($abs >= 1_000)         return number_format($f / 1_000, 1) . ' K';
                if ($abs < 100)            return number_format($f, 2);
                return number_format($f, 0);
            };

            // Helper: recommendation → CSS class
            $swing = $result['swing'] ?? [];
            $fund  = $result['fundamental'] ?? [];
            $ps    = $result['pillar_scores'] ?? [];
            $gs    = $result['golden_signal'] ?? null;

            $gsLabels = [
                'strong'    => ['stars' => '⭐⭐', 'label' => 'Silny sygnał — wartość i momentum'],
                'watchlist' => ['stars' => '⭐',   'label' => 'Setup — czekaj na momentum'],
                'momentum'  => ['stars' => '',     'label' => 'Momentum — nie value'],
            ];

            $swingWeights = [
                'Wycena'   => '40%',
                'Momentum' => '45%',
                'Jakość'   => '15%',
            ];
            $fundWeights = [
                'Wycena'   => '65%',
                'Momentum' => '15%',
                'Jakość'   => '20%',
            ];

            $cfgFile   = require dirname(__DIR__) . '/config/cvs-weights.php';
            $modeSwing = $cfgFile['modes']['swing']       ?? [];
            $modeFund  = $cfgFile['modes']['fundamental'] ?? [];

            // CVS Fair Value — delegated to FairPriceCalculator rather than
            // reimplemented here. This block used to carry its own copy of the
            // EV/FCF formula, which meant the tile silently knew nothing about
            // the peer-group medians or the financial-sector path: every bank
            // rendered without a fair value at all, and any change to the
            // canonical formula left this copy behind. Same resolver as the
            // screener column, so the two figures cannot disagree.
            $cvsFairPrice = !empty($financials)
                ? \CVS\Ai\FairPriceCalculator::compute($financials, $cfgFile, \CVS\CVS\Valuation\MedianResolver::fromConfig($cfgFile))
                : null;

            $tileLevelClass = static function (float $cvs): string {
                if ($cvs >= 72) return 'score-tile--strong';
                if ($cvs >= 42) return 'score-tile--neutral';
                return 'score-tile--weak';
            };

            // Shadow overlays (3.1/3.2) computed once here so both the compact
            // "po korekcie" badges on the score tiles below AND the detailed
            // breakdown chips further down read the same variables — previously
            // each block recomputed its own copy from $result.
            $overlay = $result['overlay'] ?? null;
            if ($overlay !== null) {
                $ovPenalties = $overlay['penalties'] ?? [];
                $ovCoverage  = $overlay['coverage']  ?? [];
                $ovRevision  = (float) ($ovPenalties['revision'] ?? 0.0);
                $ovTarget    = (float) ($ovPenalties['target']   ?? 0.0);
                $ovVersion   = (string) ($overlay['shadow_version'] ?? '3.1');

                $ovMissing = [];
                if (!empty($ovCoverage['missing_eps_trend'])) $ovMissing[] = 'rewizja';
                if (!empty($ovCoverage['missing_target']))    $ovMissing[] = 'target';

                $ov31BreakdownHtml = '<strong>Skąd ta korekta (' . htmlspecialchars($ovVersion) . ')?</strong><br>'
                    . 'Kara za rewizję EPS: ' . htmlspecialchars(number_format($ovRevision, 1)) . ' pkt<br>'
                    . 'Kara za cel cenowy analityków: ' . htmlspecialchars(number_format($ovTarget, 1)) . ' pkt'
                    . ($ovMissing !== [] ? '<br><em>Brak danych: ' . htmlspecialchars(implode('/', $ovMissing)) . '</em>' : '')
                    . '<br><br>Tryb cieniowy eksperymentalny — nigdy nie zmienia oficjalnej rekomendacji pokazanej wyżej.';
            }

            $shadow32 = null;
            foreach (($result['shadows'] ?? []) as $shadowBlock) {
                if (($shadowBlock['shadow_version'] ?? '') === '3.2') {
                    $shadow32 = $shadowBlock;
                    break;
                }
            }
            if ($shadow32 !== null) {
                $s32Penalties = $shadow32['penalties'] ?? [];
                $s32Signals   = $shadow32['signals']   ?? [];
                $s32Coverage  = $shadow32['coverage']  ?? [];
                $s32Adj       = $s32Signals['adjustments'] ?? [];
                $s32Pead      = (float) ($s32Penalties['earnings_guard'] ?? 0.0);
                $s32Breadth   = (float) ($s32Adj['breadth']    ?? 0.0);
                $s32High52w   = (float) ($s32Adj['high_52w']   ?? 0.0);
                $s32Consist   = (float) ($s32Adj['consistency'] ?? 0.0);

                $s32Missing = [];
                if (!empty($s32Coverage['missing_surprise']))    $s32Missing[] = 'zaskoczenie';
                if (!empty($s32Coverage['missing_breadth']))     $s32Missing[] = 'rewizje';
                if (!empty($s32Coverage['missing_52w']))         $s32Missing[] = '52w';
                if (!empty($s32Coverage['missing_consistency'])) $s32Missing[] = 'konsystencja';

                $ov32BreakdownHtml = '<strong>Skąd ta korekta (3.2)?</strong><br>'
                    . 'PEAD (reakcja na wyniki): ' . htmlspecialchars(number_format($s32Pead, 1)) . ' pkt<br>'
                    . 'Szerokość rewizji analityków: ' . htmlspecialchars(number_format($s32Breadth, 1)) . ' pkt<br>'
                    . 'Bliskość 52-tyg. maksimum: ' . htmlspecialchars(number_format($s32High52w, 1)) . ' pkt<br>'
                    . 'Konsystencja pobić prognoz: ' . htmlspecialchars(number_format($s32Consist, 1)) . ' pkt'
                    . ($s32Missing !== [] ? '<br><em>Brak danych: ' . htmlspecialchars(implode('/', $s32Missing)) . '</em>' : '')
                    . '<br><br>Tryb cieniowy eksperymentalny — nigdy nie zmienia oficjalnej rekomendacji pokazanej wyżej.';
            }

            // Compact "po korekcie" row rendered under each score tile — the same
            // shadow number the breakdown chips explain in detail below, placed
            // next to the real indicator it adjusts. Delta arrow/colour mirrors
            // the screener's $trendChip convention.
            $shadowDeltaChip = static function (string $version, float $adjusted, float $base, string $shadowReco, string $officialReco, string $breakdownHtml): string {
                $delta = round($adjusted - $base, 1);
                [$arrow, $color] = match (true) {
                    $delta > 0  => ['↑', 'var(--c-success)'],
                    $delta < 0  => ['↓', 'var(--c-danger)'],
                    default     => ['→', 'var(--c-muted)'],
                };
                $deltaText = $delta != 0.0 ? ' ' . $arrow . ' ' . number_format(abs($delta), 1) : ' ' . $arrow;
                $recoNote = $shadowReco !== '' && $shadowReco !== $officialReco
                    ? ' Rekomendacja cieniowa: <strong>' . htmlspecialchars($shadowReco) . '</strong> (różni się od oficjalnej).'
                    : '';
                return '<span class="score-tile__shadow-row">'
                    . '<span class="score-tile__shadow-label">' . htmlspecialchars($version) . '</span>'
                    . '<span class="score-tile__shadow-value" style="color:' . $color . ';">' . number_format($adjusted, 1) . $deltaText . '</span>'
                    . '<span class="chart-hint" tabindex="0">ⓘ<span class="chart-hint__tooltip">' . $breakdownHtml . $recoNote . '</span></span>'
                    . '</span>';
            };
            ?>


            <!-- Dual CVS score header -->
            <div class="card card--result">
                <?php if ($gs && isset($gsLabels[$gs])): ?>
                    <span class="signal-pill signal-pill--<?= htmlspecialchars($gs) ?>">
                        <?= $gsLabels[$gs]['stars'] ? htmlspecialchars($gsLabels[$gs]['stars']) . ' ' : '' ?><?= htmlspecialchars($gsLabels[$gs]['label']) ?>
                    </span>
                <?php endif; ?>

                <div class="dual-cvs-header">
                    <!-- Swing score -->
                    <div class="score-tile score-tile--swing <?= $tileLevelClass((float)($swing['cvs'] ?? 0)) ?>">
                        <span class="score-tile__mode"><?= htmlspecialchars($modeSwing['label'] ?? 'Swing') ?></span>
                        <span class="score-tile__value"><?= number_format((float)($swing['cvs'] ?? 0), 1) ?></span>
                        <span class="score-tile__reco"><?= htmlspecialchars($swing['recommendation'] ?? '') ?></span>
                        <?php if ($overlay !== null || $shadow32 !== null): ?>
                        <div class="score-tile__shadows">
                            <?php if ($overlay !== null): ?>
                                <?= $shadowDeltaChip($ovVersion, (float) $overlay['swing'], (float) ($swing['cvs'] ?? 0), (string) ($overlay['swing_reco'] ?? ''), (string) ($swing['recommendation'] ?? ''), $ov31BreakdownHtml) ?>
                            <?php endif; ?>
                            <?php if ($shadow32 !== null): ?>
                                <?= $shadowDeltaChip('3.2', (float) $shadow32['swing'], (float) ($swing['cvs'] ?? 0), (string) ($shadow32['swing_reco'] ?? ''), (string) ($swing['recommendation'] ?? ''), $ov32BreakdownHtml) ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Fundamental score -->
                    <div class="score-tile score-tile--fund <?= $tileLevelClass((float)($fund['cvs'] ?? 0)) ?>">
                        <span class="score-tile__mode"><?= htmlspecialchars($modeFund['label'] ?? 'Fundamentalny') ?></span>
                        <span class="score-tile__value"><?= number_format((float)($fund['cvs'] ?? 0), 1) ?></span>
                        <span class="score-tile__reco"><?= htmlspecialchars($fund['recommendation'] ?? '') ?></span>
                        <?php if ($overlay !== null || $shadow32 !== null): ?>
                        <div class="score-tile__shadows">
                            <?php if ($overlay !== null): ?>
                                <?= $shadowDeltaChip($ovVersion, (float) $overlay['fund'], (float) ($fund['cvs'] ?? 0), (string) ($overlay['fund_reco'] ?? ''), (string) ($fund['recommendation'] ?? ''), $ov31BreakdownHtml) ?>
                            <?php endif; ?>
                            <?php if ($shadow32 !== null): ?>
                                <?= $shadowDeltaChip('3.2', (float) $shadow32['fund'], (float) ($fund['cvs'] ?? 0), (string) ($shadow32['fund_reco'] ?? ''), (string) ($fund['recommendation'] ?? ''), $ov32BreakdownHtml) ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                // Phase 5 (slice 2) — earnings-timing badge (FR-010). Always present,
                // independent of overlays/earnings_guard flags (badge ≠ guard
                // separation, FR-017) — deliberately NOT nested inside
                // `$overlay !== null` (the 3.1/3.2 breakdown chips that used to sit
                // here were removed once their numbers moved onto the score tiles
                // themselves as "po korekcie" badges — see $shadowDeltaChip above).
                if (($et = $result['earnings_timing'] ?? null) !== null && $et['state'] !== null):
                    $etState  = (string) $et['state'];
                    $etDaysTo = $et['days_to']    ?? null;
                    $etDaysSi = $et['days_since'] ?? null;

                    $etPillClass = match ($etState) {
                        'before'     => 'signal-pill--momentum',
                        'in_transit' => 'signal-pill--watchlist',
                        'after'      => 'signal-pill--neutral',
                        default      => 'signal-pill--neutral',
                    };

                    $etDni = static function (?int $n): string {
                        return $n === 1 ? 'dzień' : 'dni';
                    };

                    $etLabel = match ($etState) {
                        'before'     => sprintf('📅 Wyniki za %d %s', (int) $etDaysTo, $etDni((int) $etDaysTo)),
                        'in_transit' => '📅 W oknie wyników',
                        'after'      => sprintf('📅 Wyniki %d %s temu', (int) $etDaysSi, $etDni((int) $etDaysSi)),
                        default      => '📅 Wyniki',
                    };
                ?>
                <span class="signal-pill <?= htmlspecialchars($etPillClass) ?>" style="margin-top:.6rem;display:inline-block;">
                    <?= htmlspecialchars($etLabel) ?>
                </span>
                <?php endif; ?>

                <!-- Radar + Price chart side-by-side -->
                <div class="radar-price-row">
                    <div class="detail-radar-wrapper chart-zoom-target" data-zoom-canvas="detail-radar" data-zoom-title="Radar 3 filarów — Swing vs Fundamentalny">
                        <span class="chart-zoom-target__hint" aria-hidden="true">🔍</span>
                        <canvas id="detail-radar" width="300" height="300"></canvas>
                        <div class="detail-radar-legend">
                            <span class="legend-dot legend-dot--swing"></span> Swing &nbsp;
                            <span class="legend-dot legend-dot--fund"></span> Fundamentalny
                        </div>
                    </div>
                    <?php if (!empty($financials['monthly_closes'])): ?>
                    <div class="price-chart-compact chart-zoom-target" data-zoom-canvas="price-chart" data-zoom-title="Kurs akcji — 12 miesięcy (baza=100)">
                        <span class="chart-zoom-target__hint" aria-hidden="true">🔍</span>
                        <div class="price-chart-compact__label">
                            Kurs akcji — 12 miesięcy (baza=100)
                            <span class="chart-hint" tabindex="0">ⓘ
                                <span class="chart-hint__tooltip">
                                    <strong>Jak czytać wykres?</strong><br>
                                    Obie linie są przeliczone do bazy&nbsp;100 na początku okresu —
                                    porównujesz tempo wzrostu, nie cenę nominalną.<br><br>
                                    <?php $benchmarkLabel = (string) ($financials['benchmark_label'] ?? 'S&P 500'); ?>
                                    <strong><?= htmlspecialchars($ticker) ?></strong> — miesięczne zamknięcia spółki.<br>
                                    <strong><?= htmlspecialchars($benchmarkLabel) ?></strong> — benchmark rynku macierzystego spółki.
                                    Linia spółki powyżej <?= htmlspecialchars($benchmarkLabel) ?> = spółka biła swój rynek w tym okresie.
                                </span>
                            </span>
                        </div>
                        <canvas id="price-chart"></canvas>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- CVS trajectory (Phase 8 slice 1) -->
                <?php $hasTrajChart = !empty($trajectory) && !empty($trajectory['has_trajectory']); ?>
                <div class="trajectory-block<?= $hasTrajChart ? ' chart-zoom-target' : '' ?>"
                     <?= $hasTrajChart ? 'data-zoom-canvas="trajectory-chart" data-zoom-title="Trajektoria CVS Swing · 90 dni"' : '' ?>>
                    <?php if ($hasTrajChart): ?><span class="chart-zoom-target__hint" aria-hidden="true">🔍</span><?php endif; ?>
                    <h3>Trajektoria CVS <span class="trajectory-block__sub">Swing · 90 dni</span>
                        <span class="chart-hint" tabindex="0">ⓘ
                            <span class="chart-hint__tooltip">
                                <strong>Czym jest trajektoria CVS?</strong><br>
                                Linia pokazuje, jak wynik <strong>CVS Swing</strong> (0–100) tej spółki
                                zmieniał się w czasie — z dziennych pomiarów z ostatnich 90 dni.<br><br>
                                <strong>Kierunek bywa ważniejszy niż poziom:</strong> linia rosnąca =
                                poprawiające się przekonanie modelu, opadająca = słabnące.<br><br>
                                <strong>d/d</strong> — zmiana od poprzedniego pomiaru.
                                <strong>t/t</strong> — zmiana tydzień do tygodnia (b/d gdy brak punktu sprzed ~7 dni).
                            </span>
                        </span>
                    </h3>
                    <?php if ($hasTrajChart): ?>
                        <?php
                        $deltaChip = static function ($d): string {
                            if ($d === null) {
                                return '<span class="trajectory-delta trajectory-delta--flat">→ b/d</span>';
                            }
                            $d = (float) $d;
                            if ($d > 0) {
                                return '<span class="trajectory-delta trajectory-delta--up">▲ +' . number_format($d, 1) . '</span>';
                            }
                            if ($d < 0) {
                                return '<span class="trajectory-delta trajectory-delta--down">▼ ' . number_format($d, 1) . '</span>';
                            }
                            return '<span class="trajectory-delta trajectory-delta--flat">→ 0.0</span>';
                        };
                        ?>
                        <div class="trajectory-deltas">
                            <span class="trajectory-deltas__label">Zmiana:</span>
                            d/d <?= $deltaChip($trajectory['delta_daily']) ?>
                            &nbsp; t/t <?= $deltaChip($trajectory['delta_weekly']) ?>
                        </div>
                        <div class="trajectory-chart"><canvas id="trajectory-chart"></canvas></div>
                        <a class="trajectory-link" href="/track-record/<?= urlencode($ticker) ?>">Pełna historia CVS →</a>
                    <?php elseif ($trajectory === null): ?>
                        <p class="trajectory-empty">Dodaj tę spółkę do watchlisty, by CVS zbierał jej trajektorię w czasie.</p>
                    <?php else: ?>
                        <p class="trajectory-empty">Za mało danych — trajektoria pojawi się po kolejnych odświeżeniach (spółka jest obserwowana).</p>
                    <?php endif; ?>
                </div>

                <!-- Chart zoom modal (desktop only — see .chart-zoom-target click
                     handler in app.js). Reuses the already-rendered Chart.js
                     instance's data/options at a larger size; never wired on
                     mobile, where closing a full-screen modal reliably is its
                     own unsolved problem elsewhere in this app. -->
                <div id="chart-zoom-modal" class="ai-modal" hidden>
                    <div class="ai-modal__inner chart-zoom-modal__inner">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1rem;">
                            <h3 id="chart-zoom-title" style="margin:0;font-size:var(--text-lg);">—</h3>
                            <button id="chart-zoom-close" class="btn btn--ghost btn--sm" type="button">✕</button>
                        </div>
                        <div class="chart-zoom-modal__canvas-wrap">
                            <canvas id="chart-zoom-canvas"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Execution plan (Phase 8 slice 2) -->
                <?php if (!empty($execPlan) && !empty($execPlan['has_zone'])): ?>
                <?php
                $epBadge = match ($execPlan['state'] ?? null) {
                    'in_zone' => ['exec-badge--in',    '✓ Cena w strefie kupna'],
                    'above'   => ['exec-badge--above', '↑ Powyżej strefy — czekaj na cofnięcie'],
                    'below'   => ['exec-badge--below', '↓ Poniżej strefy (poniżej wsparcia)'],
                    default   => ['exec-badge--flat',  '—'],
                };
                $usd = static fn($v): string => '$' . number_format((float) $v, 2);
                ?>
                <div class="exec-plan">
                    <h3>Plan egzekucji <span class="trajectory-block__sub">ATR · strefa + stop</span>
                        <span class="chart-hint" tabindex="0">ⓘ
                            <span class="chart-hint__tooltip">
                                <strong>Jak czytać plan egzekucji?</strong><br>
                                <strong>Strefa kupna</strong> — sugerowany przedział akumulacji, kotwiczony o
                                ostatnie wsparcie (min. z ~20 sesji) i poszerzony o zmienność (ATR-14).<br><br>
                                <strong>Stop</strong> — poziom wyjścia oparty na zmienności (N×ATR pod strefą):
                                ciaśniejszy dla swingu, szerszy dla podejścia fundamentalnego.<br><br>
                                To warstwa ryzyka NAD wynikiem CVS — nie zmienia oceny modelu.
                            </span>
                        </span>
                    </h3>
                    <div class="exec-badge <?= $epBadge[0] ?>"><?= $epBadge[1] ?></div>
                    <table class="exec-table">
                        <tr><td>Strefa kupna</td><td><strong><?= $usd($execPlan['zone_low']) ?> – <?= $usd($execPlan['zone_high']) ?></strong></td></tr>
                        <tr><td>Stop (swing)</td><td><?= $usd($execPlan['stop_swing']) ?></td></tr>
                        <tr><td>Stop (fundamentalny)</td><td><?= $usd($execPlan['stop_fund']) ?></td></tr>
                    </table>
                    <?php if (($execPlan['source'] ?? '') === 'fallback'): ?>
                    <p class="exec-note">Strefa zmiennościowa (brak wyraźnego wsparcia w oknie) — oparta na ATR wokół ceny.</p>
                    <?php endif; ?>
                    <?php $paGlobalOn = $alertsEnabled ?? false; $paOn = $priceAlertEnabled ?? false; ?>
                    <div class="exec-alert-row">
                        <button id="btn-price-alert" type="button"
                                class="btn btn--sm <?= $paOn ? 'btn--secondary' : 'btn--ghost' ?>"
                                data-ticker="<?= htmlspecialchars($ticker) ?>"
                                data-enabled="<?= $paOn ? '1' : '0' ?>"
                                <?= $paGlobalOn ? '' : 'disabled' ?>
                                title="<?= $paGlobalOn ? 'Powiadom mailem, gdy cena wejdzie w strefę kupna' : 'Najpierw włącz alerty globalnie (dzwonek na panelu)' ?>">
                            <?= $paOn ? '🔔 Alert ceny ON' : '🔕 Powiadom, gdy cena wejdzie w strefę' ?>
                        </button>
                        <?php if (!$paGlobalOn): ?><span class="exec-note">Włącz alerty globalnie (dzwonek na panelu), by uruchomić.</span><?php endif; ?>
                    </div>
                    <p class="exec-disclaimer">Poziomy orientacyjne z danych cenowych — nie są rekomendacją. Inwestuj świadomie.</p>
                </div>
                <?php endif; ?>

                <!-- Market metrics mini-card (P/E trailing vs forward, Beta, Short %) -->
                <?php
                $mPe       = $financials['pe_ratio']        ?? null;
                $mFwdPe    = $financials['forward_pe']      ?? null;
                $mBeta     = $financials['beta']            ?? null;
                $mShortPct = $financials['short_pct_float'] ?? null;
                $mShortRatio = $financials['short_ratio']   ?? null;
                $hasMarketMetrics = $mPe !== null || $mFwdPe !== null || $mBeta !== null || $mShortPct !== null;
                ?>
                <?php if ($hasMarketMetrics): ?>
                <div class="exec-plan" style="margin-top:.75rem;">
                    <h3>Wskaźniki rynkowe
                        <span class="chart-hint" tabindex="0">ⓘ
                            <span class="chart-hint__tooltip">
                                <strong>P/E trailing</strong> — cena do zysku z ostatnich 12 miesięcy.<br>
                                <strong>P/E forward</strong> — cena do prognozowanego zysku; niższy od trailing = rynek oczekuje wzrostu zysków.<br><br>
                                <strong>Beta</strong> — wrażliwość na ruchy rynku. Beta 2.0 = akcja porusza się 2× silniej niż indeks w obu kierunkach. Ważne przy planowaniu stopu.<br><br>
                                <strong>Short % float</strong> — odsetek akcji w wolnym obrocie obstawionych na spadek. Wysoki (>15%) może oznaczać potencjał short squeeze lub sygnał ostrzegawczy.
                            </span>
                        </span>
                    </h3>
                    <table class="exec-table">
                        <?php if ($mPe !== null || $mFwdPe !== null): ?>
                        <tr>
                            <td>P/E trailing / forward</td>
                            <td>
                                <strong><?= $mPe !== null ? number_format((float) $mPe, 1) : '—' ?></strong>
                                <?php if ($mFwdPe !== null): ?>
                                <span style="color:var(--c-muted);font-size:var(--text-sm)"> / <?= number_format((float) $mFwdPe, 1) ?> fwd</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($mBeta !== null): ?>
                        <tr>
                            <td>Beta</td>
                            <td><strong><?= number_format((float) $mBeta, 2) ?></strong></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($mShortPct !== null): ?>
                        <tr>
                            <td>Short % float<?= $mShortRatio !== null ? ' / days to cover' : '' ?></td>
                            <td>
                                <strong><?= number_format((float) $mShortPct * 100, 1) ?>%</strong>
                                <?php if ($mShortRatio !== null): ?>
                                <span style="color:var(--c-muted);font-size:var(--text-sm)"> / <?= number_format((float) $mShortRatio, 1) ?>d</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Pillar table with weights -->
                <h3>Składowe filary</h3>
                <table class="pillar-table">
                    <thead>
                        <tr>
                            <th>Filar</th>
                            <th>Wynik (0–100)</th>
                            <th>Waga Swing</th>
                            <th>Waga Fund</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Valuation reference badge (FR-005) — shows which benchmark was used
                        $valRef     = $result['valuation_reference'] ?? [];
                        $valSource  = $valRef['source'] ?? '';
                        $valBucket  = $valRef['bucket'] ?? '';
                        $valValue   = $valRef['value'] ?? null;
                        $valVariant = $valRef['variant'] ?? null;
                        $valBadge   = '';
                        // Company-value attributes (only meaningful when $valValue is a
                        // real number): openModal() in app.js overlays this as a dashed
                        // reference line on the peer-median history chart — variant A
                        // (ev_fcf) plots on the EV/FCF axis, variant B (ev_sales_adj) on
                        // the EV/Sales axis. Omitted entirely when null so the JS falls
                        // back to the plain sector/industry chart with no overlay.
                        $companyAttrs = '';
                        if (is_numeric($valValue) && in_array($valVariant, ['A', 'B'], true)) {
                            $companyAttrs = ' data-company-value="' . htmlspecialchars((string) $valValue) . '"'
                                . ' data-company-variant="' . htmlspecialchars((string) $valVariant) . '"'
                                . ' data-company-label="' . htmlspecialchars($ticker) . '"';
                        }
                        // Badge is clickable (.js-sector-chart, shared with admin/sectors.php —
                        // public/js/app.js) to open the peer-median history chart for whichever
                        // bucket the Valuation pillar actually benchmarked against. level must
                        // match $valSource: 'subsector' benchmarks against an industry bucket,
                        // 'sector_fallback'/'cold_start' against the sector itself.
                        if ($valSource === 'subsector' && $valBucket !== '') {
                            $valBadge = ' <span title="Benchmark: podsektor ' . htmlspecialchars($valBucket) . ' — kliknij, aby zobaczyć historię"'
                                . ' class="js-sector-chart" data-level="industry" data-bucket="' . htmlspecialchars($valBucket) . '"'
                                . $companyAttrs
                                . ' style="font-size:.7rem;background:rgba(64,144,224,.15);color:var(--c-primary);'
                                . 'border-radius:3px;padding:1px 5px;margin-left:.3rem;cursor:pointer;">'
                                . '⊂ ' . htmlspecialchars($valBucket) . '</span>';
                        } elseif (in_array($valSource, ['sector_fallback', 'cold_start'], true) && $valBucket !== '') {
                            $valBadge = ' <span title="Benchmark: sektor ' . htmlspecialchars($valBucket) . ' — kliknij, aby zobaczyć historię"'
                                . ' class="js-sector-chart" data-level="sector" data-bucket="' . htmlspecialchars($valBucket) . '"'
                                . $companyAttrs
                                . ' style="font-size:.7rem;background:rgba(255,255,255,.06);color:var(--c-muted);'
                                . 'border-radius:3px;padding:1px 5px;margin-left:.3rem;cursor:pointer;">'
                                . '≈ ' . htmlspecialchars($valBucket) . '</span>';
                        }
                        ?>
                        <?php
                        $pillarRows = [
                            // Label follows the variant the pillar actually used. It read
                            // "EV/FCF" for everything, which was already wrong for variant B
                            // (EV/Sales) and became plainly false for banks scored on P/B.
                            ['key' => 'valuation',      'label' => 'Wycena (' . match ($valVariant) {
                                'B'     => 'EV/Sprzedaż',
                                'C'     => 'P/B',
                                default => 'EV/FCF',
                            } . ')', 'sw' => '40%', 'fn' => '65%', 'badge' => $valBadge],
                            ['key' => 'momentum_swing', 'label' => 'Momentum (Swing)',     'sw' => '45%', 'fn' => '—',   'badge' => ''],
                            ['key' => 'momentum_fund',  'label' => 'Momentum (Fund)',      'sw' => '—',   'fn' => '15%', 'badge' => ''],
                            ['key' => 'quality',        'label' => 'Jakość fundamentalna', 'sw' => '15%', 'fn' => '20%', 'badge' => ''],
                        ];
                        foreach ($pillarRows as $row):
                            $score = $ps[$row['key']] ?? null;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($row['label']) ?><?= $row['badge'] ?></td>
                            <td>
                                <?php if ($score !== null): ?>
                                <div class="progress-bar">
                                    <div class="progress-bar__track">
                                        <div class="progress-bar__fill" style="transform:scaleX(<?= max(0.01, min(1, round((float)$score) / 100)) ?>)"></div>
                                    </div>
                                    <span style="font-size:var(--text-xs);color:var(--c-muted);min-width:2.5rem;"><?= number_format((float)$score, 1) ?></span>
                                </div>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($row['sw']) ?></td>
                            <td><?= htmlspecialchars($row['fn']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="1">CVS Swing</th>
                            <td colspan="3">
                                <strong id="cvs-score-swing"><?= number_format((float)($swing['cvs'] ?? 0), 1) ?></strong>
                                — <?= htmlspecialchars($swing['recommendation'] ?? '') ?>
                            </td>
                        </tr>
                        <tr>
                            <th colspan="1">CVS Fund</th>
                            <td colspan="3">
                                <strong id="cvs-score-fund"><?= number_format((float)($fund['cvs'] ?? 0), 1) ?></strong>
                                — <?= htmlspecialchars($fund['recommendation'] ?? '') ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>

                <!-- Raw financial data -->
                <?php if (!empty($financials)): ?>
                <details class="raw-data">
                    <summary>Dane źródłowe (surowe)</summary>
                    <table class="pillar-table raw-table">
                        <tbody>
                            <?php
                            $rawFields = [
                                'current_price'       => 'Cena bieżąca (USD)',
                                'shares_outstanding'  => 'Liczba akcji',
                                'revenue'             => 'Przychody ($)',
                                'ebitda'              => 'EBITDA ($)',
                                'free_cash_flow'      => 'FCF efektywny ($)',
                                'free_cash_flow_raw'  => 'FCF raportowany ($)',
                                'operating_cash_flow' => 'OpCF ($)',
                                'free_cash_flow_adjusted' => 'FCF adjusted (OpCF fallback)',
                                'total_debt'          => 'Dług całkowity ($)',
                                'cash'                => 'Gotówka ($)',
                                'gross_margins'          => 'Marża brutto',
                                'operating_margin'       => 'Marża operacyjna',
                                'profit_margin'          => 'Marża netto',
                                'revenue_growth'         => 'Wzrost przychodów',
                                'return_on_equity'       => 'ROE',
                                'pe_ratio'               => 'P/E trailing',
                                'forward_pe'             => 'P/E forward',
                                'peg_ratio'              => 'PEG ratio',
                                'ev_ebitda'              => 'EV/EBITDA',
                                'beta'                   => 'Beta',
                                'short_pct_float'        => 'Short % float',
                                'institutional_ownership' => 'Institutional ownership',
                                'forward_eps'            => 'EPS forward',
                                'trailing_eps'           => 'EPS trailing',
                                'sector'                 => 'Sektor',
                                // change: fundamentals-validation — fields the model uses but this
                                // table didn't render before (Yahoo gaps on otherwise well-covered
                                // companies; see context/changes/fundamentals-validation/research.md).
                                'gross_profit'           => 'Zysk brutto ($)',
                                'total_equity'           => 'Kapitał własny ($)',
                                'current_assets'         => 'Aktywa obrotowe ($)',
                                'current_liabilities'    => 'Zobowiązania krótkoterminowe ($)',
                                'ps_ratio'                => 'P/S',
                                'moving_average_200'     => 'Średnia 200-dniowa (USD)',
                            ];
                            // change: fundamentals-validation — NULL fields now render too (label +
                            // dash) so they can be colored; a field absent from $fieldStates renders
                            // with no color at all (e.g. trailing_pe null on negative EPS is a
                            // correct NULL, never flagged — see SuspectFieldDetector).
                            $fvStates = $fieldStates ?? [];
                            $fvTitles = [
                                'suspect'          => 'Oznaczone jako podejrzane — patrz przycisk walidacji poniżej',
                                'validated'        => 'Zwalidowane',
                                'checked_no_data'  => 'Sprawdzone — brak wiarygodnych danych',
                            ];
                            foreach ($rawFields as $key => $label):
                                $val   = $financials[$key] ?? null;
                                $state = $fvStates[$key] ?? null;
                                $rowClass = $state !== null ? ' class="fv-field fv-field--' . $state . '"' : '';
                                $rowTitle = $state !== null ? ' title="' . htmlspecialchars($fvTitles[$state]) . '"' : '';
                            ?>
                            <tr<?= $rowClass ?><?= $rowTitle ?> data-field="<?= htmlspecialchars($key) ?>">
                                <td><?= htmlspecialchars($label) ?></td>
                                <td>
                                    <?= $val === null ? '—' : $fmtRaw($key, $val) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php if (!empty($isAdmin)): ?>
                    <div class="fv-actions">
                        <button id="btn-fv-missing" class="btn btn--secondary btn--sm"
                                data-ticker="<?= htmlspecialchars($ticker) ?>" data-mode="missing"
                                <?= ($validationRun['status'] ?? null) === 'pending' ? 'disabled' : '' ?>>
                            Sprawdź dane brakujące
                        </button>
                        <button id="btn-fv-all" class="btn btn--ghost btn--sm"
                                data-ticker="<?= htmlspecialchars($ticker) ?>" data-mode="all"
                                <?= ($validationRun['status'] ?? null) === 'pending' ? 'disabled' : '' ?>>
                            Sprawdź wszystkie dane
                        </button>
                        <span id="fv-status" class="fv-status"></span>
                    </div>
                    <div id="fv-diff" class="fv-diff" hidden>
                        <table class="pillar-table raw-table" id="fv-diff-table">
                            <tbody></tbody>
                        </table>
                        <button id="btn-fv-confirm" class="btn btn--primary btn--sm">Zastosuj</button>
                    </div>
                    <?php endif; ?>
                </details>
                <?php endif; ?>
            </div>

            <?php
            // --- Analyst forecast (S-09) ---
            $forecast  = $financials['forecast'] ?? null;
            $fcTargets = $forecast['targets'] ?? [];
            $fcLatest  = $forecast['latest'] ?? null;
            $fcTrend   = $forecast['trend'] ?? [];
            $fcMean    = $fcTargets['mean']   ?? null;
            $fcMedian  = $fcTargets['median'] ?? null;
            $fcHigh    = $fcTargets['high']   ?? null;
            $fcLow     = $fcTargets['low']    ?? null;
            $fcUpside  = $fcTargets['upside'] ?? null;
            $fcNum     = $forecast['num_analysts']        ?? null;
            $fcRecMean = $forecast['recommendation_mean'] ?? null;
            $curPrice  = $financials['current_price'] ?? null;

            $hasTargets   = $fcMean !== null || $fcMedian !== null || $fcHigh !== null || $fcLow !== null;
            $hasConsensus = $fcLatest !== null || $fcRecMean !== null;
            $hasTrend     = !empty($fcTrend);
            $hasFan       = !empty($financials['monthly_closes'])
                && $fcHigh !== null && $fcMean !== null && $fcLow !== null && $curPrice !== null;
            $hasForecast  = $hasTargets || $hasConsensus || $hasTrend;

            $consensusLabel = ($fcRecMean !== null)
                ? \CVS\Forecast\ForecastParser::consensusLabel((float) $fcRecMean, $cfgFile['analyst_consensus'] ?? [])
                : null;

            $consensusRows = [
                'strong_buy'  => ['label' => 'Silne Kupuj',    'class' => 'sb'],
                'buy'         => ['label' => 'Kupuj',          'class' => 'b'],
                'hold'        => ['label' => 'Trzymaj',        'class' => 'h'],
                'sell'        => ['label' => 'Sprzedaj',       'class' => 's'],
                'strong_sell' => ['label' => 'Silna Sprzedaż', 'class' => 'ss'],
            ];
            $latestTotal = $fcLatest ? array_sum($fcLatest) : 0;
            ?>

            <?php if ($hasForecast): ?>
            <div class="card forecast-card">
                <h2>Prognoza analityków</h2>

                <?php if ($hasTargets): ?>
                <div class="forecast-block">
                    <div class="forecast-block__head">
                        <h3>Cele cenowe</h3>
                        <?php if ($fcUpside !== null): ?>
                            <span class="upside-badge <?= $fcUpside >= 0 ? 'upside-badge--pos' : 'upside-badge--neg' ?>">
                                <?= ($fcUpside >= 0 ? '+' : '') . number_format($fcUpside * 100, 1) ?>% vs cena
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="forecast-targets">
                        <?php
                        $targetTiles = [
                            'Min'     => $fcLow,
                            'Średnia' => $fcMean,
                            'Mediana' => $fcMedian,
                            'Max'     => $fcHigh,
                        ];
                        foreach ($targetTiles as $tLabel => $tVal):
                            if ($tVal === null) continue;
                        ?>
                        <div class="forecast-tile">
                            <div class="forecast-tile__label"><?= htmlspecialchars($tLabel) ?></div>
                            <div class="forecast-tile__value">$<?= number_format((float) $tVal, 2) ?></div>
                        </div>
                        <?php endforeach; ?>
                        <?php if ($cvsFairPrice !== null): ?>
                        <div class="forecast-tile forecast-tile--cvs">
                            <div class="forecast-tile__label">CVS Fair Value</div>
                            <div class="forecast-tile__value" style="color:var(--c-fund);">
                                <?= htmlspecialchars($fmtDual($cvsFairPrice)) ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($fcNum !== null): ?>
                        <p class="forecast-note">Na podstawie <?= (int) $fcNum ?> ocen analityków<?php if ($curPrice !== null): ?> · cena bieżąca <?= htmlspecialchars($fmtDual((float) $curPrice, isset($financials['native_price']) ? (float) $financials['native_price'] : null)) ?><?php endif; ?>.</p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasConsensus): ?>
                <div class="forecast-block">
                    <div class="forecast-block__head">
                        <h3>Konsensus rekomendacji</h3>
                        <?php if ($consensusLabel !== null): ?>
                            <span class="consensus-label">
                                <?= htmlspecialchars($consensusLabel) ?>
                                <?php if ($fcRecMean !== null): ?>
                                    <small>(śr. <?= number_format((float) $fcRecMean, 2) ?>)</small>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <?php if ($fcLatest !== null): ?>
                    <ul class="consensus-bars">
                        <?php foreach ($consensusRows as $key => $meta):
                            $count = (int) ($fcLatest[$key] ?? 0);
                            $pct   = $latestTotal > 0 ? round($count / $latestTotal * 100) : 0;
                        ?>
                        <li class="consensus-bar">
                            <span class="consensus-bar__label"><?= htmlspecialchars($meta['label']) ?></span>
                            <span class="consensus-bar__track">
                                <span class="consensus-bar__fill consensus-bar__fill--<?= $meta['class'] ?>" style="width:<?= $pct ?>%"></span>
                            </span>
                            <span class="consensus-bar__count"><?= $count ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasTrend): ?>
                <div class="forecast-block">
                    <h3>Zmiana rekomendacji w czasie</h3>
                    <div class="forecast-chart-wrap">
                        <canvas id="reco-trend-chart"></canvas>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($hasFan): ?>
                <div class="forecast-block">
                    <h3>Prognoza ceny (min / średnia / max)</h3>
                    <div class="forecast-chart-wrap">
                        <canvas id="forecast-fan-chart"></canvas>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ===================================================== -->
            <!-- S-01: AI Divergence Analysis section                  -->
            <!-- ===================================================== -->
            <div class="card ai-analysis-card" id="ai-section">
                <div class="ai-analysis-card__header">
                    <h2>Analiza AI — rozjazd CVS vs analitycy</h2>
                    <div class="ai-analysis-card__actions">
                        <?php if (!empty($cachedAi)): ?>
                            <span class="ai-analysis-card__date">
                                Analiza z <?= htmlspecialchars(substr((string) $cachedAi['generated_at'], 0, 10)) ?>
                                <?php if (!empty($cachedAi['stale'])): ?>
                                    <span style="color:var(--c-warn);font-weight:600;" title="Starsza niż tydzień">· może być nieaktualna</span>
                                <?php endif; ?>
                            </span>
                            <button id="btn-share-prompt" class="btn btn--ghost btn--sm"
                                    data-ticker="<?= htmlspecialchars($ticker) ?>"
                                    title="Eksportuj prompt do własnego modelu AI">
                                ↗ Share for your LLM
                            </button>
                        <?php endif; ?>
                        <?php if (!empty($canGenerateAi) && empty($cachedAi)): ?>
                            <button id="btn-generate-ai" class="btn btn--primary btn--sm"
                                    data-ticker="<?= htmlspecialchars($ticker) ?>">
                                Generuj analizę AI
                            </button>
                        <?php elseif (empty($canGenerateAi) && empty($cachedAi)): ?>
                            <button id="btn-enter-pro" class="btn btn--secondary btn--sm">
                                Wprowadź kod PRO
                            </button>
                        <?php endif; ?>
                        <?php if (!empty($aiCanRefresh)): ?>
                            <button id="btn-refresh-ai" class="btn btn--ghost btn--sm"
                                    data-ticker="<?= htmlspecialchars($ticker) ?>"
                                    data-force="1">
                                Odśwież analizę
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($cachedAi)): ?>
                <div class="ai-narrative" id="ai-result">
                    <?php
                    // Render cached AI narrative: convert section headers and paragraphs.
                    $raw  = htmlspecialchars((string) $cachedAi['content']);
                    // "## N. Title" → <h3>
                    $html = preg_replace('/^## (\d+\. .+)$/m', '<h3 class="ai-narrative__section">$1</h3>', $raw);
                    // "N. Title" at start of line (without ##) → <h3>
                    $html = preg_replace('/^(\d+\. [^\n]{5,60})$/m', '<h3 class="ai-narrative__section">$1</h3>', $html ?? $raw);
                    // Blank lines → paragraph breaks
                    $html = preg_replace('/\n{2,}/', '</p><p>', $html ?? $raw);
                    $html = str_replace("\n", '<br>', $html ?? $raw);
                    echo '<p>' . $html . '</p>';
                    ?>
                </div>
                <?php elseif (empty($canGenerateAi)): ?>
                <p style="color:var(--c-muted);font-size:var(--text-sm);">
                    Brak analizy AI dla tej spółki.
                    <?php if (empty($_SESSION['pro_code'] ?? null)): ?>
                        Aktywuj kod PRO aby generować analizy.
                    <?php else: ?>
                        Dzienny lub miesięczny limit analiz został osiągnięty.
                    <?php endif; ?>
                </p>
                <?php else: ?>
                <p style="color:var(--c-muted);font-size:var(--text-sm);" id="ai-placeholder">
                    Kliknij „Generuj analizę AI" aby otrzymać wyjaśnienie rozjazdu
                    między modelem CVS a oceną analityków Wall Street.
                </p>
                <div id="ai-result" hidden></div>
                <?php endif; ?>

                <p class="disclaimer-inline" style="margin-top:.75rem;">
                    Analiza AI to hipoteza modelu — nie rekomendacja inwestycyjna.
                    Uziemiona w danych CVS i prognozach analityków z Yahoo Finance.
                </p>
            </div>

            <!-- ===================================================== -->
            <!-- Stage-2: Recenzja krytyczna (change: cvs-ai-critical-review) -->
            <!-- ===================================================== -->
            <?php if (!empty($cachedAi)): ?>
            <?php
                $crStatus = $criticalReviewStatus ?? 'none';
                $crCached = $cachedCriticalReview ?? null;
            ?>
            <div class="card ai-analysis-card" id="critical-review-section">
                <div class="ai-analysis-card__header">
                    <h2>Recenzja krytyczna AI
                        <span class="chart-hint" tabindex="0">ⓘ
                            <span class="chart-hint__tooltip">
                                <strong>Czym różni się od analizy powyżej?</strong><br>
                                Recenzja krytyczna szuka świeżych newsów (web search, ~14 dni) i
                                konfrontuje je z analizą etapu 1 — pokazuje co model mógł przeoczyć
                                i gdzie analiza powyżej bywa zbyt optymistyczna lub ostrożna.
                                Trwa dłużej (~2-4 min), generuje się w tle.
                            </span>
                        </span>
                    </h2>
                    <div class="ai-analysis-card__actions">
                        <?php if ($crCached): ?>
                            <span class="ai-analysis-card__date" id="critical-review-date">
                                Recenzja z <?= htmlspecialchars(substr((string) $crCached['generated_at'], 0, 10)) ?>
                                <?php if (!empty($crCached['stale'])): ?>
                                    <span style="color:var(--c-warn);font-weight:600;" title="Starsza niż 48h">· może być nieaktualna</span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($canGenerateAi) && $crStatus !== 'pending'): ?>
                            <button id="btn-critical-review" class="btn btn--primary btn--sm"
                                    data-ticker="<?= htmlspecialchars($ticker) ?>">
                                <?= $crCached ? 'Odśwież recenzję' : 'Recenzja krytyczna' ?>
                            </button>
                        <?php elseif (empty($canGenerateAi) && !$crCached): ?>
                            <button id="btn-enter-pro-cr" class="btn btn--secondary btn--sm">
                                Wprowadź kod PRO
                            </button>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="critical-review-result" <?= $crCached ? '' : 'hidden' ?>>
                    <?php if ($crCached): ?>
                    <div class="ai-narrative">
                        <?php
                        $raw  = htmlspecialchars($crCached['content']);
                        $html = preg_replace('/^## (\d+\. .+)$/m', '<h3 class="ai-narrative__section">$1</h3>', $raw);
                        $html = preg_replace('/\n{2,}/', '</p><p>', $html ?? $raw);
                        $html = str_replace("\n", '<br>', $html ?? $raw);
                        echo '<p>' . $html . '</p>';
                        ?>
                    </div>
                    <?php if (!empty($crCached['sources'])): ?>
                    <div id="critical-review-sources" style="margin-top:.75rem;font-size:var(--text-sm);">
                        <strong>Źródła:</strong>
                        <ul style="margin:.35rem 0 0 1.1rem;">
                            <?php foreach ($crCached['sources'] as $src): ?>
                                <li><a href="<?= htmlspecialchars((string) ($src['url'] ?? '')) ?>" target="_blank" rel="noopener noreferrer">
                                    <?= htmlspecialchars((string) ($src['title'] ?? $src['url'] ?? '')) ?>
                                </a></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <?php else: ?>
                    <div id="critical-review-sources" style="margin-top:.75rem;font-size:var(--text-sm);"></div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ($crStatus === 'pending'): ?>
                <p style="color:var(--c-muted);font-size:var(--text-sm);" id="critical-review-placeholder">
                    Recenzja jest generowana w tle — strona sama się zaktualizuje (~2-4 min).
                </p>
                <?php elseif ($crStatus === 'failed' && !$crCached): ?>
                <p style="color:var(--c-danger);font-size:var(--text-sm);" id="critical-review-placeholder">
                    Poprzednia próba się nie powiodła. Spróbuj ponownie.
                </p>
                <?php elseif (!$crCached): ?>
                <p style="color:var(--c-muted);font-size:var(--text-sm);" id="critical-review-placeholder">
                    Kliknij „Recenzja krytyczna", aby sprawdzić świeże katalizatory i skonfrontować
                    je z analizą powyżej.
                </p>
                <?php endif; ?>

                <?php if (!$crCached): ?>
                <!-- Cached review content already ends with this exact disclaimer
                     (mandated by the AiCriticalReviewService system prompt) — only
                     show the static fallback when there's no AI text to carry it.
                     Hidden client-side too once a fresh poll completes (see
                     crRenderCompleted() below), so it's never shown alongside the
                     AI's own disclaimer within the same page session. -->
                <p class="disclaimer-inline" id="critical-review-disclaimer-fallback" style="margin-top:.75rem;">
                    Recenzja krytyczna to hipoteza modelu analitycznego, nie rekomendacja
                    inwestycyjna. Inwestuj świadomie.
                </p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- PRO code activation modal -->
            <div id="pro-modal" class="ai-modal" hidden>
                <div class="ai-modal__inner" style="max-width:360px;">
                    <h3 style="margin-bottom:1rem;font-size:var(--text-base);">Wprowadź kod PRO</h3>
                    <p style="color:var(--c-muted);font-size:var(--text-sm);margin-bottom:1rem;">
                        Kod PRO wydaje admin. Wpisz go raz — zostanie zapamiętany w tej sesji.
                    </p>
                    <div class="form-group" style="margin-bottom:1rem;">
                        <input id="pro-code-input" type="text" placeholder="np. CVS-BETA-2026"
                               style="font-family:monospace;text-transform:uppercase;" autocomplete="off">
                    </div>
                    <div id="pro-modal-error" class="alert alert--error" style="display:none;margin-bottom:.75rem;"></div>
                    <div style="display:flex;gap:.5rem;justify-content:center;">
                        <button id="btn-pro-submit" class="btn btn--primary btn--sm">Aktywuj</button>
                        <button id="btn-pro-cancel" class="btn btn--ghost btn--sm">Anuluj</button>
                    </div>

                    <!-- PRO request form -->
                    <hr style="border:none;border-top:1px solid var(--c-border);margin:1.25rem 0;">
                    <p style="font-size:var(--text-sm);color:var(--c-muted);margin-bottom:.75rem;">
                        Nie masz kodu? Wyślij prośbę do admina.
                    </p>
                    <?php if (!empty($_SESSION['pro_request_sent'])): ?>
                    <p style="color:var(--c-success);font-size:var(--text-sm);text-align:center;">
                        ✓ Prośba wysłana — admin skontaktuje się wkrótce.
                    </p>
                    <?php else: ?>
                    <form method="POST" action="/pro/request">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <div class="form-group" style="margin-bottom:.5rem;">
                            <input type="text" name="name" placeholder="Twoje imię (opcjonalne)" maxlength="100">
                        </div>
                        <div class="form-group" style="margin-bottom:.75rem;">
                            <textarea name="message" rows="2" placeholder="Dopisz tutaj swoją prośbę lub uwagi (opcjonalne)" maxlength="500"></textarea>
                        </div>
                        <button type="submit" class="btn btn--secondary btn--sm" style="width:100%;">
                            Wyślij prośbę do admina
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- AI generation modal -->
            <div id="ai-modal" class="ai-modal" hidden>
                <div class="ai-modal__inner">
                    <div class="ai-modal__spinner"></div>
                    <p id="ai-modal-status" class="ai-modal__status">Przygotowuję dane…</p>
                    <button id="btn-ai-cancel" class="btn btn--ghost btn--sm" style="margin-top:1rem;">
                        Anuluj
                    </button>
                </div>
            </div>

            <!-- Share-it-for-your-LLM export modal -->
            <div id="share-modal" class="ai-modal" hidden>
                <div class="ai-modal__inner" style="max-width:660px;width:95vw;">
                    <h3 style="margin-bottom:.75rem;font-size:var(--text-base);">
                        Prompt do własnego modelu AI
                    </h3>
                    <p style="color:var(--c-muted);font-size:var(--text-sm);margin-bottom:1rem;">
                        Skopiuj gotowy prompt i wklej do ChatGPT, Gemini, Claude lub innego modelu.
                        Możesz go edytować przed skopiowaniem.
                    </p>
                    <div style="display:flex;gap:.5rem;margin-bottom:.75rem;">
                        <button id="btn-lang-pl" class="btn btn--primary btn--sm" data-lang="pl">PL</button>
                        <button id="btn-lang-en" class="btn btn--ghost btn--sm" data-lang="en">EN</button>
                    </div>
                    <div id="share-spinner" style="text-align:center;padding:1rem;display:none;">
                        <div class="ai-modal__spinner" style="display:inline-block;"></div>
                    </div>
                    <textarea id="share-prompt-text" rows="14"
                              style="width:100%;box-sizing:border-box;font-family:monospace;font-size:var(--text-sm);resize:vertical;border:1px solid var(--c-border);border-radius:4px;padding:.5rem;background:var(--c-bg-secondary,var(--c-bg));color:var(--c-text);"
                              placeholder="Ładowanie promptu…" readonly></textarea>
                    <div style="display:flex;gap:.5rem;margin-top:.75rem;justify-content:flex-end;">
                        <button id="btn-copy-prompt" class="btn btn--primary btn--sm">Kopiuj do schowka</button>
                        <button id="btn-share-close" class="btn btn--ghost btn--sm">Zamknij</button>
                    </div>
                    <p id="share-copy-feedback"
                       style="display:none;color:var(--c-success,#22c55e);font-size:var(--text-sm);margin-top:.5rem;text-align:right;">
                        ✓ Skopiowano do schowka!
                    </p>
                </div>
            </div>

            <script>
            (function () {
                'use strict';

                var stages   = [
                    'Przygotowuję dane…',
                    'Analizuję CVS vs analitycy…',
                    'Piszę raport…',
                    'Kończę analizę…'
                ];
                var stageIdx  = 0;
                var stageTimer = null;
                var modal     = document.getElementById('ai-modal');
                var statusEl  = document.getElementById('ai-modal-status');
                var resultEl  = document.getElementById('ai-result');
                var placeholder = document.getElementById('ai-placeholder');
                var csrf      = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

                function showModal() {
                    stageIdx = 0;
                    if (statusEl) statusEl.textContent = stages[0];
                    modal.hidden = false;
                    stageTimer = setInterval(function () {
                        stageIdx = (stageIdx + 1) % stages.length;
                        if (statusEl) statusEl.textContent = stages[stageIdx];
                    }, 7000);
                }

                function hideModal() {
                    clearInterval(stageTimer);
                    modal.hidden = true;
                }

                function renderAnalysis(content, generatedAt) {
                    if (!resultEl) return;
                    // Convert plain newlines to line breaks (server sends plain text)
                    var html = content
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/## (\d+\. .+)/g, '<h3 class="ai-narrative__section">$1</h3>')
                        .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                        .replace(/\n\n/g, '</p><p>')
                        .replace(/\n/g, '<br>');
                    // "Share for your LLM" and the whole Recenzja krytyczna card
                    // are only server-rendered when a cached analysis already
                    // existed at page load — on a ticker's first-ever generation
                    // neither is in the DOM at all, and no amount of DOM
                    // patching here reveals them. Reload once to pick up the
                    // now-populated server state (same "state changed server-
                    // side → reload" pattern already used after PRO activation
                    // below) instead of hand-duplicating that markup in JS.
                    if (!document.getElementById('btn-share-prompt') || !document.getElementById('critical-review-section')) {
                        window.location.reload();
                        return;
                    }

                    resultEl.innerHTML = '<p>' + html + '</p>';
                    resultEl.hidden = false;
                    if (placeholder) placeholder.hidden = true;

                    // Refresh of an existing analysis: update the date in place —
                    // this also clears any "może być nieaktualna" staleness badge
                    // (nested inside the same span), since a fresh regeneration
                    // is never stale.
                    var dateEl = document.querySelector('.ai-analysis-card__date');
                    if (dateEl && generatedAt) {
                        dateEl.textContent = 'Analiza z ' + generatedAt.substring(0, 10);
                    }
                    var btnGen = document.getElementById('btn-generate-ai');
                    if (btnGen) btnGen.hidden = true;
                }

                function doGenerate(ticker, force) {
                    showModal();
                    fetch('/analysis/' + encodeURIComponent(ticker) + '/generate-ai', {
                        method: 'POST',
                        headers: {
                            'Content-Type':     'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token':     csrf,
                        },
                        body: new URLSearchParams({ _csrf: csrf, force: force ? '1' : '0' }),
                    })
                    .then(function (resp) { return resp.json(); })
                    .then(function (data) {
                        hideModal();
                        if (data.ok) {
                            renderAnalysis(data.content, data.generated_at);
                        } else {
                            var errEl = document.createElement('div');
                            errEl.className = 'alert alert--error';
                            errEl.style.marginTop = '1rem';
                            errEl.textContent = data.message ?? 'Analiza AI niedostępna — spróbuj ponownie za chwilę.';
                            if (resultEl) resultEl.parentNode.insertBefore(errEl, resultEl);
                        }
                    })
                    .catch(function () {
                        hideModal();
                        var errEl = document.createElement('div');
                        errEl.className = 'alert alert--error';
                        errEl.style.marginTop = '1rem';
                        errEl.textContent = 'Błąd sieci. Sprawdź połączenie i spróbuj ponownie.';
                        if (resultEl) resultEl.parentNode.insertBefore(errEl, resultEl);
                    });
                }

                // PRO code activation modal
                var proModal   = document.getElementById('pro-modal');
                var proInput   = document.getElementById('pro-code-input');
                var proErrEl   = document.getElementById('pro-modal-error');
                var btnEnterPro = document.getElementById('btn-enter-pro');

                if (btnEnterPro) {
                    btnEnterPro.addEventListener('click', function () {
                        if (proErrEl) { proErrEl.style.display = 'none'; proErrEl.textContent = ''; }
                        if (proInput) proInput.value = '';
                        proModal.hidden = false;
                        setTimeout(function () { if (proInput) proInput.focus(); }, 50);
                    });
                }

                document.getElementById('btn-pro-cancel')?.addEventListener('click', function () {
                    proModal.hidden = true;
                });

                document.getElementById('btn-pro-submit')?.addEventListener('click', function () {
                    var code = (proInput?.value ?? '').trim();
                    if (!code) return;

                    fetch('/pro/activate', {
                        method: 'POST',
                        headers: {
                            'Content-Type':     'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token':     csrf,
                        },
                        body: new URLSearchParams({ _csrf: csrf, code: code }),
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            proModal.hidden = true;
                            // Reload page so $canGenerateAi = true
                            window.location.reload();
                        } else {
                            if (proErrEl) {
                                proErrEl.textContent = data.message ?? 'Nieprawidłowy kod PRO.';
                                proErrEl.style.display = 'block';
                            }
                        }
                    })
                    .catch(function () {
                        if (proErrEl) {
                            proErrEl.textContent = 'Błąd sieci. Spróbuj ponownie.';
                            proErrEl.style.display = 'block';
                        }
                    });
                });

                // Allow Enter key in code input
                proInput?.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') document.getElementById('btn-pro-submit')?.click();
                });

                var btnGen = document.getElementById('btn-generate-ai');
                if (btnGen) {
                    btnGen.addEventListener('click', function () {
                        doGenerate(btnGen.dataset.ticker, false);
                    });
                }

                var btnRef = document.getElementById('btn-refresh-ai');
                if (btnRef) {
                    btnRef.addEventListener('click', function () {
                        doGenerate(btnRef.dataset.ticker, true);
                    });
                }

                var btnCancel = document.getElementById('btn-ai-cancel');
                if (btnCancel) {
                    btnCancel.addEventListener('click', hideModal);
                }

                // ── Share-it-for-your-LLM ────────────────────────────────────────
                var shareModal    = document.getElementById('share-modal');
                var shareTextEl   = document.getElementById('share-prompt-text');
                var shareSpinnerEl = document.getElementById('share-spinner');
                var shareFeedback = document.getElementById('share-copy-feedback');
                var currentLang   = 'pl';

                function setLangButtons(lang) {
                    var pl = document.getElementById('btn-lang-pl');
                    var en = document.getElementById('btn-lang-en');
                    if (!pl || !en) return;
                    pl.className = lang === 'pl' ? 'btn btn--primary btn--sm' : 'btn btn--ghost btn--sm';
                    en.className = lang === 'en' ? 'btn btn--primary btn--sm' : 'btn btn--ghost btn--sm';
                }

                function fetchSharePrompt(lang) {
                    currentLang = lang;
                    setLangButtons(lang);
                    if (shareTextEl)    { shareTextEl.value = ''; shareTextEl.style.display = 'none'; shareTextEl.setAttribute('readonly', ''); }
                    if (shareSpinnerEl) { shareSpinnerEl.style.display = 'block'; }
                    if (shareFeedback)  { shareFeedback.style.display = 'none'; }

                    var ticker = document.getElementById('btn-share-prompt')?.dataset.ticker ?? '';
                    fetch('/analysis/' + encodeURIComponent(ticker) + '/share-prompt', {
                        method: 'POST',
                        headers: {
                            'Content-Type':     'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token':     csrf,
                        },
                        body: new URLSearchParams({ _csrf: csrf, lang: lang }),
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (shareSpinnerEl) shareSpinnerEl.style.display = 'none';
                        if (shareTextEl) {
                            shareTextEl.style.display = '';
                            if (data.ok) {
                                shareTextEl.value = data.prompt;
                                shareTextEl.removeAttribute('readonly');
                            } else {
                                shareTextEl.value = '⚠️ ' + (data.message ?? 'Błąd pobierania promptu.');
                            }
                        }
                    })
                    .catch(function () {
                        if (shareSpinnerEl) shareSpinnerEl.style.display = 'none';
                        if (shareTextEl) {
                            shareTextEl.style.display = '';
                            shareTextEl.value = '⚠️ Błąd sieci. Sprawdź połączenie i spróbuj ponownie.';
                        }
                    });
                }

                document.getElementById('btn-share-prompt')?.addEventListener('click', function () {
                    shareModal.hidden = false;
                    fetchSharePrompt(currentLang);
                });

                document.getElementById('btn-lang-pl')?.addEventListener('click', function () { fetchSharePrompt('pl'); });
                document.getElementById('btn-lang-en')?.addEventListener('click', function () { fetchSharePrompt('en'); });

                document.getElementById('btn-share-close')?.addEventListener('click', function () {
                    shareModal.hidden = true;
                });

                document.getElementById('btn-copy-prompt')?.addEventListener('click', function () {
                    var text = shareTextEl?.value ?? '';
                    if (!text || text.startsWith('⚠️')) return;
                    function showFeedback() {
                        if (shareFeedback) { shareFeedback.style.display = 'block'; setTimeout(function () { shareFeedback.style.display = 'none'; }, 2500); }
                    }
                    if (navigator.clipboard && window.isSecureContext) {
                        navigator.clipboard.writeText(text).then(showFeedback).catch(function () {
                            shareTextEl.select();
                            document.execCommand('copy');
                            showFeedback();
                        });
                    } else {
                        shareTextEl.select();
                        document.execCommand('copy');
                        showFeedback();
                    }
                });

                // Per-ticker alert toggle
                document.getElementById('btn-alert-ticker')?.addEventListener('click', function () {
                    var btn  = this;
                    if (btn.disabled) return;
                    fetch('/alerts/ticker', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf},
                        body: new URLSearchParams({_csrf: csrf, ticker: btn.dataset.ticker}),
                    }).then(function (r) { return r.json(); }).then(function (d) {
                        if (!d.ok) return;
                        btn.dataset.disabled = d.disabled ? '1' : '0';
                        btn.textContent  = d.disabled ? '🔕 Alerty OFF' : '🔔 Alerty ON';
                        btn.className    = 'btn btn--sm ' + (d.disabled ? 'btn--ghost' : 'btn--secondary');
                        btn.title        = d.disabled ? 'Włącz alerty dla tej spółki' : 'Wycisz alerty dla tej spółki';
                    });
                });

                // Phase 8 slice 3 — "price entered zone" alert toggle.
                document.getElementById('btn-price-alert')?.addEventListener('click', function () {
                    var btn = this;
                    if (btn.disabled) return;
                    fetch('/alerts/price', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf},
                        body: new URLSearchParams({_csrf: csrf, ticker: btn.dataset.ticker}),
                    }).then(function (r) { return r.json(); }).then(function (d) {
                        if (!d.ok) return;
                        btn.dataset.enabled = d.enabled ? '1' : '0';
                        btn.textContent = d.enabled ? '🔔 Alert ceny ON' : '🔕 Powiadom, gdy cena wejdzie w strefę';
                        btn.className   = 'btn btn--sm ' + (d.enabled ? 'btn--secondary' : 'btn--ghost');
                    });
                });

                // ── Recenzja krytyczna (stage 2) — change: cvs-ai-critical-review ──
                var crTicker         = <?= json_encode($ticker) ?>;
                var crInitialStatus  = <?= json_encode($criticalReviewStatus ?? 'none') ?>;
                var crCanGenerate    = <?= json_encode(!empty($canGenerateAi)) ?>;
                var crResultEl       = document.getElementById('critical-review-result');
                var crSourcesEl      = document.getElementById('critical-review-sources');
                var crPlaceholderEl  = document.getElementById('critical-review-placeholder');
                var crBtn            = document.getElementById('btn-critical-review');
                var crPollTimer      = null;
                var crStageTimer     = null;
                var crPollStart      = null;
                var crStageIdx       = 0;
                var crStages = ['Przeszukuję newsy…', 'Konfrontuję z modelem…', 'Piszę recenzję…', 'Prawie gotowe…'];
                var CR_MAX_WAIT_MS      = 5 * 60 * 1000;
                var CR_POLL_INTERVAL_MS = 5000;

                function crHandleClick() {
                    if (crBtn) crBtn.disabled = true;
                    fetch('/analysis/' + encodeURIComponent(crTicker) + '/critical-review', {
                        method: 'POST',
                        headers: {
                            'Content-Type':     'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token':     csrf,
                        },
                        body: new URLSearchParams({ _csrf: csrf }),
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            crStartPolling();
                        } else {
                            if (crBtn) crBtn.disabled = false;
                            crShowMessage(data.message || 'Nie udało się uruchomić generowania.', true);
                        }
                    })
                    .catch(function () {
                        if (crBtn) crBtn.disabled = false;
                        crShowMessage('Błąd sieci. Sprawdź połączenie i spróbuj ponownie.', true);
                    });
                }

                function crShowMessage(text, isError) {
                    if (!crPlaceholderEl) {
                        var container = document.getElementById('critical-review-result');
                        if (!container || !container.parentNode) return;
                        crPlaceholderEl = document.createElement('p');
                        crPlaceholderEl.id = 'critical-review-placeholder';
                        crPlaceholderEl.style.fontSize = 'var(--text-sm)';
                        container.parentNode.insertBefore(crPlaceholderEl, container.nextSibling);
                    }
                    crPlaceholderEl.hidden = false;
                    crPlaceholderEl.style.color = isError ? 'var(--c-danger)' : 'var(--c-muted)';
                    crPlaceholderEl.textContent = text;
                }

                function crRenderCompleted(content, sources, generatedAt) {
                    if (crResultEl) {
                        var html = String(content)
                            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                            .replace(/## (\d+\. .+)/g, '<h3 class="ai-narrative__section">$1</h3>')
                            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                            .replace(/\n\n/g, '</p><p>')
                            .replace(/\n/g, '<br>');
                        crResultEl.innerHTML = '<div class="ai-narrative"><p>' + html + '</p></div>';
                        crResultEl.hidden = false;
                    }
                    if (crSourcesEl && Array.isArray(sources) && sources.length) {
                        var lis = sources.map(function (s) {
                            var url   = String(s.url || '').replace(/"/g, '&quot;');
                            var title = String(s.title || s.url || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                            return '<li><a href="' + url + '" target="_blank" rel="noopener noreferrer">' + title + '</a></li>';
                        }).join('');
                        crSourcesEl.innerHTML = '<strong>Źródła:</strong><ul style="margin:.35rem 0 0 1.1rem;">' + lis + '</ul>';
                    }
                    if (crPlaceholderEl) crPlaceholderEl.hidden = true;
                    var crFallbackDisclaimer = document.getElementById('critical-review-disclaimer-fallback');
                    if (crFallbackDisclaimer) crFallbackDisclaimer.hidden = true;

                    if (generatedAt) {
                        var dateText = 'Recenzja z ' + String(generatedAt).substring(0, 10);
                        var dateEl = document.getElementById('critical-review-date');
                        if (dateEl) {
                            dateEl.textContent = dateText;
                        } else {
                            var hdr = document.querySelector('#critical-review-section .ai-analysis-card__actions');
                            if (hdr) {
                                var span = document.createElement('span');
                                span.className = 'ai-analysis-card__date';
                                span.id = 'critical-review-date';
                                span.textContent = dateText;
                                hdr.prepend(span);
                            }
                        }
                    }

                    if (crBtn) {
                        crBtn.textContent = 'Odśwież recenzję';
                        crBtn.disabled = false;
                        crBtn.hidden = false;
                    } else if (crCanGenerate) {
                        var actions = document.querySelector('#critical-review-section .ai-analysis-card__actions');
                        if (actions) {
                            crBtn = document.createElement('button');
                            crBtn.id = 'btn-critical-review';
                            crBtn.className = 'btn btn--primary btn--sm';
                            crBtn.dataset.ticker = crTicker;
                            crBtn.textContent = 'Odśwież recenzję';
                            crBtn.addEventListener('click', crHandleClick);
                            actions.appendChild(crBtn);
                        }
                    }
                }

                function crStopPolling() {
                    if (crPollTimer) { clearInterval(crPollTimer); crPollTimer = null; }
                    if (crStageTimer) { clearInterval(crStageTimer); crStageTimer = null; }
                    if (crBtn) crBtn.disabled = false;
                }

                function crPoll() {
                    if (Date.now() - crPollStart > CR_MAX_WAIT_MS) {
                        crStopPolling();
                        crShowMessage('Generowanie trwa dłużej niż zwykle — sprawdź ponownie za chwilę.', false);
                        return;
                    }
                    fetch('/analysis/' + encodeURIComponent(crTicker) + '/critical-review/status', {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.ok) return;
                        if (data.status === 'completed') {
                            crStopPolling();
                            crRenderCompleted(data.content, data.sources, data.generated_at);
                        } else if (data.status === 'failed') {
                            crStopPolling();
                            crShowMessage(data.error_message || 'Recenzja się nie powiodła. Spróbuj ponownie.', true);
                        }
                        // 'pending' → keep polling silently.
                    })
                    .catch(function () { /* transient network hiccup — keep polling */ });
                }

                function crStartPolling() {
                    crPollStart = Date.now();
                    if (crBtn) crBtn.disabled = true;
                    crStageIdx = 0;
                    crShowMessage(crStages[0], false);
                    crStageTimer = setInterval(function () {
                        crStageIdx = (crStageIdx + 1) % crStages.length;
                        crShowMessage(crStages[crStageIdx], false);
                    }, 8000);
                    crPollTimer = setInterval(crPoll, CR_POLL_INTERVAL_MS);
                    crPoll();
                }

                if (crBtn) {
                    crBtn.addEventListener('click', crHandleClick);
                }

                document.getElementById('btn-enter-pro-cr')?.addEventListener('click', function () {
                    if (proErrEl) { proErrEl.style.display = 'none'; proErrEl.textContent = ''; }
                    if (proInput) proInput.value = '';
                    proModal.hidden = false;
                    setTimeout(function () { if (proInput) proInput.focus(); }, 50);
                });

                // Tab was closed/reopened while a background job was still running —
                // resume polling instead of leaving the page in a dead "pending" state.
                if (crInitialStatus === 'pending') {
                    crStartPolling();
                }

                // ── Walidacja danych fundamentalnych — change: fundamentals-validation ──
                var fvTicker        = <?= json_encode($ticker) ?>;
                var fvInitialStatus = <?= json_encode(!empty($isAdmin) ? ($validationRun['status'] ?? 'none') : 'none') ?>;
                var fvBtnMissing    = document.getElementById('btn-fv-missing');
                var fvBtnAll        = document.getElementById('btn-fv-all');
                var fvStatusEl      = document.getElementById('fv-status');
                var fvDiffEl        = document.getElementById('fv-diff');
                var fvDiffTableBody = document.querySelector('#fv-diff-table tbody');
                var fvConfirmBtn    = document.getElementById('btn-fv-confirm');
                var fvPollTimer     = null;
                var fvPollStart     = null;
                var fvPendingDiff   = null;
                var FV_MAX_WAIT_MS      = 5 * 60 * 1000;
                var FV_POLL_INTERVAL_MS = 5000;

                function fvSetButtonsDisabled(disabled) {
                    if (fvBtnMissing) fvBtnMissing.disabled = disabled;
                    if (fvBtnAll) fvBtnAll.disabled = disabled;
                }

                function fvHandleClick(mode) {
                    fvSetButtonsDisabled(true);
                    if (fvStatusEl) fvStatusEl.textContent = 'Uruchamiam walidację…';
                    fetch('/analysis/' + encodeURIComponent(fvTicker) + '/validate-fundamentals', {
                        method: 'POST',
                        headers: {
                            'Content-Type':     'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token':     csrf,
                        },
                        body: new URLSearchParams({ _csrf: csrf, mode: mode }),
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            fvStartPolling();
                        } else {
                            fvSetButtonsDisabled(false);
                            if (fvStatusEl) fvStatusEl.textContent = data.message || 'Nie udało się uruchomić walidacji.';
                        }
                    })
                    .catch(function () {
                        fvSetButtonsDisabled(false);
                        if (fvStatusEl) fvStatusEl.textContent = 'Błąd sieci. Spróbuj ponownie.';
                    });
                }

                function fvStopPolling() {
                    if (fvPollTimer) { clearInterval(fvPollTimer); fvPollTimer = null; }
                    fvSetButtonsDisabled(false);
                }

                function fvRenderDiff(diff, notes) {
                    fvPendingDiff = diff;
                    if (!fvDiffTableBody) return;
                    var rows = '';
                    Object.keys(diff).forEach(function (field) {
                        var entry = diff[field];
                        var label = field;
                        var labelCell = document.querySelector('tr[data-field="' + field + '"] td:first-child');
                        if (labelCell) label = labelCell.textContent;
                        var oldVal = (entry.old === null || entry.old === undefined) ? '—' : entry.old;
                        var newVal = entry.status === 'validated' ? entry.new : 'brak wiarygodnych danych';
                        rows += '<tr><td>' + label + '</td>'
                              + '<td class="fv-diff__old">' + oldVal + '</td>'
                              + '<td class="fv-diff__new">' + newVal + '</td></tr>';
                    });
                    fvDiffTableBody.innerHTML = rows;
                    if (fvDiffEl) fvDiffEl.hidden = false;
                    if (fvStatusEl) fvStatusEl.textContent = notes || 'Gotowe do przeglądu.';
                }

                function fvPoll() {
                    if (Date.now() - fvPollStart > FV_MAX_WAIT_MS) {
                        fvStopPolling();
                        if (fvStatusEl) fvStatusEl.textContent = 'Walidacja trwa dłużej niż zwykle — sprawdź ponownie za chwilę.';
                        return;
                    }
                    fetch('/analysis/' + encodeURIComponent(fvTicker) + '/validate-fundamentals/status', {
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data.ok) return;
                        if (data.status === 'completed') {
                            fvStopPolling();
                            fvRenderDiff(data.diff || {}, data.notes || '');
                        } else if (data.status === 'failed') {
                            fvStopPolling();
                            if (fvStatusEl) fvStatusEl.textContent = data.error_message || 'Walidacja się nie powiodła.';
                        }
                        // 'pending' → keep polling silently.
                    })
                    .catch(function () { /* transient network hiccup — keep polling */ });
                }

                function fvStartPolling() {
                    fvPollStart = Date.now();
                    fvSetButtonsDisabled(true);
                    if (fvStatusEl) fvStatusEl.textContent = 'Walidacja w toku…';
                    fvPollTimer = setInterval(fvPoll, FV_POLL_INTERVAL_MS);
                    fvPoll();
                }

                if (fvBtnMissing) fvBtnMissing.addEventListener('click', function () { fvHandleClick('missing'); });
                if (fvBtnAll) fvBtnAll.addEventListener('click', function () { fvHandleClick('all'); });

                if (fvConfirmBtn) {
                    fvConfirmBtn.addEventListener('click', function () {
                        if (!fvPendingDiff) return;
                        fvConfirmBtn.disabled = true;
                        if (fvStatusEl) fvStatusEl.textContent = 'Zapisuję…';
                        fetch('/analysis/' + encodeURIComponent(fvTicker) + '/validate-fundamentals/confirm', {
                            method: 'POST',
                            headers: {
                                'Content-Type':     'application/x-www-form-urlencoded',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-Token':     csrf,
                            },
                            body: new URLSearchParams({ _csrf: csrf }),
                        })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (!data.ok) {
                                if (fvStatusEl) fvStatusEl.textContent = data.message || 'Zapis się nie powiódł.';
                                fvConfirmBtn.disabled = false;
                                return;
                            }
                            Object.keys(fvPendingDiff).forEach(function (field) {
                                var entry = fvPendingDiff[field];
                                var row = document.querySelector('tr[data-field="' + field + '"]');
                                if (!row) return;
                                row.classList.remove('fv-field--suspect');
                                row.classList.add(entry.status === 'validated' ? 'fv-field--validated' : 'fv-field--checked-no-data');
                                if (entry.status === 'validated' && row.children[1]) {
                                    row.children[1].textContent = entry.new;
                                }
                            });
                            var swingEl = document.getElementById('cvs-score-swing');
                            var fundEl  = document.getElementById('cvs-score-fund');
                            if (swingEl && data.cvs_swing !== null && data.cvs_swing !== undefined) {
                                swingEl.textContent = Number(data.cvs_swing).toFixed(1);
                            }
                            if (fundEl && data.cvs_fund !== null && data.cvs_fund !== undefined) {
                                fundEl.textContent = Number(data.cvs_fund).toFixed(1);
                            }
                            if (fvDiffEl) fvDiffEl.hidden = true;
                            fvPendingDiff = null;
                            if (fvStatusEl) fvStatusEl.textContent = 'Zastosowano i przeliczono.';
                        })
                        .catch(function () {
                            if (fvStatusEl) fvStatusEl.textContent = 'Błąd sieci. Spróbuj ponownie.';
                            fvConfirmBtn.disabled = false;
                        });
                    });
                }

                // Resume polling on reload if a job was left running.
                if (fvInitialStatus === 'pending') {
                    fvStartPolling();
                }
            })();
            </script>

            <p class="disclaimer-inline"><?= htmlspecialchars($result['disclaimer']) ?></p>

            <!-- Radar chart initialisation -->
            <script>
            window.addEventListener('load', function () {
                if (typeof Chart === 'undefined') return;
                const ctx = document.getElementById('detail-radar');
                if (!ctx) return;
                const ps = <?= json_encode($ps) ?>;
                new Chart(ctx.getContext('2d'), {
                    type: 'radar',
                    data: {
                        labels: ['Wycena', 'Momentum', 'Jakość'],
                        datasets: [
                            {
                                label: 'Swing',
                                data: [ps.valuation ?? 0, ps.momentum_swing ?? 0, ps.quality ?? 0],
                                borderColor:     'rgba(79, 142, 247, 0.9)',
                                backgroundColor: 'rgba(79, 142, 247, 0.08)',
                                pointRadius: 3,
                                borderWidth: 2,
                            },
                            {
                                label: 'Fundamentalny',
                                data: [ps.valuation ?? 0, ps.momentum_fund ?? 0, ps.quality ?? 0],
                                borderColor:     'rgba(250, 204, 21, 0.9)',
                                backgroundColor: 'rgba(250, 204, 21, 0.08)',
                                pointRadius: 3,
                                borderWidth: 2,
                            },
                        ],
                    },
                    options: {
                        animation: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            r: {
                                min: 0,
                                max: 100,
                                ticks: { display: false, stepSize: 25 },
                                pointLabels: {
                                    font: { size: 11 },
                                    color: 'rgba(255,255,255,0.75)',
                                },
                                grid: { color: 'rgba(128,128,128,.15)' },
                            },
                        },
                    },
                });
            });
            </script>

            <?php if (!empty($financials['monthly_closes'])): ?>
            <script>
            window.addEventListener('load', function () {
                if (typeof Chart === 'undefined') return;
                var pCtx = document.getElementById('price-chart');
                if (!pCtx) return;

                var closes    = <?= json_encode(array_values($financials['monthly_closes'])) ?>;
                var spyData   = <?= json_encode(array_values($financials['spy_closes'] ?? [])) ?>;
                var ticker    = <?= json_encode($ticker) ?>;
                var benchmarkLabel = <?= json_encode($financials['benchmark_label'] ?? 'S&P 500') ?>;
                var n = 12;

                // Take last N points
                var tickerRaw = closes.slice(-n);
                var spyRaw    = spyData.slice(-n);
                var len       = tickerRaw.length;
                if (len === 0) return;

                // Normalize to base-100 index from first point
                var tBase = tickerRaw[0] || 1;
                var sBase = spyRaw[0]    || 1;
                var tickerNorm = tickerRaw.map(function(v){ return parseFloat((v / tBase * 100).toFixed(2)); });
                var spyNorm    = spyRaw.length
                    ? spyRaw.map(function(v){ return parseFloat((v / sBase * 100).toFixed(2)); })
                    : [];

                // Generate month labels going back 'len' months from today
                var labels = [];
                var now = new Date();
                for (var i = len - 1; i >= 0; i--) {
                    var d = new Date(now.getFullYear(), now.getMonth() - i, 1);
                    labels.push(d.toLocaleDateString('pl-PL', { month: 'short', year: '2-digit' }));
                }

                var datasets = [
                    {
                        label: ticker,
                        data: tickerNorm,
                        borderColor: 'rgba(79, 142, 247, 0.9)',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        pointRadius: 2,
                        tension: 0.15,
                    },
                ];
                if (spyNorm.length) {
                    datasets.push({
                        label: benchmarkLabel,
                        data: spyNorm,
                        borderColor: 'rgba(160, 160, 160, 0.55)',
                        backgroundColor: 'transparent',
                        borderWidth: 1.5,
                        pointRadius: 1,
                        tension: 0.15,
                        borderDash: [5, 3],
                    });
                }

                // Phase 8 slice 2 — ATR zone/stop overlay, converted to the chart's base=100 scale.
                <?php if (!empty($execPlan) && !empty($execPlan['has_zone'])): ?>
                var ep = <?= json_encode([
                    'zone_low'   => $execPlan['zone_low'],
                    'zone_high'  => $execPlan['zone_high'],
                    'stop_swing' => $execPlan['stop_swing'],
                    'stop_fund'  => $execPlan['stop_fund'],
                ]) ?>;
                if (tBase > 0) {
                    var toIdx = function(v){ return v == null ? null : parseFloat((v / tBase * 100).toFixed(2)); };
                    var flat  = function(val){ return labels.map(function(){ return val; }); };
                    var zHigh = toIdx(ep.zone_high), zLow = toIdx(ep.zone_low);
                    if (zHigh != null) datasets.push({ label:'Strefa (góra)', data:flat(zHigh), borderColor:'rgba(34,197,94,.55)', backgroundColor:'rgba(34,197,94,.08)', borderWidth:1, pointRadius:0, fill:'+1', borderDash:[4,3] });
                    if (zLow  != null) datasets.push({ label:'Strefa (dół)',  data:flat(zLow),  borderColor:'rgba(34,197,94,.55)', backgroundColor:'transparent', borderWidth:1, pointRadius:0, borderDash:[4,3] });
                    var sSwing = toIdx(ep.stop_swing), sFund = toIdx(ep.stop_fund);
                    if (sSwing != null) datasets.push({ label:'Stop swing', data:flat(sSwing), borderColor:'rgba(239,68,68,.6)',  backgroundColor:'transparent', borderWidth:1, pointRadius:0, borderDash:[2,2] });
                    if (sFund  != null) datasets.push({ label:'Stop fund',  data:flat(sFund),  borderColor:'rgba(239,68,68,.35)', backgroundColor:'transparent', borderWidth:1, pointRadius:0, borderDash:[2,2] });
                }
                <?php endif; ?>

                new Chart(pCtx.getContext('2d'), {
                    type: 'line',
                    data: { labels: labels, datasets: datasets },
                    options: {
                        animation: false,
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: {
                                    color: 'rgba(255,255,255,0.7)',
                                    boxWidth: 12,
                                    font: { size: 11 },
                                },
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(c) {
                                        return c.dataset.label + ': ' + c.parsed.y.toFixed(1);
                                    },
                                },
                            },
                        },
                        scales: {
                            x: {
                                grid: { color: 'rgba(128,128,128,.08)' },
                                ticks: {
                                    color: 'rgba(255,255,255,0.45)',
                                    font: { size: 10 },
                                    maxRotation: 45,
                                },
                            },
                            y: {
                                grid: { color: 'rgba(128,128,128,.08)' },
                                ticks: {
                                    color: 'rgba(255,255,255,0.45)',
                                    font: { size: 10 },
                                    callback: function(v) { return v.toFixed(0); },
                                },
                                title: {
                                    display: true,
                                    text: 'Indeks (baza = 100)',
                                    color: 'rgba(255,255,255,0.3)',
                                    font: { size: 10 },
                                },
                            },
                        },
                    },
                });
            });
            </script>
            <?php endif; ?>

            <?php if ($hasTrajChart): ?>
            <script>
            window.addEventListener('load', function () {
                if (typeof Chart === 'undefined') return;
                var tCtx = document.getElementById('trajectory-chart');
                if (!tCtx) return;

                var points = <?= json_encode($trajectory['points']) ?>;
                if (!points.length) return;

                var labels = points.map(function(p){
                    var d = new Date(p.date);
                    return d.toLocaleDateString('pl-PL', { day: '2-digit', month: 'short' });
                });
                var data = points.map(function(p){ return parseFloat(p.cvs); });

                new Chart(tCtx.getContext('2d'), {
                    type: 'line',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: 'CVS Swing',
                            data: data,
                            borderColor: 'rgba(79, 142, 247, 0.9)',
                            backgroundColor: 'transparent',
                            borderWidth: 2,
                            pointRadius: 2,
                            tension: 0.15,
                        }],
                    },
                    options: {
                        animation: false,
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function(c){ return 'CVS ' + c.parsed.y.toFixed(1); },
                                },
                            },
                        },
                        scales: {
                            x: {
                                grid: { color: 'rgba(128,128,128,.08)' },
                                ticks: { color: 'rgba(255,255,255,0.45)', font: { size: 10 }, maxRotation: 45 },
                            },
                            y: {
                                min: 0,
                                max: 100,
                                grid: { color: 'rgba(128,128,128,.08)' },
                                ticks: { color: 'rgba(255,255,255,0.45)', font: { size: 10 }, stepSize: 25 },
                            },
                        },
                    },
                });
            });
            </script>
            <?php endif; ?>

            <?php if ($hasTrend): ?>
            <script>
            window.addEventListener('load', function () {
                if (typeof Chart === 'undefined') return;
                var ctx = document.getElementById('reco-trend-chart');
                if (!ctx) return;

                // Yahoo returns periods newest-first; reverse so newest sits on the right.
                var trend = <?= json_encode(array_values($fcTrend)) ?>.slice().reverse();
                if (!trend.length) return;

                var labels = trend.map(function (r) {
                    if (r.period === '0m') return 'Teraz';
                    var m = parseInt(String(r.period).replace(/[^0-9]/g, ''), 10);
                    return isNaN(m) ? r.period : (m + ' mc temu');
                });

                var series = [
                    { key: 'strong_buy',  label: 'Silne Kupuj',    color: 'rgba(22,163,74,0.85)' },
                    { key: 'buy',         label: 'Kupuj',          color: 'rgba(74,222,128,0.85)' },
                    { key: 'hold',        label: 'Trzymaj',        color: 'rgba(234,179,8,0.85)' },
                    { key: 'sell',        label: 'Sprzedaj',       color: 'rgba(249,115,22,0.85)' },
                    { key: 'strong_sell', label: 'Silna Sprzedaż', color: 'rgba(239,68,68,0.85)' },
                ];

                var datasets = series.map(function (s) {
                    return {
                        label: s.label,
                        data: trend.map(function (r) { return r[s.key] || 0; }),
                        backgroundColor: s.color,
                        borderWidth: 0,
                    };
                });

                new Chart(ctx.getContext('2d'), {
                    type: 'bar',
                    data: { labels: labels, datasets: datasets },
                    options: {
                        animation: false,
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: { color: 'rgba(255,255,255,0.7)', boxWidth: 12, font: { size: 11 } },
                            },
                        },
                        scales: {
                            x: {
                                stacked: true,
                                grid: { color: 'rgba(128,128,128,.08)' },
                                ticks: { color: 'rgba(255,255,255,0.45)', font: { size: 10 } },
                            },
                            y: {
                                stacked: true,
                                grid: { color: 'rgba(128,128,128,.08)' },
                                ticks: { color: 'rgba(255,255,255,0.45)', font: { size: 10 }, precision: 0 },
                            },
                        },
                    },
                });
            });
            </script>
            <?php endif; ?>

            <?php if ($hasFan): ?>
            <script>
            window.addEventListener('load', function () {
                if (typeof Chart === 'undefined') return;
                var ctx = document.getElementById('forecast-fan-chart');
                if (!ctx) return;

                var closes = <?= json_encode(array_values($financials['monthly_closes'])) ?>;
                var cur    = <?= json_encode((float) $curPrice) ?>;
                var high   = <?= json_encode((float) $fcHigh) ?>;
                var mean   = <?= json_encode((float) $fcMean) ?>;
                var low    = <?= json_encode((float) $fcLow) ?>;
                var n = 12;

                var hist = closes.slice(-n);
                var len  = hist.length;
                if (len === 0) return;

                // Labels: 'len' historical months + a +12M projection endpoint.
                var labels = [];
                var now = new Date();
                for (var i = len - 1; i >= 0; i--) {
                    var d = new Date(now.getFullYear(), now.getMonth() - i, 1);
                    labels.push(d.toLocaleDateString('pl-PL', { month: 'short', year: '2-digit' }));
                }
                labels.push('+12M');

                // History line: actual closes, null at the projection endpoint.
                var historyData = hist.slice();
                historyData.push(null);

                // Projection datasets fan from the current price (now-anchor) to each target.
                function fan(target) {
                    var arr = new Array(len + 1).fill(null);
                    arr[len - 1] = cur;
                    arr[len]     = target;
                    return arr;
                }

                var datasets = [
                    {
                        label: <?= json_encode($ticker) ?>,
                        data: historyData,
                        borderColor: 'rgba(79, 142, 247, 0.9)',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        pointRadius: 2,
                        spanGaps: false,
                        tension: 0.15,
                    },
                    {
                        label: 'Max',
                        data: fan(high),
                        borderColor: 'rgba(34, 197, 94, 0.9)',
                        backgroundColor: 'transparent',
                        borderWidth: 1.5,
                        pointRadius: 3,
                        borderDash: [5, 3],
                        spanGaps: false,
                    },
                    {
                        label: 'Średnia',
                        data: fan(mean),
                        borderColor: 'rgba(160, 160, 160, 0.8)',
                        backgroundColor: 'transparent',
                        borderWidth: 1.5,
                        pointRadius: 3,
                        spanGaps: false,
                    },
                    {
                        label: 'Min',
                        data: fan(low),
                        borderColor: 'rgba(239, 68, 68, 0.9)',
                        backgroundColor: 'transparent',
                        borderWidth: 1.5,
                        pointRadius: 3,
                        borderDash: [5, 3],
                        spanGaps: false,
                    },
                <?php if ($cvsFairPrice !== null): ?>
                    {
                        label: 'CVS Fair Value',
                        data: fan(<?= json_encode($cvsFairPrice) ?>),
                        borderColor: 'rgba(250, 204, 21, 0.95)',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        pointRadius: 4,
                        pointBackgroundColor: 'rgba(250, 204, 21, 0.95)',
                        borderDash: [8, 4],
                        spanGaps: false,
                    },
                <?php endif; ?>
                ];

                new Chart(ctx.getContext('2d'), {
                    type: 'line',
                    data: { labels: labels, datasets: datasets },
                    options: {
                        animation: false,
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                                labels: { color: 'rgba(255,255,255,0.7)', boxWidth: 12, font: { size: 11 } },
                            },
                            tooltip: {
                                callbacks: {
                                    label: function (c) {
                                        if (c.parsed.y === null) return null;
                                        return c.dataset.label + ': $' + c.parsed.y.toFixed(2);
                                    },
                                },
                            },
                        },
                        scales: {
                            x: {
                                grid: { color: 'rgba(128,128,128,.08)' },
                                ticks: { color: 'rgba(255,255,255,0.45)', font: { size: 10 }, maxRotation: 45 },
                            },
                            y: {
                                grid: { color: 'rgba(128,128,128,.08)' },
                                ticks: {
                                    color: 'rgba(255,255,255,0.45)',
                                    font: { size: 10 },
                                    callback: function (v) { return '$' + v.toFixed(0); },
                                },
                            },
                        },
                    },
                });
            });
            </script>
            <?php endif; ?>

        <?php endif; ?>

    <?php endif; ?>

    <?php
    // Peer-median history modal — opened by the sector/subsector badge in
    // the pillar table above (.js-sector-chart). Public endpoint (any
    // logged-in user), not the admin one.
    $historyEndpoint = '/sectors/history';
    require __DIR__ . '/partials/sector-history-modal.php';
    ?>

    <p><a href="/dashboard">&larr; Powrót do panelu</a></p>
</section>

