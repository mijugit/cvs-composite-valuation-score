<?php declare(strict_types=1);

/**
 * Shared sector/industry peer-median history modal + Chart.js canvas.
 * Reused by templates/admin/sectors.php and templates/analysis.php — any
 * element with class="js-sector-chart" data-level="sector|industry"
 * data-bucket="<name>" opens it (public/js/app.js, "Sector history modal"
 * block). Despite living next to admin markup originally, the JS and the
 * underlying endpoints are page-agnostic; $historyEndpoint below is what
 * actually scopes it to admin-only vs everyone-logged-in data.
 *
 * @var string $historyEndpoint '/admin/sectors/history' (admin) or '/sectors/history' (any logged-in user)
 */

$sectorHint = static fn (string $text): string =>
    ' <span class="chart-hint" tabindex="0">&#9432;<span class="chart-hint__tooltip">' . $text . '</span></span>';
?>
<div id="sector-history-modal" class="ai-modal" hidden
     data-history-base="<?= htmlspecialchars($historyEndpoint) ?>">
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
                EV/FCF<?= $sectorHint('Mediana mnożnika EV/FCF (wartość przedsiębiorstwa / wolne przepływy pieniężne) w tej grupie — główny benchmark filaru Wyceny.') ?>
            </span>
            <span style="display:inline-flex;align-items:center;gap:.4rem;">
                <span style="width:10px;height:10px;border-radius:2px;background:rgba(250,204,21,.9);display:inline-block;"></span>
                EV/Sales<?= $sectorHint('Mediana mnożnika EV/Sprzedaż — zastępuje EV/FCF, gdy spółka ma ujemne wolne przepływy pieniężne (typowo spółki wzrostowe reinwestujące całą gotówkę).') ?>
            </span>
            <span style="display:inline-flex;align-items:center;gap:.4rem;">
                <span style="width:10px;height:10px;border-radius:2px;background:rgba(52,211,153,.9);display:inline-block;"></span>
                GM%<?= $sectorHint('Mediana marży brutto (zysk brutto / przychody) w grupie — benchmark filaru Jakości.') ?>
            </span>
        </div>
        <canvas id="sector-history-chart" style="display:none;"></canvas>
    </div>
</div>
