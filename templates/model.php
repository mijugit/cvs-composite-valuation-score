<article class="model-page">

<style>
/* ── model-page layout ───────────────────────────────────────────────── */
.model-page { max-width: 860px; margin: 0 auto; padding: 2rem 0 4rem; }
.model-page h1 { font-size: var(--text-2xl, 1.75rem); margin-bottom: .25rem; }
.model-page .subtitle {
    color: var(--c-muted); font-size: var(--text-base); margin-bottom: 2.5rem;
}
.model-page h2 {
    font-size: var(--text-xl, 1.25rem);
    margin: 2.5rem 0 .75rem;
    padding-bottom: .35rem;
    border-bottom: 2px solid var(--c-border, #e5e7eb);
}
.model-page h3 { font-size: var(--text-base); margin: 1.5rem 0 .5rem; font-weight: 600; }
.model-page p, .model-page li { line-height: 1.7; color: var(--c-text, #1f2937); }
.model-page ul { margin: .5rem 0 1rem 1.4rem; }
.model-page ul li { margin-bottom: .3rem; }

/* pillar grid */
.pillar-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 1rem; margin: 1rem 0 1.5rem;
}
.pillar-card {
    border: 1px solid var(--c-border, #e5e7eb);
    border-radius: 8px; padding: 1rem 1.1rem;
    background: var(--c-surface, #fff);
}
.pillar-card__icon { font-size: 1.6rem; margin-bottom: .4rem; }
.pillar-card__name { font-weight: 700; margin-bottom: .25rem; }
.pillar-card__weight { font-size: var(--text-sm); color: var(--c-muted); margin-bottom: .5rem; }
.pillar-card__desc { font-size: var(--text-sm); line-height: 1.6; }

/* mode comparison table */
.mode-table { width: 100%; border-collapse: collapse; margin: .75rem 0 1.5rem; font-size: var(--text-sm); }
.mode-table th, .mode-table td {
    border: 1px solid var(--c-border, #e5e7eb);
    padding: .55rem .8rem; text-align: left;
}
.mode-table thead th { background: var(--c-surface-alt, #f9fafb); font-weight: 600; }
.mode-table td:first-child { font-weight: 500; }

/* reco badges */
.reco-grid { display: flex; flex-wrap: wrap; gap: .5rem; margin: .75rem 0 1.25rem; }
.reco-badge {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .3rem .75rem; border-radius: 99px; font-size: var(--text-sm); font-weight: 600;
}
.reco-badge--sb  { background: #dcfce7; color: #15803d; }
.reco-badge--buy { background: #d1fae5; color: #065f46; }
.reco-badge--neu { background: #f3f4f6; color: #374151; }
.reco-badge--red { background: #fee2e2; color: #b91c1c; }
.reco-badge--av  { background: #fecaca; color: #991b1b; }

/* callout boxes */
.callout {
    padding: .9rem 1.1rem; border-radius: 8px; margin: 1rem 0;
    border-left: 4px solid; font-size: var(--text-sm); line-height: 1.65;
}
.callout--info  { background: #eff6ff; border-color: #3b82f6; }
.callout--warn  { background: #fffbeb; border-color: #f59e0b; }
.callout--danger{ background: #fff1f2; border-color: #f43f5e; }
.callout--tip   { background: #f0fdf4; border-color: #22c55e; }
.callout strong { display: block; margin-bottom: .2rem; }

/* math formula box */
.formula {
    background: var(--c-surface-alt, #f9fafb);
    border: 1px solid var(--c-border, #e5e7eb);
    border-radius: 6px; padding: .65rem 1rem;
    font-family: 'Courier New', monospace; font-size: .9rem;
    overflow-x: auto; margin: .5rem 0 1rem;
}

/* edge-case table */
.edge-table { width: 100%; border-collapse: collapse; margin: .75rem 0 1.5rem; font-size: var(--text-sm); }
.edge-table th, .edge-table td {
    border: 1px solid var(--c-border, #e5e7eb);
    padding: .5rem .75rem; vertical-align: top;
}
.edge-table thead th { background: var(--c-surface-alt, #f9fafb); font-weight: 600; }

/* glossary */
.glossary { column-count: 1; }
.glossary dt { font-weight: 700; margin-top: 1rem; }
.glossary dd { margin: .25rem 0 .25rem 1.2rem; color: var(--c-muted); font-size: var(--text-sm); line-height: 1.6; }

/* FAQ */
.faq details { border: 1px solid var(--c-border, #e5e7eb); border-radius: 6px; margin-bottom: .5rem; }
.faq summary {
    padding: .75rem 1rem; cursor: pointer; font-weight: 600;
    font-size: var(--text-sm); user-select: none;
}
.faq details[open] summary { border-bottom: 1px solid var(--c-border, #e5e7eb); }
.faq .faq__body { padding: .75rem 1rem; font-size: var(--text-sm); line-height: 1.65; }

/* toc */
.toc {
    background: var(--c-surface-alt, #f9fafb);
    border: 1px solid var(--c-border, #e5e7eb);
    border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 2rem;
}
.toc h4 { margin: 0 0 .5rem; font-size: var(--text-sm); color: var(--c-muted); text-transform: uppercase; letter-spacing: .05em; }
.toc ol { margin: 0; padding-left: 1.3rem; }
.toc li { font-size: var(--text-sm); margin-bottom: .2rem; }
.toc a { color: var(--c-accent, #2563eb); }
</style>

<!-- ── HEADER ──────────────────────────────────────────────────────────── -->
<h1>Jak działa model CVS?</h1>
<p class="subtitle">
    Kompletny przewodnik po metodologii Composite Valuation Score — od danych wejściowych
    do rekomendacji. Bez wymaganej wiedzy finansowej.
</p>

<!-- ── TOC ─────────────────────────────────────────────────────────────── -->
<nav class="toc" aria-label="Spis treści">
    <h4>Spis treści</h4>
    <ol>
        <li><a href="#skad-dane">Skąd pochodzą dane?</a></li>
        <li><a href="#bramka">Krok 1 — Bramka Jakości (filtr wstępny)</a></li>
        <li><a href="#filary">Krok 2 — Trzy filary modelu</a></li>
        <li><a href="#wynik">Krok 3 — Wynik CVS i rekomendacje</a></li>
        <li><a href="#overlay">Krok 4 — Korekty i ostrzeżenia</a></li>
        <li><a href="#ai">Krok 5 — Analiza AI (Claude)</a></li>
        <li><a href="#brzegowe">Sytuacje brzegowe</a></li>
        <li><a href="#slabe-strony">Słabe strony modelu</a></li>
        <li><a href="#slownik">Słownik pojęć</a></li>
        <li><a href="#faq">FAQ</a></li>
    </ol>
</nav>

<!-- ── INTRO ────────────────────────────────────────────────────────────── -->
<p>
    <strong>CVS (Composite Valuation Score)</strong> to automatyczny model analityczny, który
    dla dowolnej spółki notowanej na giełdzie oblicza <strong>wynik od 0 do 100</strong> i
    przekształca go w czytelną rekomendację (np. „⬆ AKUMULUJ"). Model nie jest poradą
    inwestycyjną — to hipoteza ilościowa, która łączy trzy niezależne perspektywy:
    <em>jak droga jest spółka, jak mocny jest jej trend cenowy</em> i <em>jak zdrowe są jej
    fundamenty</em>.
</p>
<div class="callout callout--info">
    <strong>Dla kogo jest ten artykuł?</strong>
    Dla każdego, kto chce zrozumieć, co kryje się za liczbami widocznymi na stronie analizy.
    Nie zakładamy wcześniejszej wiedzy finansowej — każde pojęcie jest wyjaśnione.
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<h2 id="skad-dane">Skąd pochodzą dane?</h2>
<!-- ══════════════════════════════════════════════════════════════════════ -->
<p>
    Wszystkie dane finansowe są pobierane automatycznie z serwisu <strong>Yahoo Finance</strong>
    w momencie wpisania symbolu spółki (np. <code>AAPL</code>). Obejmują m.in.:
</p>
<ul>
    <li>Bieżącą cenę akcji i historię kursów miesięcznych (do 13 miesięcy wstecz)</li>
    <li>Dane fundamentalne: przychody, zysk brutto, EBITDA, zadłużenie, gotówkę</li>
    <li>Wskaźniki rentowności: EPS bieżący i prognozowany przez analityków (<em>forward EPS</em>), marża brutto</li>
    <li>Wolne przepływy pieniężne (FCF) i kapitał obrotowy</li>
    <li>Dane analityków: konsensus cenowy, rekomendacje, prognozy przychodów</li>
    <li>Kalendarz wyników (data ostatnich i kolejnych wyników kwartalnych)</li>
    <li>Dane rynkowe indeksu SPY (S&amp;P 500) do kalibracji momentum</li>
</ul>
<p>
    Dane są <strong>buforowane przez 1 godzinę</strong> — wielokrotne zapytania o tę samą spółkę
    w krótkim czasie nie pobierają danych ponownie. Yahoo Finance jest darmowym źródłem i może
    sporadycznie zwracać brakujące lub opóźnione dane — w takich przypadkach model stosuje
    bezpieczne wartości domyślne lub neutralizuje dany składnik (szczegóły poniżej).
</p>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<h2 id="bramka">Krok 1 — Bramka Jakości (filtr wstępny)</h2>
<!-- ══════════════════════════════════════════════════════════════════════ -->
<p>
    Zanim model w ogóle rozpocznie obliczenia, spółka musi przejść przez <strong>Bramkę
    Jakości</strong> — binarny filtr, który eliminuje spółki niezdatne do analizy.
    Bramka sprawdza cztery warunki:
</p>
<table class="mode-table">
    <thead><tr><th>Kryterium</th><th>Próg</th><th>Co oznacza niepowodzenie?</th></tr></thead>
    <tbody>
        <tr>
            <td>Przychody > 0</td>
            <td>wymagane</td>
            <td>Spółka bez przychodów (np. startup przed IPO z zerową sprzedażą) jest zbyt niepewna, żeby ją oceniać.</td>
        </tr>
        <tr>
            <td>Marża brutto ≥ 10 %</td>
            <td>≥ 10 %</td>
            <td>Spółki ze skrajnie niską marżą brutto mają niemal zerowy bufor bezpieczeństwa.</td>
        </tr>
        <tr>
            <td>Dług / Kapitał własny ≤ 5×</td>
            <td>≤ 5×</td>
            <td>Nadmierne zadłużenie (np. dług = 10× kapitał własny) sugeruje ryzyko bankructwa.</td>
        </tr>
        <tr>
            <td>Wskaźnik płynności bieżącej ≥ 0,5</td>
            <td>≥ 0,5</td>
            <td>Aktywa bieżące muszą wystarczyć na pokrycie co najmniej połowy zobowiązań krótkoterminowych.</td>
        </tr>
    </tbody>
</table>
<div class="callout callout--warn">
    <strong>Jeśli spółka nie przejdzie Bramki Jakości</strong>, strona analizy wyświetla komunikat
    „Spółka nie przeszła filtra jakości" wraz z listą powodów. Nie pojawia się żaden wynik CVS —
    model nie szacuje wartości spółek zdyskwalifikowanych.
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<h2 id="filary">Krok 2 — Trzy filary modelu</h2>
<!-- ══════════════════════════════════════════════════════════════════════ -->
<p>
    Po przejściu przez Bramkę Jakości spółka trafia do właściwego modelu. Wynik CVS składa się
    z trzech niezależnych <em>filarów</em>, z których każdy mierzy inny aspekt spółki i zwraca
    ocenę <strong>0–100 punktów</strong>. Każdy filar ma inną wagę w zależności od horyzontu
    inwestycyjnego (patrz Krok 3).
</p>

<div class="pillar-grid">
    <div class="pillar-card">
        <div class="pillar-card__icon">⚖️</div>
        <div class="pillar-card__name">Wycena</div>
        <div class="pillar-card__weight">Swing: 40 % · Fundamentalny: 65 %</div>
        <div class="pillar-card__desc">Czy spółka jest tania czy droga względem branży? Im wyższy wynik, tym bardziej atrakcyjna wycena.</div>
    </div>
    <div class="pillar-card">
        <div class="pillar-card__icon">📈</div>
        <div class="pillar-card__name">Momentum</div>
        <div class="pillar-card__weight">Swing: 45 % · Fundamentalny: 15 %</div>
        <div class="pillar-card__desc">Czy akcje rosną szybciej niż rynek (S&amp;P 500)? Wysoki wynik = trend wzrostowy względem indeksu.</div>
    </div>
    <div class="pillar-card">
        <div class="pillar-card__icon">🏛️</div>
        <div class="pillar-card__name">Jakość</div>
        <div class="pillar-card__weight">Swing: 15 % · Fundamentalny: 20 %</div>
        <div class="pillar-card__desc">Jak zdrowe są fundamenty spółki? Marża brutto, zadłużenie netto i prognoza wzrostu.</div>
    </div>
</div>

<!-- FILAR 1 -->
<h3>Filar Wyceny — ile kosztuje przepływ gotówki?</h3>
<p>
    Wycena bazuje na wskaźniku <strong>EV/FCF</strong> (Enterprise Value do Free Cash Flow —
    wartość przedsiębiorstwa podzielona przez wolne przepływy pieniężne). Im niższy wskaźnik,
    tym spółka jest „tańsza" w sensie ile płacimy za każdą złotówkę generowanej gotówki.
</p>
<p>
    Model porównuje obliczony EV/FCF spółki z <strong>medianą sektora</strong> (np. dla
    Technologii medialna EV/FCF wynosi ok. 32×). Wynik > 50 oznacza, że spółka jest tańsza
    niż mediana sektora; wynik &lt; 50 — droższa.
</p>

<h4 style="font-size:var(--text-sm);font-weight:600;margin:.75rem 0 .25rem;">Jak liczone jest EV?</h4>
<div class="formula">EV = cena akcji × liczba akcji w obiegu + dług całkowity − gotówka</div>

<h4 style="font-size:var(--text-sm);font-weight:600;margin:.75rem 0 .25rem;">Jak liczone jest forward FCF?</h4>
<p>
    Model nie używa bieżącego FCF wprost, lecz <strong>prognozowanego przyszłorocznego FCF</strong>.
    Oblicza go dwoma metodami (w zależności od dostępności danych):
</p>
<div class="formula">forward FCF = forward_EPS × (trailing_FCF / trailing_EPS)</div>
<p>
    Tę metodę stosujemy gdy spółka ma wiarygodne prognozy EPS od analityków i normalną relację
    FCF/EPS (w przedziale 0,3–3,0× — patrz sekcja <a href="#brzegowe">Sytuacje brzegowe</a>).
    Dzięki temu model poprawnie wycenia spółki w trakcie dużych cykli inwestycyjnych (np.
    producenci chipów HBM, gdzie bieżące FCF są tymczasowo zaniżone przez intensywne nakłady
    kapitałowe).
</p>
<div class="formula">forward FCF = trailing_FCF × (1 + wzrost)²</div>
<p>
    Metoda rezerwowa: gdy brakuje prognoz EPS lub relacja FCF/EPS jest poza normalnym zakresem.
    Używa stopę wzrostu przychodów lub zysku kwartalnego jako przybliżenia.
</p>

<h4 style="font-size:var(--text-sm);font-weight:600;margin:.75rem 0 .25rem;">Wariant B — gdy FCF jest ujemny</h4>
<p>
    Spółki wzrostowe często reinwestują całą gotówkę i wykazują ujemne FCF. W takim przypadku
    filar Wyceny przechodzi na <strong>Wariant B: EV/Sprzedaż (EV/Sales)</strong> skorygowany
    o wzrost przychodów i marżę brutto. Pozwala to oceniać firmy takie jak SNOW czy PLTR, które
    nie generują jeszcze dodatniego FCF.
</p>

<h4 style="font-size:var(--text-sm);font-weight:600;margin:.75rem 0 .25rem;">Funkcja sigmoid — zamiana wskaźnika w punkty</h4>
<p>
    Stosunek EV/FCF spółki do mediany sektora trafia do <strong>funkcji sigmoid</strong>, która
    zamienia proporcję na wynik 0–100. Przy <em>ratio = 1,0</em> (spółka na poziomie mediany)
    wynik to dokładnie 50. Im niższe ratio (spółka tańsza od mediany), tym wynik wyższy.
</p>
<div class="formula">wynik_wyceny = 100 / (1 + e^(3 × (ratio − 1)))</div>
<div class="callout callout--tip">
    <strong>Dlaczego sigmoid, a nie prosta proporcja?</strong>
    Sigmoid wygładza ekstrema — spółka 5× tańsza od mediany nie dostaje 500% wyniku, lecz
    wartość bliską 100. To zapobiega dominacji jednego filara nad pozostałymi.
</div>

<h4 style="font-size:var(--text-sm);font-weight:600;margin:.75rem 0 .25rem;">Peer group vs. statyczne benchmarki</h4>
<p>
    Gdy w bazie jest wystarczająco dużo spółek z tej samej branży (≥ 5), model używa
    <strong>medianowej peer group</strong> zamiast statycznego benchmarku sektora.
    Na przykład producent półprzewodników będzie porównywany z innymi producentami
    półprzewodników, a nie z całym sektorem Technologii. Dodatkowo stosowane jest
    <em>zakotwiczenie</em> (anchor): ostateczny wynik to minimum(wynik subsektora, wynik sektora),
    co chroni przed zawyżaniem oceny gdy cały subszektor jest przewartościowany.
</p>

<!-- FILAR 2 -->
<h3>Filar Momentum — czy rynek lubi tę spółkę?</h3>
<p>
    Momentum mierzy, czy kurs akcji rośnie <em>szybciej niż rynek</em> (S&amp;P 500, symbol SPY).
    Liczymy <strong>Return on Capital (ROC)</strong>, czyli procentową zmianę kursu od 1 miesiąca,
    3 miesięcy, 6 miesięcy i 12 miesięcy temu.
</p>
<div class="formula">ROC(Nm) = (cena_dziś / cena_N_miesięcy_temu − 1) × 100 %</div>
<p>
    Następnie obliczamy <strong>ważoną kompozytową stopę zwrotu</strong> spółki i identyczną
    wartość dla SPY. Różnica to tzw. <em>nadwyżkowy zwrot (excess return)</em>.
</p>
<div class="formula">excess_return = kompozyt_spółki − kompozyt_SPY</div>
<p>
    Wynik > 0% (spółka bije rynek) → score > 50. Wynik &lt; 0% (spółka za rynkiem) → score &lt; 50.
    Transformacja jest też sigmoidalna, ograniczona do zakresu [5, 95], żeby outlier nie
    monopolizował wyniku końcowego.
</p>
<div class="callout callout--info">
    <strong>Dwa profile wag ROC</strong> — model oblicza momentum dwa razy, bo horyzont ma znaczenie:
    dla trybu Swing (1–4 miesiące) liczy się głównie krótkoterminowy impet (waga 1M = 50%),
    dla trybu Fundamentalnego (6–12 miesięcy) ważniejsze są długoterminowe trendy (6M = 40%, 12M = 30%).
    Tabela szczegółów poniżej w sekcji <a href="#wynik">Dwa tryby</a>.
</div>

<!-- FILAR 3 -->
<h3>Filar Jakości — jak zdrowe są fundamenty?</h3>
<p>
    Jakość to suma trzech składowych, każda oceniana w punktach:
</p>
<table class="mode-table">
    <thead><tr><th>Składowa</th><th>Maks. pkt</th><th>Jak jest obliczana</th></tr></thead>
    <tbody>
        <tr>
            <td>Marża brutto vs. sektor</td>
            <td>4 pkt</td>
            <td>
                Różnica między marżą brutto spółki a medianą sektora.<br>
                +15 pp i więcej → 4 pkt; +5…+15 pp → 3 pkt; −5…+5 pp → 2 pkt;
                −15…−5 pp → 1 pkt; poniżej −15 pp → 0 pkt.
            </td>
        </tr>
        <tr>
            <td>Dźwignia finansowa</td>
            <td>3 pkt</td>
            <td>
                Dług netto / EBITDA: ≤ 1× → 3 pkt; ≤ 2,5× → 2 pkt; ≤ 4× → 1 pkt; > 4× → 0 pkt.<br>
                Gdy EBITDA ≤ 0 (spółka spalająca gotówkę): Cash / Przychody ≥ 30% → 2 pkt;
                ≥ 10% → 1 pkt; poniżej → 0 pkt.
            </td>
        </tr>
        <tr>
            <td>Prognozowany wzrost</td>
            <td>3 pkt</td>
            <td>
                Forward EPS growth (lub wzrost przychodów jako fallback).<br>
                > 10% → 3 pkt; > 0% → 1,5 pkt; ≤ 0% → 0 pkt.
            </td>
        </tr>
    </tbody>
</table>
<div class="formula">wynik_jakości = (suma_pkt / 10) × 100</div>
<p>
    Przykład: spółka z marżą 5 pp powyżej mediany (3 pkt), długiem netto/EBITDA = 1,8× (2 pkt)
    i prognozowanym wzrostem EPS 18% (3 pkt) uzyskuje 8/10 = 80 punktów.
</p>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<h2 id="wynik">Krok 3 — Wynik CVS i rekomendacje</h2>
<!-- ══════════════════════════════════════════════════════════════════════ -->
<p>
    Model oblicza wynik CVS <strong>dwukrotnie</strong> — dla dwóch horyzontów inwestycyjnych.
    Surowe wyniki filarów (Wycena, Jakość) są identyczne w obu trybach; różnią się tylko
    wagi i profil ROC Momentum.
</p>
<table class="mode-table">
    <thead>
        <tr>
            <th>Parametr</th>
            <th>Tryb Swing<br><small>1–4 miesiące</small></th>
            <th>Tryb Fundamentalny<br><small>6–12 miesięcy</small></th>
        </tr>
    </thead>
    <tbody>
        <tr><td>Waga Wyceny</td><td>40 %</td><td>65 %</td></tr>
        <tr><td>Waga Momentum</td><td>45 %</td><td>15 %</td></tr>
        <tr><td>Waga Jakości</td><td>15 %</td><td>20 %</td></tr>
        <tr><td>ROC — 1 miesiąc</td><td>50 %</td><td>—</td></tr>
        <tr><td>ROC — 3 miesiące</td><td>30 %</td><td>30 %</td></tr>
        <tr><td>ROC — 6 miesięcy</td><td>20 %</td><td>40 %</td></tr>
        <tr><td>ROC — 12 miesięcy</td><td>—</td><td>30 %</td></tr>
    </tbody>
</table>
<div class="formula">CVS = waga_wyceny × wynik_wyceny + waga_momentum × wynik_momentum + waga_jakości × wynik_jakości</div>

<h3>Rekomendacje</h3>
<p>Wynik CVS (0–100) jest mapowany na 5 etykiet:</p>
<div class="reco-grid">
    <span class="reco-badge reco-badge--sb">⬆⬆ SILNE KUPUJ &nbsp;(≥ 72)</span>
    <span class="reco-badge reco-badge--buy">⬆ AKUMULUJ &nbsp;(58–71)</span>
    <span class="reco-badge reco-badge--neu">→ NEUTRALNIE &nbsp;(42–57)</span>
    <span class="reco-badge reco-badge--red">⬇ REDUKUJ &nbsp;(28–41)</span>
    <span class="reco-badge reco-badge--av">⬇⬇ UNIKAJ &nbsp;(&lt; 28)</span>
</div>

<h3>Sygnały złote (Golden Signals)</h3>
<p>
    Gdy oba tryby są jednocześnie wysoko (oba ≥ 58), model emituje sygnał <strong>„Strong"</strong>
    (silna konwergencja krótko- i długoterminowa). Gdy tylko tryb Fundamentalny ≥ 58, ale
    Swing &lt; 58, pojawia się sygnał <strong>„Watchlist"</strong> — spółka jest fundamentalnie
    dobra, ale momentum jeszcze tego nie potwierdza. Screener pozwala filtrować po tych sygnałach.
</p>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<h2 id="overlay">Krok 4 — Korekty i ostrzeżenia</h2>
<!-- ══════════════════════════════════════════════════════════════════════ -->
<p>
    Na podstawowy wynik CVS nakładane są trzy dodatkowe warstwy, które tworzą <strong>wynik cienia
    (shadow score)</strong>. Jest to równoległa kalkulacja, którą model oblicza i wyświetla obok
    podstawowej oceny — bazowa rekomendacja nie ulega zmianie.
</p>

<h3>Korekta A — Rewizja EPS przez analityków</h3>
<p>
    Jeśli analitycy <em>obniżyli</em> prognozy zysku na akcję (EPS) w ciągu ostatnich 90 dni
    (negatywna rewizja), model nakłada karę proporcjonalną do skali obniżki <em>i</em> do
    tego, jak droga była spółka w filarze Wyceny. Logika: rewizja w dół przy i tak drogiej
    spółce (wysoki EV/FCF) jest bardziej niepokojąca niż ta sama rewizja przy taniej.
</p>

<h3>Korekta B — Cel cenowy analityków</h3>
<p>
    Gdy konsensus cenowy analityków jest <em>poniżej bieżącej ceny</em> (negatywny upside),
    model nakłada dodatkową karę — rynek jest droższy niż analitycy uważają za uzasadnione.
</p>

<h3>Korekta C — Bliskość wyników kwartalnych</h3>
<p>
    W oknie <strong>5 sesji przed i po wynikach</strong> kwartalnych kurs akcji jest z natury
    bardziej zmienny i trudniejszy do przewidzenia. Model obniża wynik cienia proporcjonalnie
    do bliskości daty wyników (maksymalna kara: 10 pkt) i wyświetla badge (znaczek) z informacją
    o stanie: „przed wynikami", „po wynikach" lub „w oknie zmiany sesyjnej".
</p>
<div class="callout callout--tip">
    <strong>Badge wyników jest zawsze widoczny</strong> — niezależnie od tego, czy shadow score
    jest włączony. To informacja dla Ciebie, nie element wyniku.
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<h2 id="ai">Krok 5 — Analiza AI (Claude)</h2>
<!-- ══════════════════════════════════════════════════════════════════════ -->
<p>
    Po obliczeniu CVS opcjonalnie dostępna jest <strong>narracyjna analiza AI</strong> generowana
    przez model językowy Claude firmy Anthropic. AI <em>nie zmienia</em> żadnego wyniku CVS —
    jest wyłącznie warstwą interpretacyjną.
</p>
<p>
    Do modelu językowego przekazywane są następujące dane:
</p>
<ul>
    <li>Wyniki CVS (Swing i Fundamentalny) wraz z rekomendacjami</li>
    <li>Rozbicie na filary (wynik każdego z 0–100)</li>
    <li>Sektor i branża spółki</li>
    <li>Źródło benchmarku Wyceny (sektor lub peer group branży)</li>
    <li>Dane analityków: konsensus, cel cenowy (min/średni/max), upside/downside</li>
    <li>Prognozowane przychody i EPS na kolejny rok</li>
    <li>Szacunkowa „uczciwa cena" modelu CVS (<em>CVS Implied Fair Value</em>) —
        cena, przy której EV/FCF spółki równałby się medianie sektora</li>
</ul>
<p>
    AI generuje analizę w czterech sekcjach:
</p>
<ol>
    <li><strong>Ocena modelu CVS</strong> — interpretacja filarów i co oznaczają dla tej konkretnej spółki</li>
    <li><strong>Opinia rynku (analitycy)</strong> — podsumowanie konsensusu i celów cenowych Wall Street</li>
    <li><strong>Analiza rozjazdu</strong> — dlaczego model CVS i analitycy mogą się różnić</li>
    <li><strong>Komu wierzyć i w jakim horyzoncie</strong> — praktyczna wskazówka z uwzględnieniem niepewności</li>
</ol>
<div class="callout callout--warn">
    <strong>Ograniczenie AI</strong>: model językowy odpowiada wyłącznie na podstawie danych
    liczbowych przekazanych w zapytaniu. Nie ma dostępu do internetu, nie zna aktualnych
    wiadomości ani raportów prasowych. Wszelkie sformułowania w rodzaju „spółka ogłosiła..."
    byłyby halucynacją — guardrail w systemie promptu to uniemożliwia.
</div>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<h2 id="brzegowe">Sytuacje brzegowe</h2>
<!-- ══════════════════════════════════════════════════════════════════════ -->
<p>
    Model przewiduje szereg sytuacji, w których standardowe obliczenia są niemożliwe lub
    mogłyby dać fałszywy wynik. Poniżej lista najważniejszych przypadków:
</p>
<table class="edge-table">
    <thead>
        <tr>
            <th style="width:28%">Sytuacja</th>
            <th style="width:42%">Jak model reaguje</th>
            <th style="width:30%">Przykładowe spółki</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Ujemne FCF (spółka inwestuje agresywnie)</td>
            <td>Przejście na Wariant B: EV/Sales zamiast EV/FCF</td>
            <td>SNOW, PLTR, wczesne fazy SaaS</td>
        </tr>
        <tr>
            <td>Trough FCF (cykl capex — chwilowo niskie FCF)</td>
            <td>Użycie forward_FCF z prognoz EPS zamiast trailing_FCF × (1+g)²; aktywne gdy relacja FCF/EPS ∈ [0,3; 3,0]</td>
            <td>MU (Micron) w cyklu HBM</td>
        </tr>
        <tr>
            <td>Efekt bazy EPS (wzrost EPS > 200%)</td>
            <td>Pominięcie EPS jako miary wzrostu, fallback na wzrost przychodów lub wzrost kwartalny</td>
            <td>Spółki wychodzące z recesji zysków</td>
        </tr>
        <tr>
            <td>Dysproporcja EPS/Przychody (EPS rośnie 3,5× szybciej niż przychody)</td>
            <td>Pominięcie EPS growth — prawdopodobnie efekt jednorazowy, nie trwały wzrost</td>
            <td>Spółki z jednorazowymi ulgami podatkowymi</td>
        </tr>
        <tr>
            <td>Brak historii cen (< 7 miesięcy)</td>
            <td>Momentum = 50 (neutralne)</td>
            <td>Nowe IPO</td>
        </tr>
        <tr>
            <td>Brak danych wzrostu (brak EPS, brak przychodów)</td>
            <td>Wzrost = null → pts_growth = 0 w Jakości; Wycena = 50</td>
            <td>Małe spółki bez pokrycia analitycznego</td>
        </tr>
        <tr>
            <td>Ujemne EBITDA (startup/biotech)</td>
            <td>Dźwignia oceniana przez Cash Runway (gotówka / przychody)</td>
            <td>Pre-revenue biotech</td>
        </tr>
        <tr>
            <td>Brak danych peer group (< 5 spółek w branży)</td>
            <td>Fallback na statyczny benchmark sektora</td>
            <td>Niszowe subindustrie</td>
        </tr>
        <tr>
            <td>Bliskość wyników kwartalnych</td>
            <td>Badge „przed/po wynikach" + kara w shadow score (do −10 pkt)</td>
            <td>Każda spółka w oknie ±5 sesji</td>
        </tr>
        <tr>
            <td>Finansowe i Real Estate</td>
            <td>EV/FCF często nieodpowiednie (banki = FCF to kapitał regulacyjny); model działa, ale z niższą dokładnością</td>
            <td>JPM, BAC, Realty Income</td>
        </tr>
    </tbody>
</table>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<h2 id="slabe-strony">Słabe strony modelu</h2>
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div class="callout callout--danger">
    <strong>Ważne — przeczytaj przed użyciem</strong>
    Model CVS to narzędzie pomocnicze, nie wyrocznię. Poniżej lista jego znanych ograniczeń.
</div>
<ul>
    <li>
        <strong>Jakość danych Yahoo Finance.</strong> Model jest tak dobry jak dane wejściowe.
        Yahoo Finance bywa niekompletne, opóźnione lub zawierające błędy (szczególnie dla małych
        i zagranicznych spółek). Zawsze weryfikuj kluczowe liczby w raporcie rocznym.
    </li>
    <li>
        <strong>Brak analizy makro i jakościowej.</strong> Model nie widzi: zmian regulacyjnych,
        prawa patentowego, jakości zarządzania, competitive moat, ryzyk geopolitycznych ani
        zmian w modelu biznesowym.
    </li>
    <li>
        <strong>Statyczne benchmarki sektora.</strong> Mediany EV/FCF i EV/Sales sektorów są
        wyliczone ze zbioru historycznego i mogą nie odzwierciedlać obecnych wycen rynkowych
        (np. AI-premium w Technologii zmienia co roku, co oznacza co jest „tanio").
    </li>
    <li>
        <strong>Momentum jest opóźnionym wskaźnikiem.</strong> Kurs, który przez 6 miesięcy rósł
        szybko, może już być w szczycie. Momentum dobrze identyfikuje trendy w toku, ale nie
        przewiduje odwrócenia.
    </li>
    <li>
        <strong>Niska dokładność dla sektora Finansowego i Real Estate.</strong> EV/FCF nie jest
        standardową metryką dla banków (FCF to tam zysk regulacyjny, nie gotówka operacyjna)
        ani REIT-ów. Używaj wyników z tych sektorów z dużą ostrożnością.
    </li>
    <li>
        <strong>Wyniki kwartalne = punkt nieciągłości.</strong> Jedne zaskakujące wyniki mogą
        w jednej chwili zresetować Momentum i Wycenę. Model reaguje na dane historyczne —
        po dużym zaskoczeniu potrzebuje kilku tygodni na „nauczenie się" nowej rzeczywistości.
    </li>
    <li>
        <strong>Model nie wie, że coś jest „modne".</strong> AI bubble, meme stocks, spółki
        z bardzo silnym sentymentem rynkowym mogą przez długi czas wyglądać drogo w modelu
        EV/FCF, a i tak rosnąć.
    </li>
    <li>
        <strong>Deterministyczność = brak adaptacji.</strong> Zaletą jest przewidywalność —
        te same dane zawsze dają ten sam wynik. Wadą jest brak dynamicznej adaptacji do
        reżimu rynkowego (hossy, bessy, stagflacji mają inne wzorce korelacji filarów).
    </li>
</ul>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<h2 id="slownik">Słownik pojęć</h2>
<!-- ══════════════════════════════════════════════════════════════════════ -->
<dl class="glossary">
    <dt>CVS (Composite Valuation Score)</dt>
    <dd>Wynik od 0 do 100 obliczany przez model dla konkretnej spółki. Łączy trzy filary: Wycenę, Momentum i Jakość.</dd>

    <dt>EV (Enterprise Value — Wartość Przedsiębiorstwa)</dt>
    <dd>Całkowity koszt „przejęcia" firmy: kapitalizacja rynkowa + dług − gotówka. Bardziej kompletna miara niż sama cena akcji.</dd>

    <dt>FCF (Free Cash Flow — Wolne Przepływy Pieniężne)</dt>
    <dd>Gotówka, którą firma faktycznie generuje po odjęciu wydatków inwestycyjnych (capex). „Czysty zysk gotówkowy".</dd>

    <dt>EV/FCF</dt>
    <dd>Ile razy wartość przedsiębiorstwa przekracza roczny FCF. Im niższy, tym spółka „tańsza". Odpowiednik ceny/zysku, ale oparty na gotówce.</dd>

    <dt>EPS (Earnings Per Share — Zysk na Akcję)</dt>
    <dd>Zysk netto podzielony przez liczbę akcji. <em>Trailing EPS</em> = ostatni rok; <em>Forward EPS</em> = prognoza analityków na kolejny rok.</dd>

    <dt>ROC (Rate of Change — Stopa Zwrotu)</dt>
    <dd>Procentowa zmiana kursu akcji w danym okresie (1M, 3M, 6M, 12M). Podstawa Filara Momentum.</dd>

    <dt>Marża brutto (Gross Margin)</dt>
    <dd>Zysk brutto / Przychody. Mówi, ile ze sprzedaży zostaje po odjęciu bezpośrednich kosztów produkcji. Wyższa marża = więcej gotówki na pokrycie kosztów operacyjnych i zysk.</dd>

    <dt>Dług netto / EBITDA</dt>
    <dd>Ile lat zajęłoby spłacenie długu netto z EBITDA. Wskaźnik dźwigni finansowej. Poniżej 1× → bardzo bezpieczny poziom; powyżej 4× → potencjalnie ryzykowny.</dd>

    <dt>EBITDA</dt>
    <dd>Zysk przed odsetkami, podatkami i amortyzacją. Przybliżenie przepływów gotówkowych z działalności operacyjnej.</dd>

    <dt>Peer group (Porównanie z grupą rówieśniczą)</dt>
    <dd>Zbiór spółek z tej samej branży (subsektora), używany jako benchmark zamiast statycznych mediany dla całego sektora.</dd>

    <dt>Sigmoid</dt>
    <dd>Matematyczna funkcja "S-kształtna" zamieniająca dowolną liczbę w wartość między 0 a 100. Wygładza ekstrema i sprawia, że żaden czynnik nie dominuje wyniku.</dd>

    <dt>Shadow score (Wynik cienia)</dt>
    <dd>Równoległa kalkulacja CVS uwzględniająca trzy korekty: rewizję EPS, konsensus cenowy analityków i bliskość wyników. Wyświetlany obok podstawowego CVS.</dd>

    <dt>Forward EPS</dt>
    <dd>Prognozowany zysk na akcję na kolejny rok fiskalny, oparty na konsensusie analityków Wall Street.</dd>

    <dt>Capex (Capital Expenditure — Nakłady inwestycyjne)</dt>
    <dd>Pieniądze wydane na aktywa trwałe (maszyny, infrastruktura, budynki). Wysokie capex obniżają FCF, ale mogą być produktywne w przyszłości.</dd>

    <dt>SPY</dt>
    <dd>ETF śledzący indeks S&amp;P 500, używany jako punkt odniesienia dla Momentum. Spółka, która rośnie szybciej niż SPY, ma dodatni excess return.</dd>

    <dt>Excess return (Nadwyżkowy zwrot)</dt>
    <dd>Różnica między kompozytową stopą zwrotu spółki a analogicznym wskaźnikiem SPY. Pozytywna wartość = spółka bije rynek.</dd>

    <dt>Bramka Jakości (Quality Gate)</dt>
    <dd>Binarny filtr wstępny. Spółki niespełniające minimum (przychody, marża, zadłużenie, płynność) nie są oceniane przez CVS.</dd>
</dl>

<!-- ══════════════════════════════════════════════════════════════════════ -->
<h2 id="faq">FAQ — Najczęstsze pytania</h2>
<!-- ══════════════════════════════════════════════════════════════════════ -->
<div class="faq">
    <details>
        <summary>Czy CVS to sygnał do kupna/sprzedaży?</summary>
        <div class="faq__body">
            Nie. CVS to <em>hipoteza modelu ilościowego</em>, nie rekomendacja inwestycyjna.
            Model nie zna Twojej sytuacji finansowej, horyzontu inwestycyjnego ani tolerancji ryzyka.
            Używaj go jako jednego z wielu narzędzi, zawsze potwierdzając wnioski własną analizą
            lub konsultacją z licencjonowanym doradcą.
        </div>
    </details>
    <details>
        <summary>Dlaczego ta sama spółka ma inne wyniki Swing i Fundamental?</summary>
        <div class="faq__body">
            Oba tryby używają tych samych filarów, ale z różnymi wagami. W trybie Swing dominuje
            Momentum (45%) — krótkoterminowy trend cenowy. W trybie Fundamentalnym dominuje Wycena
            (65%) — ile płacisz za przepływ gotówki. Spółka może być fundamentalnie tania, ale
            mieć słaby trend cenowy (Swing niski, Fundamental wysoki) lub odwrotnie.
        </div>
    </details>
    <details>
        <summary>Spółka dostała 85/100 CVS, ale kurs spada. Dlaczego?</summary>
        <div class="faq__body">
            Wysoki CVS oznacza, że spółka <em>w danej chwili</em> wyglądała atrakcyjnie na
            podstawie danych historycznych. Model nie przewiduje przyszłości — opisuje stan
            na moment obliczeń. Kurs może spadać z powodów niewidocznych w danych (nowe przepisy,
            zmiana strategii, kryzys sektorowy). Sprawdź badge wyników kwartalnych — jeśli
            spółka jest przed wynikami, zwiększona zmienność jest normalna.
        </div>
    </details>
    <details>
        <summary>Jak często aktualizuje się wynik CVS?</summary>
        <div class="faq__body">
            Wynik jest pobierany na żądanie z Yahoo Finance i buforowany przez <strong>1 godzinę</strong>.
            Przy pierwszym zapytaniu o spółkę od ponad godziny model pobierze świeże dane.
            Automatyczne odświeżanie w tle (batch) odbywa się raz w tygodniu dla każdego sektora
            według harmonogramu (Technologia i Media poniedziałek, Zdrowie i Finanse wtorek itd.).
        </div>
    </details>
    <details>
        <summary>Co oznacza „Brak danych peer group — użyto benchmarku sektora"?</summary>
        <div class="faq__body">
            Dla precyzyjnego porównania model potrzebuje minimum 5 spółek z tej samej branży w bazie.
            Dla niszowych subindustrii może być ich mniej — model automatycznie wraca do porównania
            z całym sektorem (mniej precyzyjne, ale zawsze dostępne).
        </div>
    </details>
    <details>
        <summary>Czy model działa dla spółek spoza USA?</summary>
        <div class="faq__body">
            Model opiera się na danych Yahoo Finance, który pokrywa większość globalnych giełd.
            Jednak benchmarki sektora (EV/FCF, EV/Sales) bazują głównie na spółkach amerykańskich.
            Dla spółek europejskich, azjatyckich czy emerging markets porównanie z tymi samymi
            mediami może być mniej trafne — traktuj wyniki z dodatkową ostrożnością.
        </div>
    </details>
    <details>
        <summary>Czym różni się CVS od P/E lub P/S?</summary>
        <div class="faq__body">
            P/E (cena/zysk) i P/S (cena/sprzedaż) to wskaźniki jednoczynnikowe — patrzą tylko
            na cenę przez pryzmat jednej metryki. CVS łączy wycenę, trend cenowy <em>i</em>
            zdrowie fundamentalne w jeden wynik, a wycenę opiera na EV/FCF (wartość
            przedsiębiorstwa do przepływów gotówkowych), która jest odporna na manipulacje
            księgowe i strukturę kapitałową.
        </div>
    </details>
    <details>
        <summary>Spółka finansowa (bank) dostała bardzo niski CVS — czy to błąd?</summary>
        <div class="faq__body">
            Nie błąd, ale ograniczenie modelu. EV/FCF nie jest standardową metryką dla banków
            — FCF banku to nie to samo co FCF firmy produkcyjnej (regulowany kapitał,
            rezerwy). Model sygnalizuje to na stronie analizy. Dla banków i REIT-ów lepszym
            podejściem są wskaźniki branżowe (P/BV, Dividend Yield), których CVS nie używa.
        </div>
    </details>
    <details>
        <summary>Co to jest „CVS Implied Fair Value"?</summary>
        <div class="faq__body">
            To teoretyczna cena akcji, przy której wskaźnik EV/FCF spółki byłby równy
            medianie jej sektora. Innymi słowy: „ile powinna kosztować akcja, żeby spółka
            była wyceniana dokładnie jak typowa firma w branży". To przybliżenie —
            nie jest to prognoza kursu, lecz punkt odniesienia dla rozmów AI o wycenie.
        </div>
    </details>
</div>

<div class="callout callout--warn" style="margin-top:2.5rem;">
    <strong>Zastrzeżenie prawne</strong>
    Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Wszelkie decyzje
    inwestycyjne podejmujesz na własną odpowiedzialność. Inwestuj świadomie.
</div>

</article>
