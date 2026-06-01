<section class="analysis-detail">
    <div class="analysis-detail__heading">
        <h1>Analiza: <?= htmlspecialchars($ticker) ?></h1>
        <button class="watchlist-detail-btn<?= ($isWatched ?? false) ? ' is-watched' : '' ?>"
                data-ticker="<?= htmlspecialchars($ticker) ?>"
                data-watched="<?= ($isWatched ?? false) ? '1' : '0' ?>">
            <?= ($isWatched ?? false) ? '× Usuń z obserwowanych' : '⭐ Obserwuj' ?>
        </button>
    </div>

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

            $tileLevelClass = static function (float $cvs): string {
                if ($cvs >= 72) return 'score-tile--strong';
                if ($cvs >= 42) return 'score-tile--neutral';
                return 'score-tile--weak';
            };
            ?>

            <?php if (!empty($financials['monthly_closes'])): ?>
            <div class="price-chart-section">
                <canvas id="price-chart"></canvas>
            </div>
            <?php endif; ?>

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

                <!-- Radar chart with two lines -->
                <div class="detail-radar-wrapper">
                    <canvas id="detail-radar" width="300" height="300"></canvas>
                    <div class="detail-radar-legend">
                        <span class="legend-dot legend-dot--swing"></span> Swing &nbsp;
                        <span class="legend-dot legend-dot--fund"></span> Fundamentalny
                    </div>
                </div>

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
                        $pillarRows = [
                            ['key' => 'valuation',      'label' => 'Wycena (EV/FCF)',     'sw' => '40%', 'fn' => '65%'],
                            ['key' => 'momentum_swing', 'label' => 'Momentum (Swing)',     'sw' => '45%', 'fn' => '—'],
                            ['key' => 'momentum_fund',  'label' => 'Momentum (Fund)',      'sw' => '—',   'fn' => '15%'],
                            ['key' => 'quality',        'label' => 'Jakość fundamentalna', 'sw' => '15%', 'fn' => '20%'],
                        ];
                        foreach ($pillarRows as $row):
                            $score = $ps[$row['key']] ?? null;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($row['label']) ?></td>
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
                                'current_price'       => 'Cena bieżąca ($)',
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
                    </div>
                    <?php if ($fcNum !== null): ?>
                        <p class="forecast-note">Na podstawie <?= (int) $fcNum ?> ocen analityków<?php if ($curPrice !== null): ?> · cena bieżąca $<?= number_format((float) $curPrice, 2) ?><?php endif; ?>.</p>
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
                    <?= nl2br(htmlspecialchars((string) $cachedAi['content'])) ?>
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

