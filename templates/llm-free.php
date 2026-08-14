<?php declare(strict_types=1);
/**
 * LLM_Free_Wallet — read-only view.
 *
 * @var array<string, mixed>              $state          llm_free_state row (cash, initial_capital, updated_at)
 * @var array<int, array<string, mixed>>  $holdings       enriched holdings (ticker, quantity, avg_entry_price, live_price, price_is_snapshot, value_usd)
 * @var float                             $totalValue     cash + sum(holdings value_usd)
 * @var array<string, mixed>              $walletConfig   config/llm-free-wallet.php
 * @var array<int, array{cycle_date: string, legend: string}> $legendHistory newest-first
 */

$cash           = (float) $state['cash'];
$initialCapital = (float) ($walletConfig['initial_capital_usd'] ?? 10000.0);
$pnl            = $totalValue - $initialCapital;
$pnlPct         = $initialCapital > 0 ? ($pnl / $initialCapital) * 100.0 : 0.0;

$fmt    = static fn(float $v): string => '$' . number_format($v, 2, '.', ' ');
$fmtPct = static fn(float $v): string => ($v >= 0 ? '+' : '') . number_format($v, 2, '.', '') . '%';
?>

<div style="margin-bottom:1.5rem;">
    <h1 style="margin:0 0 .25rem;">LLM Free</h1>
    <p style="color:var(--c-muted);font-size:var(--text-sm);margin:0;">
        Drugi portfel wirtualny &mdash; ten sam model CVS jako źródło sygnałów, ale bez twardych reguł:
        model interpretuje dane sam, może się nie zgodzić z rekomendacją, i musi uzasadnić swoje rozumowanie.
    </p>
</div>

<!-- ─── Summary cards ─────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;">

    <div class="card" style="padding:1.25rem;">
        <p style="font-size:var(--text-xs);color:var(--c-muted);margin:0 0 .25rem;text-transform:uppercase;letter-spacing:.05em;">Gotówka</p>
        <p style="font-size:1.5rem;font-weight:700;margin:0;"><?= $fmt($cash) ?></p>
        <p style="font-size:var(--text-xs);color:var(--c-muted);margin:.25rem 0 0;">USD — dostępna gotówka</p>
    </div>

    <div class="card" style="padding:1.25rem;">
        <p style="font-size:var(--text-xs);color:var(--c-muted);margin:0 0 .25rem;text-transform:uppercase;letter-spacing:.05em;">Wycena portfela</p>
        <p style="font-size:1.5rem;font-weight:700;margin:0;"><?= $fmt($totalValue) ?></p>
        <p style="font-size:var(--text-xs);color:var(--c-muted);margin:.25rem 0 0;">cash + pozycje</p>
    </div>

    <div class="card" style="padding:1.25rem;">
        <p style="font-size:var(--text-xs);color:var(--c-muted);margin:0 0 .25rem;text-transform:uppercase;letter-spacing:.05em;">Wynik vs start</p>
        <p style="font-size:1.5rem;font-weight:700;margin:0;color:<?= $pnl >= 0 ? 'var(--c-success)' : 'var(--c-danger)' ?>;">
            <?= $fmtPct($pnlPct) ?>
        </p>
        <p style="font-size:var(--text-xs);color:var(--c-muted);margin:.25rem 0 0;"><?= $fmt(abs($pnl)) ?> <?= $pnl >= 0 ? 'zysku' : 'straty' ?> vs <?= $fmt($initialCapital) ?></p>
    </div>

</div>

<!-- ─── Holdings ──────────────────────────────────────────────── -->
<h2 style="font-size:1rem;font-weight:600;margin:0 0 .75rem;">Pozycje</h2>

<?php if (empty($holdings)): ?>
<div class="card" style="padding:1.5rem;text-align:center;color:var(--c-muted);">
    <p style="margin:0;">Portfel w 100% gotówkowy. Brak otwartych pozycji.</p>
</div>
<?php else: ?>
<div class="card" style="overflow-x:auto;margin-bottom:1.5rem;">
    <table class="pillar-table" style="width:100%;">
        <thead>
            <tr>
                <th>Ticker</th>
                <th>Ilość</th>
                <th style="text-align:right;">Cena zakupu</th>
                <th style="text-align:right;">Cena rynkowa</th>
                <th style="text-align:right;">Wartość</th>
                <th style="text-align:right;">Wynik</th>
                <th style="text-align:right;">% portfela</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($holdings as $h): ?>
            <?php
                $pctPortfolio = $totalValue > 0 ? ($h['value_usd'] / $totalValue * 100.0) : 0.0;
                $isLive       = !empty($h['price_is_live']);
                $pnlRow       = (float) ($h['pnl_pct'] ?? 0.0);
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($h['ticker']) ?></strong></td>
                <td><?= (int) $h['quantity'] ?></td>
                <td style="text-align:right;color:var(--c-muted);"><?= $fmt((float) $h['avg_entry_price']) ?></td>
                <td style="text-align:right;">
                    <?= $fmt((float) $h['live_price']) ?>
                    <span class="px-badge px-badge--<?= $isLive ? 'live' : 'stale' ?>"
                          title="<?= $isLive ? 'Kurs pobrany na żywo' : 'Ostatnia znana wycena (API niedostępne)' ?>">
                        <?= $isLive ? 'live' : 'wycena' ?>
                    </span>
                </td>
                <td style="text-align:right;font-weight:600;"><?= $fmt((float) $h['value_usd']) ?></td>
                <td style="text-align:right;font-weight:600;color:<?= $pnlRow >= 0 ? 'var(--c-success)' : 'var(--c-danger)' ?>;">
                    <?= ($pnlRow >= 0 ? '+' : '') . number_format($pnlRow, 1) ?>%
                </td>
                <td style="text-align:right;color:var(--c-muted);"><?= number_format($pctPortfolio, 1) ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ─── Legend history — the model's own investment thesis ──────── -->
<h2 style="font-size:1rem;font-weight:600;margin:0 0 .75rem;">Legenda &mdash; rozumowanie modelu</h2>

<?php if (empty($legendHistory)): ?>
<div class="card" style="padding:1.5rem;text-align:center;color:var(--c-muted);">
    <p style="margin:0;">Pierwszy cykl jeszcze się nie odbył. Legenda pojawi się po pierwszym rebalansie.</p>
</div>
<?php else: ?>
<?php /* Same collapsible pattern as /portfolio/history's .cycle-card (native
   <details>, no JS needed) — newest entry open by default, older ones
   collapsed, so a growing history doesn't turn into a wall of paragraphs. */ ?>
<div style="display:flex;flex-direction:column;gap:.75rem;">
    <?php foreach ($legendHistory as $i => $entry): ?>
    <details class="card cycle-card"<?= $i === 0 ? ' open' : '' ?>>
        <summary>
            <div class="cycle-card__summary">
                <div class="cycle-card__meta">
                    <strong><?= htmlspecialchars((string) $entry['cycle_date']) ?></strong>
                </div>
            </div>
        </summary>
        <div class="cycle-card__body">
            <p style="margin:0;line-height:1.6;"><?= nl2br(htmlspecialchars((string) $entry['legend'])) ?></p>
        </div>
    </details>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<p class="disclaimer-inline" style="margin-top:2rem;font-size:var(--text-xs);color:var(--c-muted);">
    Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.
</p>
