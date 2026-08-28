<?php declare(strict_types=1);

use CVS\Logo\TickerLogoPresenter;

/**
 * S-01: Global virtual portfolio — read-only view.
 *
 * @var array<string, mixed>              $state          portfolio_state row (cash, initial_capital, updated_at)
 * @var array<int, array<string, mixed>>  $holdings       enriched holdings (ticker, quantity, avg_entry_price, live_price, price_is_snapshot, value_usd)
 * @var array<string, mixed>|null         $latestCycle    latest rebalance_cycle row or null
 * @var float                             $totalValue     cash + sum(holdings value_usd)
 * @var \DateTimeImmutable|null           $nextTradingDay next NYSE trading day from today
 * @var array<string, mixed>              $portfolioConfig config/portfolio.php
 * @var array<int, array<string, mixed>>  $recommended    S-04: screener SILNE KUPUJ/AKUMULUJ not held
 */

$cash           = (float) $state['cash'];
$initialCapital = (float) ($portfolioConfig['initial_capital_usd'] ?? 10000.0);
$marketHolidays = $portfolioConfig['holidays'] ?? [];

// Hover hint: friendly company name + CVS Swing/Fund — same content shape as
// the dashboard watchlist chip tooltip (rendered via the shared .ticker-hint
// portal mechanism in app.js, so it isn't clipped by this table's
// overflow-x:auto wrapper).
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

$statusChip = static function (?string $status): string {
    return match ($status) {
        'completed'         => '<span class="signal-pill signal-pill--strong">✓ Zakończony</span>',
        'llm_failed'        => '<span class="signal-pill signal-pill--danger">✕ Błąd LLM</span>',
        'failed'            => '<span class="signal-pill signal-pill--danger">✕ Błąd</span>',
        'started'           => '<span class="signal-pill signal-pill--momentum">⟳ W toku</span>',
        'market_closed'     => '<span class="signal-pill signal-pill--neutral">— Rynek zamknięty</span>',
        default             => '<span class="signal-pill signal-pill--neutral">' . htmlspecialchars((string) $status) . '</span>',
    };
};
?>

<div style="margin-bottom:1.5rem;">
    <h1 style="margin:0 0 .25rem;">Portfel Bazowy Claude</h1>
    <p style="color:var(--c-muted);font-size:var(--text-sm);margin:0;">
        Portfel globalny CVS &mdash; zarządzany autonomicznie przez model CVS + LLM
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
            <?php foreach ($holdings as $i => $h): ?>
            <?php
                $pctPortfolio = $totalValue > 0 ? ($h['value_usd'] / $totalValue * 100.0) : 0.0;
                $isLive  = !empty($h['price_is_live']);
                $pnl     = (float) ($h['pnl_pct'] ?? 0.0);
                $reason  = $h['reason'] ?? null;
            ?>
            <tr>
                <td>
                    <?= TickerLogoPresenter::render((string) $h['ticker'], $h['company_name'] ?? null, $h['ticker_logo'] ?? null) ?>
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
                    <?php if (!empty($reason)): ?>
                    <button type="button" class="pos-info" aria-label="Uzasadnienie"
                            data-reason="<?= htmlspecialchars((string) $reason, ENT_QUOTES) ?>"
                            data-ticker="<?= htmlspecialchars($h['ticker'], ENT_QUOTES) ?>">ⓘ</button>
                    <?php endif; ?>
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
                <td style="text-align:right;font-weight:600;color:<?= $pnl >= 0 ? 'var(--c-success)' : 'var(--c-danger)' ?>;">
                    <?= ($pnl >= 0 ? '+' : '') . number_format($pnl, 1) ?>%
                </td>
                <td style="text-align:right;color:var(--c-muted);"><?= number_format($pctPortfolio, 1) ?>%</td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Per-position reason popover (click ⓘ) -->
<div id="pos-info-pop" class="pos-info-pop" hidden>
    <div class="pos-info-pop__head"><strong id="pos-info-ticker"></strong> — uzasadnienie po ostatnim rebalansie</div>
    <p id="pos-info-text" style="margin:.4rem 0 0;font-size:var(--text-sm);line-height:1.5;"></p>
