<?php declare(strict_types=1);
/** @var array<int, array<string, mixed>> $rows */
/** @var string|null $lastScored */
/** @var string[] $sectors */
/** @var list<array{value: string, label: string}> $markets */
/** @var string|null $filter_reco */
/** @var string|null $filter_signal */
/** @var int $filter_min_swing */
/** @var string|null $filter_sector */
/** @var string|null $filter_market */
/** @var string|null $filter_atr */
/** @var bool $filter_near_boundary */
/** @var bool $filter_fv_only */
/** @var string $sort */
/** @var array<string, true> $heldTickersMap */
/** @var bool $isAdmin */
/** @var int $currentUserId */

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
$sortLink = static function (string $col, string $label) use ($sort, $filter_reco, $filter_signal, $filter_min_swing, $filter_sector, $filter_market, $filter_atr, $filter_near_boundary, $filter_fv_only): string {
    $arrow = $col === $sort ? ' ↓' : '';
    $params = http_build_query(array_filter([
        'reco'          => $filter_reco,
        'signal'        => $filter_signal,
        'min_swing'     => $filter_min_swing > 0 ? $filter_min_swing : null,
        'sector'        => $filter_sector,
        'market'        => $filter_market,
        'atr'           => $filter_atr,
        'near_boundary' => $filter_near_boundary ? '1' : null,
        'fv_only'       => $filter_fv_only ? '1' : null,
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

// FV column: CVS Fair Value margin over price, as a signed percentage.
// Same colour convention as $trendChip. Null (pre-migration row, sector where
// FairPriceCalculator can't produce a number, e.g. Financial Services) → dash,
// same "model has no opinion here" convention as $atrChip's default branch.
$fvChip = static function (?float $marginPct): string {
    if ($marginPct === null) {
        return '<span style="color:var(--c-muted);" title="Fair Value niedostępne dla tej spółki (brak danych lub sektor słabo mierzony przez EV/FCF)">—</span>';
    }
    $color = $marginPct > 0 ? 'var(--c-success)' : 'var(--c-danger)';
    $sign  = $marginPct > 0 ? '+' : '';
    $title = $marginPct > 0
        ? 'CVS Fair Value powyżej ceny — model widzi margines bezpieczeństwa'
        : 'CVS Fair Value poniżej ceny mimo rekomendacji — sprawdź filar Wyceny i Jakości przed decyzją';
    return '<span style="color:' . $color . ';font-weight:600;" title="' . htmlspecialchars($title) . '">'
        . $sign . number_format($marginPct, 0) . '%</span>';
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
    $name   = $row['company_name'] ?? null;
    $swing  = isset($row['cvs_swing']) ? (float) $row['cvs_swing'] : null;
    $fund   = isset($row['cvs_fund'])  ? (float) $row['cvs_fund']  : null;
    $sector = $row['sector'] ?? null;
    $date   = isset($row['score_date']) ? substr((string) $row['score_date'], 0, 10) : null;
    if ($name === null && $swing === null && $fund === null && $sector === null && $date === null) {
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
    if ($sector !== null || $date !== null) {
        $html .= '<span class="ticker-hint__tooltip-scores" style="font-size:var(--text-xs);">';
        if ($sector !== null) {
            $html .= '<span>' . htmlspecialchars((string) $sector) . '</span>';
        }
        if ($date !== null) {
            $html .= '<span>Rescore: ' . htmlspecialchars($date) . '</span>';
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

<!-- Filter panel -->
<div class="card screener-filter-card">
    <form method="GET" action="/screener" class="screener-filters">

        <div class="screener-filters__search">
            <label for="screener-search">
                Szukaj<?= $hint('Filtruje widoczną listę po tickerze lub nazwie spółki — tylko wśród spółek już obecnych na liście, nie w całym uniwersum.') ?>
            </label>
            <div class="ac-wrapper">
                <input type="text" id="screener-search" placeholder="Ticker lub nazwa spółki…" autocomplete="off">
                <div class="ac-dropdown" id="screener-search-dropdown" hidden></div>
            </div>
        </div>

        <div class="screener-filters__fields">
            <div class="form-group">
                <label>Rekomendacja<?= $hint('Etykieta wynikająca z CVS Swing: od SILNE KUPUJ do UNIKAJ.') ?></label>
                <select name="reco">
                    <option value="">— Wszystkie —</option>
                    <?php foreach ($recoOptions as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>" <?= $filter_reco === $opt ? 'selected' : '' ?>>
                        <?= htmlspecialchars($opt) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Złoty sygnał<?= $hint('⭐⭐ wartość + momentum, ⭐ obserwuj (setup fundamentalny), ↑ momentum. Puste = brak filtra.') ?></label>
                <select name="signal">
                    <option value="">— Wszystkie —</option>
                    <?php foreach ($signalLabels as $val => $label): ?>
                    <option value="<?= htmlspecialchars($val) ?>" <?= $filter_signal === $val ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (!empty($sectors)): ?>
            <div class="form-group">
                <label>Sektor<?= $hint('Ogranicza listę do jednego sektora GICS.') ?></label>
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

            <?php if (!empty($markets)): ?>
            <div class="form-group">
                <label>Rynek<?= $hint('Ogranicza listę do jednego rynku/giełdy, wyznaczonego sufiksem tickera (np. .WA = GPW Warszawa).') ?></label>
                <select name="market">
                    <option value="">— Wszystkie —</option>
                    <?php foreach ($markets as $m): ?>
                    <option value="<?= htmlspecialchars($m['value']) ?>" <?= $filter_market === $m['value'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($m['label']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label>Strefa ATR<?= $hint('Pozycja ceny względem strefy akumulacji wyliczonej z ATR: w strefie kupna, powyżej (czekaj na cofnięcie) lub poniżej (pod wsparciem).') ?></label>
                <select name="atr">
                    <option value="">— Wszystkie —</option>
                    <option value="in_zone" <?= $filter_atr === 'in_zone' ? 'selected' : '' ?>>✓ W strefie kupna</option>
                    <option value="above"   <?= $filter_atr === 'above'   ? 'selected' : '' ?>>↑ Powyżej strefy</option>
                    <option value="below"   <?= $filter_atr === 'below'   ? 'selected' : '' ?>>↓ Poniżej strefy</option>
                </select>
            </div>

            <div class="form-group">
                <label for="filter-min-swing">Min CVS Swing<?= $hint('Pokazuje tylko spółki z wynikiem CVS Swing co najmniej tak wysokim (skala 0–100).') ?></label>
                <input type="number" id="filter-min-swing" name="min_swing" min="0" max="100"
                       value="<?= $filter_min_swing > 0 ? $filter_min_swing : '' ?>"
                       placeholder="0–100">
            </div>
        </div>

        <div class="screener-filters__footer">
            <div class="screener-filters__toggles">
                <div class="filter-toggle">
                    <label class="filter-toggle__control">
                        <input type="checkbox" name="near_boundary" value="1" <?= $filter_near_boundary ? 'checked' : '' ?>>
                        <span class="filter-toggle__pill">Tylko pogranicze (±5 pkt)</span>
                    </label>
                    <?= $hint('Tylko spółki, których CVS Swing leży blisko progu rekomendacji — mały ruch wystarczy, by etykieta się zmieniła, więc warto je obserwować.') ?>
                </div>

                <div class="filter-toggle">
                    <label class="filter-toggle__control">
                        <input type="checkbox" name="fv_only" value="1" <?= $filter_fv_only ? 'checked' : '' ?>>
                        <span class="filter-toggle__pill">Tylko FV &gt; cena</span>
                    </label>
                    <?= $hint('Tylko spółki, dla których model widzi margines bezpieczeństwa: implikowana wartość godziwa CVS (Fair Value) jest powyżej bieżącej ceny.') ?>
                </div>
            </div>

            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">

            <div class="screener-filters__actions">
                <button type="submit" class="btn btn--primary btn--sm">Filtruj</button>
                <a href="/screener" class="btn btn--ghost btn--sm">Reset</a>
            </div>
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
<div class="card screener-results-card">
    <p id="screener-count" style="color:var(--c-muted);font-size:var(--text-xs);margin-bottom:.75rem;" data-total="<?= count($rows) ?>">
        <?= count($rows) ?> spółek
    </p>
    <div class="screener-table-scroll">
    <table class="pillar-table" id="screener-table" style="width:100%;"
           data-is-admin="<?= $isAdmin ? '1' : '0' ?>" data-user-id="<?= (int) $currentUserId ?>">
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
                <th><?= $sortLink('fv', 'FV') . $hint('Margines implikowanej wartości godziwej CVS (Fair Value) nad lub pod bieżącą ceną. Dodatni = model widzi margines bezpieczeństwa. Ujemny mimo dobrej rekomendacji = sygnał ciągnięty głównie przez momentum, nie przez wycenę — sprawdź kartę spółki przed decyzją.') ?></th>
                <th><?= $sortLink('price', 'Cena') ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $row):
            $swing = $row['cvs_swing'] !== null ? number_format((float) $row['cvs_swing'], 1) : '—';
            $fund  = $row['cvs_fund']  !== null ? number_format((float) $row['cvs_fund'],  1) : '—';
            $price = $row['price_at_snapshot'] !== null ? '$' . number_format((float) $row['price_at_snapshot'], 2) : '—';
            $reco  = htmlspecialchars((string) ($row['reco_swing'] ?? '—'));

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
        <tr class="<?= isset($heldTickersMap[(string) $row['ticker']]) ? 'tr--held' : '' ?>"
            data-ticker="<?= htmlspecialchars((string) $row['ticker']) ?>"
            data-company="<?= htmlspecialchars((string) ($row['company_name'] ?? '')) ?>"
            data-links="<?= htmlspecialchars(json_encode($row['ticker_links'] ?? [], JSON_UNESCAPED_UNICODE) ?: '[]', ENT_QUOTES) ?>">
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
            <td><?= $fvChip(isset($row['fv_margin_pct']) ? (float) $row['fv_margin_pct'] : null) ?></td>
            <td style="font-size:var(--text-sm);"><?= $price ?></td>
        </tr>
        <?php endforeach; ?>
        <tr id="screener-search-empty" hidden>
            <td colspan="11" style="text-align:center;color:var(--c-muted);padding:1.5rem 0;">
                Brak spółek na liście pasujących do wyszukiwania.
            </td>
        </tr>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- Ticker "favourite links" right-click menu (desktop only) — markup is
     empty on load, filled/positioned by app.js on contextmenu; moved to a
     direct <body> child there too (see .ticker-link-menu CSS comment). -->
<div id="ticker-link-menu" class="ticker-link-menu" hidden></div>

<!-- Add-link modal — any authenticated user may add a link (removal is
     ownership/admin-gated, see .ticker-link-menu__remove in app.js); /screener
     itself already requires auth, so no extra guard is needed here. -->
<div id="ticker-link-add-modal" class="ai-modal" hidden>
    <div class="ai-modal__inner" style="max-width:360px;">
        <h3 style="margin-bottom:1rem;font-size:var(--text-base);">
            Dodaj link — <span id="ticker-link-add-ticker"></span>
        </h3>
        <div class="form-group" style="margin-bottom:.75rem;text-align:left;">
            <label for="ticker-link-label-input">Etykieta</label>
            <input id="ticker-link-label-input" type="text" placeholder="np. TradingView" maxlength="80" autocomplete="off">
        </div>
        <div class="form-group" style="margin-bottom:.75rem;text-align:left;">
            <label for="ticker-link-url-input">Adres URL</label>
            <input id="ticker-link-url-input" type="text" placeholder="https://…" maxlength="500" autocomplete="off">
        </div>
        <div id="ticker-link-add-error" class="alert alert--error" style="display:none;margin-bottom:.75rem;"></div>
        <div style="display:flex;gap:.5rem;justify-content:center;">
            <button id="ticker-link-add-submit" type="button" class="btn btn--primary btn--sm">Dodaj</button>
            <button id="ticker-link-add-cancel" type="button" class="btn btn--ghost btn--sm">Anuluj</button>
        </div>
    </div>
</div>

<p class="disclaimer-inline" style="margin-top:1.5rem;">
    Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna.
    Dane aktualizowane codziennie. Inwestuj świadomie.
</p>
