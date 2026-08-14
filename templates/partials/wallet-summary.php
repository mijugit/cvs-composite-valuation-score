<?php declare(strict_types=1);

/**
 * Shared summary strip for the two autonomous virtual wallets — reused by
 * templates/portfolio.php and templates/llm-free.php so the KPI cards and
 * market clock don't drift between the two (were 1:1 copy-pasted before).
 *
 * @var float               $cash            wallet cash balance (USD)
 * @var float               $totalValue      cash + sum(holdings value_usd)
 * @var float                $initialCapital starting capital (USD)
 * @var array<int, string>  $marketHolidays  NYSE holiday dates (Y-m-d), from config/portfolio.php
 */

$fmt    = static fn(float $v): string => '$' . number_format($v, 2, '.', ' ');
$fmtPct = static fn(float $v): string => ($v >= 0 ? '+' : '') . number_format($v, 2, '.', '') . '%';

$pnl    = $totalValue - $initialCapital;
$pnlPct = $initialCapital > 0 ? ($pnl / $initialCapital) * 100.0 : 0.0;
?>

<!-- ─── Summary cards ─────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem;">

    <div class="card kpi-card">
        <p class="kpi-card__label">Gotówka</p>
        <p class="kpi-card__value"><?= $fmt($cash) ?></p>
        <p class="kpi-card__caption">USD — dostępna gotówka</p>
    </div>

    <div class="card kpi-card">
        <p class="kpi-card__label">Wycena portfela</p>
        <p class="kpi-card__value"><?= $fmt($totalValue) ?></p>
        <p class="kpi-card__caption">cash + pozycje</p>
    </div>

    <div class="card kpi-card">
        <p class="kpi-card__label">Wynik vs start</p>
        <p class="kpi-card__value <?= $pnl >= 0 ? 'kpi-card__value--up' : 'kpi-card__value--down' ?>">
            <?= $fmtPct($pnlPct) ?>
        </p>
        <p class="kpi-card__caption"><?= $fmt(abs($pnl)) ?> <?= $pnl >= 0 ? 'zysku' : 'straty' ?> vs <?= $fmt($initialCapital) ?></p>
    </div>

</div>

<!-- ─── Market clock ───────────────────────────────────────────── -->
<div class="card market-clock-card" style="padding:1.25rem;margin-bottom:1.5rem;">
    <div class="market-clock">

        <div class="market-clock__item">
            <span class="market-clock__label">Warszawa</span>
            <span id="clock-waw" class="market-clock__value">—</span>
            <span class="market-clock__hint">Europe/Warsaw (CET/CEST)</span>
        </div>

        <div class="market-clock__sep"></div>

        <div class="market-clock__item">
            <span class="market-clock__label">Nowy Jork</span>
            <span id="clock-ny" class="market-clock__value">—</span>
            <span class="market-clock__hint">America/New_York (ET)</span>
        </div>

        <div class="market-clock__sep"></div>

        <div class="market-clock__item">
            <span class="market-clock__label">Sesja NYSE</span>
            <span class="market-clock__value market-clock__value--session">09:30 – 16:00 ET</span>
            <span class="market-clock__hint">15:30 – 22:00 Warsaw</span>
        </div>

        <div class="market-clock__sep"></div>

        <div class="market-clock__item">
            <span class="market-clock__label">Status rynku</span>
            <span id="market-status" class="market-clock__value--session" style="font-weight:700;">—</span>
            <span id="market-hint" class="market-clock__hint"></span>
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
        const holidays = <?= json_encode($marketHolidays ?? []) ?>;
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
