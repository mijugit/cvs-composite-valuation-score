<?php declare(strict_types=1);
/** @var array<string, array<string, mixed>> $portfolioDefs code => config row (name, rules, hypothesis) */
/** @var array<string, array<string, mixed>> $portfolios code => DB row (started_at, cash, ...) */
/** @var array<string, list<array{date: string, value: float}>> $chartSeries code (+ 'LLM') => normalised series */
/** @var array<string, array<string, mixed>> $metrics code => computed metrics */
/** @var array<string, array<string, array{quantity: float, avg_entry_price: float, entry_date: string}>> $positions code => ticker => position */
/** @var string|null $d0 */
/** @var array<string, array{status: string, ci: array{0: float, 1: float}, n: int, min_sessions: int}> $hypothesisStatuses */

$chipMeta = [
    'too_early'    => ['label' => 'za wcześnie',       'bg' => 'rgba(148,163,184,.18)', 'fg' => '#94a3b8'],
    'inconclusive' => ['label' => 'nierozstrzygnięte',  'bg' => 'rgba(96,165,250,.18)',  'fg' => '#60a5fa'],
    'supported'    => ['label' => 'potwierdzana',       'bg' => 'rgba(34,197,94,.18)',   'fg' => 'var(--c-success)'],
    'refuted'      => ['label' => 'obalana',            'bg' => 'rgba(239,68,68,.18)',   'fg' => 'var(--c-danger)'],
];

// Same portal-hint pattern as the ticker hover hints (public/js/app.js +
// .ticker-hint/.ticker-hint__tooltip in components.css) — reused as-is so the
// CI explainer matches the app's existing tooltip look instead of a bare
// browser-native `title` attribute.
$ciTooltipHtml = '<strong>Przedział ufności 95% (bootstrap)</strong>'
    . '<span class="ticker-hint__tooltip-scores">'
    . '<span>Różnica dziennych zwrotów</span>'
    . '<span>vs portfel odniesienia.</span>'
    . '</span>'
    . '<span class="ticker-hint__tooltip-scores">'
    . '<span>Cały przedział po jednej stronie zera</span>'
    . '<span>→ różnica raczej nie jest przypadkiem.</span>'
    . '<span>Obejmuje zero → za wcześnie wnioskować.</span>'
    . '</span>';

// Holdings hint — same portal-hint pattern as the CI tooltip above, reused
// per-portfolio to show current tickers + quantity (shares) on hover. Hover
// tooltips don't work on touch anyway, so no separate mobile handling.
$holdingsTooltipHtml = static function (array $positions): string {
    if ($positions === []) {
        return '<strong>Aktualne pozycje</strong>'
            . '<span class="ticker-hint__tooltip-scores"><span>Brak pozycji (cała gotówka).</span></span>';
    }
    $tickers = array_keys($positions);
    sort($tickers);
    $lines = array_map(
        static fn (string $t): string => '<span>' . htmlspecialchars($t) . ' — '
            . number_format($positions[$t]['quantity'], 2) . ' szt.</span>',
        $tickers
    );
    return '<strong>Aktualne pozycje</strong>'
        . '<span class="ticker-hint__tooltip-scores">' . implode('', $lines) . '</span>';
};

// Shared (i) trigger + portal wrapper — same markup used for the metrics-table
// row and the portfolio card header, kept in one place to avoid duplicating
// the inline-style badge.
$holdingsHintIcon = static function (string $code) use ($positions, $holdingsTooltipHtml): string {
    return '<span class="ticker-hint">'
        . '<span style="cursor:help;color:var(--c-muted);font-size:var(--text-xs);border:1px solid var(--c-border);border-radius:999px;width:1.1rem;height:1.1rem;display:inline-flex;align-items:center;justify-content:center;">ⓘ</span>'
        . '<span class="ticker-hint__tooltip">' . $holdingsTooltipHtml($positions[$code] ?? []) . '</span>'
        . '</span>';
};

$executionLabel = static function (string $execution): string {
    return $execution === 'open'
        ? 'Egzekucja na otwarciu (D+1, cena open)'
        : 'Egzekucja na zamknięciu (close)';
};

