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

// Same portal-hint pattern as the screener column headers (public/js/app.js
// .chart-hint / .chart-hint-portal) — a plain position:absolute tooltip would
// be clipped by this table's card (overflow-x:auto computes overflow-y:auto
// too, per the CSS overflow spec), so the tooltip is rendered via a single
// body-level portal instead. Shared with the chart-modal legend below so the
// wording for EV/FCF/EV/Sales/GM% never drifts between the two places.
$hint = static fn (string $text): string =>
    ' <span class="chart-hint" tabindex="0">&#9432;<span class="chart-hint__tooltip">' . $text . '</span></span>';

$hintSektor = 'Sektor giełdowy wg klasyfikacji Yahoo Finance. Kliknij wiersz, aby rozwinąć podsektory (industries) — każdy z własną, węższą medianą.';
$hintCron = 'Dzień tygodnia, w którym rolujący cron przelicza mediany tego sektora (jeden sektor dziennie, żeby nie przekroczyć limitów zapytań Yahoo Finance). Np. „Pon" = mediany odświeżają się automatycznie w każdy poniedziałek.';
$hintStatus = 'Zaindeksowany = mediana policzona i używana jako benchmark w filarze Wyceny/Jakości. Niezaindeksowany = model tymczasowo używa statycznego benchmarku całego sektora zamiast węższej mediany tego podsektora.';
$hintAktualizacja = 'Data i godzina ostatniego przeliczenia median. Np. wpis sprzed 8 dni przy cronie ustawionym na „Pon" może oznaczać, że ostatni przebieg się nie powiódł — sprawdź log albo kliknij „Odśwież".';
$hintNSpolek = 'Liczba spółek użyta do policzenia mediany. Próg minimalny to 5 — poniżej niego model automatycznie cofa się do mediany szerszego sektora, żeby nie opierać wyceny na zbyt małej, niepewnej próbie (np. 2 spółki w wąskim podsektorze).';
$hintEvFcf = 'Mediana mnożnika EV/FCF (wartość przedsiębiorstwa / wolne przepływy pieniężne) w tej grupie — główny benchmark filaru Wyceny. Np. spółka z EV/FCF=15 przy medianie 30 handluje z ok. 2× dyskontem, więc dostanie wysoki wynik Wyceny.';
$hintEvSales = 'Mediana mnożnika EV/Sprzedaż — zastępuje EV/FCF, gdy spółka ma ujemne wolne przepływy pieniężne (Wariant B, typowo spółki wzrostowe reinwestujące całą gotówkę). Np. mediana 8× oznacza, że typowa spółka w grupie jest wyceniana na 8-krotność rocznej sprzedaży.';
$hintGm = 'Mediana marży brutto (zysk brutto / przychody) w grupie — benchmark filaru Jakości. Np. spółka z marżą 45% przy medianie 35% (+10pp) dostaje wysokie punkty za ten składnik filaru Jakości.';
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
            <th style="width:22%;">Sektor<?= $hint($hintSektor) ?></th>
            <th style="width:7%;text-align:center;">Cron<?= $hint($hintCron) ?></th>
            <th style="width:13%;">Status<?= $hint($hintStatus) ?></th>
            <th style="width:14%;">Aktualizacja<?= $hint($hintAktualizacja) ?></th>
            <th style="width:7%;text-align:right;">N spółek<?= $hint($hintNSpolek) ?></th>
            <th style="width:9%;text-align:right;">EV/FCF<?= $hint($hintEvFcf) ?></th>
            <th style="width:9%;text-align:right;">EV/Sales<?= $hint($hintEvSales) ?></th>
            <th style="width:8%;text-align:right;">GM%<?= $hint($hintGm) ?></th>
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
        <div id="sector-history-legend" style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-bottom:.75rem;font-size:var(--text-sm);">
            <span style="display:inline-flex;align-items:center;gap:.4rem;">
                <span style="width:10px;height:10px;border-radius:2px;background:rgba(64,144,224,.9);display:inline-block;"></span>
                EV/FCF<?= $hint($hintEvFcf) ?>
            </span>
            <span style="display:inline-flex;align-items:center;gap:.4rem;">
                <span style="width:10px;height:10px;border-radius:2px;background:rgba(250,204,21,.9);display:inline-block;"></span>
                EV/Sales<?= $hint($hintEvSales) ?>
            </span>
            <span style="display:inline-flex;align-items:center;gap:.4rem;">
                <span style="width:10px;height:10px;border-radius:2px;background:rgba(52,211,153,.9);display:inline-block;"></span>
                GM%<?= $hint($hintGm) ?>
            </span>
        </div>
        <canvas id="sector-history-chart" style="display:none;"></canvas>
    </div>
</div>
