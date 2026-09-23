---
project: "CVS — Walidacja danych fundamentalnych przez LLM"
context_type: brownfield
created: 2026-08-20
updated: 2026-08-21
product_type: web-app+api
target_scale:
  users: small
checkpoint:
  current_phase: 8
  phases_completed: [1, 2, 3, 4, 5, 6, 7]
  gray_areas_resolved:
    - topic: "persona"
      decision: "Ty / admin systemu — narzędzie wewnętrzne jakości danych, bez bezpośredniej widoczności dla użytkowników CVS"
    - topic: "kategoria zmiany"
      decision: "Nowy moduł jakości danych, dołożony obok istniejącego FinancialDataFetcher/rescore.php"
    - topic: "insight"
      decision: "Bug był niewidoczny (dane złe, ale nie NULL, nie dawały żadnego sygnału); dopiero systematyczny audyt + eksperyment 3-LLM (Gemini/Perplexity/GPT na GIS) dał namacalny dowód i wzorzec wykrywania"
    - topic: "mechanika"
      decision: "PIVOT z automatycznego dziennego crona na ręczny trigger per spółka: przycisk w karcie analizy → async request do LLM → wynik zapisany jako 'dane zwalidowane' z timestampem/provenance → rescore tej spółki"
    - topic: "trwałość nadpisania"
      decision: "Bezterminowo, do następnego ręcznego triggera admina (bez własnego TTL w MVP)"
    - topic: "zakres pól do walidacji"
      decision: "Tylko pola oznaczone lokalnie jako podejrzane/puste (NULL lub złamana reguła spójności, np. FCF>OCF, kadencja earnings) — nie pełen zestaw ~50 pól"
    - topic: "dostawca LLM"
      decision: "Gemini na MVP (rozszerza istniejącą integrację LlmGemini); architektura ma zostawić miejsce na inny provider później, skoro eksperyment pokazał GPT/Perplexity jako jakościowo lepsze"
    - topic: "mechanizm async"
      decision: "Reużyć istniejący wzorzec background worker z Recenzji Krytycznej (AiCriticalReviewService/generate_critical_review.php): markPending() → exec('php bin/worker.php &') → markCompleted()/markFailed(), frontend polluje status. Zero nowej infrastruktury kolejkowej."
  frs_drafted: 12
  quality_check_status: accepted
timeline_budget:
  delivery_weeks: 2
  hard_deadline: null
  after_hours_only: true
---

## Current System

**Produkt:** CVS — Composite Valuation Score. `FinancialDataFetcher` (src/Api/) pobiera dane
fundamentalne z Yahoo Finance na żywo przy każdym rescore (`bin/rescore.php`), normalizuje do
~50 pól (`normalise()`) i przekazuje do `CVSModel`. Wynik (score, nie surowe dane fundamentalne)
trafia do `cvs_snapshots` — same surowce nigdy nie są trwale zapisywane, liczone na nowo za
każdym razem.

