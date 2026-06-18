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
            $ratioKeys = ['gross_margins', 'revenue_growth', 'return_on_equity'];
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

            // CVS Fair Value: price at which Valuation pillar = 50 (sector-median parity).
            // Fair EV = median_ev_fcf × forward_FCF; Fair Price = (Fair EV - debt + cash) / shares.
            $cvsFairPrice = null;
            if (!empty($financials)) {
                $sector      = (string) ($financials['sector'] ?? 'DEFAULT');
                $benchmarks  = $cfgFile['benchmarks'] ?? [];
                $bm          = $benchmarks[$sector] ?? $benchmarks['DEFAULT'] ?? [];
                $medEvFcf    = (float) ($bm['median_ev_fcf'] ?? 0);
                $maxGrowthPct= (float) ($bm['max_growth']    ?? 20);

                $fcf    = (float) ($financials['free_cash_flow']          ?? 0);
                if ($fcf <= 0) $fcf = (float) ($financials['free_cash_flow_adjusted'] ?? 0);

                $debt   = (float) ($financials['total_debt']  ?? 0);
                $cash   = (float) ($financials['cash']        ?? 0);
                $shares = (float) ($financials['shares_outstanding'] ?? 0);

                // Growth: implied EPS or revenue growth, capped to sector max.
                $fwdEps   = (float) ($financials['forward_eps']  ?? 0);
                $trailEps = (float) ($financials['trailing_eps'] ?? 0);
                $growth   = null;
                if ($fwdEps > 0 && $trailEps > 0) {
                    $implied = ($fwdEps / $trailEps - 1) * 100;
                    if ($implied > 0 && $implied <= 200) $growth = $implied;
                }
                if ($growth === null) {
                    $rg = (float) ($financials['revenue_growth'] ?? 0);
                    if ($rg > 0) $growth = $rg * 100;
                }
                $growth = $growth !== null ? min($growth, $maxGrowthPct) : null;

                if ($fcf > 0 && $growth !== null && $medEvFcf > 0 && $shares > 0) {
                    $fwdFcf      = $fcf * (1 + $growth / 100) ** 2;
                    $fairEv      = $medEvFcf * $fwdFcf;
                    $fairPriceRaw = ($fairEv - $debt + $cash) / $shares;
                    $curPrice    = (float) ($financials['current_price'] ?? 0);
                    // Sanity bounds: suppress if outside 0.05× – 10× current price.
                    if ($fairPriceRaw > 0 && ($curPrice <= 0 || ($fairPriceRaw / $curPrice >= 0.05 && $fairPriceRaw / $curPrice <= 10.0))) {
                        $cvsFairPrice = round($fairPriceRaw, 2);
                    }
                }
            }

            $tileLevelClass = static function (float $cvs): string {
                if ($cvs >= 72) return 'score-tile--strong';
                if ($cvs >= 42) return 'score-tile--neutral';
                return 'score-tile--weak';
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
                    </div>

                    <!-- Fundamental score -->
                    <div class="score-tile score-tile--fund <?= $tileLevelClass((float)($fund['cvs'] ?? 0)) ?>">
                        <span class="score-tile__mode"><?= htmlspecialchars($modeFund['label'] ?? 'Fundamentalny') ?></span>
                        <span class="score-tile__value"><?= number_format((float)($fund['cvs'] ?? 0), 1) ?></span>
                        <span class="score-tile__reco"><?= htmlspecialchars($fund['recommendation'] ?? '') ?></span>
                    </div>
                </div>

                <?php
                // Phase 5 (slice 1) — shadow model_version (3.1) preview chip.
                // Shown alongside the headline 3.0 scores above; never replaces them
                // (guardrail FR-016 — displayed reco stays at 3.0 until recalibration).
                $overlay = $result['overlay'] ?? null;
                if ($overlay !== null):
                    $ovPenalties = $overlay['penalties'] ?? [];
                    $ovCoverage  = $overlay['coverage']  ?? [];
                    $ovTotal     = (float) ($ovPenalties['total']    ?? 0.0);
                    $ovRevision  = (float) ($ovPenalties['revision'] ?? 0.0);
                    $ovTarget    = (float) ($ovPenalties['target']   ?? 0.0);
                    $ovVersion   = (string) ($overlay['shadow_version'] ?? '3.1');

                    $ovMissing = [];
                    if (!empty($ovCoverage['missing_eps_trend'])) $ovMissing[] = 'rewizja';
                    if (!empty($ovCoverage['missing_target']))    $ovMissing[] = 'target';
                ?>
                <div class="overlay-preview-chip"
                     style="margin-top:.6rem;padding:.5rem .75rem;border-radius:6px;
                            font-size:.8rem;line-height:1.5;color:var(--c-muted);
                            background:rgba(255,255,255,.04);">
                    <strong style="color:var(--c-text);">Podgląd <?= htmlspecialchars($ovVersion) ?>:</strong>
                    <?= htmlspecialchars(number_format($ovTotal, 1)) ?> pkt
                    (rewizja <?= htmlspecialchars(number_format($ovRevision, 1)) ?> /
                     target <?= htmlspecialchars(number_format($ovTarget, 1)) ?>)
                    <?php if ($ovMissing !== []): ?>
                        <span style="opacity:.75;"> — brak danych: <?= htmlspecialchars(implode('/', $ovMissing)) ?></span>
                    <?php endif; ?>
                    <span style="display:block;margin-top:.15rem;opacity:.65;">
                        Tryb cieniowy (eksperymentalny) — oficjalna rekomendacja pozostaje wg modelu 3.0 powyżej.
                    </span>
                </div>
                <?php endif; ?>

                <?php
                // Phase 7 (slice 2) — shadow model_version (3.2) preview chip:
                // 3.1 penalties + directional PEAD guard + symmetric signals
                // (breadth/52w/consistency). Same guardrail as 3.1 above —
                // headline reco stays at 3.0 (FR-020).
                $shadow32 = null;
                foreach (($result['shadows'] ?? []) as $shadowBlock) {
                    if (($shadowBlock['shadow_version'] ?? '') === '3.2') {
                        $shadow32 = $shadowBlock;
                        break;
                    }
                }
                if ($shadow32 !== null):
                    $s32Penalties = $shadow32['penalties'] ?? [];
                    $s32Signals   = $shadow32['signals']   ?? [];
                    $s32Coverage  = $shadow32['coverage']  ?? [];
                    $s32Adj       = $s32Signals['adjustments'] ?? [];
                    $s32Total     = (float) ($s32Penalties['total']         ?? 0.0);
                    $s32Pead      = (float) ($s32Penalties['earnings_guard'] ?? 0.0);
                    $s32Breadth   = (float) ($s32Adj['breadth']    ?? 0.0);
                    $s32High52w   = (float) ($s32Adj['high_52w']   ?? 0.0);
                    $s32Consist   = (float) ($s32Adj['consistency'] ?? 0.0);

                    $s32Missing = [];
                    if (!empty($s32Coverage['missing_surprise']))    $s32Missing[] = 'zaskoczenie';
                    if (!empty($s32Coverage['missing_breadth']))     $s32Missing[] = 'rewizje';
                    if (!empty($s32Coverage['missing_52w']))         $s32Missing[] = '52w';
                    if (!empty($s32Coverage['missing_consistency'])) $s32Missing[] = 'konsystencja';
                ?>
                <div class="overlay-preview-chip"
                     style="margin-top:.6rem;padding:.5rem .75rem;border-radius:6px;
                            font-size:.8rem;line-height:1.5;color:var(--c-muted);
                            background:rgba(255,255,255,.04);">
                    <strong style="color:var(--c-text);">Podgląd 3.2:</strong>
                    <?= htmlspecialchars(number_format($s32Total, 1)) ?> pkt
                    (PEAD <?= htmlspecialchars(number_format($s32Pead, 1)) ?> /
                     rewizje <?= htmlspecialchars(number_format($s32Breadth, 1)) ?> /
                     52w <?= htmlspecialchars(number_format($s32High52w, 1)) ?> /
                     konsystencja <?= htmlspecialchars(number_format($s32Consist, 1)) ?>)
                    <?php if ($s32Missing !== []): ?>
                        <span style="opacity:.75;"> — brak danych: <?= htmlspecialchars(implode('/', $s32Missing)) ?></span>
                    <?php endif; ?>
                    <span style="display:block;margin-top:.15rem;opacity:.65;">
                        Tryb cieniowy (eksperymentalny) — oficjalna rekomendacja pozostaje wg modelu 3.0 powyżej.
                    </span>
                </div>
                <?php endif; ?>

                <?php
                // Phase 5 (slice 2) — earnings-timing badge (FR-010). Always present,
                // independent of overlays/earnings_guard flags (badge ≠ guard
                // separation, FR-017) — a sibling block to the shadow preview chip
                // above, deliberately NOT nested inside `$overlay !== null`.
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
                    <div class="detail-radar-wrapper">
                        <canvas id="detail-radar" width="300" height="300"></canvas>
                        <div class="detail-radar-legend">
                            <span class="legend-dot legend-dot--swing"></span> Swing &nbsp;
                            <span class="legend-dot legend-dot--fund"></span> Fundamentalny
                        </div>
                    </div>
                    <?php if (!empty($financials['monthly_closes'])): ?>
                    <div class="price-chart-compact">
                        <div class="price-chart-compact__label">
                            Kurs akcji — 12 miesięcy (baza=100)
                            <span class="chart-hint" tabindex="0">ⓘ
                                <span class="chart-hint__tooltip">
                                    <strong>Jak czytać wykres?</strong><br>
                                    Obie linie są przeliczone do bazy&nbsp;100 na początku okresu —
                                    porównujesz tempo wzrostu, nie cenę nominalną.<br><br>
                                    <strong><?= htmlspecialchars($ticker) ?></strong> — miesięczne zamknięcia spółki.<br>
                                    <strong>SPY</strong> — ETF odwzorowujący indeks S&amp;P 500 (benchmark rynku US).
                                    Linia spółki powyżej SPY = spółka biła rynek w tym okresie.
                                </span>
                            </span>
                        </div>
                        <canvas id="price-chart"></canvas>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- CVS trajectory (Phase 8 slice 1) -->
                <div class="trajectory-block">
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
                    <?php if (!empty($trajectory) && !empty($trajectory['has_trajectory'])): ?>
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
                    <p class="exec-disclaimer">Poziomy orientacyjne z danych cenowych — nie są rekomendacją. Inwestuj świadomie.</p>
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
                        $valRef    = $result['valuation_reference'] ?? [];
                        $valSource = $valRef['source'] ?? '';
                        $valBucket = $valRef['bucket'] ?? '';
                        $valBadge  = '';
                        if ($valSource === 'subsector' && $valBucket !== '') {
                            $valBadge = ' <span title="Benchmark: podsektor ' . htmlspecialchars($valBucket) . '"'
                                . ' style="font-size:.7rem;background:rgba(64,144,224,.15);color:var(--c-primary);'
                                . 'border-radius:3px;padding:1px 5px;margin-left:.3rem;cursor:default;">'
                                . '⊂ ' . htmlspecialchars($valBucket) . '</span>';
                        } elseif (in_array($valSource, ['sector_fallback', 'cold_start'], true) && $valBucket !== '') {
                            $valBadge = ' <span title="Benchmark: sektor ' . htmlspecialchars($valBucket) . '"'
                                . ' style="font-size:.7rem;background:rgba(255,255,255,.06);color:var(--c-muted);'
                                . 'border-radius:3px;padding:1px 5px;margin-left:.3rem;cursor:default;">'
                                . '≈ ' . htmlspecialchars($valBucket) . '</span>';
                        }
                        ?>
                        <?php
                        $pillarRows = [
                            ['key' => 'valuation',      'label' => 'Wycena (EV/FCF)',     'sw' => '40%', 'fn' => '65%', 'badge' => $valBadge],
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
                                        <div class="progress-bar__fill" style="width:<?= min(100, round((float)$score)) ?>%"></div>
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
                                <strong><?= number_format((float)($swing['cvs'] ?? 0), 1) ?></strong>
                                — <?= htmlspecialchars($swing['recommendation'] ?? '') ?>
                            </td>
                        </tr>
                        <tr>
                            <th colspan="1">CVS Fund</th>
                            <td colspan="3">
                                <strong><?= number_format((float)($fund['cvs'] ?? 0), 1) ?></strong>
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
                                'gross_margins'       => 'Marża brutto',
                                'revenue_growth'      => 'Wzrost przychodów',
                                'return_on_equity'    => 'ROE',
                                'forward_eps'         => 'EPS forward',
                                'trailing_eps'        => 'EPS trailing',
                                'sector'              => 'Sektor',
                            ];
                            foreach ($rawFields as $key => $label):
                                $val = $financials[$key] ?? null;
                                if ($val === null) continue;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($label) ?></td>
                                <td>
                                    <?= $fmtRaw($key, $val) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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
                            </span>
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
                    resultEl.innerHTML = '<p>' + html + '</p>';
                    resultEl.hidden = false;
                    if (placeholder) placeholder.hidden = true;

                    // Show generated date and hide generate button
                    var dateEl = document.querySelector('.ai-analysis-card__date');
                    if (!dateEl && generatedAt) {
                        var hdr = document.querySelector('.ai-analysis-card__actions');
                        if (hdr) {
                            var span = document.createElement('span');
                            span.className = 'ai-analysis-card__date';
                            span.textContent = 'Analiza z ' + generatedAt.substring(0, 10);
                            hdr.prepend(span);
                        }
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

                var closes  = <?= json_encode(array_values($financials['monthly_closes'])) ?>;
                var spyData = <?= json_encode(array_values($financials['spy_closes'] ?? [])) ?>;
                var ticker  = <?= json_encode($ticker) ?>;
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
                        label: 'SPY',
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

            <?php if (!empty($trajectory) && !empty($trajectory['has_trajectory'])): ?>
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

    <p><a href="/dashboard">&larr; Powrót do panelu</a></p>
</section>