$weightingLabel = static function (string $weighting): string {
    return $weighting === 'score'
        ? 'Wagi proporcjonalne do CVS score'
        : 'Wagi równe';
};

$stopsLabel = static function (?array $stops): string {
    if ($stops === null) {
        return 'Brak stopów ochronnych';
    }
    return match ($stops['type'] ?? null) {
        'atr_swing' => 'Stop ATR (1.5x Wilder, poziom swing)',
        'fixed_pct' => sprintf('Stop sztywny -%s%%', number_format((float) ($stops['pct'] ?? 0.0), 0)),
        default     => 'Reguła stopu (' . (string) ($stops['type'] ?? '?') . ')',
    };
};

$sectorCapLabel = static function (?float $cap): string {
    return $cap !== null ? sprintf('Cap sektorowy %s%%', number_format($cap, 0)) : 'Brak capu sektorowego';
};

$rebalanceLabel = static function (string $frequency): string {
    return match ($frequency) {
        'daily'  => 'Rebalans dzienny (każda sesja)',
        'weekly' => 'Rebalans tygodniowy (pierwsza sesja tygodnia)',
        default  => 'Rebalans miesięczny (pierwsza sesja miesiąca)',
    };
};

$pct = static function (?float $v, int $decimals = 2): string {
    if ($v === null) {
        return '—';
    }
    return ($v >= 0 ? '+' : '') . number_format($v, $decimals) . '%';
};

$pctColor = static function (?float $v): string {
    if ($v === null) {
        return '';
    }
    return $v >= 0 ? 'color:var(--c-success)' : 'color:var(--c-danger)';
};

$palette = [
    'P0' => 'rgba(148,163,184,0.9)',
    'P1' => 'rgba(64,144,224,0.9)',
    'P2' => 'rgba(250,204,21,0.9)',
    'P3' => 'rgba(52,211,153,0.9)',
    'P4' => 'rgba(239,68,68,0.9)',
    'P5' => 'rgba(167,139,250,0.9)',
    'P6' => 'rgba(251,146,60,0.9)',
    'P7' => 'rgba(34,211,238,0.9)',
    'P8' => 'rgba(244,114,182,0.9)',
    'LLM' => 'rgba(255,255,255,0.55)',
];
?>

<div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem;">
    <h1 style="margin:0;">Lab: portfele eksperymentalne</h1>
</div>

<p style="color:var(--c-muted);margin-bottom:1.25rem;max-width:70ch;">
    Dziewięć deterministycznych portfeli papierowych (P0–P8). P0–P6 różnią się
    od bazowego P1 dokładnie jedną regułą egzekucji; P7 i P8 powtarzają reguły
    P6 (cap sektorowy 30%) i różnią się od niego wyłącznie częstotliwością
    rebalansu — izolują sam efekt cyklu rebalansu. Każda różnica ma
    pre-zarejestrowaną hipotezę opartą na konkretnym badaniu — sprawdzamy, czy
    dane ją potwierdzają, zamiast dopasowywać wnioski po fakcie.
    Skład portfeli P0–P6 jest <strong>rebalansowany raz w miesiącu</strong>
    (pierwsza sesja giełdowa NYSE w miesiącu, do aktualnego rankingu CVS); P7
    rebalansuje się <strong>codziennie</strong>, a P8 <strong>raz w tygodniu</strong>
    (pierwsza sesja tygodnia). Stopy ochronne tam, gdzie występują (P3, P4), są
    sprawdzane <strong>codziennie</strong> i mogą wyrzucić pozycję z portfela
    niezależnie od cyklu rebalansu.
    Linia LLM (istniejący <a href="/portfolio">Portfel</a>) pokazana wyłącznie
    poglądowo — to inny mechanizm decyzyjny, poza tym eksperymentem.
</p>

<p style="color:var(--c-muted);margin-bottom:1.25rem;max-width:70ch;font-size:var(--text-sm);">
    Pełny przegląd badań stojących za regułami P0–P6 (premia overnight, stop-lossy
    a momentum, równe wagi, rebalance timing luck) — na blogu:
    <a href="https://blog.timeflow.fun/post/udokumentowane-strategie-egzekucji-co-naprawde-mowia-badania-2026-07-05" target="_blank">Udokumentowane strategie egzekucji: co naprawdę mówią badania</a>.
