---
project: CVS — Composite Valuation Score (Faza 5 — dostrajanie modelu: trajektoria i czas wyników)
context_type: brownfield
updated: 2026-06-05
checkpoint:
  current_phase: 8
  phases_completed: [1, 2, 3, 4, 5, 6, 7]
  frs_drafted: 19
  quality_check_status: accepted
product_type: web-app+api
target_scale:
  users: small
timeline_budget:
  delivery_weeks: 2
  hard_deadline: null
  after_hours_only: true
  delivery_model: phased-by-priority
---

## Current System

**Produkt:** CVS — Composite Valuation Score. Web-app oceniająca spółki US (NYSE/NASDAQ)
kompozytowym wynikiem 0–100 w dwóch trybach (Swing 1–4M / Fundamentalny 6–12M) z etykietą
rekomendacji. MVP + Faza 2 + Faza 3 (peer-group, `model_version` 3.0) ukończone, live na
https://cvs.timeflow.fun/.

**Stack:** Vanilla PHP 8.2, MySQL, Apache, bez frameworka, PSR-4 (`CVS\`). Hosting
Cyber_Folks. Dane: Yahoo Finance via cURL (`FinancialDataFetcher`, 8 modułów quoteSummary),
cache `$_SESSION` (TTL 3600s).

**Model (rdzeń do strojenia w fazie 5):** 3 filary × 2 tryby.
- *Valuation* (40% swing / 65% fund) — forward EV/FCF vs **mediana peer-group** (podsektor→sektor,
  faza 3), sigmoid k=3. **Growth z `forward_eps/trailing_eps`** (POZIOM, nie kierunek).
- *Momentum* (45% / 15%) — ROC vs SPY na **zamkniętych miesięcznych close'ach** (odporne na pojedynczą sesję).
- *Quality* (15% / 20%) — marża brutto vs mediana, dźwignia, growth — punkty progowe 0–10.
- Wagi/progi/`model_version` z `config/cvs-weights.php` (FR-010, determinizm rdzenia).

**Kluczowe odkrycia (walidacja na żywych spadkach 5.06.2026 + symulacja `sim_overlay.php`):**
1. **Ślepota na trajektorię** — `ValuationPillar` i `QualityPillar` czytają POZIOM trailing/forward,
   nie KIERUNEK rewizji. Stąd NVO (cięte prognozy, trailing różowy) dostaje zawyżony score.
2. **Wycena tania się po spadku ceny** — EV liczone z `current_price`; spadek → "taniej" → score rośnie
   dokładnie gdy biznes się psuje (mechanizm pułapki wartości; potwierdzony w sim).
3. **Trough-FCF w cyklu inwestycyjnym** — MU: EV/trailing-FCF = ~74 (val 1.9!) bo capex na HBM dusi FCF;
   forward-recovery niewidoczny. Fallback capex-heavy (fix XOM) NIE odpala (capex < 70% OpCF).
4. **Dane analityków JUŻ płyną, ale nie wracają do score'a** — `ForecastParser` parsuje `targets.mean`/
   `upside` i `recommendationTrend`; `mostRecentQuarter` jest w payloadzie `defaultKeyStatistics` (nieparsowany).
5. **Czas wyników zniekształca przekrój** — sezon rozciągnięty: część spółek po wynikach (świeży TTM),
   część za miesiąc (stały TTM). Najgroźniejszy: okno "cena wyprzedza fundamenty" tuż po raporcie.

## Vision & Problem Statement

**Delta fazy 5:** model przechodzi od oceny opartej wyłącznie na **poziomie** danych trailing/bieżących
→ do oceny świadomej **trajektorii i świeżości** danych. Faza 3 naprawiła *jednostkę odniesienia*
(peer-group); faza 5 dokłada **ortogonalną oś — wymiar czasu**: w którą stronę idą prognozy, gdzie
cena jest względem konsensusu analityków, czy FCF nie jest chwilowo zdołowany cyklem inwestycyjnym,
i jak świeże są fundamenty względem ostatniego raportu. Plus domknięcie odłożonej **rekalibracji progów**
na pełnych medianach peer-group.

**Problem (dlaczego teraz):** model kotwiczy na trailing/bieżących liczbach i nie widzi, dokąd zmierzają.
Skutki potwierdzone na żywych przykładach: pułapki wartości punktowane wysoko (NVO), realne okazje
niedoceniane (MU trough-FCF), a tuż po wynikach spółki spadek ceny mechanicznie podbija „taniość"
w najgorszym momencie. Rozciągnięty sezon wyników dokłada niespójność przekrojową. Efekt: rekomendacje
bywają niezgodne z osądem eksperckim dokładnie w momentach o najwyższej stawce (wokół wyników).

**Insight (dlaczego nie w fazie 3):** to kolejny, świadomy krok doskonalenia. Faza 3 zmieniła „DO KOGO
porównujemy" (jednostka odniesienia / peer-group); faza 5 zmienia „JAK ŚWIEŻE i PRZYSZŁOŚCIOWE są dane"
(wymiar czasu). To ortogonalne osie — dlatego osobna faza. Rekalibracja progów była dodatkowo świadomie
odłożona do napełnienia `peer_medians` przez cron (~tydzień), a reszta dojrzała z dzisiejszej walidacji.

**Co MUSI przetrwać (preserved):**
1. **Determinizm rdzenia** — ten sam input → ten sam wynik; wagi/progi/pokrętła w configu (FR-010). Święte.
2. **Ciągłość track-record** — każda zmiana metodyki za bumpem `model_version`; istniejące snapshoty czytelne.
3. **Znaczenie skali rekomendacji** — „AKUMULUJ"/„SILNE KUPUJ" mają znaczyć dla użytkownika to samo (kalibracja rozkładu).
4. **Kontrakt AI** — `AiDivergenceService` i szablony działają bez regresji (rozbudowa OK, zepsucie nie).

## User & Persona

**Bez zmian wobec faz 2–3.** Ten sam inwestor detaliczny; role User / PRO / Admin i brama PRO
niezmienione. Faza 5 to czysto wewnętrzna jakość modelu — nie dotyka auth ani ról.

## Access Control

**No changes planned — current model preserved.** Login (email+hasło), role User / PRO / Admin,
brama PRO (kod per-user) bez zmian. Jeśli powstanie powierzchnia admina (raport rozkładu CVS /
rekalibracja progów), trafia pod istniejący guard `is_admin` — bez nowej roli.

## Success Criteria

### Primary
Dwa **overlaye jako deterministyczne kary post-agregacyjne** za `model_version` 3.1:
- **Overlay A (rewizja prognoz)** — celowana kara, największa gdy wycena wysoka ORAZ forward-EPS cięte
  (`trap = (valScore−50)/50` × skala cięcia). Łapie pułapkę wartości.
- **Overlay B (cena-vs-target)** — kara liniowa gdy cena > średni target analityków (`upside` z `ForecastParser`);
  dodatni upside nie nagradzany (konserwatywnie).

**Dowód działania = produkcja odtwarza zwalidowaną symulację (`sim_overlay.php`) na próbce znanych spółek:**
NVO schodzi *SILNE KUPUJ → AKUMULUJ* (swing → NEUTRALNIE) przez Overlay A; QCOM/MU notowane powyżej targetu
schodzą o szczebel przez Overlay B; **AVGO (realna okazja po krachu: estymaty rosną, cena −32% pod targetem)
pozostaje nietknięte oboma overlayami**. Czyli: pułapki w dół, prawdziwe okazje bez szwanku.

### Secondary (nice-to-have)
Nowe sygnały (kierunek rewizji, `upside` vs target, świeżość danych) **wystawione warstwie AI / na detalu**
jako przejrzystość — `AiDivergenceService` może je wpleść w narrację rozjazdu. Rozbudowa kontraktu, nie zmiana zasady.

### Guardrails (nie wolno zepsuć)
- **Determinizm rdzenia** — overlaye bez `date()/time()`; pokrętła (SLOPE/CAP/sensitivity) w configu (FR-010).
- **Ciągłość track-record** — nowa metodyka za `model_version` 3.1; snapshoty 3.0 czytelne, nie mieszane.
- **Znaczenie skali rekomendacji** — rozkład rekalibrowany ZANIM nowa wersja „wejdzie" na produkcję; 72/58/42/28 znaczą to co dziś.
- **Kontrakt AI / szablony** — `AiDivergenceService` działa bez regresji (rozbudowa OK).
- **Tylko darmowe/publiczne dane** — Yahoo Finance, bez płatnych API.

## Functional Requirements

### Pierwszy plaster — overlaye (model_version 3.1) [must-have]
- FR-001: System nalicza **karę za rewizję prognoz (Overlay A)** — celowaną: rosnącą ze wzrostem wyceny ORAZ skalą cięcia forward-EPS; rosnące/stabilne estymaty = kara 0. Priority: must-have. Change: new
  > Socrates: Kontrargument — czemu celowana, nie płaska? Rozstrzygnięcie: w symulacji płaskie skalowanie growthu chybiło (nasycony sigmoid przy głębokiej taniości) — kara musi trafiać tam, gdzie powstaje pułapka: wysoka wycena × cięcie.
- FR-002: System nalicza **karę cena-vs-target (Overlay B)** gdy `upside` (z `ForecastParser`) < 0; dodatni upside nie nagradzany. Priority: must-have. Change: new
  > Socrates: Kontrargument — targety analityków są zaszumione i opóźnione. Rozstrzygnięcie: używamy ich tylko jako miękkiej kary przy ujemnym upside (cena ponad konsensus = dyscyplina), nigdy do nagradzania — ryzyko jednostronne, akceptowalne.
- FR-003: Oba overlaye działają jako **kary post-agregacyjne** na CVS (swing i fund), nie modyfikują wnętrza filarów. Priority: must-have. Change: new
  > Socrates: Kontrargument — czemu nie wewnątrz filarów? Rozstrzygnięcie: warstwa post-agregacyjna trzyma filary i determinizm czyste, a całość łatwo chować za `model_version` i wyłączać kill-switchem.
- FR-004: Nowa metodyka (z overlayami) jest stemplowana `model_version` 3.1; snapshoty 3.0 niezmienione. Priority: must-have. Change: modified
- FR-005: Źródłem kierunku rewizji jest realny **`earningsTrend.epsTrend` (90daysAgo)** z Yahoo (nowy moduł quoteSummary), nie wartość modelowana. Priority: must-have. Change: new
  > Socrates: Kontrargument — recommendationTrend już parsowany, po co moduł? Rozstrzygnięcie: epsTrend mierzy **rewizję estymat EPS** (właściwy sygnał pułapki), recommendationTrend to migracja ocen (słabszy proxy). Bierzemy realny epsTrend.

### Świadomość czasu wyników [rdzeń — bliskość wyników jako sygnał zmienności]
**Refrejming (insight użytkownika):** ważniejsza od nieświeżości PO raporcie jest **bliskość NADCHODZĄCYCH
wyników**. ~5 sesji przed raportem wchodzi gra spekulacyjna (nadzieje, plotki, pozycjonowanie insiderów) →
ruchy w górę i w dół się **potęgują**. Im dalej od historycznych wyników, tym mniejsza ich waga; im bliżej
nowych, tym większa zmienność i ryzyko, że ruch ceny to spekulacja, nie informacja. Model ma to uwzględniać
po OBU stronach, ze szczególnym naciskiem na okno przed-wynikowe.

- FR-006: System parsuje **`mostRecentQuarter`** (już w payloadzie) → „dni od wyników" (malejąca waga starych liczb). Priority: must-have. Change: new
- FR-007: System dociąga **`calendarEvents`** → „**dni do następnych wyników**" (sygnał okna przed-wynikowego). Priority: must-have. Change: new
  > Socrates: Podniesione z nice-to-have do must-have przez refrejming — proximity to upcoming earnings to główny sygnał zmienności, nie dodatek.
- FR-008: Snapshoty CVS wzbogacone o znaczniki czasu wyników (dni od / dni do) — addytywne kolumny. Priority: must-have. Change: modified
- FR-009: **Earnings-proximity guard** — w oknie przed-wynikowym (~K sesji) model traktuje ruch ceny jako spekulacyjnie wzmocniony → **temperuje konwersję** (nie pozwala momentum podbić rekomendacji) i flaguje „okno przed-wynikowe"; symetrycznie tuż po raporcie „w tranzycie". Staleness/proximity liczone **przy fetchu/snapshocie i podane jako INPUT** → determinizm rdzenia zachowany. Priority: must-have. Change: new
  > Socrates: Kontrargument — „dni od wyników" wymaga `date('now')` → łamie determinizm. Rozstrzygnięcie: świeżość/bliskość wyznaczane POZA logiką score'a (przy pobraniu) i wstrzykiwane jak każde wejście; rdzeń zostaje czystą funkcją. Dodatkowo (insight) ciężar przesunięty na okno PRZED wynikami, gdzie zmienność spekulacyjna jest największa.
- FR-010: Screener / detal pokazuje **badge czasu wyników** (przed wynikami / po wynikach / w tranzycie). Priority: nice-to-have. Change: new

### Normalizacja FCF dla cyklu inwestycyjnego [must-have]
- FR-011: `ValuationPillar` używa **forward FCF z estymat** w mianowniku (spójnie z forward-EPS), by trough-FCF cyklu inwestycyjnego nie zawyżał „drogości" (case MU). Priority: must-have. Change: modified
  > Socrates: Kontrargument — normalizacja w górę uczyni KAŻDĄ capex-spółkę „tanią". Rozstrzygnięcie: forward FCF z **estymat analityków** (nie dowolne ×), najbliżej realnej recovery; trzymane w ryzach przez kotwicę absolutną z fazy 3 (FR-015 tamtej fazy).

### Rekalibracja skali [must-have, zależna od danych crona]
- FR-012: **Raport rozkładu CVS** (CLI/admin) na pełnych `peer_medians` — narzędzie do oceny progów. Priority: must-have. Change: new
  > Socrates: Kontrargument — po co narzędzie, skoro progi „działają"? Rozstrzygnięcie: każdy overlay przesuwa rozkład; bez pomiaru rekalibracja byłaby zgadywaniem.
- FR-013: Progi 72/58/42/28 (i siła kar overlayów) **świadomie rekalibrowane** tak, by „AKUMULUJ" znaczyło to samo; ocena ekspercka, nie auto-tuning. Priority: must-have. Change: modified
  > Socrates: Kontrargument — bez backtestu to subiektywne. Rozstrzygnięcie: świadoma reguła projektu (jak faza 3) — osąd ekspercki na próbce; track-record dojrzeje ~lipiec 2026 jako walidacja ex-post.
- FR-014: **Overlay A pozostaje wyłącznie karzący** (asymetryczny) — rosnące estymaty NIE dodają punktów; odblokowanie MU należy do FR-011 (forward FCF), nie do nagrody za momentum estymat. Priority: must-have. Change: modified
  > Socrates: Kontrargument — symetria odblokowałaby niedocenione okazje. Rozstrzygnięcie: nagradzanie rosnących estymat = gonienie momentum, wbrew dyscyplinie wartości; zostajemy asymetryczni, recovery łapiemy przez FCF.

### Zachowane (obronne — guardrail-FR)
> Socrates (zbiorczo): FR-015–019 to jawne kotwice obronne — każdy „stoi jak napisany"; ich rolą jest uczynić nienaruszalne granice (determinizm, ciągłość pomiaru, brak regresji AI, darmowe dane, addytywne migracje) niewidzialnymi-do-złamania.
- FR-015: CVS pozostaje deterministyczny — ten sam input → ten sam wynik; pokrętła overlayów w configu (FR-010). Priority: must-have. Change: preserved
- FR-016: Progi rekomendacji i ich znaczenie zachowane (rozkład rekalibrowany przed aktywacją 3.1). Priority: must-have. Change: preserved
- FR-017: `AiDivergenceService` i szablony działają bez regresji. Priority: must-have. Change: preserved
- FR-018: Model używa wyłącznie darmowych/publicznych danych Yahoo Finance. Priority: must-have. Change: preserved
- FR-019: Zmiany schematu wyłącznie addytywne, numerowane SQL (`NNN_*.sql`); track-record per `model_version` (mechanizm fazy 3). Priority: must-have. Change: preserved

## User Stories

### US-01 — overlaye odtwarzają walidację na produkcji
- **Given** spółka z ciętymi prognozami i wysoką wyceną (np. NVO) oraz spółka po krachu z rosnącymi estymatami i ceną pod targetem (np. AVGO), pod `model_version` 3.1,
- **When** model liczy CVS dual-mode z włączonymi overlayami A i B,
- **Then** NVO schodzi *SILNE KUPUJ → AKUMULUJ* (swing → NEUTRALNIE), AVGO pozostaje *AKUMULUJ* nietknięte, a spółka notowana powyżej targetu (QCOM/MU) schodzi o jeden szczebel — zgodnie z `sim_overlay.php`.

### US-02 — guard chroni przed oknem po-wynikowym
- **Given** spółka, która raportowała wyniki w ciągu ostatnich K dni i jej cena spadła,
- **When** model przelicza CVS przed odświeżeniem fundamentów/estymat przez Yahoo,
- **Then** samo potanienie ceny nie podbija rekomendacji; wynik jest oznaczony „świeże wyniki / w tranzycie" do czasu dojścia danych.

## Business Logic

**Reguła dziś:** CVS ocenia spółkę z **poziomu** danych trailing/bieżących — forward EV/FCF vs mediana
peer-group, momentum vs SPY, jakość progowa — i mapuje wynik 0–100 na rekomendację. Model nie patrzy,
w którą stronę zmierzają prognozy ani jak blisko jest raport.

**Reguła po fazie 5:** do oceny dochodzi **wymiar czasu i trajektorii** jako warstwa korygująca:
1. **Kierunek prognoz** — gdy forward-EPS są cięte przy wysokiej (taniej) wycenie, model **nieufnie**
   obniża wynik (kara celowana, Overlay A) — bo „tanio + psujące się estymaty" to odcisk pułapki wartości.
2. **Cena vs konsensus** — gdy cena przewyższa średni target analityków, model **dyscyplinuje** wynik
   (Overlay B); dodatni upside nie jest nagradzany (konserwatyzm).
3. **Bliskość wyników** — w oknie przed-wynikowym (~K sesji) ruch ceny jest spekulacyjnie wzmocniony
   (nadzieje, plotki, insiderzy), więc model **temperuje** konwersję napędzaną momentum i to flaguje;
   im dalej od ostatniego raportu, tym mniejsza waga jego liczb.
4. **Normalizacja FCF** — w mianowniku wyceny model używa **forward FCF z estymat**, by chwilowo
   zdołowany FCF cyklu inwestycyjnego nie udawał „drogości".

Wejścia (z perspektywy użytkownika): te same dane spółki co dziś + kierunek rewizji estymat, cena docelowa
analityków oraz daty wyników (ostatnie i najbliższe). Wyjście: ten sam wynik 0–100 + rekomendacja, ale
**świadome trajektorii i momentu w cyklu wynikowym**, znaczone nową `model_version`. Determinizm rdzenia
zachowany: świeżość/bliskość wyników wyznaczane przy pobraniu i podawane jako wejście, nie liczone w score.

## Non-Functional Requirements

- **Determinizm:** ten sam input → identyczny wynik. Overlaye i guard nie wołają `date()/time()` —
  „dni od / do wyników" są wyznaczane w warstwie pobierania i wstrzykiwane jak każde inne wejście.
- **Stabilność wejść:** kierunek rewizji, target i daty wyników pochodzą z tego samego snapshotu danych
  co reszta fundamentów (jeden fetch / cykl cache), nie z osobnych żądań w trakcie scoringu.
- **Czas reakcji użytkownika:** bez regresji — overlaye to tania arytmetyka post-agregacyjna; jeden
  dodatkowy moduł quoteSummary (`calendarEvents` + `earningsTrend`) w istniejącym wywołaniu, nie nowy round-trip.
- **Ciągłość pomiaru:** przejście na `model_version` 3.1 nie psuje widoków track-record 3.0 (stara wersja
  czytelna, nowa liczona osobno); rozkład rekalibrowany przed „włączeniem" 3.1 na produkcji.

## Constraints & Preserved Behavior

- **Dane:** wyłącznie darmowe/publiczne Yahoo Finance; dwa dodatkowe moduły quoteSummary
  (`earningsTrend`, `calendarEvents`) respektują nieoficjalne rate-limity (dokładane do istniejącego wywołania).
- **Migracje:** wyłącznie addytywne, numerowane SQL (`NNN_*.sql`) — kolumny świeżości/czasu wyników
  w `cvs_snapshots`, ew. surowe metryki. Nie łamać istniejących tabel.
- **Determinizm/Config (FR-010):** pokrętła overlayów (SLOPE/CAP/sensitivity), okno guardu K, próg
  normalizacji FCF, progi rekomendacji — wszystko w `config/cvs-weights.php`, zero hardkodu.
- **Kontrakt AI:** `AiDivergenceService` i szablony bez regresji; nowe pola (rewizja, upside, czas wyników)
  podawane do promptu wstecznie zgodnie (rozbudowa narracji rozjazdu, nie zmiana zasady).
- **Wersjonowanie:** każda zmiana metodyki za bumpem `model_version`; snapshoty znaczone wersją; track-record
  per wersja (mechanizm fazy 3 — reużyć, nie wymyślać).
- **Cron CF:** raport rozkładu / batch jako CLI z jawną ścieżką PHP 8.2, idempotentny — spójne z `bin/rescore.php`.

## Non-Goals

- **Brak zmian w Momentum / auth / UX-redesign** — dotykamy wyłącznie Valuation, warstwy overlayów i czasu
  wyników. Filar Momentum, logowanie, role i redesign UI poza zakresem. Model pozostaje **dzienny**
  (re-score w oknach ~30min); bez intraday/realtime — bliskość wyników liczona w sesjach, nie tick-by-tick.
- **Brak twardego backtestu / autokalibracji ML** — pokrętła overlayów i progi strojone osądem eksperckim,
  nie automatem pod wyniki historyczne. Track-record dojrzeje ~lipiec 2026 jako walidacja ex-post.
- **Brak płatnych danych / własnego datasetu estymat** — wyłącznie darmowe Yahoo (epsTrend, calendarEvents,
  targety). Bez kupowania feedu fundamentalnego ani budowy własnych estymat.
- **Brak przebudowy peer-group z fazy 3** — drzewo sektor→podsektor, mediany i kotwica absolutna zostają
  jak są. Faza 5 dokłada warstwę czasu/trajektorii OBOK, nie przebudowuje fazy 3.

## Product Framing

- **Typ produktu:** bez zmian — web-app + API (PHP/MySQL na CF).
- **Skala:** bez zmian — ~10 userów (small).
- **Czas:** po godzinach, brak twardego deadline'u; pierwszy plaster (dwa overlaye za `model_version` 3.1) ~2 tygodnie.
- **Model dostarczania:** fazowy po priorytetach. Kolejność: (1) **overlaye A+B** za 3.1 [rdzeń, dane głównie
  są] → (2) **świadomość czasu wyników** (epsTrend już zasila A; calendarEvents + proximity guard) → (3)
  **normalizacja FCF** (forward FCF, case MU) → (4) **rekalibracja skali** (czeka na pełne `peer_medians` z crona).

## Open Questions

- **OQ-1 (okno K):** Ile sesji przed wynikami to „okno przed-wynikowe" amplifikacji spekulacyjnej? Kandydat ~5
  (insight użytkownika); do potwierdzenia osądem/obserwacją. Czy guard temperuje proporcjonalnie do bliskości?
- **OQ-2 (pokrętła overlayów):** Wartości SLOPE/CAP/sensitivity (sim: REV_SLOPE 120, GATE_SLOPE 60, CAP 18) —
  ilustracyjne; finalne dobrać przy rekalibracji rozkładu. W configu (FR-010).
- **OQ-3 (forward FCF z Yahoo):** Czy Yahoo udostępnia forward FCF bezpośrednio, czy trzeba go wyprowadzić
  (np. z forward-EPS × historyczna konwersja FCF/EPS)? Wymaga sprawdzenia pól w planie.
- **OQ-4 (rekalibracja):** Jak przeliczyć progi/siłę kar tak, by „AKUMULUJ" znaczyło to samo po włączeniu 3.1 —
  i jak zweryfikować bez backtestu (osąd ekspercki na próbce). Zależne od pełnych `peer_medians` (~tydzień crona).

## Quality cross-check

Wszystkie elementy obecne (status `accepted`):
- **Access Control** — bez zmian, jawnie zapisane (ewentualny panel admina pod `is_admin`).
- **Business Logic** — reguła przed/po (modyfikacja: dochodzi warstwa czasu/trajektorii; determinizm broniony przez input-time staleness).
- **Timeline-cost** — 2 tygodnie na pierwszy plaster (krótkie okno, bez długoterminowego zobowiązania).
- **Non-Goals** — 4 wykluczenia (Momentum/auth/UX, backtest/ML, płatne dane, przebudowa peer-group).
- **Preserved behavior** — Constraints & Preserved Behavior + 5 guardrail-FR (015–019).

Brak warnów. Pozostają 4 Open Questions (okno K, pokrętła, forward FCF, rekalibracja) — świadome decyzje
do rozstrzygnięcia w /10x-prd i /10x-plan, nie luki shape'u.
