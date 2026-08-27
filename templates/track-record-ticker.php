<?php declare(strict_types=1);

use CVS\Logo\TickerLogoPresenter;

/** @var string $ticker */
/** @var string|null $companyName */
/** @var array{domain: string|null, logo_path: string|null, status: string}|null $tickerLogo */
/** @var array<int, array<string, mixed>> $evaluations enriched with 'result' */
/** @var array<int, array<string, mixed>> $all all snapshots for chart */
/** @var array{total: int, hits: int, misses: int, neutral: int, pending: int,
 *            hit_rate_pct: float|null, avg_change_pct: float|null} $stats */
/** @var int $horizon */
/** @var int[] $horizons */

$resultChip = static function (string $result): string {
    return match ($result) {
        'hit'     => '<span class="signal-pill signal-pill--strong">✓ Trafna</span>',
        'miss'    => '<span class="signal-pill" style="background:rgba(239,68,68,.15);color:#ef4444;">✗ Błąd</span>',
        'neutral' => '<span class="signal-pill" style="background:rgba(90,117,149,.15);color:var(--c-muted);">Neutralna</span>',
        default   => '—',
    };
};
?>

<div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem;">
    <h1 style="margin:0;"><?= TickerLogoPresenter::render($ticker, $companyName, $tickerLogo) ?>Historia CVS: <?= htmlspecialchars($ticker) ?></h1>
    <a href="/analysis/<?= urlencode($ticker) ?>" class="btn btn--ghost btn--sm">← Analiza</a>
    <a href="/track-record" class="btn btn--ghost btn--sm">← Track Record</a>
</div>

<!-- Horizon selector -->
<div style="display:flex;gap:.4rem;align-items:center;margin-bottom:1.25rem;">
    <span style="font-size:var(--text-sm);color:var(--c-muted);">Horyzont oceny:</span>
    <?php foreach ($horizons as $h): ?>
    <a href="/track-record/<?= urlencode($ticker) ?>?days=<?= $h ?>"
       class="btn btn--sm <?= $h === $horizon ? 'btn--primary' : 'btn--ghost' ?>">
        <?= $h ?> dni
    </a>
    <?php endforeach; ?>
</div>

<!-- CVS history chart -->
<?php if (!empty($all)): ?>
<div class="card" style="margin-bottom:1.5rem;">
    <h3 style="margin-bottom:.75rem;font-size:var(--text-base);">Historia wyników CVS</h3>
    <div style="position:relative;height:220px;">
        <canvas id="cvs-history-chart"></canvas>
    </div>
</div>
<script>
window.addEventListener('load', function () {
    if (typeof Chart === 'undefined') return;
    var ctx = document.getElementById('cvs-history-chart');
    if (!ctx) return;

    var data = <?= json_encode(array_values($all)) ?>;
    var labels  = data.map(function(r){ return r.score_date; });
    var swing   = data.map(function(r){ return r.cvs_swing !== null ? parseFloat(r.cvs_swing) : null; });
    var fund    = data.map(function(r){ return r.cvs_fund  !== null ? parseFloat(r.cvs_fund)  : null; });

    new Chart(ctx.getContext('2d'), {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Swing',
                    data: swing,
                    borderColor: 'rgba(64, 144, 224, 0.9)',
                    backgroundColor: 'transparent',
                    pointRadius: 4, borderWidth: 2, spanGaps: true,
                },
                {
                    label: 'Fundamentalny',
                    data: fund,
                    borderColor: 'rgba(250, 204, 21, 0.9)',
                    backgroundColor: 'transparent',
                    pointRadius: 4, borderWidth: 2, spanGaps: true,
                },
            ],
        },
        options: {
            animation: false, responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', labels: { color: 'rgba(255,255,255,.7)', boxWidth: 12, font: { size: 11 } } },
            },
            scales: {
                x: { grid: { color: 'rgba(128,128,128,.08)' }, ticks: { color: 'rgba(255,255,255,.45)', font: { size: 10 } } },
                y: {
                    min: 0, max: 100,
                    grid: { color: 'rgba(128,128,128,.08)' },
                    ticks: { color: 'rgba(255,255,255,.45)', font: { size: 10 } },
                },
            },
        },
    });
});
</script>
<?php endif; ?>

<!-- Evaluations table -->
<?php if (empty($evaluations)): ?>
<div class="card" style="text-align:center;padding:2rem;">
    <p style="color:var(--c-muted);">
        Brak ocenionych rekomendacji dla horyzontu <?= $horizon ?> dni.<br>
        <small>Snapshoty zebrane: <?= count($all) ?>. Pierwsze oceny po <?= $horizon ?> dniach od pierwszego snapshotu.</small>
    </p>
</div>
<?php else: ?>
<div class="card" style="overflow-x:auto;">
    <h3 style="margin-bottom:.75rem;font-size:var(--text-base);">
        Oceny rekomendacji (horyzont <?= $horizon ?> dni)
        — <?= $stats['hits'] ?> trafnych / <?= $stats['hits'] + $stats['misses'] ?> ocenionych
        <?= $stats['hit_rate_pct'] !== null ? '(' . $stats['hit_rate_pct'] . '%)' : '' ?>
    </h3>
    <table class="pillar-table" style="width:100%;">
        <thead>
            <tr>
                <th>Data snapshotu</th>
                <th>CVS Swing</th>
                <th>CVS Fund</th>
                <th>Rekomendacja</th>
                <th>Złoty sygnał</th>
                <th>Cena wtedy</th>
                <th>Cena teraz</th>
                <th>Zmiana %</th>
                <th>Wynik</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($evaluations as $row):
            $change = $row['price_change_pct'] !== null ? (float) $row['price_change_pct'] : null;
            $changeStr   = $change !== null ? ($change >= 0 ? '+' : '') . number_format($change, 1) . '%' : '—';
            $changeColor = $change !== null ? ($change >= 0 ? 'color:var(--c-success)' : 'color:var(--c-danger)') : '';
        ?>
        <tr>
            <td style="color:var(--c-muted);font-size:var(--text-sm);"><?= htmlspecialchars((string) $row['score_date']) ?></td>
            <td><strong><?= $row['cvs_swing'] !== null ? number_format((float) $row['cvs_swing'], 1) : '—' ?></strong></td>
            <td><?= $row['cvs_fund']  !== null ? number_format((float) $row['cvs_fund'],  1) : '—' ?></td>
            <td style="font-size:var(--text-sm);"><?= htmlspecialchars((string) ($row['reco_swing'] ?? '—')) ?></td>
            <td>
                <?php if (!empty($row['golden_signal'])): ?>
                <span class="signal-pill signal-pill--<?= htmlspecialchars((string) $row['golden_signal']) ?>">
                    <?= $row['golden_signal'] === 'strong' ? '⭐⭐' : '⭐' ?>
                </span>
                <?php else: ?>—<?php endif; ?>
            </td>
            <td>$<?= $row['price_then'] !== null ? number_format((float) $row['price_then'], 2) : '—' ?></td>
            <td>$<?= $row['price_now']  !== null ? number_format((float) $row['price_now'],  2) : '—' ?></td>
            <td style="<?= $changeColor ?>;font-weight:600;"><?= $changeStr ?></td>
            <td><?= $resultChip($row['result'] ?? 'neutral') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<p class="disclaimer-inline" style="margin-top:1.5rem;">
    Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.
</p>