</div>
<script>
(function () {
    const pop  = document.getElementById('pos-info-pop');
    const txt  = document.getElementById('pos-info-text');
    const tick = document.getElementById('pos-info-ticker');
    if (!pop) return;

    function openPop(btn) {
        txt.textContent  = btn.getAttribute('data-reason') || '';
        tick.textContent = btn.getAttribute('data-ticker') || '';
        const r = btn.getBoundingClientRect();
        pop.style.top  = (window.scrollY + r.bottom + 6) + 'px';
        pop.style.left = (window.scrollX + Math.min(r.left, document.documentElement.clientWidth - 320)) + 'px';
        pop.hidden = false;
    }
    document.querySelectorAll('.pos-info').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (!pop.hidden && tick.textContent === btn.getAttribute('data-ticker')) {
                pop.hidden = true;
            } else {
                openPop(btn);
            }
        });
    });
    document.addEventListener('click', function (e) {
        if (!pop.hidden && !pop.contains(e.target)) pop.hidden = true;
    });
})();
</script>
<?php endif; ?>

<!-- ─── S-04: Screener recommendations not held ────────────────── -->
<?php if (!empty($recommended)): ?>
<?php
    $recoColorRec = static function (string $reco): string {
        return match (true) {
            str_contains($reco, 'SILNE KUPUJ') => 'color:var(--c-success);font-weight:700;',
            str_contains($reco, 'AKUMULUJ')    => 'color:var(--c-primary);font-weight:700;',
            default                            => 'color:var(--c-muted);',
        };
    };
?>
<h2 style="font-size:1rem;font-weight:600;margin:0 0 .75rem;">Polecane przez screener, ale nie w portfelu</h2>
<div class="card" style="overflow-x:auto;margin-bottom:1.5rem;">
    <p style="color:var(--c-muted);font-size:var(--text-xs);margin-bottom:.75rem;">
        Sp&#243;&#322;ki z reko <strong>SILNE KUPUJ</strong> lub <strong>AKUMULUJ</strong> (quality gate &#10003;), kt&#243;rych nie ma w portfelu &mdash; posortowane wg CVS Swing.
    </p>
    <table class="pillar-table" style="width:100%;">
        <thead>
            <tr>
                <th>Ticker</th>
                <th>Rekomendacja</th>
                <th style="text-align:right;">CVS Swing</th>
                <th style="text-align:right;">Cena</th>
                <th>Data</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recommended as $rec):
            $recTicker = htmlspecialchars((string) $rec['ticker']);
            $recReco   = htmlspecialchars((string) ($rec['reco_swing'] ?? '&#8212;'));
            $recSwing  = $rec['cvs_swing'] !== null ? number_format((float) $rec['cvs_swing'], 1) : '&#8212;';
            $recPrice  = $rec['price_at_snapshot'] !== null ? '$' . number_format((float) $rec['price_at_snapshot'], 2) : '&#8212;';
            $recDate   = htmlspecialchars(substr((string) ($rec['score_date'] ?? ''), 0, 10));
            $recColor  = $recoColorRec((string) ($rec['reco_swing'] ?? ''));
        ?>
        <tr>
            <td>
                <?= TickerLogoPresenter::render((string) $rec['ticker'], $rec['company_name'] ?? null, $rec['ticker_logo'] ?? null) ?>
                <span class="ticker-hint">
                    <a href="/analysis/<?= urlencode((string) $rec['ticker']) ?>"
                       style="font-weight:700;color:var(--c-fund);"><?= $recTicker ?></a>
                    <?= $tickerHint(
                        (string) $rec['ticker'],
                        $rec['company_name'] ?? null,
                        $rec['cvs_swing'] !== null ? (float) $rec['cvs_swing'] : null,
                        $rec['cvs_fund']  !== null ? (float) $rec['cvs_fund']  : null,
                        $rec['reco_swing'] ?? null,
                        $rec['reco_fund']  ?? null
                    ) ?>
                </span>
            </td>
            <td style="font-size:var(--text-sm);<?= $recColor ?>"><?= $recReco ?></td>
            <td style="text-align:right;"><strong style="color:var(--c-primary);"><?= $recSwing ?></strong></td>
            <td style="text-align:right;font-size:var(--text-sm);"><?= $recPrice ?></td>
            <td style="font-size:var(--text-xs);color:var(--c-muted);"><?= $recDate ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ─── Latest rebalance cycle ────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;">
    <h2 style="font-size:1rem;font-weight:600;margin:0;">Ostatni rebalans</h2>
    <a href="/portfolio/history" style="font-size:var(--text-sm);">Zobacz pe&#322;n&#261; histori&#281; &rarr;</a>
