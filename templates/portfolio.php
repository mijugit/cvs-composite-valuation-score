<?php declare(strict_types=1);
/**
 * S-01: Global virtual portfolio — read-only view.
 *
 * @var array<string, mixed>              $state          portfolio_state row (cash, initial_capital, updated_at)
 * @var array<int, array<string, mixed>>  $holdings       enriched holdings (ticker, quantity, avg_entry_price, live_price, price_is_snapshot, value_usd)
 * @var array<string, mixed>|null         $latestCycle    latest rebalance_cycle row or null
 * @var float                             $totalValue     cash + sum(holdings value_usd)
 * @var \DateTimeImmutable|null           $nextTradingDay next NYSE trading day from today
 * @var array<string, mixed>              $portfolioConfig config/portfolio.php
 */

$cash          = (float) $state['cash'];
$initialCapital = (float) ($portfolioConfig['initial_capital_usd'] ?? 10000.0);
$pnl           = $totalValue - $initialCapital;
$pnlPct        = $initialCapital > 0 ? ($pnl / $initialCapital) * 100.0 : 0.0;

$fmt = static fn(float $v): string => '$' . number_format($v, 2, '.', ' ');
$fmtPct = static fn(float $v): string => ($v >= 0 ? '+' : '') . number_format($v, 2, '.', '') . '%';

$statusChip = static function (?string $status): string {
    return match ($status) {
        'completed'         => '<span class="signal-pill signal-pill--strong">✓ Zakończony</span>',
        'llm_failed'        => '<span class="signal-pill" style="background:var(--c-danger-bg,#fee2e2);color:var(--c-danger);">✕ Błąd LLM</span>',
        'failed'            => '<span class="signal-pill" style="background:var(--c-danger-bg,#fee2e2);color:var(--c-danger);">✕ Błąd</span>',
        'started'           => '<span class="signal-pill signal-pill--momentum">⟳ W toku</span>',
        'market_closed'     => '<span class="signal-pill" style="background:var(--c-muted-bg,#f1f5f9);color:var(--c-muted);">— Rynek zamknięty</span>',
        default             => '<span class="signal-pill" style="color:var(--c-muted);">' . htmlspecialchars((string) $status) . '</span>',
    };
};
?>

<div style="margin-bottom:1.5rem;">
    <h1 style="margin:0 0 .25rem;">Wirtualny Portfel</h1>
    <p style="color:var(--c-muted);font-size:var(--text-sm);margin:0;">
        Portfel globalny CVS &mdash; zarządzany autonomicznie przez model CVS + LLM
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

<!-- ─── Market clock ───────────────────────────────────────────── -->
<div class="card" style="padding:1.25rem;margin-bottom:1.5rem;">
    <div style="display:flex;flex-wrap:wrap;gap:1.5rem;align-items:center;">

        <div style="display:flex;flex-direction:column;gap:.15rem;">
            <span style="font-size:var(--text-xs);color:var(--c-muted);text-transform:uppercase;letter-spacing:.05em;">Warszawa</span>
            <span id="clock-waw" style="font-size:1.1rem;font-weight:700;font-variant-numeric:tabular-nums;">—</span>
            <span style="font-size:var(--text-xs);color:var(--c-muted);">Europe/Warsaw (CET/CEST)</span>
        </div>

        <div style="width:1px;background:var(--c-border);align-self:stretch;"></div>

        <div style="display:flex;flex-direction:column;gap:.15rem;">
            <span style="font-size:var(--text-xs);color:var(--c-muted);text-transform:uppercase;letter-spacing:.05em;">Nowy Jork</span>
            <span id="clock-ny" style="font-size:1.1rem;font-weight:700;font-variant-numeric:tabular-nums;">—</span>
            <span style="font-size:var(--text-xs);color:var(--c-muted);">America/New_York (ET)</span>
        </div>

        <div style="width:1px;background:var(--c-border);align-self:stretch;"></div>

        <div style="display:flex;flex-direction:column;gap:.15rem;">
            <span style="font-size:var(--text-xs);color:var(--c-muted);text-transform:uppercase;letter-spacing:.05em;">Sesja NYSE</span>
            <span style="font-size:.95rem;font-weight:600;">09:30 – 16:00 ET</span>
            <span style="font-size:var(--text-xs);color:var(--c-muted);">15:30 – 22:00 Warsaw</span>
        </div>

        <div style="width:1px;background:var(--c-border);align-self:stretch;"></div>

        <div style="display:flex;flex-direction:column;gap:.15rem;">
            <span style="font-size:var(--text-xs);color:var(--c-muted);text-transform:uppercase;letter-spacing:.05em;">Status rynku</span>
            <span id="market-status" style="font-size:.95rem;font-weight:700;">—</span>
            <span id="market-hint" style="font-size:var(--text-xs);color:var(--c-muted);"></span>
        </div>

    </div>
</div>

