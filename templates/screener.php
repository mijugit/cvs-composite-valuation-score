<?php declare(strict_types=1);
/** @var array<int, array<string, mixed>> $rows */
/** @var string|null $lastScored */
/** @var string[] $sectors */
/** @var string|null $filter_reco */
/** @var string|null $filter_signal */
/** @var int $filter_min_swing */
/** @var string|null $filter_sector */
/** @var string|null $filter_atr */
/** @var bool $filter_near_boundary */
/** @var string $sort */
/** @var array<string, true> $heldTickersMap */

$recoOptions = [
    '⬆⬆ SILNE KUPUJ',
    '⬆ AKUMULUJ',
    '→ NEUTRALNIE',
    '⬇ REDUKUJ',
    '⬇⬇ UNIKAJ',
];

$signalLabels = [
    'strong'    => '⭐⭐ Silny sygnał',
    'watchlist' => '⭐ Obserwuj',
    'momentum'  => '↑ Momentum',
    'none'      => '— Brak sygnału',
];

// Helper: sort link with arrow indicator
$sortLink = static function (string $col, string $label) use ($sort, $filter_reco, $filter_signal, $filter_min_swing, $filter_sector, $filter_atr, $filter_near_boundary): string {
    $arrow = $col === $sort ? ' ↓' : '';
    $params = http_build_query(array_filter([
        'reco'          => $filter_reco,
        'signal'        => $filter_signal,
        'min_swing'     => $filter_min_swing > 0 ? $filter_min_swing : null,
        'sector'        => $filter_sector,
        'atr'           => $filter_atr,
        'near_boundary' => $filter_near_boundary ? '1' : null,
        'sort'          => $col,
    ], fn($v) => $v !== null && $v !== ''));
    return '<a href="/screener?' . $params . '" style="color:inherit;text-decoration:none;">'
        . htmlspecialchars($label) . $arrow . '</a>';
};

$signalChip = static function (?string $sig): string {
    return match ($sig) {
        'strong'    => '<span class="signal-pill signal-pill--strong">⭐⭐</span>',
        'watchlist' => '<span class="signal-pill signal-pill--watchlist">⭐</span>',
        'momentum'  => '<span class="signal-pill signal-pill--momentum">↑</span>',
        default     => '<span style="color:var(--c-muted);">—</span>',
    };
};

// Phase 5 (slice 2) — earnings-timing chip (FR-008/FR-010), reading the persisted
// columns from migration 015 (earnings_state/days_to_earnings/days_since_earnings).
// `default` (state NULL — pre-migration snapshot or no calendar coverage) renders
// `—`, consistent with $signalChip's no-signal convention.
$earningsChip = static function (?string $state, ?int $daysTo, ?int $daysSince): string {
    $dni = static fn (?int $n): string => $n === 1 ? 'dzień' : 'dni';
    return match ($state) {
        'before'     => '<span class="signal-pill signal-pill--momentum">📅 za ' . (int) $daysTo . ' ' . $dni((int) $daysTo) . '</span>',
        'in_transit' => '<span class="signal-pill signal-pill--watchlist">📅 w oknie</span>',
        'after'      => '<span class="signal-pill signal-pill--neutral">📅 ' . (int) $daysSince . ' ' . $dni((int) $daysSince) . ' temu</span>',
        default      => '<span style="color:var(--c-muted);">—</span>',
    };
};

// Phase 8 follow-up — ATR zone state chip (price vs accumulation zone).
$atrChip = static function (?string $state): string {
    return match ($state) {
        'in_zone' => '<span class="signal-pill signal-pill--strong" title="Cena w strefie akumulacji ATR — sprzyjający moment wejścia">✓ w strefie</span>',
        'above'   => '<span class="signal-pill signal-pill--watchlist" title="Cena powyżej strefy akumulacji — rozważ czekanie na cofnięcie">↑ powyżej</span>',
        'below'   => '<span class="signal-pill" style="background:rgba(239,68,68,.15);color:var(--c-danger,#ef4444);" title="Cena poniżej strefy akumulacji (poniżej wsparcia)">↓ poniżej</span>',
        default   => '<span style="color:var(--c-muted);" title="Brak danych strefy ATR dla tej spółki">—</span>',
    };
};

