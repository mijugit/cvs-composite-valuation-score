<?php declare(strict_types=1);
/** @var array<int, array<string, mixed>> $evaluations */
/** @var array<string, array<string, mixed>> $tickerSummaries Per-ticker: total/hits/misses/neutral/hit_rate_pct/avg_change_pct/delta/rows */
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

$deltaChip = static function (?float $delta): string {
    if ($delta === null || $delta === 0.0) {
        return '<span class="tr-delta--flat">→ b/d</span>';
    }
    return $delta > 0
        ? '<span class="tr-delta--up">▲ +' . number_format($delta, 1) . 'pp</span>'
        : '<span class="tr-delta--down">▼ ' . number_format($delta, 1) . 'pp</span>';
};

// Hover hint: friendly company name + CVS Swing/Fund — same content shape as
// the dashboard watchlist chip tooltip.
$recoColor = static function (?string $reco): string {
    return match (true) {
        $reco === null                        => 'color:var(--c-muted);',
        str_contains($reco, 'SILNE KUPUJ')     => 'color:var(--c-success);',
        str_contains($reco, 'AKUMULUJ')        => 'color:var(--c-primary);',
        str_contains($reco, 'REDUKUJ')         => 'color:var(--c-warn);',
        str_contains($reco, 'UNIKAJ')          => 'color:var(--c-danger);',
        default                                => 'color:var(--c-muted);',
    };
};

$tickerHint = static function (string $ticker, ?array $info) use ($recoColor): string {
    $name  = $info['companyName'] ?? null;
    $swing = $info['cvsSwing']    ?? null;
    $fund  = $info['cvsFund']     ?? null;
    if ($name === null && $swing === null && $fund === null) {
        return '';
    }

    $html = '<span class="ticker-hint__tooltip"><strong>' . htmlspecialchars($name ?? $ticker) . '</strong>';
    if ($swing !== null || $fund !== null) {
        $html .= '<span class="ticker-hint__tooltip-scores">';
        if ($swing !== null) {
            $html .= '<span style="' . $recoColor($info['recoSwing'] ?? null) . '">CVS Swing ' . number_format($swing, 1) . '</span>';
        }
        if ($fund !== null) {
            $html .= '<span style="' . $recoColor($info['recoFund'] ?? null) . '">CVS Fund ' . number_format($fund, 1) . '</span>';
        }
        $html .= '</span>';
    }
    $html .= '</span>';
    return $html;
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

<!-- Per-ticker accordion -->
<div class="card" style="overflow-x:auto;padding:0;">
    <table class="pillar-table" style="width:100%;">
        <thead>
            <tr>
                <th>Ticker</th>
                <th>Ocen</th>
                <th>Trafnych</th>
                <th>Błędów</th>
                <th>% Trafności</th>
                <th>
                    Δ Trafność
                    <span class="chart-hint" tabindex="0">ⓘ
                        <span class="chart-hint__tooltip">
                            Porównanie trafności dwóch pasm: ocen, które dopiero co „dojrzały" w tym
                            horyzoncie ([<?= $horizon ?>, <?= $horizon * 2 ?>) dni), względem starszej,
                            ustalonej historii (≥<?= $horizon * 2 ?> dni). Dodatnia = model ostatnio
                            trafia częściej niż wcześniej.
                        </span>
                    </span>
                </th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($tickerSummaries as $ticker => $summary): ?>
        <tr class="tr-summary-row" data-ticker="<?= htmlspecialchars($ticker) ?>">
            <td>
                <span class="tr-summary-row__arrow">▶</span>
                <span class="ticker-hint">
                    <a href="/track-record/<?= urlencode($ticker) ?>" style="font-weight:700;color:var(--c-fund);">
                        <?= htmlspecialchars($ticker) ?>
                    </a>
                    <?= $tickerHint($ticker, $summary['info'] ?? null) ?>
                </span>
            </td>
            <td><?= (int) $summary['total'] ?></td>
            <td style="color:var(--c-success);"><?= (int) $summary['hits'] ?></td>
            <td style="color:var(--c-danger);"><?= (int) $summary['misses'] ?></td>
            <td><strong><?= $summary['hit_rate_pct'] !== null ? number_format((float) $summary['hit_rate_pct'], 1) . '%' : '—' ?></strong></td>
            <td><?= $deltaChip($summary['delta']) ?></td>
        </tr>
        <?php foreach ($summary['rows'] as $row):
            $change = $row['price_change_pct'] !== null ? (float) $row['price_change_pct'] : null;
            $changeStr = $change !== null ? ($change >= 0 ? '+' : '') . number_format($change, 1) . '%' : '—';
            $changeColor = $change !== null ? ($change >= 0 ? 'color:var(--c-success)' : 'color:var(--c-danger)') : '';
        ?>
        <tr class="tr-detail-row" data-ticker="<?= htmlspecialchars($ticker) ?>" hidden>
            <td colspan="2" style="color:var(--c-muted);"><?= htmlspecialchars((string) $row['score_date']) ?></td>
            <td colspan="2">
                <?= $row['cvs_swing'] !== null ? number_format((float) $row['cvs_swing'], 1) : '—' ?>
                &nbsp;·&nbsp; <?= htmlspecialchars((string) ($row['reco_swing'] ?? '—')) ?>
            </td>
            <td>
                $<?= $row['price_then'] !== null ? number_format((float) $row['price_then'], 2) : '—' ?>
                → $<?= $row['price_now'] !== null ? number_format((float) $row['price_now'], 2) : '—' ?>
                &nbsp; <span style="<?= $changeColor ?>;font-weight:600;"><?= $changeStr ?></span>
            </td>
            <td><?= $resultChip($row['result'] ?? 'neutral') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php endif; ?>

<p class="disclaimer-inline" style="margin-top:1.5rem;">
    Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.
</p>
