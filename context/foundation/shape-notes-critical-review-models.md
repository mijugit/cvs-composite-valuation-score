---
project: "CVS — Recenzja krytyczna: wielomodelowa"
context_type: brownfield
created: 2026-08-24
updated: 2026-08-24
product_type: web-app+api
target_scale:
  users: small
checkpoint:
  current_phase: 8
  phases_completed: [1, 2, 3, 4, 5, 6, 7]
  gray_areas_resolved:
    - topic: "insight"
      decision: "Koszt Gemini z web search był nieznany do niedawna — dopiero zmiana fundamentals-validation zweryfikowała że to niska bariera kosztowa"
    - topic: "kategoria zmiany"
      decision: "Znaczące rozszerzenie istniejącego modułu Recenzji Krytycznej (nie nowy moduł, nie przebudowa architektoniczna)"
    - topic: "limity PRO per model"
      decision: "Wspólny limit dla obu modeli — jedna recenzja (niezależnie od dostawcy) to jedno użycie limitu PRO, bez zmian w AiUsageRepository/ProGate"
  frs_drafted: 8
  quality_check_status: accepted
timeline_budget:
  delivery_weeks: 1
  hard_deadline: null
  after_hours_only: true
---

## Current System

**Produkt:** Sekcja "Recenzja krytyczna" (`#critical-review-section`) na karcie analizy
spółki (`/analysis/{ticker}`) — istniejący moduł (change: cvs-ai-critical-review). Dziś:
jeden dostawca (Claude), jedna recenzja per ticker w tabeli `ai_critical_reviews`
(`UNIQUE(ticker)`), przycisk PRO-gated, async worker (`markPending` → `exec(cmd.' &')` →
`markCompleted`/`markFailed` → polling), wynik to narracja + cytowania web search.

**Tech stack:** PHP/MySQL, `AiCriticalReviewRepository`/`AiCriticalReviewService`,
`ClaudeClientFactory`. Ten sam wzorzec async workera co skopiowany do
fundamentals-validation (`bin/generate_critical_review.php`).

**Użytkownicy dziś:** Użytkownicy z aktywnym kodem PRO (`ProGate::canGenerate()`).

**Rdzeń funkcjonalności:** `AiAnalysisController::criticalReview()` (trigger) +
`criticalReviewStatus()` (poll) → `bin/generate_critical_review.php` woła Claude z pełnym
kontekstem CVS → zapis narracji + źródeł.

## Problem Statement & Motivation

Dziś dostępny jest tylko jeden dostawca (Claude), mimo że w projekcie istnieje już drugi,
zintegrowany, tani, z web search (Gemini — z poprzedniej zmiany fundamentals-validation).
Brak możliwości porównania perspektyw różnych modeli na tę samą spółkę. Narracja jest
czysto opisowa — brak ilościowego sygnału (prawdopodobieństwo scenariusza byczego vs
niedźwiedziego), co utrudnia szybkie zestawienie wielu recenzji.

**Dlaczego teraz:** koszt Gemini z web search był nieznany do niedawna — dopiero zmiana
fundamentals-validation zweryfikowała w praktyce, że to niska bariera kosztowa, co czyni
opcję wielu dostawców realną finansowo.

**Obecny workaround:** żaden — użytkownik dostaje wyłącznie perspektywę Claude.

## User & Persona

**Persona:** użytkownik z aktywnym kodem PRO (bez zmian względem obecnego systemu) —
zleca recenzję krytyczną tak jak dziś, ale teraz z wyborem modelu.

## Access Control

**Bez zmian w bramce.** `ProGate::canGenerate()` i istniejący mechanizm kodu PRO zostają
identyczne. Jedyna zmiana: wybór modelu (Claude/Gemini) zużywa **wspólny** limit PRO —
jedna recenzja, niezależnie od dostawcy, to jedno użycie limitu. Brak osobnych liczników
per model — `AiUsageRepository`/`ProGate` nie wymagają zmian w logice limitowania.

## Success Criteria

### Primary
- Dla co najmniej jednego tickera użytkownik może zlecić i zobaczyć NIEZALEŻNIE recenzję
  od Claude i od Gemini — każda z narracją, nowym polem prawdopodobieństw (bycze % /
  niedźwiedzie %) i źródłami, bez utraty tej drugiej recenzji.

### Secondary
- Istniejące (sprzed tej zmiany) recenzje Claude pozostają dostępne po migracji schematu
  bazy — żadna dotychczasowa recenzja nie ginie.

### Guardrails
- Limit PRO pozostaje wspólny dla obu dostawców — bez zmian w logice `AiUsageRepository`/
  `ProGate`.