</p>

<?php if ($d0 === null): ?>
<div class="card" style="text-align:center;padding:2rem;">
    <p style="color:var(--c-muted);">Brak jeszcze danych — portfele nie zostały zaseedowane.</p>
</div>
<?php else: ?>

<!-- NAV chart -->
<div class="card" style="margin-bottom:1.5rem;">
    <h3 style="margin-bottom:.75rem;font-size:var(--text-base);">
        NAV, baza=100 od <?= htmlspecialchars($d0) ?>
    </h3>
    <div class="chart-zoom-target" style="position:relative;height:320px;"
         data-zoom-canvas="lab-nav-chart" data-zoom-title="NAV, baza=100 od <?= htmlspecialchars($d0) ?>">
        <span class="chart-zoom-target__hint" aria-hidden="true">🔍</span>
        <canvas id="lab-nav-chart"></canvas>
    </div>
</div>

<!-- Chart zoom modal (desktop only — see .chart-zoom-target click handler in
     app.js, shared with the analysis page's radar/price/trajectory charts).
     Reuses the already-rendered Chart.js instance's data/options at a larger
     size, with Chart.js's default reveal animation replayed on open (the
     compact chart above disables animation — several Lab charts could in
     principle render at once — but the zoom modal only ever builds one, on
     a deliberate click, so the "grow in" flourish is worth it there). -->
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
    renderCvsNavChart('lab-nav-chart', <?= json_encode($chartSeries) ?>, <?= json_encode($palette) ?>, {
        dashedLabels: ['LLM'],
        labelFor: function (code) { return code === 'LLM' ? 'LLM (poglądowo)' : code; },
    });
});
</script>

<!-- Metrics table -->
<div class="card" style="overflow-x:auto;margin-bottom:1.5rem;">
    <h3 style="margin-bottom:.75rem;font-size:var(--text-base);">Metryki</h3>
    <table class="pillar-table" style="width:100%;">
        <thead>
            <tr>
                <th>Portfel</th>
                <th>Zwrot</th>
                <th>vs SPY (p.p.)</th>
                <th>vs P1 (p.p.)</th>
                <th>Max DD</th>
                <th>Opłaty</th>
                <th>Transakcje</th>
                <th>Sesje</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($portfolioDefs as $code => $def): $m = $metrics[$code] ?? []; ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars($code) ?></strong>
                <span style="color:var(--c-muted);font-size:var(--text-sm);"><?= htmlspecialchars((string) $def['name']) ?></span>
                <?= $holdingsHintIcon($code) ?>
            </td>
            <td style="<?= $pctColor($m['total_return_pct'] ?? null) ?>;font-weight:600;"><?= $pct($m['total_return_pct'] ?? null) ?></td>
            <td style="<?= $pctColor($m['vs_spy_pp'] ?? null) ?>"><?= $code === 'P0' ? '—' : $pct($m['vs_spy_pp'] ?? null) ?></td>
            <td style="<?= $pctColor($m['vs_p1_pp'] ?? null) ?>"><?= $code === 'P1' ? '—' : $pct($m['vs_p1_pp'] ?? null) ?></td>
            <td><?= $m['max_drawdown_pct'] !== null ? '-' . number_format($m['max_drawdown_pct'], 2) . '%' : '—' ?></td>
            <td>$<?= number_format((float) ($m['fee_total'] ?? 0.0), 2) ?></td>
            <td><?= (int) ($m['tx_count'] ?? 0) ?></td>
            <td><?= (int) ($m['sessions'] ?? 0) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Portfolio cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem;margin-bottom:1.5rem;">
<?php foreach ($portfolioDefs as $code => $def):
    $rules = $def['rules'];
    $hyp   = $def['hypothesis'];
