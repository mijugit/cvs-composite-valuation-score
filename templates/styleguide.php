<?php declare(strict_types=1); ?>
<style>
.sg-section  { margin-bottom: 3rem; }
.sg-heading  { font-size: var(--text-xl); font-weight: 700; margin-bottom: 1.25rem;
               padding-bottom: .5rem; border-bottom: 1px solid var(--c-border); color: var(--c-text); }
.sg-label    { font-size: var(--text-xs); color: var(--c-muted); text-transform: uppercase;
               letter-spacing: .06em; margin-bottom: .4rem; }
.sg-row      { display: flex; flex-wrap: wrap; gap: .75rem; align-items: flex-start; margin-bottom: 1rem; }
.sg-swatch   { display: flex; flex-direction: column; align-items: center; gap: .35rem; }
.sg-swatch__box { width: 56px; height: 56px; border-radius: var(--radius); border: 1px solid var(--c-border); }
.sg-swatch__name { font-size: .65rem; color: var(--c-muted); text-align: center; }
.sg-space   { display: flex; align-items: flex-end; gap: .5rem; }
.sg-space__bar { background: var(--c-primary); border-radius: 2px; width: 20px; }
.sg-scale   { display: flex; flex-wrap: wrap; gap: .5rem 1.5rem; }
.sg-tile    { background: var(--c-surface); border: 1px solid var(--c-border);
              border-radius: var(--radius); padding: 1rem 1.25rem; }
.sg-code    { font-size: var(--text-xs); background: var(--c-bg); padding: .15rem .45rem;
              border-radius: 4px; color: var(--c-muted); font-family: monospace; }
</style>

<h1 style="font-size:var(--text-2xl);font-weight:700;margin-bottom:.5rem;">CVS Styleguide</h1>
<p style="color:var(--c-muted);margin-bottom:2.5rem;">Żywa galeria tokenów i komponentów. Referencja dla nowych widoków fazy 2.</p>

<!-- ============================================================ -->
<!-- TOKENY — KOLORY                                              -->
<!-- ============================================================ -->
<div class="sg-section">
    <h2 class="sg-heading">Tokeny — Kolory</h2>

    <div class="sg-label">Tła / powierzchnie</div>
    <div class="sg-row" style="margin-bottom:1.25rem;">
        <?php foreach ([
            ['--c-bg',        'c-bg'],
            ['--c-surface',   'c-surface'],
            ['--c-surface-2', 'c-surface-2'],
            ['--c-border',    'c-border'],
        ] as [$var, $name]): ?>
        <div class="sg-swatch">
            <div class="sg-swatch__box" style="background:var(<?= $var ?>);"></div>
            <div class="sg-swatch__name"><?= $name ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="sg-label">Tekst</div>
    <div class="sg-row" style="margin-bottom:1.25rem;">
        <?php foreach ([
            ['--c-text',  'c-text'],
            ['--c-muted', 'c-muted'],
        ] as [$var, $name]): ?>
        <div class="sg-swatch">
            <div class="sg-swatch__box" style="background:var(<?= $var ?>);"></div>
            <div class="sg-swatch__name"><?= $name ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="sg-label">Semantyczne / brand</div>
    <div class="sg-row">
        <?php foreach ([
            ['--c-primary', 'c-primary', 'niebieski'],
            ['--c-fund',    'c-fund',    'żółty (akcent)'],
            ['--c-success', 'c-success', 'zielony'],
            ['--c-warn',    'c-warn',    'amber'],
            ['--c-danger',  'c-danger',  'czerwony'],
        ] as [$var, $name, $desc]): ?>
        <div class="sg-swatch">
            <div class="sg-swatch__box" style="background:var(<?= $var ?>);"></div>
            <div class="sg-swatch__name"><?= $name ?></div>
            <div class="sg-swatch__name" style="color:var(--c-muted)"><?= $desc ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ============================================================ -->
