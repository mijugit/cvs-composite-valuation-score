---
project: CVS — Composite Valuation Score (Faza 5 — dostrajanie modelu: trajektoria i czas wyników)
version: 1
status: draft
created: 2026-06-05
context_type: brownfield
product_type: web-app+api
target_scale:
  users: small
timeline_budget:
  delivery_weeks: 2
  hard_deadline: null
  after_hours_only: true
---

## Current System Overview

**Cel systemu:** CVS ocenia spółki US (NYSE/NASDAQ) kompozytowym wynikiem 0–100 w dwóch trybach
(Swing 1–4M / Fundamentalny 6–12M) z etykietą rekomendacji (SILNE KUPUJ ≥72 … UNIKAJ <28).

**Architektura:** monolit, vanilla PHP 8.2, MySQL, Apache, bez frameworka, PSR-4 (`CVS\`), front
controller (`public/index.php`). Hosting Cyber_Folks. Dane z Yahoo Finance via cURL
(`FinancialDataFetcher`, 8 modułów quoteSummary), cache w `$_SESSION` (TTL 3600s). Cron CF do
dziennego re-scoringu (`bin/rescore.php`).

**Model (rdzeń, po fazie 3 = `model_version` 3.0):** 3 filary × 2 tryby.
- *Valuation* (40% swing / 65% fund) — forward EV/FCF vs **mediana peer-group** (podsektor→sektor),
  sigmoid k=3. Growth wyprowadzony z `forward_eps/trailing_eps` — POZIOM, nie kierunek.
- *Momentum* (45% / 15%) — ROC vs SPY na zamkniętych miesięcznych close'ach.
- *Quality* (15% / 20%) — marża brutto vs mediana sektora, dźwignia, growth (punkty 0–10).
- Wagi/progi/`model_version` w `config/cvs-weights.php` (FR-010, determinizm rdzenia). Snapshoty
  znaczone wersją; track-record liczony per wersja.

**Użytkownicy:** ~10 inwestorów detalicznych; role User / PRO / Admin, brama PRO (kod per-user).

**Funkcjonalność dziś:** analiza pojedynczej spółki z rozbiciem na filary, Screener ~spółek,
Track Record, watchlist + alerty, narracja AI rozjazdu CVS-vs-analitycy (`AiDivergenceService`, tier PRO).

## Problem Statement & Motivation

**Luka:** model kotwiczy na danych **trailing/bieżących** i jest ślepy na **trajektorię** — nie widzi,
w którą stronę zmierzają prognozy, gdzie cena jest względem konsensusu analityków, ani jak blisko jest
raport kwartalny. Skutki potwierdzone na żywych przykładach (walidacja 5.06.2026 + symulacja
`sim_overlay.php` na prawdziwych filarach):
- **Fałszywe pozytywy** — pułapka wartości: NVO z ciętymi prognozami i wciąż różowym trailing dostaje
  zawyżony wynik (Fund ~72, SILNE KUPUJ).
- **Fałszywe negatywy** — MU w cyklu inwestycyjnym (capex na HBM dusi FCF) wychodzi „drogo" (val 1.9)
  mimo −11% i rosnących estymat; forward-recovery niewidoczny.
- **Okno po-/przed-wynikowe** — cena reaguje przed fundamentami; tuż po guide-down spadek ceny
  mechanicznie podbija „taniość" w najgorszym momencie. Rozciągnięty sezon wyników dokłada niespójność
  przekrojową (część spółek po raporcie, część przed).

**Dlaczego teraz:** to kolejny świadomy krok doskonalenia. Faza 3 naprawiła *jednostkę odniesienia*
(peer-group — „do kogo porównujemy"). Faza 5 dokłada **ortogonalną oś — wymiar czasu** („jak świeże
i przyszłościowe są dane"). Dodatkowo rekalibracja progów była świadomie odłożona do napełnienia
median peer-group przez cron (~tydzień), a reszta dojrzała z dzisiejszej walidacji.

**Koszt status quo:** rekomendacje bywają niezgodne z osądem eksperckim dokładnie w momentach o
najwyższej stawce — wokół wyników, gdzie ruchy cen i zmiana informacji są największe.

## User & Persona

**Bez zmian wobec faz 2–3.** Ten sam inwestor detaliczny; role User / PRO / Admin i brama PRO
niezmienione. Faza 5 to czysto wewnętrzna jakość modelu — nie zmienia doświadczenia logowania ani ról.
Zmienia się jakość liczby i rekomendacji, którą użytkownik widzi — zwłaszcza dla spółek z psującymi się
prognozami, notowanych powyżej targetu lub tuż przed wynikami.

## Success Criteria

### Primary
Dwa **overlaye jako deterministyczne kary post-agregacyjne** za `model_version` 3.1:
- **Overlay A (rewizja prognoz)** — celowana kara, największa gdy wycena wysoka ORAZ forward-EPS cięte.
- **Overlay B (cena-vs-target)** — kara liniowa gdy cena > średni target analityków; dodatni upside nie nagradzany.

Dowód działania = produkcja odtwarza zwalidowaną symulację (`sim_overlay.php`) na próbce znanych spółek:
NVO schodzi *SILNE KUPUJ → AKUMULUJ* (swing → NEUTRALNIE) przez Overlay A; spółka notowana powyżej
targetu (QCOM/MU) schodzi o szczebel przez Overlay B; **AVGO (realna okazja po krachu: estymaty rosną,
cena −32% pod targetem) pozostaje nietknięte oboma overlayami**.

### Secondary
Nowe sygnały (kierunek rewizji, upside vs target, świeżość/bliskość wyników) wystawione warstwie AI /
na detalu jako przejrzystość — narracja rozjazdu może je wpleść. Rozbudowa kontraktu, nie zmiana zasady.

### Guardrails
- **Determinizm rdzenia** — ten sam input → ten sam wynik; overlaye/guard bez `date()/time()`; pokrętła w configu.
- **Ciągłość track-record** — nowa metodyka za `model_version` 3.1; snapshoty 3.0 czytelne, nie mieszane.
- **Znaczenie skali rekomendacji** — rozkład rekalibrowany ZANIM 3.1 „wejdzie" na produkcję; 72/58/42/28 znaczą to co dziś.
- **Kontrakt AI / szablony** — `AiDivergenceService` bez regresji (rozbudowa OK).
- **Tylko darmowe/publiczne dane** — Yahoo Finance, bez płatnych API.

## User Stories

### US-01: Overlaye odtwarzają walidację na produkcji
- **Given** spółka z ciętymi prognozami i wysoką wyceną (np. NVO) oraz spółka po krachu z rosnącymi estymatami i ceną pod targetem (np. AVGO), pod `model_version` 3.1,
- **When** model liczy CVS dual-mode z włączonymi overlayami A i B,
- **Then** NVO schodzi *SILNE KUPUJ → AKUMULUJ* (swing → NEUTRALNIE), AVGO pozostaje *AKUMULUJ* nietknięte, a spółka notowana powyżej targetu (QCOM/MU) schodzi o jeden szczebel — zgodnie z `sim_overlay.php`.

#### Acceptance Criteria
- Overlay A nie karze, gdy prognozy są stabilne/rosnące (kara 0).
- Overlay B nie karze przy dodatnim upside (cena poniżej targetu).
- Wynik z włączonymi overlayami jest deterministyczny (ten sam input → ten sam wynik).

### US-02: Guard chroni przed oknem wynikowym
- **Given** spółka w oknie przed-wynikowym (~K sesji do raportu), której cena rośnie/spada na spekulacji,
- **When** model przelicza CVS,
- **Then** ruch ceny napędzany momentum nie podbija rekomendacji; wynik jest oznaczony „okno przed-wynikowe", symetrycznie „w tranzycie" tuż po raporcie.

#### Acceptance Criteria
- „Dni od / do wyników" wyznaczane przy pobraniu danych i podane jako wejście (rdzeń pozostaje czystą funkcją).
- Symetrycznie: im dalej od ostatniego raportu, tym mniejsza waga jego liczb.

## Scope of Change

Mapowanie z 19 FR shape (zachowane identyfikatory FR-NNN i rozstrzygnięcia Sokratesa).

### Pierwszy plaster — overlaye (model_version 3.1)
- **[new] FR-001:** kara za rewizję prognoz (Overlay A) — celowana: rośnie ze wzrostem wyceny ORAZ skalą cięcia forward-EPS; rosnące/stabilne estymaty = 0.
  > Socrates: płaskie skalowanie growthu chybiło w symulacji (nasycony sigmoid przy głębokiej taniości) — kara musi trafiać tam, gdzie powstaje pułapka: wysoka wycena × cięcie.
- **[new] FR-002:** kara cena-vs-target (Overlay B) gdy `upside` < 0; dodatni upside nie nagradzany.
  > Socrates: targety zaszumione i opóźnione — używamy ich tylko jako miękkiej kary przy ujemnym upside, nigdy do nagradzania.
- **[new] FR-003:** oba overlaye jako kary post-agregacyjne na CVS (swing i fund), bez modyfikacji wnętrza filarów.
  > Socrates: warstwa post-agregacyjna trzyma filary i determinizm czyste; całość chowana za `model_version` i wyłączalna kill-switchem.
- **[modified] FR-004:** nowa metodyka stemplowana `model_version` 3.1; snapshoty 3.0 niezmienione.
- **[new] FR-005:** źródłem kierunku rewizji jest realny kanał trendu estymat EPS (90 dni) z Yahoo, nie wartość modelowana.
  > Socrates: trend ocen analityków już jest dostępny, ale mierzy migrację rekomendacji; trend estymat EPS to właściwy sygnał pułapki.

### Świadomość czasu wyników
- **[new] FR-006:** „dni od ostatnich wyników" (malejąca waga starych liczb) — z pola już obecnego w pobieranych danych.
- **[new] FR-007 (must-have):** „dni do następnych wyników" — sygnał okna przed-wynikowego.
  > Socrates: podniesione z nice-to-have — bliskość nadchodzących wyników to główny sygnał zmienności spekulacyjnej, nie dodatek.
- **[modified] FR-008:** snapshoty CVS wzbogacone o znaczniki czasu wyników (dni od / do) — addytywnie.
- **[new] FR-009:** earnings-proximity guard — w oknie przed-wynikowym (~K sesji) model temperuje konwersję napędzaną momentum i flaguje; „dni od/do" liczone przy pobraniu i podane jako wejście (determinizm).
  > Socrates: „dni od wyników" wymagałoby bieżącej daty w logice score'a (łamie determinizm) — wyznaczamy świeżość/bliskość poza scoringiem i wstrzykujemy jak każde wejście; ciężar przesunięty na okno PRZED wynikami.
- **[new] FR-010 (nice-to-have):** Screener / detal pokazuje badge czasu wyników (przed / po / w tranzycie).

### Normalizacja FCF dla cyklu inwestycyjnego
- **[modified] FR-011:** wycena używa forward FCF z estymat w mianowniku (spójnie z forward-EPS), by trough-FCF cyklu inwestycyjnego nie zawyżał „drogości".
  > Socrates: normalizacja w górę grozi uczynieniem każdej capex-spółki „tanią" — bierzemy forward FCF z estymat (nie dowolne ×), trzymany w ryzach przez kotwicę absolutną z fazy 3.

### Rekalibracja skali (zależna od danych crona)
- **[new] FR-012:** raport rozkładu CVS na pełnych medianach peer-group — narzędzie do oceny progów.
- **[modified] FR-013:** progi 72/58/42/28 i siła kar overlayów świadomie rekalibrowane (osąd ekspercki, nie auto-tuning).
- **[modified] FR-014:** Overlay A pozostaje wyłącznie karzący (asymetryczny) — rosnące estymaty NIE dodają punktów; recovery łapie FR-011.
  > Socrates: nagradzanie rosnących estymat = gonienie momentum, wbrew dyscyplinie wartości.

### Zachowane (preserved)
- **[preserved] FR-015:** CVS deterministyczny — ten sam input → ten sam wynik; pokrętła w configu.
- **[preserved] FR-016:** progi rekomendacji i ich znaczenie zachowane (rozkład rekalibrowany przed aktywacją 3.1).
- **[preserved] FR-017:** narracja AI i szablony bez regresji.
- **[preserved] FR-018:** wyłącznie darmowe/publiczne dane Yahoo Finance.
- **[preserved] FR-019:** zmiany schematu wyłącznie addytywne, numerowane; track-record per `model_version`.

## Constraints & Compatibility

- **Kompatybilność wstecz:** istniejące widoki (analiza, Screener, Track Record, narracja AI) działają bez
  regresji; snapshoty `model_version` 3.0 pozostają czytelne obok 3.1.
- **Migracja danych:** wyłącznie addytywne, numerowane zmiany schematu — kolumny czasu wyników / świeżości
  (i ew. surowe metryki) w tabeli snapshotów. Bez łamania istniejących tabel; track-record izolowany per wersja.
- **Istniejące integracje:** Yahoo Finance — dwa dodatkowe moduły danych (trend estymat EPS + kalendarz wyników)
  dokładane do istniejącego pojedynczego pobrania; respektować nieoficjalne rate-limity. Cron CF (CLI, jawna
  ścieżka PHP 8.2, idempotentny) — spójnie z istniejącym re-scoringiem.
- **Zachowane wprost (preserved):** determinizm rdzenia; znaczenie skali rekomendacji; kontrakt wyniku dla AI;
  brak płatnych źródeł. Wszystkie pokrętła (siła kar, okno K, próg normalizacji FCF, progi) w configu (FR-010).
- **Właściwości obserwowalne (NFR):** czas reakcji bez regresji (overlaye to tania arytmetyka; dodatkowe moduły
  w tym samym pobraniu, nie nowy round-trip); wejścia overlayów stabilne w obrębie jednego cyklu cache;
  przejście na 3.1 nie psuje widoków track-record 3.0.

## Business Logic Changes

**Reguła dziś:** CVS ocenia spółkę z **poziomu** danych trailing/bieżących — forward EV/FCF vs mediana
peer-group, momentum vs SPY, jakość progowa — i mapuje wynik 0–100 na rekomendację. Model nie patrzy,
w którą stronę zmierzają prognozy ani jak blisko jest raport.

**Reguła po fazie 5 (modyfikacja):** do oceny dochodzi **warstwa korygująca świadoma trajektorii i czasu**:
1. **Kierunek prognoz** — gdy forward-EPS są cięte przy wysokiej (taniej) wycenie, model nieufnie obniża wynik
   (Overlay A) — „tanio + psujące się estymaty" to odcisk pułapki wartości.
2. **Cena vs konsensus** — gdy cena przewyższa średni target analityków, model dyscyplinuje wynik (Overlay B);
   dodatni upside nie jest nagradzany.
3. **Bliskość wyników** — w oknie przed-wynikowym (~K sesji) ruch ceny jest spekulacyjnie wzmocniony (nadzieje,
   plotki, pozycjonowanie insiderów), więc model temperuje konwersję napędzaną momentum i to flaguje; im dalej
   od ostatniego raportu, tym mniejsza waga jego liczb.
4. **Normalizacja FCF** — w mianowniku wyceny model używa forward FCF z estymat, by chwilowo zdołowany FCF cyklu
   inwestycyjnego nie udawał „drogości".

Wejścia (z perspektywy użytkownika): te same dane spółki co dziś + kierunek rewizji estymat, cena docelowa
analityków oraz daty wyników (ostatnie i najbliższe). Wyjście: ten sam wynik 0–100 + rekomendacja, ale świadome
trajektorii i momentu w cyklu wynikowym, znaczone nową `model_version`.

## Access Control Changes

**No access control changes — current model preserved.** Login (email+hasło), role User / PRO / Admin,
brama PRO (kod per-user) bez zmian. Jeśli powstanie powierzchnia admina (raport rozkładu CVS / rekalibracja
progów), trafia pod istniejący guard `is_admin` — bez nowej roli.

## Non-Goals

- **Brak zmian w Momentum / auth / UX-redesign** — dotykamy wyłącznie Valuation, warstwy overlayów i czasu
  wyników. Model pozostaje dzienny (bez intraday/realtime); bliskość wyników liczona w sesjach, nie tick-by-tick.
- **Brak twardego backtestu / autokalibracji ML** — pokrętła i progi strojone osądem eksperckim; track-record
  dojrzeje ~lipiec 2026 jako walidacja ex-post.
- **Brak płatnych danych / własnego datasetu estymat** — wyłącznie darmowe publiczne Yahoo Finance.
- **Brak przebudowy peer-group z fazy 3** — drzewo sektor→podsektor, mediany i kotwica absolutna zostają jak są;
  faza 5 dokłada warstwę czasu/trajektorii OBOK, nie przebudowuje fazy 3.

## Open Questions

1. **Okno K (przed-wynikowe)** — ile sesji to okno amplifikacji spekulacyjnej? Kandydat ~5 (insight użytkownika);
   czy guard temperuje proporcjonalnie do bliskości? Owner: user. By: /10x-plan.
2. **Wartości pokręteł overlayów** — SLOPE/CAP/sensitivity (sim ilustracyjnie: REV_SLOPE 120, GATE_SLOPE 60,
   CAP 18); finalne przy rekalibracji rozkładu. W configu (FR-010). Owner: user. By: faza rekalibracji.
3. **Forward FCF z Yahoo (FR-011)** — czy Yahoo udostępnia forward FCF wprost, czy trzeba go wyprowadzić
   (np. forward-EPS × historyczna konwersja FCF/EPS)? Wymaga sprawdzenia pól. Owner: implementer. By: /10x-plan.
4. **Rekalibracja skali (FR-013/016)** — jak przeliczyć progi/siłę kar, by „AKUMULUJ" znaczyło to samo po
   włączeniu 3.1, i jak zweryfikować bez backtestu (osąd ekspercki na próbce). Zależne od pełnych median z crona
   (~tydzień). Owner: user. By: faza rekalibracji.