<script>
(function () {
    function pad(n) { return String(n).padStart(2, '0'); }

    function fmt(date, tz) {
        try {
            return date.toLocaleTimeString('pl-PL', {
                timeZone: tz,
                hour: '2-digit', minute: '2-digit', second: '2-digit',
                hour12: false
            });
        } catch (_) { return '—'; }
    }

    function isMarketOpen(now) {
        // NYSE Mon–Fri 09:30–16:00 ET, excluding holidays
        const holidays = <?= json_encode($portfolioConfig['holidays'] ?? []) ?>;
        const et = new Date(now.toLocaleString('en-US', { timeZone: 'America/New_York' }));
        const dow = et.getDay(); // 0=Sun 6=Sat
        if (dow === 0 || dow === 6) return false;

        const ymd = et.getFullYear() + '-'
            + pad(et.getMonth() + 1) + '-'
            + pad(et.getDate());
        if (holidays.includes(ymd)) return false;

        const mins = et.getHours() * 60 + et.getMinutes();
        return mins >= 570 && mins < 960; // 09:30–16:00
    }

    function tick() {
        const now = new Date();
        document.getElementById('clock-waw').textContent = fmt(now, 'Europe/Warsaw');
        document.getElementById('clock-ny').textContent  = fmt(now, 'America/New_York');

        const open = isMarketOpen(now);
        const statusEl = document.getElementById('market-status');
        const hintEl   = document.getElementById('market-hint');
        if (open) {
            statusEl.textContent = '🟢 Otwarta';
            statusEl.style.color = 'var(--c-success)';
            hintEl.textContent   = 'Trwa sesja NYSE';
        } else {
            statusEl.textContent = '🔴 Zamknięta';
            statusEl.style.color = 'var(--c-muted)';
            // hint: next open
            const et = new Date(now.toLocaleString('en-US', { timeZone: 'America/New_York' }));
            const dow = et.getDay();
            hintEl.textContent = (dow >= 1 && dow <= 4) ? 'Jutro od 09:30 ET'
                               : (dow === 5 || dow === 6) ? 'Poniedziałek 09:30 ET'
                               : 'Jutro od 09:30 ET';
        }
    }

    tick();
    setInterval(tick, 1000);
})();
</script>

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
                <th style="text-align:right;">% portfela</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($holdings as $h): ?>
            <?php
                $pctPortfolio = $totalValue > 0 ? ($h['value_usd'] / $totalValue * 100.0) : 0.0;
                $isApprox = !$h['price_is_snapshot'];
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($h['ticker']) ?></strong></td>
                <td><?= (int) $h['quantity'] ?></td>
                <td style="text-align:right;color:var(--c-muted);"><?= $fmt((float) $h['avg_entry_price']) ?></td>
                <td style="text-align:right;">
                    <?= $fmt((float) $h['live_price']) ?>
                    <?php if ($isApprox): ?>
                    <span style="font-size:var(--text-xs);color:var(--c-muted);"> aprox</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;font-weight:600;"><?= $fmt((float) $h['value_usd']) ?></td>
                <td style="text-align:right;color:var(--c-muted);"><?= number_format($pctPortfolio, 1) ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ─── Latest rebalance cycle ────────────────────────────────── -->
<h2 style="font-size:1rem;font-weight:600;margin:0 0 .75rem;">Ostatni rebalans</h2>

<?php if ($latestCycle === null): ?>
<div class="card" style="padding:1.5rem;">
    <p style="margin:0 0 .5rem;color:var(--c-muted);">
        Pierwszy autonomiczny cykl rebalansowania jeszcze nie wystąpił.
    </p>
    <?php if ($nextTradingDay !== null): ?>
    <p style="margin:0;font-size:var(--text-sm);">
        Następny planowany:
        <strong><?= $nextTradingDay->format('l, d.m.Y') ?></strong>
        o ok. 20:30–21:30 (Warsaw)
    </p>
    <?php else: ?>
    <p style="margin:0;font-size:var(--text-sm);color:var(--c-muted);">
        Rebalansowanie odbywa się automatycznie każdego dnia roboczego.
    </p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="card" style="padding:1.25rem;">
    <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin-bottom:.75rem;">
        <strong><?= htmlspecialchars((string) $latestCycle['cycle_date']) ?></strong>
        <?= $statusChip((string) ($latestCycle['status'] ?? '')) ?>
        <?php if ((int) ($latestCycle['executed_count'] ?? 0) > 0): ?>
        <span style="font-size:var(--text-sm);color:var(--c-muted);">
            <?= (int) $latestCycle['executed_count'] ?> transakcji wykonanych
        </span>
        <?php endif; ?>
        <?php if ((int) ($latestCycle['skipped_count'] ?? 0) > 0): ?>
        <span style="font-size:var(--text-sm);color:var(--c-muted);">
            <?= (int) $latestCycle['skipped_count'] ?> pominięto (brak gotówki)
        </span>
        <?php endif; ?>
    </div>

    <?php if (!empty($latestCycle['notes'])): ?>
    <p style="font-size:var(--text-sm);color:var(--c-text);margin:0 0 .5rem;padding:.75rem;background:var(--c-surface-alt,#f8fafc);border-radius:.375rem;">
        <?= htmlspecialchars((string) $latestCycle['notes']) ?>
    </p>
    <?php endif; ?>

    <?php if (!empty($latestCycle['llm_failure_kind'])): ?>
    <p style="font-size:var(--text-xs);color:var(--c-danger);margin:.25rem 0 0;">
        Przyczyna błędu LLM: <?= htmlspecialchars((string) $latestCycle['llm_failure_kind']) ?>
    </p>
    <?php endif; ?>

    <?php if (!empty($latestCycle['finished_at'])): ?>
    <p style="font-size:var(--text-xs);color:var(--c-muted);margin:.5rem 0 0;">
        Zakończono: <?= htmlspecialchars((string) $latestCycle['finished_at']) ?> UTC
    </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<p class="disclaimer-inline" style="margin-top:2rem;font-size:var(--text-xs);color:var(--c-muted);">
    Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.
</p>