// Helper: column-header info hint (ⓘ tooltip), reusing the .chart-hint pattern.
$hint = static fn (string $text): string =>
    ' <span class="chart-hint" tabindex="0">&#9432;<span class="chart-hint__tooltip">' . $text . '</span></span>';

// change: cvs-screener-trend — CVS Swing trend chip, shared by the
// day-over-day and week-over-week columns. null (insufficient history)
// renders as a dash, matching the analysis page's has_trajectory=false empty
// state. $emptyTitle/$title let the two callers customise the tooltip.
$trendChip = static function (?float $delta, string $title, string $emptyTitle): string {
    if ($delta === null) {
        return '<span style="color:var(--c-muted);" title="' . htmlspecialchars($emptyTitle) . '">—</span>';
    }
    [$arrow, $color] = match (true) {
        $delta > 0  => ['↑', 'var(--c-success)'],
        $delta < 0  => ['↓', 'var(--c-danger)'],
        default     => ['→', 'var(--c-muted)'],
    };
    $sign = $delta > 0 ? '+' : '';
    return '<span style="color:' . $color . ';font-weight:600;" title="' . htmlspecialchars($title) . '">'
        . $arrow . ' ' . $sign . number_format($delta, 1) . '</span>';
};

// S-04: badge "w portfelu" next to the ticker link.
// Conflict variant (amber/red) when held but reco is REDUKUJ or UNIKAJ.
$heldBadge = static function (string $ticker, string $reco) use ($heldTickersMap): string {
    if (!isset($heldTickersMap[$ticker])) {
        return '';
    }
    $conflict = str_contains($reco, 'REDUKUJ') || str_contains($reco, 'UNIKAJ');
    $cls = $conflict ? 'portfolio-badge portfolio-badge--conflict' : 'portfolio-badge';
    return ' <span class="' . $cls . '">w portfelu</span>';
};

// Hover hint: friendly company name + CVS Swing/Fund — same content shape as
// the dashboard watchlist chip tooltip.
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

$tickerHint = static function (string $ticker, array $row) use ($hintRecoColor): string {
    $name  = $row['company_name'] ?? null;
    $swing = isset($row['cvs_swing']) ? (float) $row['cvs_swing'] : null;
    $fund  = isset($row['cvs_fund'])  ? (float) $row['cvs_fund']  : null;
    if ($name === null && $swing === null && $fund === null) {
        return '';
    }

    $html = '<span class="ticker-hint__tooltip"><strong>' . htmlspecialchars($name ?? $ticker) . '</strong>';
    if ($swing !== null || $fund !== null) {
        $html .= '<span class="ticker-hint__tooltip-scores">';
        if ($swing !== null) {
            $html .= '<span style="' . $hintRecoColor($row['reco_swing'] ?? null) . '">CVS Swing ' . number_format($swing, 1) . '</span>';
        }
        if ($fund !== null) {
            $html .= '<span style="' . $hintRecoColor($row['reco_fund'] ?? null) . '">CVS Fund ' . number_format($fund, 1) . '</span>';
        }
        $html .= '</span>';
    }
    $html .= '</span>';
    return $html;
};
?>

<div style="display:flex;align-items:baseline;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.5rem;">
    <h1 style="margin:0;">Screener CVS</h1>
    <?php if ($lastScored): ?>
    <small style="color:var(--c-muted);font-size:var(--text-xs);">
        Dane z <?= htmlspecialchars(substr($lastScored, 0, 16)) ?>
    </small>
    <?php endif; ?>
</div>

