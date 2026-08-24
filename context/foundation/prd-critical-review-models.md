---
project: "CVS — Recenzja krytyczna: wielomodelowa"
version: 1
status: draft
created: 2026-08-24
context_type: brownfield
product_type: web-app+api
target_scale:
  users: small
timeline_budget:
  delivery_weeks: 1
  hard_deadline: null
  after_hours_only: true
---

## Current System Overview

**System purpose:** CVS — Composite Valuation Score. Sekcja "Recenzja krytyczna" na karcie
analizy spółki dostarcza użytkownikom z aktywnym kodem PRO pogłębioną, jednorazową narrację
AI na temat spółki, ugruntowaną w danych modelu CVS.

**Kluczowa architektura:** Monolit PHP bez frameworka. Recenzja jest generowana asynchronicznie
w tle (wywołanie modelu językowego z web search trwa zbyt długo na synchroniczny request) —
wzorzec: żądanie oznacza zadanie jako oczekujące, uruchamia proces w tle, a strona odpytuje
o status aż do zakończenia.

**Tech stack:** PHP 8.2, MySQL, bez frameworka. Dziś dostępny jest wyłącznie jeden dostawca
modelu językowego (Claude/Anthropic) — pojedyncza tabela `ai_critical_reviews` z ograniczeniem
unikalności per spółka (jedna recenzja na tickera), więc dodanie drugiej recenzji nadpisuje
pierwszą.

**Obecni użytkownicy:** Użytkownicy z aktywnym kodem PRO — recenzja jest bramkowana limitem
użycia.

**Core funkcjonalności dziś:** Przycisk zleca recenzję → zadanie w tle woła model → wynik
(narracja + cytowania źródeł z wyszukiwania w sieci) zapisuje się i renderuje na karcie
analizy. Recenzja jest czysto informacyjna — nigdy nie wpływa na wynik modelu CVS.

## Problem Statement & Motivation

Dziś dostępny jest tylko jeden dostawca modelu, mimo że w systemie istnieje już drugi,
zintegrowany i tani w użyciu (zweryfikowane w poprzedniej zmianie tego systemu). Brak
możliwości porównania perspektyw różnych modeli na tę samą spółkę — użytkownik dostaje
wyłącznie jedną opinię. Sama narracja jest czysto opisowa — brak ilościowego sygnału
(prawdopodobieństwo scenariusza byczego vs niedźwiedziego), co utrudnia szybkie zestawienie
wielu ocen obok siebie.

**Dlaczego teraz:** koszt drugiego dostawcy był nieznany do niedawna — dopiero poprzednia
zmiana w tym systemie zweryfikowała w praktyce, że to niska bariera kosztowa, co czyni opcję
wielu dostawców realną finansowo.

**Obecny workaround:** żaden — użytkownik dostaje wyłącznie jedną, ustaloną z góry
perspektywę.

## User & Persona

**Persona:** użytkownik z aktywnym kodem PRO (bez zmian względem obecnego systemu) — zleca
recenzję krytyczną tak jak dziś, ale teraz z wyborem dostawcy modelu.

## Success Criteria

### Primary
- Dla co najmniej jednej spółki użytkownik może zlecić i zobaczyć NIEZALEŻNIE recenzję od
  dwóch różnych dostawców — każda z narracją, prawdopodobieństwem bycze % / niedźwiedzie %
  wraz z uzasadnieniem, i źródłami — bez utraty tej drugiej recenzji.

### Secondary
- Istniejące (sprzed tej zmiany) recenzje pozostają dostępne po migracji schematu bazy —
  żadna dotychczasowa recenzja nie ginie.

### Guardrails
- Limit użycia PRO pozostaje wspólny dla obu dostawców — bez zmian w istniejącej logice
  limitowania.
- Recenzja i prawdopodobieństwa NIGDY nie wpływają na wynik CVS — ten sam determinism
  guardrail co dziś (warstwa AI jest czysto informacyjna).
- Migracja istniejącej tabeli recenzji nie gubi żadnej istniejącej recenzji.

## User Stories

### US-01: Użytkownik PRO zleca recenzję od wybranego dostawcy niezależnie od drugiego

