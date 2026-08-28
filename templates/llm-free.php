<?php declare(strict_types=1);
/**
 * LLM_Free_Wallet — read-only view.
 *
 * @var array<string, mixed>              $state          llm_free_state row (cash, initial_capital, updated_at)
 * @var array<int, array<string, mixed>>  $holdings       enriched holdings (ticker, quantity, avg_entry_price, live_price, price_is_snapshot, value_usd)
 * @var float                             $totalValue     cash + sum(holdings value_usd)
 * @var array<string, mixed>              $walletConfig   config/llm-free-wallet.php
 * @var array<int, array{cycle_date: string, legend: string}> $legendHistory newest-first
 * @var array<int, string>                $marketHolidays NYSE holiday dates, from config/portfolio.php
 */

$cash           = (float) $state['cash'];
$initialCapital = (float) ($walletConfig['initial_capital_usd'] ?? 10000.0);

// Hover hint: friendly company name + CVS Swing/Fund — identical shape to
// templates/portfolio.php's copy (kept separate rather than extracted: the
// two tables' surrounding markup differs enough — no .pos-info reason button
// here — that a shared partial would need its own parameter surface anyway).
$hintRecoColor = static function (?string $reco): string {
    return match (true) {
        $reco === null                        => 'color:var(--c-muted);',
        str_contains($reco, 'SILNE KUPUJ')     => 'color:var(--c-success);',
        str_contains($reco, 'AKUMULUJ')        => 'color:var(--c-primary);',
        str_contains($reco, 'REDUKUJ')         => 'color:var(--c-warn);',
        str_contains($reco, 'UNIKAJ')          => 'color:var(--c-danger);',
        default                                => 'color:var(--c-muted);',
    };
};

$tickerHint = static function (
    string  $ticker,
    ?string $name,
    ?float  $swing,
    ?float  $fund,
    ?string $recoSwing,
    ?string $recoFund
) use ($hintRecoColor): string {
    if ($name === null && $swing === null && $fund === null) {
        return '';
    }

    $html = '<span class="ticker-hint__tooltip"><strong>' . htmlspecialchars($name ?? $ticker) . '</strong>';
    if ($swing !== null || $fund !== null) {
        $html .= '<span class="ticker-hint__tooltip-scores">';
        if ($swing !== null) {
            $html .= '<span style="' . $hintRecoColor($recoSwing) . '">CVS Swing ' . number_format($swing, 1) . '</span>';
        }
        if ($fund !== null) {
            $html .= '<span style="' . $hintRecoColor($recoFund) . '">CVS Fund ' . number_format($fund, 1) . '</span>';
        }
        $html .= '</span>';
    }
    $html .= '</span>';
    return $html;
};
?>

<div style="margin-bottom:1.5rem;">
    <h1 style="margin:0 0 .25rem;">Portfel Free Claude</h1>
    <p style="color:var(--c-muted);font-size:var(--text-sm);margin:0;">
        Drugi portfel wirtualny &mdash; ten sam model CVS jako źródło sygnałów, ale bez twardych reguł:
        model interpretuje dane sam, może się nie zgodzić z rekomendacją, i musi uzasadnić swoje rozumowanie.
    </p>
</div>

<?php require __DIR__ . '/partials/wallet-summary.php'; ?>

<?php require __DIR__ . '/partials/wallet-nav-chart.php'; ?>

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
                <td>
                    <span class="ticker-hint">
                        <a href="/analysis/<?= urlencode((string) $h['ticker']) ?>"
                           style="font-weight:700;color:var(--c-fund);"><?= htmlspecialchars($h['ticker']) ?></a>
                        <?= $tickerHint(
                            $h['ticker'],
                            $h['company_name'] ?? null,
                            $h['cvs_swing'] ?? null,
                            $h['cvs_fund']  ?? null,
                            $h['reco_swing'] ?? null,
                            $h['reco_fund']  ?? null
                        ) ?>
                    </span>
                </td>
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
