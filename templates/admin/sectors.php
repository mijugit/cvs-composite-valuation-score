<?php declare(strict_types=1);

/**
 * Admin: Sector peer-median indexing status.
 * Variables injected by SectorsController::index():
 *   $allSectors   — string[]
 *   $sectorDay    — ['Technology' => 'Pon', ...]
 *   $sectorStats  — ['Technology' => ['computed_at'=>..., 'sample_count'=>..., 'ev_fcf'=>..., 'ev_sales'=>..., 'gm'=>...], ...]
 *   $industryStats— ['Software—Application' => ['parent_sector'=>'Technology', ...], ...]
 *   $modelVersion — string
 */

function fmt(?float $v): string {
    return $v !== null ? number_format($v, 1, '.', '') : '—';
}
?>

<div style="display:flex;align-items:center;gap:1rem;margin-bottom:1.5rem;">
    <h1 style="margin:0;">Sektory — stan indeksowania</h1>
    <span class="signal-pill signal-pill--neutral" style="font-size:.75rem;">
        Model v<?= htmlspecialchars($modelVersion) ?>
    </span>
</div>

<p style="color:var(--c-text-muted);margin-bottom:1.5rem;font-size:.875rem;">
    Mediany peer-group obliczane przez cron rolujący (jeden sektor dziennie).
    Kliknij wiersz sektora, aby zobaczyć podsektory (industries).
    Przycisk <strong>Odśwież</strong> uruchamia batch w tle niezależnie od harmonogramu.
</p>

<div class="card" style="padding:0;overflow:hidden;">
<table class="pillar-table sectors-table" style="width:100%;">
    <thead>
        <tr>
            <th style="width:22%;">Sektor</th>
            <th style="width:7%;text-align:center;">Cron</th>
            <th style="width:13%;">Status</th>
            <th style="width:14%;">Aktualizacja</th>
            <th style="width:7%;text-align:right;">N spółek</th>
            <th style="width:9%;text-align:right;">EV/FCF</th>
            <th style="width:9%;text-align:right;">EV/Sales</th>
            <th style="width:8%;text-align:right;">GM%</th>
            <th style="width:11%;text-align:center;">Akcja</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($allSectors as $sectorName):
        $stat    = $sectorStats[$sectorName] ?? null;
        $indexed = $stat !== null;
        $day     = htmlspecialchars($sectorDay[$sectorName] ?? '?');

        // Industries belonging to this sector
        $industries = array_filter(
            $industryStats,
            static fn($i) => ($i['parent_sector'] ?? '') === $sectorName
        );
        $hasIndustries = !empty($industries);
        $sectorSlug    = preg_replace('/[^a-z0-9]+/', '-', strtolower($sectorName));
    ?>
    <tr class="sector-row<?= $hasIndustries ? '' : ' sector-row--no-children' ?>"
        data-sector="<?= htmlspecialchars($sectorSlug) ?>"
        title="<?= $hasIndustries ? 'Kliknij, aby zobaczyć podsektory' : 'Brak zaindeksowanych podsektorów' ?>">
        <td>
            <?php if ($hasIndustries): ?>
            <span class="sector-row__arrow">▶</span>
            <?php endif; ?>
            <?= htmlspecialchars($sectorName) ?>
        </td>
        <td style="text-align:center;color:var(--c-text-muted);font-size:.8125rem;"><?= $day ?></td>
        <td>
            <?php if ($indexed): ?>
            <span class="signal-pill signal-pill--momentum">Zaindeksowany</span>
            <?php else: ?>
            <span class="signal-pill signal-pill--neutral">Niezaindeksowany</span>
            <?php endif; ?>
        </td>
        <td style="font-size:.8125rem;color:var(--c-text-muted);">
            <?= $indexed ? htmlspecialchars(substr((string)$stat['computed_at'], 0, 16)) : '—' ?>
        </td>
        <td style="text-align:right;"><?= $indexed ? (int)$stat['sample_count'] : '—' ?></td>
        <td style="text-align:right;"><?= $indexed ? fmt($stat['ev_fcf'])  : '—' ?></td>
        <td style="text-align:right;"><?= $indexed ? fmt($stat['ev_sales']) : '—' ?></td>
        <td style="text-align:right;"><?= $indexed ? fmt($stat['gm'])      : '—' ?></td>
        <td style="text-align:center;">
            <?php if ($isAdmin ?? true): ?>
            <button class="btn btn--ghost btn--sm js-refresh-sector"
                    data-sector="<?= htmlspecialchars($sectorName) ?>">
                Odśwież
            </button>
            <?php endif; ?>
            <button class="btn btn--ghost btn--sm js-sector-chart"
                    data-level="sector"
                    data-bucket="<?= htmlspecialchars($sectorName) ?>"
                    title="Historia median">📊</button>
        </td>
    </tr>

    <?php foreach ($industries as $industryName => $iStat):
        $iIndexed = $iStat !== null;
    ?>
    <tr class="industry-row industry-row--<?= htmlspecialchars($sectorSlug) ?>" hidden>
        <td style="padding-left:2rem;color:var(--c-text-muted);font-size:.8125rem;">
            ↳ <?= htmlspecialchars((string)$industryName) ?>
        </td>
        <td></td>
        <td>
            <span class="signal-pill signal-pill--momentum" style="font-size:.7rem;padding:.1rem .4rem;">
                Zaindeksowany
            </span>
        </td>
        <td style="font-size:.8125rem;color:var(--c-text-muted);">
            <?= htmlspecialchars(substr((string)($iStat['computed_at'] ?? ''), 0, 16)) ?>
        </td>
        <td style="text-align:right;font-size:.8125rem;"><?= (int)($iStat['sample_count'] ?? 0) ?></td>
        <td style="text-align:right;font-size:.8125rem;"><?= fmt($iStat['ev_fcf']  ?? null) ?></td>
        <td style="text-align:right;font-size:.8125rem;"><?= fmt($iStat['ev_sales'] ?? null) ?></td>
        <td style="text-align:right;font-size:.8125rem;"><?= fmt($iStat['gm']      ?? null) ?></td>
        <td style="text-align:center;">
            <button class="btn btn--ghost btn--sm js-sector-chart"
                    data-level="industry"
                    data-bucket="<?= htmlspecialchars((string)$industryName) ?>"
                    title="Historia median">📊</button>
        </td>
    </tr>
    <?php endforeach; ?>

    <?php endforeach; ?>
    </tbody>
</table>
</div>

<div id="sectors-toast" class="sectors-toast" hidden></div>

<div id="sector-history-modal" class="ai-modal" hidden
     data-history-base="<?= htmlspecialchars($historyEndpoint ?? '/admin/sectors/history') ?>">
    <div class="ai-modal__inner" style="max-width:700px;text-align:left;width:calc(100% - 2rem);">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:1rem;">
            <h3 id="sector-history-title" style="font-size:var(--text-lg);margin:0;">Historia: —</h3>
            <button id="sector-history-close" class="btn btn--ghost btn--sm">✕</button>
        </div>
        <p id="sector-history-empty" style="color:var(--c-text-muted);text-align:center;padding:2rem 0;" hidden>
            Brak danych historycznych. Dane zaczną się gromadzić od następnego odświeżenia.
        </p>
        <canvas id="sector-history-chart" style="display:none;"></canvas>
    </div>
</div>