- Recenzja/prawdopodobieństwa NIGDY nie wpływają na wynik CVS — ten sam determinism
  guardrail co dziś (warstwa AI jest czysto informacyjna).
- Migracja `ai_critical_reviews` (rozszerzenie o wymiar dostawcy) nie gubi żadnej
  istniejącej recenzji Claude.

## Plan dostawy
- **Szacunek:** 1 tydzień po godzinach — mniejszy zakres niż fundamentals-validation,
  większość infrastruktury (async worker, klient Gemini, PRO gate) już istnieje i działa.

## User Stories

### US-01: Użytkownik PRO zleca recenzję od wybranego dostawcy niezależnie od drugiego

- **Given** użytkownik z aktywnym kodem PRO jest na karcie analizy tickera, który ma już
  zapisaną recenzję Claude, ale nigdy nie miał recenzji Gemini
- **When** klika zakładkę "Gemini" i przycisk "Zleć recenzję"
- **Then** system uruchamia async job sparametryzowany (ticker, provider=gemini); po
  zakończeniu w zakładce Gemini pojawia się narracja + prawdopodobieństwa bycze/niedźwiedzie
  + źródła; zakładka Claude pozostaje niezmieniona (istniejąca recenzja nadal tam jest)

#### Acceptance Criteria
- Przełączanie zakładek nie gubi stanu pollingu trwającego w drugiej zakładce
- Istniejąca recenzja Claude renderuje się poprawnie niezależnie od tego, czy zakładka
  Gemini była kiedykolwiek użyta
- Zlecenie recenzji dla jednego dostawcy nie blokuje ani nie wymaga zlecenia dla drugiego
- Prawdopodobieństwo bycze/niedźwiedzie zawsze towarzyszy krótkiemu uzasadnieniu, nigdy nie
  jest samą liczbą bez kontekstu

## Functional Requirements

- FR-001: Użytkownik widzi zakładki per dostawca (Claude, Gemini) w sekcji Recenzji
  Krytycznej. Priority: must-have. Change: new
  > Socrates: Kontrargument rozważony: "dropdown/selektor byłby prostszy niż dwie osobne
  > sekcje". Rozwiązanie: zakładki zostają — pozwalają zobaczyć/przełączać OBIE recenzje
  > jednocześnie, dropdown ukrywałby drugą pod jednym stanem, co niweczy sens porównania.
- FR-002: Użytkownik zleca recenzję dla WYBRANEGO dostawcy niezależnie — trigger
  sparametryzowany providerem, bez blokowania drugiego dostawcy. Priority: must-have.
  Change: modified
  > Socrates: Kontrargument rozważony: "czy równoległe zlecenie obu recenzji naraz powinno
  > być zablokowane". Rozwiązanie: dopuszczone — każdy dostawca to niezależny wiersz i
  > niezależny worker, nie ma współdzielonego zasobu wymagającego blokady.
- FR-003: Każda recenzja przechowywana niezależnie per (ticker, provider) — rozszerzenie
  `ai_critical_reviews` o wymiar dostawcy (migracja schematu, nie osobna tabela).
  Priority: must-have. Change: modified
  > Socrates: Kontrargument rozważony: "osobna tabela dla nowych dostawców byłaby
  > bezpieczniejsza dla istniejących danych Claude". Rozwiązanie: migracja addytywna
  > (rozszerzony UNIQUE) zostaje — jedno miejsce odczytu, zgodne z konwencją projektu.
- FR-004: Prompt (niezależnie od dostawcy) wymaga podania prawdopodobieństwa (%) scenariusza
  byczego i niedźwiedziego ORAZ krótkiego subiektywnego uzasadnienia tej liczby — nie sama
  wartość bez kontekstu. Priority: must-have. Change: modified
  > Socrates: Kontrargument rozważony i przyjęty: "LLM nie ma dostępu do realnego rozkładu
  > statystycznego — goła liczba wygląda naukowo, a jest zgadywaniem". Rozwiązanie: prompt
  > wymusza uzasadnienie obok liczby (dlaczego akurat taki %, na jakiej przesłance) — liczba
  > staje się skrótem wniosku z recenzji, nie osobnym, nieumotywowanym faktem.
- FR-005: Odpowiedź z prawdopodobieństwami renderuje się czytelnie w UI, osobno od narracji.
  Priority: must-have. Change: new
  > Socrates: Kontrargument rozważony: "wystarczyłoby zostawić to w tekście narracji, bez
  > osobnego komponentu". Rozwiązanie: zostaje osobny element — to główna wartość funkcji,
  > szybkie porównanie dwóch recenzji na pierwszy rzut oka.