- **Given** użytkownik z aktywnym kodem PRO jest na karcie analizy spółki, która ma już
  zapisaną recenzję od jednego dostawcy, ale nigdy nie miała recenzji od drugiego
- **When** klika zakładkę drugiego dostawcy i przycisk "Zleć recenzję"
- **Then** system uruchamia zadanie w tle sparametryzowane (spółka, dostawca); po zakończeniu
  w tej zakładce pojawia się narracja + prawdopodobieństwa bycze/niedźwiedzie + źródła;
  zakładka pierwszego dostawcy pozostaje niezmieniona (istniejąca recenzja nadal tam jest)

#### Acceptance Criteria
- Przełączanie zakładek nie gubi stanu trwającego zadania w drugiej zakładce
- Istniejąca recenzja renderuje się poprawnie niezależnie od tego, czy druga zakładka była
  kiedykolwiek użyta
- Zlecenie recenzji dla jednego dostawcy nie blokuje ani nie wymaga zlecenia dla drugiego
- Prawdopodobieństwo bycze/niedźwiedzie zawsze towarzyszy krótkiemu uzasadnieniu, nigdy nie
  jest samą liczbą bez kontekstu

## Scope of Change

- [new] Użytkownik widzi zakładki per dostawca w sekcji Recenzji Krytycznej.
  > Socrates: Kontrargument rozważony: "prostszy selektor/dropdown zamiast dwóch zakładek".
  > Rozwiązanie: zakładki zostają — pozwalają zobaczyć/przełączać OBIE recenzje jednocześnie;
  > dropdown ukrywałby drugą pod jednym stanem, co niweczy sens porównania.
- [modified] Użytkownik zleca recenzję dla WYBRANEGO dostawcy niezależnie — zlecenie
  sparametryzowane dostawcą, bez blokowania drugiego dostawcy.
  > Socrates: Kontrargument rozważony: "czy równoległe zlecenie obu recenzji naraz powinno
  > być zablokowane". Rozwiązanie: dopuszczone — każdy dostawca to niezależny wpis i
  > niezależne zadanie w tle, nie ma współdzielonego zasobu wymagającego blokady.
- [modified] Każda recenzja przechowywana niezależnie per (spółka, dostawca) — rozszerzenie
  istniejącego magazynu recenzji o wymiar dostawcy (migracja schematu, nie osobny magazyn).
  > Socrates: Kontrargument rozważony: "osobny magazyn dla nowych dostawców byłby
  > bezpieczniejszy dla istniejących danych". Rozwiązanie: migracja addytywna (rozszerzone
  > ograniczenie unikalności) zostaje — jedno miejsce odczytu, zgodne z konwencją projektu.
- [modified] Prompt (niezależnie od dostawcy) wymaga podania prawdopodobieństwa (%) scenariusza
  byczego i niedźwiedziego ORAZ krótkiego subiektywnego uzasadnienia tej liczby — nie sama
  wartość bez kontekstu.
  > Socrates: Kontrargument rozważony i przyjęty: "model nie ma dostępu do realnego rozkładu
  > statystycznego — goła liczba wygląda naukowo, a jest zgadywaniem". Rozwiązanie: prompt
  > wymusza uzasadnienie obok liczby (dlaczego akurat taki %, na jakiej przesłance) — liczba
  > staje się skrótem wniosku z recenzji, nie osobnym, nieumotywowanym faktem.
- [new] Odpowiedź z prawdopodobieństwami renderuje się czytelnie w interfejsie, osobno od
  narracji.
  > Socrates: Kontrargument rozważony: "wystarczyłoby zostawić to w tekście narracji, bez
  > osobnego elementu". Rozwiązanie: zostaje osobny element — to główna wartość funkcji,
  > szybkie porównanie dwóch recenzji na pierwszy rzut oka.
- [new] Dane wejściowe do promptu (podsumowanie CVS) identyczne dla obu dostawców — ten sam
  generator kontekstu, nie duplikowany per dostawca.
  > Socrates: Kontrargument rozważony: "drugi dostawca mógłby dostać mniejszy kontekst,
  > skoro sam dociąga dane z sieci". Rozwiązanie: identyczny blok zostaje — spójność wejścia
  > to podstawa uczciwego porównania dostawców na tych samych danych.