**Tech stack:** Vanilla PHP 8.2 / MySQL, bez frameworka, PSR-4 (`CVS\`). `PayloadCompleteness`
(src/Api/PayloadCompleteness.php) to jedyny obecny mechanizm kontroli jakości danych wejściowych
— sprawdza wyłącznie czy pole `revenue` jest NULL. Cron na hostingu Cyber_Folks (PHP 8.2 CLI,
ścieżka jawna).

**Użytkownicy dziś:** Wewnętrzni. Screener/portfolio/track-record czytają `cvs_snapshots`;
portfele LLM (LlmFree, LlmGemini) egzekutują transakcje na podstawie tych wyników.

**Rdzeń funkcjonalności:** `AnalysisController` → `FinancialDataFetcher::fetch()` →
`QualityGate::evaluate()` (binarny pass/fail) → 3 filary (Wycena/Momentum/Jakość) → `CVSResult`.
Determinizm jest twardym wymogiem (CLAUDE.md): ten sam `$financials` → identyczny wynik, zero
`date()`/`time()` w logice scoringu.

## Problem Statement & Motivation

`rescore.php` ufa surowym polom z Yahoo Finance bez żadnej weryfikacji poza NULL-checkiem
jednego pola (`revenue`). Audyt produkcyjny (2026-08-20, 1761 tickerów, najnowszy snapshot per
ticker) i trzykrotny eksperyment walidacyjny (Gemini, Perplexity, GPT na GIS) potwierdziły
konkretnie dwa niezależne, powtarzalne błędy w danych Yahoo:

1. **`days_since_earnings` bywa wieloletnio przestarzałe, mimo że pole NIE jest NULL.**
   8 tickerów (LHX, FIX, STT, COST, GIS, DE, CBF.WA, SNT.WA) miało wartości 2000-3064 dni
   (5.5-8.4 roku) — realny stan (potwierdzony niezależnie przez 3 modele LLM z web search dla
   GIS) to ~50-81 dni. Źródło: Yahoo `defaultKeyStatistics.mostRecentQuarter`.
2. **`free_cash_flow` bywa wewnętrznie niespójne i przestarzałe.** Dla GIS: Yahoo FCF = 2.31B
   USD, ale nasze własne `operating_cash_flow` = 2.166B USD — FCF > OCF jest matematycznie
   niemożliwe (FCF = OCF − capex, capex ≥ 0). Trzy niezależne źródła zgodnie podają ~1.6B USD.

Skala luk NULL jest osobnym, mniejszym problemem: `fair_value_price` NULL dla 81% tickerów,
`earnings_state` dla 98.6%, `days_since_earnings`/`days_to_earnings` dla ~67-68% — ale te są co
najmniej **widoczne** (NULL sygnalizuje brak). Prawdziwy problem to dane **obecne, ale błędne** —
dziś kompletnie niewykrywalne, bo nic w systemie nie sprawdza wiarygodności wartości innej niż
NULL.

**Dlaczego teraz:** bug był niewidoczny przez cały czas istnienia systemu — dane złe, ale
nie-NULL, nie generowały żadnego sygnału. Dopiero systematyczny audyt bazy + trzykrotny
eksperyment z niezależnymi LLM (Gemini/Perplexity/GPT, wszystkie trzy zgodne ze sobą i rozbieżne
z Yahoo) dał namacalny dowód skali problemu oraz wzorzec wykrywania (błędy kadencji + reguły
wewnętrznej spójności jak FCF ≤ OCF).

**Obecny workaround:** żaden. `QualityGate`/`PayloadCompleteness` nie sprawdzają wiarygodności —
tylko obecność jednego pola.

## User & Persona

**Persona:** administrator/operator systemu CVS (Ty). To wewnętrzne narzędzie jakości danych —
efekt jest pośredni dla użytkowników CVS (trafniejszy score, bo wejścia do modelu są
wiarygodniejsze), ale sama walidacja/backfill nie ma bezpośredniego interfejsu użytkownika poza
adminem uruchamiającym/przeglądającym cron.

## Access Control Changes

**Zmienia się.** Mechanika jest ręcznym triggerem w UI (przycisk w karcie analizy), nie cichym
crone-em w tle — wymaga więc tego samego guarda co inne akcje administracyjne w systemie
(`AuthController::requireAuth()` + `is_admin`, wzorem `peer_bucket_override` i `ticker_links`
CRUD). Przyciski "Sprawdź wszystkie dane" / "Sprawdź dane brakujące" w sekcji "Dane źródłowe (surowe)"
widoczne i klikalne wyłącznie dla admina; zwykli użytkownicy nie widzą tej akcji ani jej wyników
poza efektem końcowym (poprawiony score po rescore).

## Success Criteria

### Primary
- Flow działa end-to-end dla co najmniej jednego realnego tickera (np. GIS): klik "Sprawdź dane
  brakujące" → podejrzane/puste pola wskazane lokalnymi regułami zostają poprawione/uzupełnione
  przez Gemini z zapisanym provenance (źródło + data) → admin przegląda diff i potwierdza →
  rescore tej spółki produkuje zaktualizowany CVS score na zwalidowanych danych.

### Secondary
- Zwalidowane pola widoczne na zielono (zamiast czerwono) bezpośrednio w sekcji "Dane źródłowe",
  z tooltipem daty/źródła — bez konieczności otwierania osobnego widoku.

### Guardrails
- Determinizm `CVSModel` pozostaje nienaruszony — AI nigdy nie zapisuje bezpośrednio do
  `cvs_swing`/`cvs_fund`/`golden_signal`; zapisuje wyłącznie do warstwy fundamentali, które
  przechodzą przez istniejący, deterministyczny pipeline scoringu.
- `bin/rescore.php` (dzienny batch dla wszystkich tickerów) działa bez zmian — funkcja walidacji
  jest addytywna, nie zastępuje istniejącego mechanizmu.
- `QualityGate`/`PayloadCompleteness` nadal działają na scalonych danych (nadpisanie + Yahoo) —
  walidacja nigdy nie omija bramki jakości.

## Plan dostawy
- **Szacunek:** 1-2 tygodnie po godzinach, oparty o istniejący, sprawdzony wzorzec workera
  (Recenzja Krytyczna: `AiCriticalReviewService`/`generate_critical_review.php`).

## User Stories

### US-01: Admin waliduje dane fundamentalne dla spółki z podejrzanymi polami

- **Given** admin przegląda sekcję "Dane źródłowe (surowe)" karty analizy spółki, która ma pola
  podświetlone na czerwono jako podejrzane/puste (np. GIS: `days_since_earnings` łamie regułę
  kadencji, `free_cash_flow` > OCF)
- **When** klika przycisk "Sprawdź dane brakujące" (albo "Sprawdź wszystkie dane")
- **Then** system: (1) zbiera listę pól do wysłania (tylko podejrzane/puste, albo pełny zestaw
  pól używanych przez CVSModel — zależnie od przycisku), (2) odpala worker w tle wzorem
  `generate_critical_review.php`, (3) worker woła Gemini z promptem zawierającym te pola +
  kontekst firmy, (4) system pokazuje adminowi diff (stare vs nowe wartości) i czeka na
  potwierdzenie, (5) po potwierdzeniu zapisuje wyłącznie typowane pola liczbowe/daty jako
  nadpisania z provenance (źródło: gemini_validation, data; tekstowe "notes" trafiają do
  osobnego logu), (6) triggeruje rescore tej spółki, (7) frontend (poll jak w Recenzji
  Krytycznej) pokazuje zwalidowane pola na zielono z tooltipem daty/źródła i zaktualizowany CVS
  score

#### Acceptance Criteria
- Pola tekstowe/opinie z odpowiedzi LLM (np. komentarz "użyj adjusted metrics zamiast GAAP")
  nigdy nie trafiają do żadnego pola liczbowego ani nie wpływają na score — trafiają wyłącznie do
  logu/adnotacji
- Jeśli Gemini nie zwróci wartości dla danego pola (np. "brak wiarygodnych danych"), pole
  pozostaje bez zmian — brak zapisu, nie zapis pustej wartości
- Rescore odpala się dopiero po jawnym potwierdzeniu admina po przejrzeniu diffu, nigdy
  automatycznie od razu po odpowiedzi LLM
- Rescore po walidacji korzysta z nadpisań scalonych z resztą świeżego fetchu Yahoo, nie z samych
  nadpisań w izolacji
- Batch `bin/rescore.php` dla innych tickerów w tym samym dniu nie jest zakłócony ani opóźniony

## Functional Requirements

### Detekcja lokalna (bez LLM)
- FR-001: Admin widzi pola oznaczone kolorem (czerwony) jako podejrzane/puste bezpośrednio w
  sekcji "Dane źródłowe (surowe)" karty analizy — bez osobnego licznika/badge. Priority:
  must-have. Change: new
  > Socrates: Kontrargument rozważony: "sam licznik bez kontekstu może mylić — admin nie wie czy
  > to pola krytyczne czy peryferyjne". Rozwiązanie: zamiast licznika, kolorowanie bezpośrednio w
  > sekcji surowych danych — widać dokładnie które pole i jaka jest jego wartość.
- FR-002: System wykrywa podejrzane pola lokalnymi regułami spójności (np. `free_cash_flow` ≤
  `operating_cash_flow`) i kadencji (np. `days_since_earnings` niespójne z typowym cyklem
  kwartalnym spółki) PRZED jakimkolwiek wywołaniem LLM. Priority: must-have. Change: new
  > Socrates: Brak kontrargumentu; FR stoi jak napisano.
- FR-003: System liczy `moving_average_200d` lokalnie z własnych danych OHLC zamiast zgłaszać
  jako brak wymagający zewnętrznej walidacji. Priority: must-have. Change: new
  > Socrates: Brak kontrargumentu; FR stoi jak napisano (świadomie w zakresie tego MVP, mimo że
  > technicznie niezależny od reszty walidacji LLM).

### Walidacja przez LLM
- FR-004: Sekcja "Dane źródłowe (surowe)" ma DWA przyciski, dostępne zawsze na każdej karcie
  (niezależnie od tego czy wykryto podejrzane pola): "Sprawdź wszystkie dane" i "Sprawdź dane
  brakujące". Priority: must-have. Change: new
  > Socrates: Kontrargument rozważony: "pojedynczy trigger nie skaluje się na 1761 tickerów".
  > Rozwiązanie: MVP celowo zostaje per-spółka (walidujemy i rescorujemy jedną naraz, nie całe
  > uniwersum) — skalowanie na całą bazę to świadomie odłożony kolejny krok, nie blocker MVP.
- FR-005: "Sprawdź wszystkie dane" wysyła do Gemini pola finansowe istotne dla CVS (pomija pola
  niewpływające na scoring, np. `beta`, `short_ratio`, `institutional_ownership`) — NIE pełen
  zestaw ~50 pól. "Sprawdź dane brakujące" wysyła wyłącznie pola oznaczone lokalnie jako
  podejrzane/puste. W obu przypadkach dochodzi kontekst firmy (sektor, ticker, aktualne wartości
  do porównania). Priority: must-have. Change: new
  > Socrates: Kontrargument rozważony i przyjęty: "pełny payload ~50 pól jest zbędnie drogi,
  > skoro tylko garstka pól bywa błędna". Rozwiązanie: nawet "sprawdź wszystkie" ogranicza się do
  > pól faktycznie używanych przez CVSModel, nie do całego surowego payloadu.
- FR-006: System zapisuje wyłącznie typowane pola liczbowe/daty z odpowiedzi LLM jako nadpisania,
  z provenance (źródło, data); TEKSTOWE "notes" z odpowiedzi są zapisywane jako log/adnotacja
  widoczna dla admina, ale nigdy nie wpływają na żadną wartość liczbową, pole ani na score.
  Priority: must-have. Change: new
  > Socrates: Kontrargument rozważony i przyjęty: "notes mają wartość diagnostyczną (np.
  > wyjaśnienie jednorazowego odpisu goodwill), odrzucenie ich w całości traci kontekst".
  > Rozwiązanie: notes trafiają do logu/adnotacji do przeglądu przez admina, oddzielnie od
  > ścieżki danych liczbowych — nigdy nie mieszają się z wartościami wpływającymi na model.

### Trwałość i integracja
- FR-007: Zwalidowane dane mają pierwszeństwo nad świeżym fetchem z Yahoo bezterminowo, do
  następnego ręcznego triggera walidacji dla tej spółki. Priority: must-have. Change: new
  > Socrates: Kontrargument rozważony i przyjęty: "bez własnego TTL zwalidowane dane same staną
  > się starą prawdą — to ten sam problem, przesunięty w czasie". Rozwiązanie: bezterminowość
  > zostaje w MVP (świadomy kompromis), ale TTL/przypominanie o ponownej walidacji trafia do
  > `## Open Questions` jako kolejny krok, nie blocker.
- FR-008: `FinancialDataFetcher` (lub warstwa nad nim) scala persystowane nadpisania ze świeżym
  fetchem Yahoo przed przekazaniem danych do `CVSModel`. Priority: must-have. Change: new
  > Socrates: Brak kontrargumentu; FR stoi jak napisano — scalanie w `FinancialDataFetcher` to
  > naturalne miejsce, każdy consumer i tak przez niego przechodzi.
- FR-009: Po walidacji system pokazuje adminowi diff (stare vs nowe wartości pól) PRZED
  zastosowaniem; admin potwierdza, dopiero wtedy system zapisuje nadpisania i odpala rescore tej
  jednej spółki. Priority: must-have. Change: new
  > Socrates: Kontrargument rozważony i przyjęty: "auto-rescore bez przeglądu może zaskoczyć
  > admina zmianą score". Rozwiązanie: dodany krok review-before-apply — diff widoczny, admin
  > świadomie potwierdza zanim cokolwiek się zmieni.
- FR-010: Zwalidowane pola zmieniają kolor (np. zielony zamiast czerwonego) w tej samej sekcji
  "Dane źródłowe (surowe)", z tooltipem pokazującym datę i źródło walidacji — bez osobnego
  badge. Priority: must-have. Change: new
  > Socrates: Kontrargument rozważony i przyjęty: "osobny badge dubluje kolorowanie pól z
  > FR-001". Rozwiązanie: ta sama sekcja, ten sam mechanizm koloru — czerwony=podejrzane,
  > zielony=zwalidowane, prowenencja w tooltipie zamiast osobnego elementu UI.

### Zachowane (must not break)
- FR-011: `bin/rescore.php` (dzienny batch dla wszystkich tickerów) działa bez zmian kodu —
  funkcja walidacji jest addytywna. Priority: must-have. Change: preserved
  > Socrates: Brak kontrargumentu; FR stoi jak napisano — "preserved" odnosi się do kodu i
  > harmonogramu, nie do konkretnych wyników. Dla zwalidowanych tickerów wynik rescore.php
  > świadomie się zmieni (bo dane wejściowe są inne) — to zamierzony efekt FR-008, nie regresja.
- FR-012: `QualityGate`/`PayloadCompleteness` nadal działają na scalonych danych (nadpisanie +
  Yahoo) — walidacja nigdy nie omija bramki jakości. Priority: must-have. Change: preserved
  > Socrates: Brak kontrargumentu; FR stoi jak napisano — dane zwalidowane przez LLM też mogą się
  > mylić, nie zasługują na wyższe zaufanie automatyczne niż surowe dane Yahoo.

## Business Logic Changes

System oznacza pole fundamentalne jako podejrzane, gdy łamie regułę wewnętrznej spójności (np.
`free_cash_flow > operating_cash_flow`) lub wykracza poza wiarygodny zakres dla swojej kadencji
sprawozdawczej (np. `days_since_earnings` niespójne z typowym cyklem kwartalnym), i dopuszcza do
modelu wyłącznie typowane, opatrzone źródłem wartości zwrotne z walidacji LLM — nigdy surowy
tekst — jako nadpisanie ponad danymi z Yahoo.

To NOWA reguła domenowa (nie zmiana istniejącej, nie infrastruktura) — obecny system nie ma dziś
żadnego mechanizmu oceny wiarygodności danych innego niż NULL-check na polu `revenue`.

## Non-Functional Requirements

- Admin widzi ciągły, widoczny sygnał stanu trwającej walidacji (pending/done/failed) — ten sam
  wzorzec co Recenzja Krytyczna.
- Walidacja jednej spółki nigdy nie opóźnia ani nie blokuje dziennego batcha `rescore.php` dla
  pozostałych tickerów.
- Dane wysłane do Gemini (nazwa spółki, wartości finansowe) nie zawierają danych użytkowników CVS
  (portfele, watchlisty, e-maile) — wyłącznie publiczne dane o spółce.

## Constraints & Compatibility

- Nowa tabela nadpisań fundamentali — addytywna migracja SQL (wzorem
  `database/migrations/NNN_*.sql`), zero zmian w istniejących tabelach.
- Kontrakt wyjściowy `FinancialDataFetcher::fetch()` (kształt tablicy ~50 pól) NIE zmienia się —
  tylko wartości mogą być nadpisane, więc downstream (`CVSModel`, portfele LlmFree/LlmGemini,
  screener) nie wymaga żadnych zmian.
- Zero migracji istniejących danych — nowa tabela startuje pusta.
- Reużyć istniejący klient Gemini z integracji LlmGemini (portfel) zamiast budować nowy.
- Dla tickerów bez nadpisania zachowanie `rescore.php` jest bit-for-bit identyczne jak dziś
  (merge to no-op gdy brak nadpisania).

## Non-Goals

- **Automatyczny dzienny cron na całe uniwersum tickerów** — oryginalny pomysł na start rozmowy;
  świadomie odrzucony na rzecz ręcznego triggera per spółka. Skalowanie na 1761 tickerów to
  osobny, przyszły krok, nie ten MVP.
- **TTL/wygasanie zwalidowanych danych** — nadpisania trwają bezterminowo do ręcznego triggera;
  mechanizm przypominania/wygaszania odłożony (patrz FR-007).
- **Inni dostawcy LLM w UI (GPT, Perplexity)** — tylko Gemini w MVP, mimo że eksperyment pokazał
  wyższą jakość backfillu u GPT/Perplexity. Architektura (worker, provenance) zostawia miejsce na
  inny provider później, ale UI/integracja obsługuje tylko jeden.
- **Walidacja pól niezwiązanych z CVSModel** — nawet "Sprawdź wszystkie dane" nie wysyła pól typu
  `beta`, `employees`, `website` — tylko pola finansowe faktycznie używane przez model.
- **Widoczność dla zwykłych użytkowników** — zero zmian w UI/danych widocznych dla nie-adminów w
  tym MVP; efekt jest wyłącznie pośredni (trafniejszy score).
- **Gwarancja czasu odpowiedzi walidacji** — wywołanie z web search może trwać 90-140s (jak
  Recenzja Krytyczna); nie próbujemy tego przyspieszać w MVP, to akceptowalny koszt ręcznej akcji
  admina.