<!-- Filter form -->
<div class="card" style="margin-bottom:1.5rem;padding:1rem 1.25rem;">
    <form method="GET" action="/screener" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;">

        <div class="form-group" style="margin:0;min-width:160px;">
            <label style="font-size:var(--text-xs);">Rekomendacja</label>
            <select name="reco">
                <option value="">— Wszystkie —</option>
                <?php foreach ($recoOptions as $opt): ?>
                <option value="<?= htmlspecialchars($opt) ?>" <?= $filter_reco === $opt ? 'selected' : '' ?>>
                    <?= htmlspecialchars($opt) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin:0;min-width:160px;">
            <label style="font-size:var(--text-xs);">Złoty sygnał</label>
            <select name="signal">
                <option value="">— Wszystkie —</option>
                <?php foreach ($signalLabels as $val => $label): ?>
                <option value="<?= htmlspecialchars($val) ?>" <?= $filter_signal === $val ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" style="margin:0;min-width:120px;">
            <label style="font-size:var(--text-xs);">Min CVS Swing</label>
            <input type="number" name="min_swing" min="0" max="100"
                   value="<?= $filter_min_swing > 0 ? $filter_min_swing : '' ?>"
                   placeholder="0–100" style="width:100%;">
        </div>

        <?php if (!empty($sectors)): ?>
        <div class="form-group" style="margin:0;min-width:160px;">
            <label style="font-size:var(--text-xs);">Sektor</label>
            <select name="sector">
                <option value="">— Wszystkie —</option>
                <?php foreach ($sectors as $sec): ?>
                <option value="<?= htmlspecialchars($sec) ?>" <?= $filter_sector === $sec ? 'selected' : '' ?>>
                    <?= htmlspecialchars($sec) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="form-group" style="margin:0;min-width:160px;">
            <label style="font-size:var(--text-xs);">Strefa ATR</label>
            <select name="atr">
                <option value="">— Wszystkie —</option>
                <option value="in_zone" <?= $filter_atr === 'in_zone' ? 'selected' : '' ?>>✓ W strefie kupna</option>
                <option value="above"   <?= $filter_atr === 'above'   ? 'selected' : '' ?>>↑ Powyżej strefy</option>
                <option value="below"   <?= $filter_atr === 'below'   ? 'selected' : '' ?>>↓ Poniżej strefy</option>
            </select>
        </div>

        <div class="form-group" style="margin:0;display:flex;align-items:center;gap:.4rem;">
            <label style="font-size:var(--text-xs);display:flex;align-items:center;gap:.35rem;cursor:pointer;">
                <input type="checkbox" name="near_boundary" value="1" <?= $filter_near_boundary ? 'checked' : '' ?>>
                Tylko pogranicze (±5 pkt)
            </label>
        </div>

        <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">

        <div style="display:flex;gap:.4rem;">
            <button type="submit" class="btn btn--primary btn--sm">Filtruj</button>
            <a href="/screener" class="btn btn--ghost btn--sm">Reset</a>
        </div>
    </form>
</div>

<!-- Results -->
<?php if (empty($rows) && $lastScored === null): ?>
<div class="card" style="text-align:center;padding:2rem;">
    <p style="color:var(--c-muted);">
        Screener buduje się — wróć po pierwszym przeliczeniu crona.
    </p>
</div>
<?php elseif (empty($rows)): ?>
<div class="card" style="text-align:center;padding:2rem;">
    <p style="color:var(--c-muted);">Brak spółek spełniających kryteria.</p>
    <a href="/screener" class="btn btn--ghost btn--sm" style="margin-top:.75rem;">Wyczyść filtry</a>
