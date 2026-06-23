<?php declare(strict_types=1);
/** @var array<int, array<string, mixed>> $evaluations */
/** @var array<string, array<int, array<string, mixed>>> $byTicker */
/** @var array{total: int, hits: int, misses: int, neutral: int, pending: int,
 *            hit_rate_pct: float|null, avg_change_pct: float|null} $stats */
/** @var int $horizon */
/** @var int[] $horizons */
/** @var string|null $trackingStart */

$resultChip = static function (string $result): string {
    return match ($result) {
        'hit'     => '<span class="signal-pill signal-pill--strong">✓ Trafna</span>',
        'miss'    => '<span class="signal-pill" style="background:rgba(239,68,68,.15);color:#ef4444;">✗ Błąd</span>',
        'neutral' => '<span class="signal-pill" style="background:rgba(90,117,149,.15);color:var(--c-muted);">Neutralna</span>',
        default   => '—',
    };
};
?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;">
    <h1 style="margin:0;">Track Record modelu CVS</h1>

    <!-- Horizon selector -->
    <div style="display:flex;gap:.4rem;align-items:center;">
        <span style="font-size:var(--text-sm);color:var(--c-muted);">Horyzont:</span>
        <?php foreach ($horizons as $h): ?>
        <a href="/track-record?days=<?= $h ?>"
           class="btn btn--sm <?= $h === $horizon ? 'btn--primary' : 'btn--ghost' ?>">
            <?= $h ?> dni
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Summary stats -->
<div style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem;">
    <?php
    $statCards = [
        ['Ocen',        $stats['total'],           ''],
        ['Trafnych',    $stats['hits'],             ' style="color:var(--c-success);"'],
        ['Błędów',      $stats['misses'],           ' style="color:var(--c-danger);"'],
        ['% Trafności', $stats['hit_rate_pct'] !== null ? $stats['hit_rate_pct'] . '%' : '—', ''],
        ['Śr. zmiana',  $stats['avg_change_pct'] !== null ? ($stats['avg_change_pct'] > 0 ? '+' : '') . $stats['avg_change_pct'] . '%' : '—', ''],
    ];
    foreach ($statCards as [$label, $value, $style]):
    ?>
    <div class="card" style="flex:1;min-width:110px;text-align:center;padding:1rem;">
        <div style="font-size:var(--text-2xl);font-weight:700;<?= ltrim($style, ' style="') ?> margin-bottom:.25rem;">
            <?= htmlspecialchars((string) $value) ?>
        </div>
        <div style="font-size:var(--text-xs);color:var(--c-muted);"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
</div>

<?php if (empty($evaluations)): ?>
<div class="card" style="text-align:center;padding:2rem;">
    <p style="color:var(--c-muted);margin-bottom:.5rem;">
        Brak ocenionych rekomendacji dla horyzontu <?= $horizon ?> dni.
    </p>
    <p style="color:var(--c-muted);font-size:var(--text-sm);">
        <?php if (!empty($trackingStart)): ?>
        Snapshoty bieżącej wersji modelu zbierane są od <?= htmlspecialchars((new DateTimeImmutable($trackingStart))->format('d.m.Y')) ?>.
        Pierwsze oceny dla tego horyzontu pojawią się ~<?= $horizon ?> dni po starcie, tj. ok.
        <strong><?= (new DateTimeImmutable($trackingStart))->modify("+{$horizon} days")->format('d.m.Y') ?></strong>.
        <?php else: ?>
        Brak snapshotów bieżącej wersji modelu — oceny pojawią się po pierwszym przebiegu re-scoringu.
        <?php endif; ?>
    </p>
</div>
<?php else: ?>

<!-- Evaluations table -->
<div class="card" style="overflow-x:auto;">
    <table class="pillar-table" style="width:100%;">
        <thead>
            <tr>
                <th>Ticker</th>
                <th>Data snapshotu</th>
                <th>CVS Swing</th>
                <th>Rekomendacja</th>
                <th>Cena wtedy</th>
                <th>Cena teraz</th>
                <th>Zmiana %</th>
                <th>Wynik</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($evaluations as $row):
            $change = $row['price_change_pct'] !== null ? (float) $row['price_change_pct'] : null;
            $changeStr = $change !== null ? ($change >= 0 ? '+' : '') . number_format($change, 1) . '%' : '—';
            $changeColor = $change !== null ? ($change >= 0 ? 'color:var(--c-success)' : 'color:var(--c-danger)') : '';
        ?>
        <tr>
            <td>
                <a href="/track-record/<?= urlencode((string) $row['ticker']) ?>"
                   style="font-weight:700;color:var(--c-fund);">
                    <?= htmlspecialchars((string) $row['ticker']) ?>
                </a>
            </td>
            <td style="color:var(--c-muted);font-size:var(--text-sm);"><?= htmlspecialchars((string) $row['score_date']) ?></td>
            <td><strong><?= $row['cvs_swing'] !== null ? number_format((float) $row['cvs_swing'], 1) : '—' ?></strong></td>
            <td style="font-size:var(--text-sm);"><?= htmlspecialchars((string) ($row['reco_swing'] ?? '—')) ?></td>
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
