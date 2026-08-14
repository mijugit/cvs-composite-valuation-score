<?php declare(strict_types=1);
/**
 * S-03: Full rebalance history page — /portfolio/history
 *
 * @var array<int, array<string, mixed>>             $completed           Completed cycles (newest-first), each with 'pnl_delta' key
 * @var array<int, list<array<string, mixed>>>       $transactionsByCycle Transactions keyed by cycle_id
 * @var array<int, array<string, mixed>>             $operational         Non-completed cycles (failed / llm_failed / started)
 * @var bool                                         $hasMore             True when more completed cycles exist beyond current window
 * @var int                                          $nextShow            ?show= value for the "Pokaż starsze" link
 */

$fmt    = static fn(float $v): string => '$' . number_format($v, 2, '.', ' ');
$fmtPct = static fn(float $v): string => ($v >= 0 ? '+' : '') . number_format($v, 2, '.', '') . '%';

$statusChip = static function (?string $status): string {
    return match ($status) {
        'completed'     => '<span class="signal-pill signal-pill--strong">&#10003; Zakończony</span>',
        'llm_failed'    => '<span class="signal-pill signal-pill--danger">&#10005; Błąd LLM</span>',
        'failed'        => '<span class="signal-pill signal-pill--danger">&#10005; Błąd</span>',
        'started'       => '<span class="signal-pill signal-pill--momentum">&#8635; W toku</span>',
        'market_closed' => '<span class="signal-pill signal-pill--neutral">&mdash; Rynek zamknięty</span>',
        default         => '<span class="signal-pill signal-pill--neutral">' . htmlspecialchars((string) $status) . '</span>',
    };
};
?>

<!-- ─── Page header ────────────────────────────────────────── -->
<div style="margin-bottom:1.5rem;">
    <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem;">
        <a href="/portfolio" style="font-size:var(--text-sm);color:var(--c-muted);">&larr; Portfel</a>
    </div>
    <h1 style="margin:0 0 .25rem;">Historia rebalansów</h1>
    <p style="color:var(--c-muted);font-size:var(--text-sm);margin:0;">
        Pełna historia autonomicznych decyzji modelu CVS + LLM
    </p>
</div>

<?php if (empty($completed) && empty($operational)): ?>
<!-- ─── Empty state ──────────────────────────────────────── -->
<div class="card" style="padding:2rem;text-align:center;color:var(--c-muted);">
    <p style="margin:0 0 .5rem;">Brak historii rebalansów.</p>
    <p style="margin:0;font-size:var(--text-sm);">Pierwszy autonomiczny cykl jeszcze nie wystąpił.</p>
</div>

<?php else: ?>

<!-- ─── Completed cycles timeline ─────────────────────────── -->
<?php if (!empty($completed)): ?>
<h2 style="font-size:1rem;font-weight:600;margin:0 0 .75rem;">
    Historia rebalansów
    <span style="font-weight:400;color:var(--c-muted);font-size:var(--text-sm);">(<?= count($completed) ?> widocznych)</span>
</h2>

<div style="display:flex;flex-direction:column;gap:.75rem;margin-bottom:1.5rem;">
<?php foreach ($completed as $cycle):
    $cycleId   = (int) $cycle['id'];
    $txList    = $transactionsByCycle[$cycleId] ?? [];
    $val       = isset($cycle['portfolio_value_usd']) ? (float) $cycle['portfolio_value_usd'] : null;
    $delta     = isset($cycle['pnl_delta']) && $cycle['pnl_delta'] !== null ? (float) $cycle['pnl_delta'] : null;
    $execCount = (int) ($cycle['executed_count'] ?? 0);
    $skipCount = (int) ($cycle['skipped_count'] ?? 0);