?>
    <div class="card">
        <h3 style="margin-bottom:.25rem;font-size:var(--text-base);">
            <?= htmlspecialchars($code) ?> — <?= htmlspecialchars((string) $def['name']) ?>
            <?= $holdingsHintIcon($code) ?>
        </h3>
        <ul style="list-style:none;padding:0;margin:.5rem 0;color:var(--c-muted);font-size:var(--text-sm);">
            <?php if (!empty($rules['benchmark_ticker'])): ?>
            <li>Benchmark 100% <?= htmlspecialchars((string) $rules['benchmark_ticker']) ?> (kontrola)</li>
            <?php else: ?>
            <li><?= htmlspecialchars($executionLabel((string) $rules['execution'])) ?></li>
            <li><?= htmlspecialchars($weightingLabel((string) $rules['weighting'])) ?></li>
            <li><?= htmlspecialchars($stopsLabel($rules['stops'])) ?></li>
            <li><?= htmlspecialchars($sectorCapLabel($rules['sector_cap_pct'] !== null ? (float) $rules['sector_cap_pct'] : null)) ?></li>
            <li><?= htmlspecialchars($rebalanceLabel((string) ($rules['rebalance_frequency'] ?? 'monthly'))) ?></li>
            <?php endif; ?>
        </ul>
        <?php if ($hyp !== null): $hs = $hypothesisStatuses[$code] ?? null; $meta = $chipMeta[$hs['status'] ?? 'too_early']; ?>
        <div style="border-top:1px solid var(--c-border);padding-top:.5rem;margin-top:.5rem;">
            <p style="font-size:var(--text-sm);margin:0 0 .35rem;"><?= htmlspecialchars((string) $hyp['claim']) ?></p>
            <p style="font-size:var(--text-xs);color:var(--c-muted);margin:0 0 .5rem;">Źródło: <?= htmlspecialchars((string) $hyp['source']) ?></p>
            <?php if ($hs !== null): ?>
            <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;">
                <span style="background:<?= $meta['bg'] ?>;color:<?= $meta['fg'] ?>;padding:.15rem .55rem;border-radius:999px;font-size:var(--text-xs);font-weight:600;">
                    <?= $hs['status'] === 'too_early'
                        ? htmlspecialchars(sprintf('%s (%d sesji / min %d)', $meta['label'], $hs['n'], $hs['min_sessions']))
                        : htmlspecialchars($meta['label']) ?>
                </span>
                <span class="ticker-hint">
                    <span style="cursor:help;color:var(--c-muted);font-size:var(--text-xs);border:1px solid var(--c-border);border-radius:999px;width:1.1rem;height:1.1rem;display:inline-flex;align-items:center;justify-content:center;">ⓘ</span>
                    <span class="ticker-hint__tooltip"><?= $ciTooltipHtml ?></span>
                </span>
                <?php if ($hs['n'] > 0): ?>
                <span style="font-size:var(--text-xs);color:var(--c-muted);">
                    CI 95%: [<?= number_format($hs['ci'][0] * 100, 3) ?>%, <?= number_format($hs['ci'][1] * 100, 3) ?>%]
                </span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <p style="font-size:var(--text-sm);color:var(--c-muted);border-top:1px solid var(--c-border);padding-top:.5rem;margin-top:.5rem;">
            Punkt odniesienia — nie jest samodzielną hipotezą badawczą.
        </p>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>

<?php endif; ?>

<div class="card" style="margin-bottom:1rem;">
    <h3 style="margin-bottom:.5rem;font-size:var(--text-base);">Zastrzeżenia metodologiczne</h3>
    <ul style="color:var(--c-muted);font-size:var(--text-sm);margin:0;padding-left:1.25rem;">
        <li>Dobór spółek pochodzi z watchlisty CVS — nie jest to losowa próba całego rynku (survivorship/selection bias).</li>
        <li>Zwroty nie uwzględniają dywidend.</li>
        <li>Koszty transakcyjne są modelowane (0,05% na stronę) — realna egzekucja może się różnić.</li>
        <li>To eksperyment papierowy o krótkim horyzoncie zbierania danych — wnioski poniżej progu minimalnej liczby sesji (patrz sekcja statystyczna) są niewiążące.</li>
        <li>Gotówka zwolniona ze stopu (P3/P4) nie jest doinwestowywana od razu — czeka do najbliższego rebalansu miesięcznego. To celowa część testowanej hipotezy (koszt ciasnych stopów), nie błąd.</li>
    </ul>
</div>

<p class="disclaimer-inline">
    Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.
</p>