</div>

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
    <p class="cycle-card__notes">
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

<!-- ─── Strategy rules (end-user, ordered by importance) ─────────── -->
<?php
    $st = $portfolioConfig['strategy'] ?? [];
    $g  = static fn(string $k, $d) => $st[$k] ?? $d;
    $numS = static fn($v): string => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.');
    $tgt  = $numS($g('target_weight_pct', 10));
    $maxW = $numS($g('max_weight_pct', 15));
    $maxSec = $numS($g('max_sector_pct', 40));
    $emLo = $numS($g('emerging_swing_low', 58));
    $emHi = $numS($g('emerging_swing_high', 72));
    $sell = $numS($g('sell_swing_below', 54));
    $tp   = $numS($g('take_profit_pct', 25));
    $sl   = $numS($g('stop_loss_pct', 15));
    $minE = (int) $g('min_emerging_positions', 2);
    $tgtPos = (int) $g('target_positions', 10);
?>
<h2 style="font-size:1rem;font-weight:600;margin:2.5rem 0 .75rem;">Na jakich zasadach działa portfel</h2>
<div class="card rules-card" style="padding:1.5rem;">
    <p style="margin:0 0 1rem;color:var(--c-muted);font-size:var(--text-sm);">
        Portfel jest zarządzany autonomicznie przez model CVS + LLM, w horyzoncie swing (1–4 miesiące),
        według poniższych reguł (od najważniejszych do mniej istotnych). Wszystkie progi są stałe i jawne.
    </p>

    <div class="rules-grid">
        <div>
            <h3 class="rules-h">🟢 Reguły zakupu</h3>
            <ol class="rules-list">
                <li>Kupujemy <strong>wyłącznie</strong> spółki z sygnałem <strong>strong</strong> (CVS Swing ≥ <?= $emLo ?> <em>oraz</em> CVS Fund ≥ <?= $emLo ?>).</li>
                <li>Twardy limit <strong>sektorowy</strong>: maks. <?= $maxSec ?>% wartości portfela w jednym sektorze.</li>
                <li>Twardy limit <strong>na spółkę</strong>: maks. <?= $maxW ?>% (waga docelowa ~<?= $tgt ?>%).</li>
                <li>Min. <strong><?= $minE ?> pozycje</strong> z pasma „emerging" (Swing <?= $emLo ?>–<?= $emHi ?>) — wczesne wejścia, pretendenci do SILNE KUPUJ.</li>
                <li>Cel ~<?= $tgtPos ?> pozycji; nie odkupujemy w tym samym cyklu spółki właśnie sprzedanej.</li>
            </ol>
        </div>

        <div>
            <h3 class="rules-h">🔵 Reguły utrzymania (HOLD)</h3>
            <ol class="rules-list">
                <li>Trzymamy, dopóki strata nie sięgnie −<?= $sl ?>% i zysk nie sięgnie +<?= $tp ?>%.</li>
                <li>CVS Swing pozostaje ≥ <?= $sell ?> i sygnał nadal jest strong/watchlist.</li>
                <li>Waga pozycji mieści się w limicie (≤ <?= $maxW ?>%).</li>
                <li>Histereza: wchodzimy przy Swing ≥ <?= $emLo ?>, ale wychodzimy dopiero < <?= $sell ?> — to ogranicza nadmierny obrót.</li>
            </ol>
        </div>

        <div>
            <h3 class="rules-h">🔴 Reguły sprzedaży (kolejność priorytetu)</h3>
            <ol class="rules-list">
                <li><strong>Stop-loss</strong>: strata ≤ −<?= $sl ?>% → sprzedaż całości. Twarda ochrona kapitału (wymuszana przez system).</li>
                <li><strong>Take-profit</strong>: zysk ≥ +<?= $tp ?>% → realizacja (chyba że spółka nadal mocno przyspiesza, Swing ≥ <?= $emHi ?>).</li>
                <li><strong>Załamanie sygnału</strong>: CVS Swing < <?= $sell ?>, lub rekomendacja REDUKUJ/UNIKAJ, lub utrata sygnału strong.</li>
                <li><strong>Przekroczenie wagi</strong>: pozycja > <?= $maxW ?>% portfela → przycięcie do wagi docelowej.</li>
            </ol>
        </div>
    </div>
</div>