?>
<details class="card cycle-card">
    <summary>
        <div class="cycle-card__summary">
            <div class="cycle-card__meta">
                <strong><?= htmlspecialchars((string) $cycle['cycle_date']) ?></strong>
                <?= $statusChip((string) ($cycle['status'] ?? '')) ?>
                <?php if ($execCount > 0): ?>
                <span style="font-size:var(--text-sm);color:var(--c-muted);"><?= $execCount ?> transakcji</span>
                <?php endif; ?>
                <?php if ($skipCount > 0): ?>
                <span style="font-size:var(--text-sm);color:var(--c-muted);"><?= $skipCount ?> pominięto</span>
                <?php endif; ?>
            </div>
            <div class="cycle-card__value">
                <?php if ($val !== null): ?>
                <span style="font-weight:600;"><?= $fmt($val) ?></span>
                <?php endif; ?>
                <?php if ($delta !== null): ?>
                <span class="cycle-delta cycle-delta--<?= $delta >= 0 ? 'pos' : 'neg' ?>">
                    <?= ($delta >= 0 ? '+' : '') . $fmt(abs($delta)) ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
    </summary>

    <div class="cycle-card__body">
        <?php if (!empty($cycle['notes'])): ?>
        <p class="cycle-card__notes"><?= htmlspecialchars((string) $cycle['notes']) ?></p>
        <?php endif; ?>

        <?php if (empty($txList)): ?>
        <p style="color:var(--c-muted);font-size:var(--text-sm);margin:0;">Brak zapisanych transakcji dla tego cyklu.</p>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table class="pillar-table cycle-tx-table">
                <thead>
                    <tr>
                        <th>Ticker</th>
                        <th>Akcja</th>
                        <th style="text-align:right;">Ilość</th>
                        <th style="text-align:right;">Cena</th>
                        <th>Uzasadnienie</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($txList as $tx):
                        $action = strtoupper((string) ($tx['action'] ?? ''));
                        $actionColor = match ($action) {
                            'BUY'  => 'var(--c-success)',
                            'SELL' => 'var(--c-danger)',
                            default => 'var(--c-muted)',
                        };
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars((string) $tx['ticker']) ?></strong></td>
                        <td style="color:<?= $actionColor ?>;font-weight:600;font-size:var(--text-sm);">
                            <?= htmlspecialchars($action) ?>
                        </td>
                        <td style="text-align:right;color:var(--c-muted);">
                            <?= $tx['quantity'] !== null ? (int) $tx['quantity'] : '—' ?>
                        </td>
                        <td style="text-align:right;color:var(--c-muted);">
                            <?= $tx['price_usd'] !== null ? $fmt((float) $tx['price_usd']) : '—' ?>
                        </td>
                        <td class="cycle-tx__reason">
                            <?= $tx['reason'] !== null && $tx['reason'] !== ''
                                ? htmlspecialchars((string) $tx['reason'])
                                : '<span style="color:var(--c-muted)">—</span>' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if (!empty($cycle['finished_at'])): ?>
        <p style="font-size:var(--text-xs);color:var(--c-muted);margin:.75rem 0 0;">
            Zakończono: <?= htmlspecialchars((string) $cycle['finished_at']) ?> UTC
        </p>
        <?php endif; ?>
    </div>
</details>
<?php endforeach; ?>
</div>

<?php if ($hasMore): ?>
<div style="text-align:center;margin-bottom:2rem;">
    <a href="/portfolio/history?show=<?= $nextShow ?>" class="btn btn--secondary">
        Pokaż starsze &darr;
    </a>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ─── Operational events ────────────────────────────────── -->
<?php if (!empty($operational)): ?>
<details class="card" style="margin-bottom:1.5rem;">
    <summary style="cursor:pointer;padding:.1rem 0;display:flex;align-items:center;gap:.5rem;">
        <span style="font-size:1rem;font-weight:600;">Zdarzenia operacyjne</span>
        <span style="font-size:var(--text-sm);color:var(--c-muted);">(<?= count($operational) ?>)</span>
    </summary>

    <div style="margin-top:1rem;">
        <p style="font-size:var(--text-sm);color:var(--c-muted);margin:0 0 .75rem;">
            Cykle nieukończone — błędy LLM, przekroczenia czasu, próby w toku. Nie wpływają na wycenę portfela.
        </p>
        <div style="display:flex;flex-direction:column;gap:.5rem;">
        <?php foreach ($operational as $op): ?>
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:.6rem;padding:.6rem .75rem;background:var(--c-bg);border-radius:var(--radius);">
            <span style="font-weight:600;font-size:var(--text-sm);"><?= htmlspecialchars((string) $op['cycle_date']) ?></span>
            <?= $statusChip((string) ($op['status'] ?? '')) ?>
            <?php if (!empty($op['llm_failure_kind'])): ?>
            <span style="font-size:var(--text-xs);color:var(--c-danger);">
                <?= htmlspecialchars((string) $op['llm_failure_kind']) ?>
            </span>
            <?php endif; ?>
            <?php if ((int) ($op['attempt_count'] ?? 1) > 1): ?>
            <span style="font-size:var(--text-xs);color:var(--c-muted);">
                Próba <?= (int) $op['attempt_count'] ?>
            </span>
            <?php endif; ?>
            <?php if (!empty($op['notes'])): ?>
            <span style="font-size:var(--text-xs);color:var(--c-muted);flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                  title="<?= htmlspecialchars((string) $op['notes'], ENT_QUOTES) ?>">
                <?= htmlspecialchars((string) $op['notes']) ?>
            </span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</details>
<?php endif; ?>

<?php endif; ?>

<p class="disclaimer-inline" style="margin-top:1.5rem;font-size:var(--text-xs);color:var(--c-muted);">
    Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.
</p>