<!-- TOKENY — TYPOGRAFIA                                          -->
<!-- ============================================================ -->
<div class="sg-section">
    <h2 class="sg-heading">Tokeny — Typografia</h2>
    <div class="sg-scale">
        <?php foreach ([
            ['--text-xs',   'text-xs',   '0.75rem'],
            ['--text-sm',   'text-sm',   '0.875rem'],
            ['--text-base', 'text-base', '1rem'],
            ['--text-lg',   'text-lg',   '1.125rem'],
            ['--text-xl',   'text-xl',   '1.25rem'],
            ['--text-2xl',  'text-2xl',  '1.5rem'],
        ] as [$var, $name, $size]): ?>
        <div style="font-size:var(<?= $var ?>);">
            Aa <span class="sg-code"><?= $name ?> (<?= $size ?>)</span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ============================================================ -->
<!-- TOKENY — ODSTĘPY                                             -->
<!-- ============================================================ -->
<div class="sg-section">
    <h2 class="sg-heading">Tokeny — Odstępy</h2>
    <div class="sg-space">
        <?php foreach ([1,2,3,4,5,6] as $n): ?>
        <div class="sg-swatch">
            <div class="sg-space__bar" style="height:var(--space-<?= $n ?>);"></div>
            <div class="sg-swatch__name">space-<?= $n ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ============================================================ -->
<!-- TOKENY — RADIUS & SHADOW                                     -->
<!-- ============================================================ -->
<div class="sg-section">
    <h2 class="sg-heading">Tokeny — Radius i Cień</h2>
    <div class="sg-row">
        <?php foreach ([
            ['--radius-sm',   'radius-sm'],
            ['--radius',      'radius'],
            ['--radius-lg',   'radius-lg'],
            ['--radius-pill', 'radius-pill'],
        ] as [$var, $name]): ?>
        <div class="sg-swatch">
            <div class="sg-swatch__box" style="background:var(--c-surface);border-radius:var(<?= $var ?>);"></div>
            <div class="sg-swatch__name"><?= $name ?></div>
        </div>
        <?php endforeach; ?>

        <div class="sg-swatch" style="margin-left:1.5rem;">
            <div class="sg-swatch__box" style="background:var(--c-surface);box-shadow:var(--shadow);border:none;"></div>
            <div class="sg-swatch__name">shadow</div>
        </div>
        <div class="sg-swatch">
            <div class="sg-swatch__box" style="background:var(--c-surface);box-shadow:var(--shadow-lg);border:none;"></div>
            <div class="sg-swatch__name">shadow-lg</div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- KOMPONENTY — PRZYCISKI                                       -->
<!-- ============================================================ -->
<div class="sg-section">
    <h2 class="sg-heading">Komponenty — Przyciski</h2>
    <div class="sg-row">
        <button class="btn btn--primary">Primary</button>
        <button class="btn btn--secondary">Secondary</button>
        <button class="btn btn--ghost">Ghost</button>
        <button class="btn btn--danger">Danger</button>
    </div>
    <div class="sg-row">
        <button class="btn btn--primary btn--sm">Primary sm</button>
        <button class="btn btn--secondary btn--sm">Secondary sm</button>
        <button class="btn btn--ghost btn--sm">Ghost sm</button>
    </div>
    <div class="sg-row">
        <button class="btn btn--primary btn--lg">Primary lg</button>
    </div>
</div>

<!-- ============================================================ -->
<!-- KOMPONENTY — KARTA                                           -->
<!-- ============================================================ -->
<div class="sg-section">
    <h2 class="sg-heading">Komponenty — Karta</h2>
    <div class="sg-row" style="align-items:stretch;">
        <div class="card" style="flex:1;min-width:180px;">
            <div class="sg-label">card (domyślna)</div>
            <p style="color:var(--c-muted);font-size:var(--text-sm);">Treść karty</p>
        </div>
        <div class="card card--result" style="flex:1;min-width:180px;">
            <div class="sg-label">card--result</div>
            <p style="color:var(--c-muted);font-size:var(--text-sm);">Border primary</p>
        </div>
        <div class="card card--fail" style="flex:1;min-width:180px;">
            <div class="sg-label">card--fail</div>
            <p style="color:var(--c-muted);font-size:var(--text-sm);">Border danger</p>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- KOMPONENTY — SCORE TILE (canonical)                          -->