- FR-006: Dane wejściowe do promptu (podsumowanie CVS) identyczne dla obu dostawców — ten
  sam generator data-blocku, nie duplikowany per provider. Priority: must-have. Change: new
  > Socrates: Kontrargument rozważony: "Gemini z web search mogłoby dostać mniejszy
  > kontekst, skoro samo dociąga dane z sieci". Rozwiązanie: identyczny blok zostaje —
  > spójność wejścia to podstawa uczciwego porównania modeli na tych samych danych.
- FR-007: Istniejące recenzje Claude sprzed zmiany pozostają dostępne po migracji (mapowane
  na provider='claude'). Priority: must-have. Change: preserved
  > Socrates: Brak kontrargumentu; FR stoi jak napisano — migracja addytywna z backfillem
  > `provider='claude'` na istniejących wierszach jest standardowym, bezpiecznym wzorcem.
- FR-008: Limit PRO pozostaje wspólny między dostawcami, bez zmian w
  `ProGate`/`AiUsageRepository`. Priority: must-have. Change: preserved
  > Socrates: Kontrargument rozważony: "wspólny limit zniechęca do eksperymentowania z
  > dwoma modelami, skoro feature promuje porównanie". Rozwiązanie: wspólny limit zostaje
  > na MVP — prostota rozliczania wygrywa; rozszerzony budżet na "drugą opinię" to osobna
  > decyzja biznesowa do rozważenia później, nie blocker.

## Business Logic Changes

**Obecna reguła:** System generuje jedną, informacyjną narrację AI per spółka, ugruntowaną
w danych CVS, nigdy niewpływającą na wynik.

**Zmiana:** rozszerza to o (a) wybór dostawcy przez użytkownika — każdy dostawca produkuje
niezależną, równorzędną recenzję dla tej samej spółki; (b) wymóg, by każda recenzja
zawierała ilościowy sygnał — prawdopodobieństwo (%) scenariusza byczego i niedźwiedziego
wraz z uzasadnieniem — obok narracji.

## Non-Functional Requirements

- Brak odpowiedzi/błąd jednego dostawcy nigdy nie psuje strony ani nie blokuje drugiego
  dostawcy (ten sam NFR co dziś, rozszerzony na niezależność między dostawcami).
- Użytkownik ma widoczny, ciągły sygnał stanu dla KAŻDEJ zakładki niezależnie — nie jeden
  wspólny wskaźnik dla obu dostawców.
- Prawdopodobieństwa renderują się w spójnym formacie między dostawcami (ta sama skala,
  ten sam sposób prezentacji) — porównanie musi być uczciwe wizualnie, nie tylko treściowo.

## Constraints & Preserved Behavior

- **API:** istniejący endpoint `POST /analysis/{ticker}/critical-review` zostaje rozszerzony
  o parametr `provider` (`claude` domyślnie, gdy nie podano — stare wywołania działają bez
  zmian). Jeden endpoint, jedna ścieżka kodu, zgodność wsteczna.
- **Migracja danych:** addytywna — nowa kolumna `provider` w `ai_critical_reviews`, backfill
  `provider='claude'` na wszystkich istniejących wierszach, rozszerzony
  `UNIQUE(ticker, provider)` zamiast `UNIQUE(ticker)`.
- **Reużycie istniejącej infrastruktury:** `GeminiClient`/`GeminiClientFactory` (z
  fundamentals-validation) — zero nowego kodu HTTP. Ten sam wzorzec async workera
  (`markPending`/`exec(&)`/poll) co dziś dla Claude, teraz sparametryzowany o dostawcę.
- **Bez zmian:** `ProGate`/`AiUsageRepository` (wspólny limit, FR-008); istniejące dane
  Claude (FR-007).

## Non-Goals

- **Więcej niż 2 dostawców w tym MVP** — tylko Claude + Gemini. Architektura (parametr
  `provider`, rozszerzone `UNIQUE(ticker, provider)`) zostawia miejsce na kolejnych, ale
  UI/integracja obsługuje tylko dwóch.
- **Wpływ prawdopodobieństw na jakąkolwiek automatyczną logikę** — czysto informacyjne;
  nigdy nie wpływają na CVS score, alerty, sortowanie w screenerze ani żadną inną
  automatyczną decyzję (ten sam determinism guardrail co cała reszta warstwy AI).
- **Historia wersji recenzji per dostawca** — ponowne zlecenie NADPISUJE poprzednią recenzję
  tego dostawcy (tak jak dziś dla Claude), bez archiwizacji starych wersji.
- **Rozszerzony/osobny limit PRO na "drugą opinię"** — jawnie odrzucone (patrz FR-008);
  wspólny limit zostaje, rozszerzenie to osobna przyszła decyzja biznesowa.
