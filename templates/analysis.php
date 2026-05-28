<?php
/**
 * Detail view — GET /analysis/{ticker}
 *
 * Injected by AnalysisController::show():
 *   $ticker     — string
 *   $result     — array|null  CVSResult::toArray()
 *   $financials — array|null  FinancialDataFetcher output  (S-03)
 *   $error      — string|null
 */
?>
<section class="analysis-detail">

    <p class="back-link"><a href="/dashboard">&larr; Powr&oacute;t do panelu</a></p>

    <h1><?= htmlspecialchars($ticker) ?></h1>
    <?php if (!empty($financials['sector'])): ?>
        <p class="ticker-meta"><?= htmlspecialchars($financials['sector']) ?></p>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <p class="alert alert--error"><?= htmlspecialchars($error) ?></p>

    <?php elseif ($result !== null): ?>

        <?php if (!$result['quality_gate']): ?>
            <!-- Quality Gate rejection -->
            <div class="card card--fail">
                <h2>Quality Gate: ODRZUCONO</h2>
                <p>Sp&oacute;&lstrok;ka nie spe&lstrok;nia minimalnych wymaga&nacute; jako&sacute;ciowych. CVS nie zosta&lstrok;o wyliczone.</p>
                <ul class="failure-list">
                    <?php foreach ($result['gate_failures'] as $fail): ?>
                        <li><?= htmlspecialchars($fail) ?></li>
                    <?php endforeach; ?>
                </ul>
                <p class="disclaimer-inline"><?= htmlspecialchars($result['disclaimer']) ?></p>
            </div>

        <?php else: ?>

            <!-- ── CVS Score + Radar (S-02) ─────────────────────── -->
            <div class="card card--result">
                <div class="cvs-score-header">
                    <span class="cvs-badge"><?= htmlspecialchars($result['recommendation']) ?></span>
                    <span class="cvs-number"><?= number_format((float) $result['cvs'], 1) ?> / 100</span>
                </div>

                <!-- S-02: radar chart canvas -->
                <div class="radar-wrapper">
                    <canvas id="pillarRadar" aria-label="Wykres radarowy filarów CVS" role="img"></canvas>
                </div>

                <h3>Sk&lstrok;adowe filary</h3>
                <table class="pillar-table">
                    <thead>
                        <tr>
                            <th>Filar</th>
                            <th>Wynik (0&ndash;100)</th>
                            <th>Waga</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $pillarMeta = [
                            'growth'   => ['label' => 'Wzrost vs własna trajektoria',  'weight' => '30%'],
                            'sector'   => ['label' => 'Benchmark sektorowy (EV/FCF)',   'weight' => '25%'],
                            'momentum' => ['label' => 'Momentum ceny (ROC vs SPY)',     'weight' => '25%'],
                            'quality'  => ['label' => 'Jakość fundamentalna',           'weight' => '20%'],
                        ];
                        foreach ($result['pillar_scores'] as $key => $score):
                            $meta = $pillarMeta[$key] ?? ['label' => $key, 'weight' => '—'];
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($meta['label']) ?></td>
                            <td>
                                <div class="pillar-bar">
                                    <div class="pillar-bar__fill" style="width:<?= round((float) $score) ?>%"></div>
                                    <span><?= number_format((float) $score, 1) ?></span>
                                </div>
                            </td>
                            <td class="pillar-weight"><?= htmlspecialchars($meta['weight']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p class="disclaimer-inline"><?= htmlspecialchars($result['disclaimer']) ?></p>
            </div>

            <?php if ($financials !== null): ?>
            <?php
            /* ── Derived metrics for S-03 raw data table ─────────────────── */
            $price   = isset($financials['current_price'])      ? (float) $financials['current_price']      : null;
            $shares  = isset($financials['shares_outstanding'])  ? (float) $financials['shares_outstanding'] : null;
            $debt    = (float) ($financials['total_debt']  ?? 0);
            $cash    = (float) ($financials['cash']        ?? 0);
            $ebitda  = isset($financials['ebitda'])              ? (float) $financials['ebitda']             : null;
            $fcf     = isset($financials['free_cash_flow'])      ? (float) $financials['free_cash_flow']     : null;
            $revenue = isset($financials['revenue'])             ? (float) $financials['revenue']            : null;
            $gm      = isset($financials['gross_margins'])       ? (float) $financials['gross_margins']      : null;

            $ev      = ($price !== null && $shares !== null && $shares > 0)
                       ? $price * $shares + $debt - $cash : null;
            $netDebt = $debt - $cash;
            $lever   = ($ebitda !== null && $ebitda > 0) ? $netDebt / $ebitda : null;
            $evFcf   = ($ev !== null && $fcf !== null && $fcf > 0) ? $ev / $fcf : null;
            $variant = ($fcf !== null && $fcf > 0) ? 'A (EV/FCF)' : 'B (EV/Sales)';

            $closes  = $financials['monthly_closes'] ?? [];
            $n       = count($closes);
            $roc6m   = null;
            $roc3m   = null;
            if ($n >= 7) {
                $now = $closes[$n - 1];
                $p6  = $closes[max(0, $n - 7)];
                $p3  = $closes[max(0, $n - 4)];
                if ($p6 > 0) { $roc6m = ($now / $p6 - 1.0) * 100.0; }
                if ($p3 > 0) { $roc3m = ($now / $p3 - 1.0) * 100.0; }
            }
            $pMin = $n > 0 ? min($closes) : null;
            $pMax = $n > 0 ? max($closes) : null;

            // Formatting helpers.
            $fmt = static function (?float $v, string $sfx = ''): string {
                if ($v === null) { return 'N/A'; }
                $a = abs($v);
                if ($a >= 1e12) { return number_format($v / 1e12, 2) . 'T' . ($sfx !== '' ? ' ' . $sfx : ''); }
                if ($a >= 1e9)  { return number_format($v / 1e9,  2) . 'B' . ($sfx !== '' ? ' ' . $sfx : ''); }
                if ($a >= 1e6)  { return number_format($v / 1e6,  2) . 'M' . ($sfx !== '' ? ' ' . $sfx : ''); }
                return number_format($v, 2) . ($sfx !== '' ? ' ' . $sfx : '');
            };
            $pct = static fn (?float $v): string =>
                $v === null ? 'N/A' : number_format($v * 100, 1) . '%';
            $mul = static fn (?float $v, int $d = 2): string =>
                $v === null ? 'N/A' : number_format($v, $d) . 'x';
            ?>

            <!-- ── S-03: Raw financial data panel ──────────────────────── -->
            <div class="card card--data">
                <h3>Dane wejściowe modelu</h3>
                <table class="data-table">
                    <tbody>

                        <tr class="data-table__section"><td colspan="2">Wycena</td></tr>
                        <tr><td>Enterprise Value</td>     <td><?= $fmt($ev, 'USD') ?></td></tr>
                        <tr><td>Free Cash Flow</td>       <td><?= $fmt($fcf, 'USD') ?></td></tr>
                        <tr><td>EV / FCF</td>             <td><?= $mul($evFcf) ?></td></tr>
                        <tr><td>Revenue</td>              <td><?= $fmt($revenue, 'USD') ?></td></tr>
                        <tr><td>Wariant modelu</td>       <td><?= htmlspecialchars($variant) ?></td></tr>

                        <tr class="data-table__section"><td colspan="2">Jakość fundamentalna</td></tr>
                        <tr><td>Gross Margin</td>         <td><?= $pct($gm) ?></td></tr>
                        <tr>
                            <td>Dźwignia (NetDebt / EBITDA)</td>
                            <td><?php
                                if ($lever !== null)                        { echo number_format($lever, 2) . 'x'; }
                                elseif ($ebitda !== null && $ebitda <= 0)  { echo 'ujemne EBITDA'; }
                                else                                        { echo 'N/A'; }
                            ?></td>
                        </tr>
                        <tr>
                            <td>Wzrost przychodów (YoY)</td>
                            <td><?= isset($financials['revenue_growth'])
                                    ? number_format((float) $financials['revenue_growth'] * 100, 1) . '%'
                                    : 'N/A' ?></td>
                        </tr>
                        <tr>
                            <td>Forward EPS</td>
                            <td><?= isset($financials['forward_eps'])
                                    ? number_format((float) $financials['forward_eps'], 2)
                                    : 'N/A' ?></td>
                        </tr>
                        <tr>
                            <td>Trailing EPS</td>
                            <td><?= isset($financials['trailing_eps'])
                                    ? number_format((float) $financials['trailing_eps'], 2)
                                    : 'N/A' ?></td>
                        </tr>

                        <tr class="data-table__section"><td colspan="2">Momentum ceny</td></tr>
                        <tr>
                            <td>Cena bieżąca</td>
                            <td><?= $price !== null ? '$' . number_format($price, 2) : 'N/A' ?></td>
                        </tr>
                        <tr>
                            <td>ROC 6M</td>
                            <td><?= $roc6m !== null ? number_format($roc6m, 1) . '%' : 'N/A' ?></td>
                        </tr>
                        <tr>
                            <td>ROC 3M</td>
                            <td><?= $roc3m !== null ? number_format($roc3m, 1) . '%' : 'N/A' ?></td>
                        </tr>
                        <tr>
                            <td>Zakres cen (hist. miesięczna)</td>
                            <td><?= ($pMin !== null && $pMax !== null)
                                    ? '$' . number_format($pMin, 2) . ' &ndash; $' . number_format($pMax, 2)
                                    : 'N/A' ?></td>
                        </tr>
                        <tr>
                            <td>Miesięcznych danych</td>
                            <td><?= $n > 0 ? $n . ' mies.' : 'N/A' ?></td>
                        </tr>

                        <tr class="data-table__section"><td colspan="2">Benchmark sektorowy</td></tr>
                        <tr>
                            <td>Sektor</td>
                            <td><?= htmlspecialchars($financials['sector'] ?? 'N/A') ?></td>
                        </tr>
                        <tr>
                            <td>Benchmark referencyjny</td>
                            <td><?= htmlspecialchars($financials['sector'] ?? 'DEFAULT') ?></td>
                        </tr>

                    </tbody>
                </table>
                <p class="disclaimer-inline"><?= htmlspecialchars($result['disclaimer']) ?></p>
            </div>
            <?php endif; // $financials !== null ?>

        <?php endif; // quality_gate ?>
    <?php endif; // $result !== null ?>

    <p class="back-link"><a href="/dashboard">&larr; Powr&oacute;t do panelu</a></p>
</section>

<?php if (!empty($result['quality_gate']) && !empty($result['pillar_scores'])): ?>
<script>
// S-02: Initialise radar chart.
// Wrapped in window.load so it runs after the Chart.js CDN script
// (which is appended at the bottom of layout.php, AFTER this template content).
window.addEventListener('load', function () {
    var ctx = document.getElementById('pillarRadar');
    if (!ctx || typeof Chart === 'undefined') { return; }

    new Chart(ctx, {
        type: 'radar',
        data: {
            labels: ['Wzrost', 'Benchmark sektorowy', 'Momentum', 'Jakość'],
            datasets: [{
                label: <?= json_encode($ticker, JSON_THROW_ON_ERROR) ?>,
                data:  <?= json_encode(array_values($result['pillar_scores']), JSON_THROW_ON_ERROR) ?>,
                backgroundColor:      'rgba(79, 142, 247, 0.15)',
                borderColor:          'rgba(79, 142, 247, 0.9)',
                borderWidth:          2,
                pointBackgroundColor: 'rgba(79, 142, 247, 1)',
                pointRadius:          4,
                pointHoverRadius:     6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                r: {
                    min: 0, max: 100,
                    ticks: {
                        stepSize: 25,
                        color: '#7a7f99',
                        backdropColor: 'transparent',
                        font: { size: 11 },
                    },
                    grid:        { color: 'rgba(42, 45, 58, 0.9)' },
                    angleLines:  { color: 'rgba(42, 45, 58, 0.9)' },
                    pointLabels: { color: '#dde1f0', font: { size: 12 } },
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (c) { return c.dataset.label + ': ' + Number(c.raw).toFixed(1); }
                    }
                }
            }
        }
    });
});
</script>
<?php endif; ?>