<!-- ============================================================ -->
<div class="sg-section">
    <h2 class="sg-heading">Komponenty — Score Tile</h2>
    <p style="color:var(--c-muted);font-size:var(--text-sm);margin-bottom:1rem;">
        Kanoniczny komponent. Zastępuje <span class="sg-code">score-badge</span> po Fazie 4.
    </p>
    <div class="sg-row">
        <div style="display:flex;flex-direction:column;align-items:center;gap:.3rem;">
            <div class="score-tile score-tile--swing score-tile--strong">
                <span class="score-tile__mode">Swing</span>
                <span class="score-tile__value">74</span>
                <span class="score-tile__reco">Silne kupuj</span>
            </div>
            <span class="sg-code">strong</span>
        </div>
        <div style="display:flex;flex-direction:column;align-items:center;gap:.3rem;">
            <div class="score-tile score-tile--fund score-tile--strong">
                <span class="score-tile__mode">Fund</span>
                <span class="score-tile__value">68</span>
                <span class="score-tile__reco">Kupuj</span>
            </div>
            <span class="sg-code">fund + strong</span>
        </div>
        <div style="display:flex;flex-direction:column;align-items:center;gap:.3rem;">
            <div class="score-tile score-tile--swing score-tile--neutral">
                <span class="score-tile__mode">Swing</span>
                <span class="score-tile__value">50</span>
                <span class="score-tile__reco">Neutralna</span>
            </div>
            <span class="sg-code">neutral</span>
        </div>
        <div style="display:flex;flex-direction:column;align-items:center;gap:.3rem;">
            <div class="score-tile score-tile--swing score-tile--weak">
                <span class="score-tile__mode">Swing</span>
                <span class="score-tile__value">22</span>
                <span class="score-tile__reco">Unikaj</span>
            </div>
            <span class="sg-code">weak</span>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- KOMPONENTY — SIGNAL PILL (canonical)                         -->
<!-- ============================================================ -->
<div class="sg-section">
    <h2 class="sg-heading">Komponenty — Signal Pill</h2>
    <p style="color:var(--c-muted);font-size:var(--text-sm);margin-bottom:1rem;">
        Kanoniczny komponent. Zastępuje <span class="sg-code">result-card__signal</span> po Fazie 4.
    </p>
    <div class="sg-row">
        <span class="signal-pill signal-pill--strong">⭐⭐ Złoty sygnał</span>
        <span class="signal-pill signal-pill--watchlist">⭐ Obserwuj</span>
        <span class="signal-pill signal-pill--momentum">↑ Momentum</span>
    </div>
</div>

<!-- ============================================================ -->
<!-- KOMPONENTY — RECO BADGE                                      -->
<!-- ============================================================ -->
<div class="sg-section">
    <h2 class="sg-heading">Komponenty — Reco Badge</h2>
    <div class="sg-row">
        <span class="reco-badge reco-badge--strong-buy">Silne kupuj</span>
        <span class="reco-badge reco-badge--buy">Kupuj</span>
        <span class="reco-badge reco-badge--neutral">Neutralna</span>
        <span class="reco-badge reco-badge--sell">Sprzedaj</span>
        <span class="reco-badge reco-badge--strong-sell">Silna sprzedaż</span>
    </div>
</div>

<!-- ============================================================ -->
<!-- KOMPONENTY — STAT CHIP                                       -->
<!-- ============================================================ -->
<div class="sg-section">
    <h2 class="sg-heading">Komponenty — Stat Chip</h2>
    <div class="sg-row">
        <span class="stat-chip stat-chip--up">+10.4%</span>
        <span class="stat-chip stat-chip--down">−3.1%</span>
        <span class="stat-chip stat-chip--flat">0.0%</span>
    </div>
</div>

<!-- ============================================================ -->
<!-- KOMPONENTY — PROGRESS BAR                                    -->
<!-- ============================================================ -->
<div class="sg-section">
    <h2 class="sg-heading">Komponenty — Progress Bar</h2>
    <div style="display:flex;flex-direction:column;gap:.75rem;max-width:480px;">
        <?php foreach ([
            ['74%', 'primary',  ''],
            ['55%', 'success',  '--success'],
            ['45%', 'warn',     '--warn'],
            ['22%', 'danger',   '--danger'],
            ['68%', 'fund (żółty)', '--fund'],
        ] as [$w, $label, $mod]): ?>
        <div>
            <div class="sg-label"><?= $label ?> (<?= $w ?>)</div>
            <div class="progress-bar">
                <div class="progress-bar__track">
                    <div class="progress-bar__fill<?= $mod ? ' progress-bar__fill'.$mod : '' ?>" style="width:<?= $w ?>;"></div>
                </div>
                <span style="font-size:var(--text-xs);color:var(--c-muted);min-width:2.5rem;"><?= $w ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ============================================================ -->
