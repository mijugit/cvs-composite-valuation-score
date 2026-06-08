<?php declare(strict_types=1);
/** @var array<int, array<string, mixed>> $rows */
/** @var string|null $lastScored */
/** @var string[] $sectors */
/** @var string|null $filter_reco */
/** @var string|null $filter_signal */
/** @var int $filter_min_swing */
/** @var string|null $filter_sector */
/** @var string $sort */

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
$sortLink = static function (string $col, string $label) use ($sort, $filter_reco, $filter_signal, $filter_min_swing, $filter_sector): string {
    $arrow = $col === $sort ? ' ↓' : '';
    $params = http_build_query(array_filter([
        'reco'      => $filter_reco,
        'signal'    => $filter_signal,
        'min_swing' => $filter_min_swing > 0 ? $filter_min_swing : null,
        'sector'    => $filter_sector,
        'sort'      => $col,
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
                <th>Ticker</th>
                <th><?= $sortLink('swing', 'CVS Swing') ?></th>
                <th><?= $sortLink('fund',  'CVS Fund') ?></th>
                <th>Rekomendacja</th>
                <th>Sygnał</th>
                <th>Wyniki</th>
                <th>Sektor</th>
                <th>Cena</th>
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
        <tr>
            <td>
                <a href="/analysis/<?= urlencode((string) $row['ticker']) ?>"
                   style="font-weight:700;color:var(--c-fund);">
                    <?= htmlspecialchars((string) $row['ticker']) ?>
                </a>
            </td>
            <td><strong style="color:var(--c-primary);"><?= $swing ?></strong></td>
            <td><strong style="color:var(--c-fund);"><?= $fund ?></strong></td>
            <td style="font-size:var(--text-sm);<?= $recoColor ?>"><?= $reco ?></td>
            <td><?= $signalChip($row['golden_signal'] ?? null) ?></td>
            <td><?= $earningsChip(
                $row['earnings_state']      ?? null,
                isset($row['days_to_earnings'])    ? (int) $row['days_to_earnings']    : null,
                isset($row['days_since_earnings']) ? (int) $row['days_since_earnings'] : null
            ) ?></td>
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
