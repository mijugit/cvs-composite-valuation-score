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
            ?>

            <?php if (!empty($financials['monthly_closes'])): ?>
            <div class="price-chart-section">
                <canvas id="price-chart"></canvas>
            </div>
            <?php endif; ?>

            <!-- Dual CVS score header -->
            <div class="card card--result">
                <?php if ($gs && isset($gsLabels[$gs])): ?>
                    <div class="golden-signal golden-signal--<?= htmlspecialchars($gs) ?>">
                        <?= $gsLabels[$gs]['stars'] ? htmlspecialchars($gsLabels[$gs]['stars']) . ' ' : '' ?>
                        <?= htmlspecialchars($gsLabels[$gs]['label']) ?>
                    </div>
                <?php endif; ?>

                <div class="dual-cvs-header">
                    <!-- Swing score -->
                    <div class="cvs-mode-tile cvs-mode-tile--swing">
                        <div class="cvs-mode-tile__label"><?= htmlspecialchars($modeSwing['label'] ?? 'Swing') ?></div>
                        <div class="cvs-mode-tile__score"><?= number_format((float)($swing['cvs'] ?? 0), 1) ?></div>
                        <div class="cvs-mode-tile__reco">
                            <span class="cvs-badge"><?= htmlspecialchars($swing['recommendation'] ?? '') ?></span>
                        </div>
                    </div>

                    <!-- Fundamental score -->
                    <div class="cvs-mode-tile cvs-mode-tile--fund">
                        <div class="cvs-mode-tile__label"><?= htmlspecialchars($modeFund['label'] ?? 'Fundamentalny') ?></div>
                        <div class="cvs-mode-tile__score"><?= number_format((float)($fund['cvs'] ?? 0), 1) ?></div>
                        <div class="cvs-mode-tile__reco">
                            <span class="cvs-badge cvs-badge--fund"><?= htmlspecialchars($fund['recommendation'] ?? '') ?></span>
                        </div>
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
                                <div class="pillar-bar">
                                    <div class="pillar-bar__fill" style="width:<?= min(100, round((float)$score)) ?>%"></div>
                                    <span><?= number_format((float)$score, 1) ?></span>
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
                                borderColor:     'rgba(234, 179, 8, 0.9)',
                                backgroundColor: 'rgba(234, 179, 8, 0.08)',
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

<style>
/* Detail heading with watchlist button */
.analysis-detail__heading {
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: .25rem;
}
.analysis-detail__heading h1 { margin-bottom: 0; }

/* Dual CVS tiles */
.dual-cvs-header {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}
.cvs-mode-tile {
    flex: 1;
    min-width: 140px;
    background: var(--c-bg);
    border: 1px solid var(--c-border);
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
}
.cvs-mode-tile--swing  { border-color: rgba(79, 142, 247, .4); }
.cvs-mode-tile--fund   { border-color: rgba(234, 179, 8, .4); }
.cvs-mode-tile__label  { font-size: .8rem; color: var(--c-muted); margin-bottom: .35rem; }
.cvs-mode-tile__score  { font-size: 2.2rem; font-weight: 700; }
.cvs-mode-tile--swing .cvs-mode-tile__score { color: var(--c-primary); }
.cvs-mode-tile--fund  .cvs-mode-tile__score { color: #eab308; }
.cvs-mode-tile__reco   { margin-top: .35rem; }

.cvs-badge--fund { background: rgba(234, 179, 8, .2); color: #eab308; }

/* Golden signal banner */
.golden-signal {
    font-size: .9rem;
    padding: .4rem 1rem;
    border-radius: 99px;
    display: inline-block;
    margin-bottom: 1rem;
}
.golden-signal--strong    { background: rgba(34,197,94,.12);  color: #22c55e; }
.golden-signal--watchlist { background: rgba(234,179,8,.12);  color: #eab308; }
.golden-signal--momentum  { background: rgba(79,142,247,.12); color: var(--c-primary); }

/* Radar */
.detail-radar-wrapper { display: flex; flex-direction: column; align-items: center; margin: 1.5rem 0; }
.detail-radar-legend  { font-size: .8rem; color: var(--c-muted); margin-top: .5rem; }
.legend-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; }
.legend-dot--swing { background: #4f8ef7; }
.legend-dot--fund  { background: #eab308; }

/* Raw data */
.raw-data { margin-top: 1.5rem; }
.raw-data summary { cursor: pointer; font-size: .85rem; color: var(--c-muted); margin-bottom: .5rem; }
.raw-table td:first-child { color: var(--c-muted); font-size: .82rem; width: 55%; }

/* Price chart section */
.price-chart-section {
    background: var(--c-card);
    border: 1px solid var(--c-border);
    border-radius: 10px;
    padding: .75rem 1rem 1rem;
    margin-bottom: 1.25rem;
    height: 220px;
    position: relative;
}

/* Forecast card (S-09) */
.forecast-card { border-color: rgba(79, 142, 247, .25); }
.forecast-block { margin-top: 1.5rem; }
.forecast-block:first-of-type { margin-top: .5rem; }
.forecast-block__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: .75rem;
}
.forecast-block h3 { margin: 0 0 .75rem; }
.forecast-block__head h3 { margin: 0; }

.upside-badge {
    font-size: .85rem;
    font-weight: 600;
    padding: .25rem .7rem;
    border-radius: 99px;
}
.upside-badge--pos { background: rgba(52, 199, 123, .15); color: var(--c-success); }
.upside-badge--neg { background: rgba(224, 85, 85, .15);  color: var(--c-danger); }

.forecast-targets {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
    gap: .75rem;
}
.forecast-tile {
    background: var(--c-bg);
    border: 1px solid var(--c-border);
    border-radius: 8px;
    padding: .75rem;
    text-align: center;
}
.forecast-tile__label { font-size: .75rem; color: var(--c-muted); margin-bottom: .3rem; }
.forecast-tile__value { font-size: 1.35rem; font-weight: 700; }
.forecast-note { font-size: .8rem; color: var(--c-muted); margin-top: .6rem; }

.consensus-label {
    font-size: .9rem;
    font-weight: 600;
    color: var(--c-primary);
}
.consensus-label small { color: var(--c-muted); font-weight: 400; }

.consensus-bars { list-style: none; display: flex; flex-direction: column; gap: .4rem; }
.consensus-bar { display: flex; align-items: center; gap: .6rem; }
.consensus-bar__label { flex: 0 0 110px; font-size: .82rem; color: var(--c-muted); }
.consensus-bar__track {
    flex: 1;
    height: 14px;
    background: var(--c-bg);
    border-radius: 7px;
    overflow: hidden;
}
.consensus-bar__fill { display: block; height: 100%; border-radius: 7px; }
.consensus-bar__fill--sb { background: rgba(22,163,74,0.85); }
.consensus-bar__fill--b  { background: rgba(74,222,128,0.85); }
.consensus-bar__fill--h  { background: rgba(234,179,8,0.85); }
.consensus-bar__fill--s  { background: rgba(249,115,22,0.85); }
.consensus-bar__fill--ss { background: rgba(239,68,68,0.85); }
.consensus-bar__count { flex: 0 0 28px; text-align: right; font-size: .82rem; font-weight: 600; }

.forecast-chart-wrap { position: relative; height: 240px; }
</style>
