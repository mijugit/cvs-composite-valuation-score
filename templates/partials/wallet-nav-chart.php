<?php declare(strict_types=1);

/**
 * Shared NAV comparison chart for the two autonomous LLM wallets — reused by
 * templates/portfolio.php and templates/llm-free.php (change: wallet-nav-chart).
 * Same visual pattern as /lab's NAV chart (canvas + Chart.js line, .chart-
 * zoom-target zoom modal) so a user switching between /lab, /portfolio and
 * /llm-free sees one consistent chart language.
 *
 * @var array<string, list<array{date: string, value: float}>> $chartSeries series label => base=100 points, from CVS\Charts\WalletNavChartService
 * @var string|null $chartD0 earliest date across the two wallets, or null when neither has history yet
 */

$walletChartPalette = [
    'LLM Bazowy' => 'rgba(64,144,224,0.9)',
    'LLM Free'   => 'rgba(250,204,21,0.9)',
    'S&P 500'    => 'rgba(148,163,184,0.85)',
    'Nasdaq 100' => 'rgba(167,139,250,0.9)',
];
?>

<?php if ($chartD0 !== null && $chartSeries !== []): ?>
<div class="card" style="margin-bottom:1.5rem;">
    <h3 style="margin-bottom:.75rem;font-size:var(--text-base);">
        Wartość portfela, baza=100 od <?= htmlspecialchars($chartD0) ?>
    </h3>
    <div class="chart-zoom-target" style="position:relative;height:320px;"
         data-zoom-canvas="wallet-nav-chart" data-zoom-title="Wartość portfela, baza=100 od <?= htmlspecialchars($chartD0) ?>">
        <span class="chart-zoom-target__hint" aria-hidden="true">🔍</span>
        <canvas id="wallet-nav-chart"></canvas>
    </div>
</div>

<!-- Chart zoom modal — same shared .chart-zoom-target click handler in app.js
     as /lab and the analysis page; each page carries its own copy of this
     markup (never two on the same page), see app.js's own comment on why. -->
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

<script>
window.addEventListener('load', function () {
    if (typeof Chart === 'undefined') return;
    var ctx = document.getElementById('wallet-nav-chart');
    if (!ctx) return;

    var chartSeries = <?= json_encode($chartSeries) ?>;
    var palette = <?= json_encode($walletChartPalette) ?>;

    var allDates = [];
    Object.keys(chartSeries).forEach(function (label) {
        chartSeries[label].forEach(function (p) { if (allDates.indexOf(p.date) === -1) allDates.push(p.date); });
    });
    allDates.sort();

    var datasets = Object.keys(chartSeries).map(function (label) {
        var byDate = {};
        chartSeries[label].forEach(function (p) { byDate[p.date] = p.value; });
        return {
            label: label,
            data: allDates.map(function (d) { return byDate.hasOwnProperty(d) ? byDate[d] : null; }),
            borderColor: palette[label] || 'rgba(255,255,255,0.6)',
            backgroundColor: 'transparent',
            pointRadius: 0, borderWidth: 2, spanGaps: true,
        };
    });

    new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: { labels: allDates, datasets: datasets },
        options: {
            animation: false, responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', labels: { color: 'rgba(255,255,255,.7)', boxWidth: 12, font: { size: 11 } } },
            },
            scales: {
                x: { grid: { color: 'rgba(128,128,128,.08)' }, ticks: { color: 'rgba(255,255,255,.45)', font: { size: 10 } } },
                y: { grid: { color: 'rgba(128,128,128,.08)' }, ticks: { color: 'rgba(255,255,255,.45)', font: { size: 10 } } },
            },
        },
    });
});
</script>
<?php endif; ?>