- [preserved] Istniejące recenzje sprzed zmiany pozostają dostępne po migracji (mapowane na
  istniejącego dostawcę).
  > Socrates: Brak kontrargumentu; stoi jak napisano — migracja addytywna z uzupełnieniem
  > istniejących wierszy jest standardowym, bezpiecznym wzorcem w tym systemie.
- [preserved] Limit PRO pozostaje wspólny między dostawcami, bez zmian w istniejącej logice
  limitowania.
  > Socrates: Kontrargument rozważony: "wspólny limit zniechęca do eksperymentowania z dwoma
  > dostawcami, skoro funkcja promuje porównanie". Rozwiązanie: wspólny limit zostaje na MVP
  > — prostota rozliczania wygrywa; rozszerzony budżet na "drugą opinię" to osobna decyzja
  > biznesowa do rozważenia później, nie blocker.

## Constraints & Compatibility

- **Kompatybilność wsteczna:** istniejący punkt wejścia zlecenia recenzji zostaje rozszerzony
  o parametr wyboru dostawcy (dostawca dotychczasowy jako wartość domyślna, gdy nie podano —
  stare wywołania działają bez zmian). Jedna ścieżka kodu, zgodność wsteczna.
- **Migracja danych:** addytywna — nowa kolumna identyfikująca dostawcę w istniejącej tabeli
  recenzji, uzupełnienie wartości dostawcy domyślnego na wszystkich istniejących wierszach,
  rozszerzone ograniczenie unikalności o parę (spółka, dostawca) zamiast samej spółki.
- **Istniejące integracje:** reużycie istniejącej infrastruktury klienta drugiego dostawcy
  (już zintegrowanego w poprzedniej zmianie systemu) — zero nowego kodu komunikacji
  sieciowej. Ten sam wzorzec zadania w tle co dla istniejącego dostawcy dziś, teraz
  sparametryzowany o wybór dostawcy.
- **Bez zmian:** logika limitowania użycia PRO (wspólny limit); istniejące zapisane recenzje.

## Business Logic Changes

System generuje jedną, informacyjną narrację AI per spółka, ugruntowaną w danych CVS, nigdy
niewpływającą na wynik — ta reguła zostaje rozszerzona o: (a) wybór dostawcy przez
użytkownika, każdy dostawca produkujący niezależną, równorzędną recenzję dla tej samej
spółki; (b) wymóg, by każda recenzja zawierała ilościowy sygnał — prawdopodobieństwo (%)
scenariusza byczego i niedźwiedziego wraz z uzasadnieniem — obok narracji.

## Access Control Changes

No access control changes — current model preserved. Ten sam gate (aktywny kod PRO) co dziś;
wybór dostawcy zużywa wspólny, niezmieniony limit użycia.

## Non-Goals

- **Więcej niż dwóch dostawców w tym MVP** — architektura (parametr wyboru dostawcy,
  rozszerzone ograniczenie unikalności) zostawia miejsce na kolejnych, ale interfejs i
  integracja obsługują tylko dwóch.
- **Wpływ prawdopodobieństw na jakąkolwiek automatyczną logikę** — czysto informacyjne;
  nigdy nie wpływają na wynik CVS, alerty, sortowanie w widoku przeglądowym ani żadną inną
  automatyczną decyzję (ten sam determinism guardrail co cała reszta warstwy AI).
- **Historia wersji recenzji per dostawca** — ponowne zlecenie NADPISUJE poprzednią recenzję
  tego dostawcy (tak jak dziś), bez archiwizacji starych wersji.
- **Rozszerzony/osobny limit PRO na "drugą opinię"** — jawnie odrzucone; wspólny limit
  zostaje, rozszerzenie to osobna przyszła decyzja biznesowa.

## Open Questions

Brak otwartych pytań — wszystkie decyzje ustalone podczas shape'owania (runda sokratejska
domknęła każdy FR).