</div>
<?php else: ?>
<div class="card" style="overflow-x:auto;">
    <p style="color:var(--c-muted);font-size:var(--text-xs);margin-bottom:.75rem;">
        <?= count($rows) ?> spółek
    </p>
    <table class="pillar-table" style="width:100%;">
        <thead>
            <tr>
                <th><?= $sortLink('ticker', 'Ticker') ?></th>
                <th><?= $sortLink('swing', 'CVS Swing') . $hint('Złożony wynik 0–100 w horyzoncie swing (1–4 mies.). Wyżej = lepiej.') ?></th>
                <th><?= $sortLink('fund',  'CVS Fund') . $hint('Złożony wynik 0–100 w horyzoncie fundamentalnym (6–12 mies.).') ?></th>
                <th>Trend (d/d)<?= $hint('Zmiana CVS Swing względem poprzedniego rescore (zwykle poprzedni dzień roboczy). Bardziej zaszumiony niż w/w — pojedynczy dzień potrafi się cofnąć mimo trwałego trendu w górę, i odwrotnie.') ?></th>
                <th>Trend (w/w)<?= $hint('Zmiana CVS Swing względem ~7 dni temu. Odróżnia spółki pnące się w górę od tych, które spadają — przy identycznym dzisiejszym wyniku kierunek dojścia bywa ważniejszy niż sam poziom.') ?></th>
                <th>Rekomendacja<?= $hint('Etykieta od SILNE KUPUJ do UNIKAJ wynikająca z wyniku CVS Swing.') ?></th>
                <th>Sygnał<?= $hint('Złoty sygnał: ⭐⭐ wartość + momentum, ⭐ obserwuj (setup fundamentalny), ↑ momentum.') ?></th>
                <th>Wyniki<?= $hint('Bliskość publikacji wyników kwartalnych (📅 za N dni / w oknie / N dni temu).') ?></th>
                <th><?= $sortLink('atr', 'ATR') . $hint('Pozycja ceny względem strefy akumulacji ATR: ✓ w strefie kupna, ↑ powyżej (czekaj na cofnięcie), ↓ poniżej (pod wsparciem). Sortowanie: najpierw w strefie, potem poniżej, potem powyżej.') ?></th>
                <th>Sektor</th>
                <th><?= $sortLink('price', 'Cena') ?></th>
                <th><?= $sortLink('date', 'Data') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row):
            $swing = $row['cvs_swing'] !== null ? number_format((float) $row['cvs_swing'], 1) : '—';
            $fund  = $row['cvs_fund']  !== null ? number_format((float) $row['cvs_fund'],  1) : '—';
            $price = $row['price_at_snapshot'] !== null ? '$' . number_format((float) $row['price_at_snapshot'], 2) : '—';
            $reco  = htmlspecialchars((string) ($row['reco_swing'] ?? '—'));
            $sec   = htmlspecialchars((string) ($row['sector']     ?? '—'));
            $date  = htmlspecialchars(substr((string) $row['score_date'], 0, 10));

            // Colour reco — full 5-level palette matching watchlist chip colours
            $recoStr   = (string) ($row['reco_swing'] ?? '');
            $recoColor = match (true) {
                str_contains($recoStr, 'SILNE KUPUJ') => 'color:var(--c-success);font-weight:700;',
                str_contains($recoStr, 'AKUMULUJ')    => 'color:var(--c-primary);font-weight:700;',
                str_contains($recoStr, 'REDUKUJ')     => 'color:var(--c-warn);',
                str_contains($recoStr, 'UNIKAJ')      => 'color:var(--c-danger);',
                default                               => 'color:var(--c-muted);',
            };
        ?>
        <tr class="<?= isset($heldTickersMap[(string) $row['ticker']]) ? 'tr--held' : '' ?>">
            <td>
                <span class="ticker-hint">
                    <a href="/analysis/<?= urlencode((string) $row['ticker']) ?>"
                       style="font-weight:700;color:var(--c-fund);">
                        <?= htmlspecialchars((string) $row['ticker']) ?>
                    </a>
                    <?= $tickerHint((string) $row['ticker'], $row) ?>
                </span><?= $heldBadge((string) $row['ticker'], $recoStr) ?>
            </td>
            <td><strong style="color:var(--c-primary);"><?= $swing ?></strong></td>
            <td><strong style="color:var(--c-fund);"><?= $fund ?></strong></td>
            <td><?= $trendChip(
                $row['trend_delta_daily'] ?? null,
                'Zmiana CVS Swing względem poprzedniego rescore',
                'Brak poprzedniego punktu rescore'
            ) ?></td>
            <td><?= $trendChip(
                $row['trend_delta_weekly'] ?? null,
                'Zmiana CVS Swing względem ~7 dni temu',
                'Za mało historii w oknie 90 dni'
            ) ?></td>
            <td style="font-size:var(--text-sm);<?= $recoColor ?>"><?= $reco ?></td>
            <td><?= $signalChip($row['golden_signal'] ?? null) ?></td>
            <td><?= $earningsChip(
                $row['earnings_state']      ?? null,
                isset($row['days_to_earnings'])    ? (int) $row['days_to_earnings']    : null,
                isset($row['days_since_earnings']) ? (int) $row['days_since_earnings'] : null
            ) ?></td>
            <td><?= $atrChip($row['atr_state'] ?? null) ?></td>
            <td style="font-size:var(--text-sm);color:var(--c-muted);"><?= $sec ?></td>
            <td style="font-size:var(--text-sm);"><?= $price ?></td>
            <td style="font-size:var(--text-xs);color:var(--c-muted);"><?= $date ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<p class="disclaimer-inline" style="margin-top:1.5rem;">
    Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna.
    Dane aktualizowane codziennie. Inwestuj świadomie.
</p>