<!-- KOMPONENTY — ALERT                                           -->
<!-- ============================================================ -->
<div class="sg-section">
    <h2 class="sg-heading">Komponenty — Alert</h2>
    <div style="display:flex;flex-direction:column;gap:.6rem;max-width:600px;">
        <div class="alert alert--error">alert--error: Nie udało się pobrać danych spółki.</div>
        <div class="alert alert--success">alert--success: Analiza zakończona pomyślnie.</div>
        <div class="alert alert--warn">alert--warn: Dane mogą być opóźnione o 15 min.</div>
        <div class="alert alert--info">alert--info: Zaloguj się, aby zobaczyć wyniki.</div>
    </div>
</div>

<!-- ============================================================ -->
<!-- KOMPONENTY — FORMULARZ                                       -->
<!-- ============================================================ -->
<div class="sg-section">
    <h2 class="sg-heading">Komponenty — Formularz</h2>
    <div class="card" style="max-width:420px;">
        <form class="form">
            <div class="form-group">
                <label>Ticker spółki</label>
                <input type="text" placeholder="np. AAPL, MSFT">
            </div>
            <div class="form-group">
                <label>Notatka</label>
                <textarea rows="3" placeholder="Opcjonalna notatka…"></textarea>
            </div>
            <button type="button" class="btn btn--primary">Analizuj</button>
        </form>
    </div>
</div>

<!-- ============================================================ -->
<!-- KOMPONENTY — TABELA                                          -->
<!-- ============================================================ -->
<div class="sg-section">
    <h2 class="sg-heading">Komponenty — Tabela</h2>
    <table class="pillar-table" style="max-width:560px;">
        <thead>
            <tr>
                <th>Filar</th>
                <th>Wynik</th>
                <th>Waga (swing)</th>
                <th>Udział</th>
            </tr>
        </thead>
        <tbody>
            <tr><td>Wycena</td><td>62</td><td>40%</td><td>24.8</td></tr>
            <tr><td>Momentum</td><td>78</td><td>45%</td><td>35.1</td></tr>
            <tr><td>Jakość</td><td>55</td><td>15%</td><td>8.25</td></tr>
        </tbody>
    </table>
</div>

<!-- ============================================================ -->
<!-- ALIASY JS (obecny stan do Fazy 4)                            -->
<!-- ============================================================ -->
<div class="sg-section">
    <h2 class="sg-heading">Aliasy JS — obecny stan (do Fazy 4)</h2>
    <p style="color:var(--c-muted);font-size:var(--text-sm);margin-bottom:1rem;">
        Klasy generowane przez <span class="sg-code">app.js</span> — działają przez aliasy w
        <span class="sg-code">components.css</span>. Zostaną zastąpione kanonicznymi po Fazie 4.
    </p>
    <div class="sg-row">
        <div class="result-card__scores" style="display:flex;gap:.5rem;">
            <div class="score-badge score-badge--swing score-badge--strong">
                <span class="score-badge__mode">Swing</span>
                <span class="score-badge__value">74</span>
                <span class="score-badge__reco">Silne kupuj</span>
            </div>
            <div class="score-badge score-badge--fund score-badge--strong">
                <span class="score-badge__mode">Fund</span>
                <span class="score-badge__value">68</span>
                <span class="score-badge__reco">Kupuj</span>
            </div>
        </div>
        <span class="result-card__signal result-card__signal--strong">⭐⭐ Złoty sygnał</span>
        <span class="result-card__signal result-card__signal--watchlist">⭐ Obserwuj</span>
        <span class="result-card__signal result-card__signal--momentum">↑ Momentum</span>
    </div>
</div>

<p class="disclaimer-inline">
    Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.
</p>
