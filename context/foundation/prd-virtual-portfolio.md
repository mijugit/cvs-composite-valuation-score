---
project: CVS — Virtual Portfolio with AI-Supported Rebalancing
version: 1
status: draft
created: 2026-06-26
context_type: brownfield
product_type: web-app+api
target_scale:
  users: small
timeline_budget:
  delivery_weeks: 3
  hard_deadline: null
  after_hours_only: true
---

## Current System Overview

CVS to brownfieldowa aplikacja PHP 8.2 (vanilla, PSR-4 `CVS\`) z MySQL, front-controllerem `public/index.php`, auth oraz procesami cron na CyberFolks. Istniejące moduły dostarczają analizę spółek, screener, elementy AI i narzędzia track-record.

Istotne niezmienniki:
- deterministyczny rdzeń CVS,
- konfiguracja parametrów modelu poza hardkodem,
- brak blokowania UX przez zadania wsadowe,
- zachowanie istniejących funkcji share/export.

## Problem Statement & Motivation

Brakuje modułu pozwalającego obserwować, jak rekomendacje CVS + decyzje LLM przekładają się na realistyczny, ale wirtualny portfel prowadzony automatycznie w czasie. Użytkownik nie ma dziś wspólnego miejsca do śledzenia stanu pozycji, gotówki i historii decyzji modelu.

Motywacja:
- edukacyjna: publiczny sandbox dla użytkownika,
- wewnętrzna: laboratorium kalibracji decyzji i polityk rebalance.

Kluczowe wymaganie: system ma działać autonomicznie (cron + LLM), z pełnym logowaniem i obsługą edge-case bez ryzyka realnych pieniędzy i bez ingerencji w deterministyczny scoring CVS.

## User & Persona

- Użytkownik zalogowany (read-only): ogląda wspólny portfel, historię oraz uzasadnienia decyzji.
- Operator/owner produktu: ocenia zachowanie modelu na danych historycznych i bieżących logach.

Brak personal portfolio per-user w MVP.

## Success Criteria

### Primary
1. Co najmniej jeden cykl rebalance zakończony sukcesem i widoczny w UI.
2. Widok portfela pokazuje holdings, cash i bieżącą wartość.
3. Widok historii pokazuje pełny log decyzji (w tym uzasadnienia LLM).

### Secondary
1. Poprawna obsługa `market_closed`, `insufficient_cash`, `failed_after_retry`.
2. Co najmniej jeden poprawnie zapisany cykl `NO_ACTION`.

### Guardrails
- rynek zamknięty/święto -> skip + log `market_closed`,
- błąd LLM -> jeden retry, potem fail bez zmian portfela,
- brak gotówki -> blokada BUY + log `insufficient_cash`,
- brak limitu historii (dni i liczby transakcji),
- rebalance nie degraduje podstawowego UX aplikacji.

## User Stories

### US-01: Read-only view of global portfolio
- **Given** zalogowany użytkownik wchodzi na widok portfela,
- **When** strona ładuje aktualny stan,
- **Then** użytkownik widzi skład portfela, gotówkę, wycenę i timestamp ostatniego rebalance.

#### Acceptance Criteria
- Widoczne: ticker, ilość, wartość pozycji, udział procentowy, cash.
- Widoczny: czas ostatniego cyklu i jego status.

### US-02: Full rebalance history with reasoning
- **Given** użytkownik otwiera historię rebalance,
- **When** przegląda wpisy,
- **Then** widzi pełną oś czasu decyzji BUY/SELL/HOLD/NO_ACTION z uzasadnieniem modelu i wpływem na portfel.

#### Acceptance Criteria
- Brak limitu liczby rekordów historii.
- Każdy wpis ma status, reason i znaczniki czasu.

### US-03: Autonomous daily decision cycle
- **Given** cron uruchamia cykl przed close rynku USA,
- **When** system pobierze sygnały i wywoła LLM,
- **Then** portfel aktualizuje się automatycznie (lub zapisuje brak transakcji) bez akceptacji manualnej.

#### Acceptance Criteria
- W przypadku błędu LLM wykonuje się dokładnie 1 retry.
- Przy podwójnym błędzie cykl kończy się statusem failed bez modyfikacji stanu.

### US-04: Cash-constrained execution
- **Given** decyzja zawiera BUY, ale portfel ma zbyt mało gotówki,
- **When** wykonywany jest silnik transakcyjny,
- **Then** BUY jest pomijany i logowany jako `insufficient_cash`, a cykl może zakończyć się bez transakcji.

#### Acceptance Criteria
- Saldo gotówki po każdej transakcji jest aktualizowane atomowo.
- Sprzedaż zwiększa cash, zakup zmniejsza cash.

## Scope of Change

### New
- Moduł domenowy portfela wirtualnego (stan, pozycje, gotówka).
- Dzienny silnik rebalance z cron i orkiestracją decyzji LLM.
- Historia decyzji i zdarzeń cyklu (full audit log).
- Widok UI: portfolio, history, stats, relacja do screenera.

### Modified
- Integracja sygnałów ze screenera jako wejście do decyzji portfelowych.
- Rozszerzenie warstwy prezentacji o dedykowany widok portfela.

### Preserved
- Deterministyczny scoring CVS.
- Aktualne flow analizy użytkownika.
- Istniejące share/export.

## Constraints & Compatibility

- Architektura bez frameworka pozostaje bez zmian.
- HTTP tylko przez `public/index.php`.
- Harmonogram oparty o strefę Europe/Warsaw z korektą DST dla rynku USA.
- Dane cenowe co godzinę podczas sesji; dodatkowe pobranie ceny na moment wykonania transakcji.
- Portfel jest symulacyjny: brak spread/slippage/płynności/odmów brokera.
- Historia bez limitu i z pełnym audit trail.

## Business Logic Changes

Nowy cykl rebalance:
1. Cron uruchamia cykl codziennie 30 minut przed zamknięciem rynku USA (czas serwera Warszawa + DST).
2. System sprawdza kalendarz sesji; gdy brak sesji zapisuje `market_closed` i kończy cykl.
3. System pobiera sygnały ze screenera i aktualne ceny dla potencjalnych akcji.
4. LLM otrzymuje stan portfela + sygnały i zwraca BUY/SELL/HOLD/NO_ACTION + reason.
5. Przy błędzie LLM uruchamiany jest jeden retry.
6. Po udanym wyniku wykonywane są transakcje wirtualne z ograniczeniem dostępnej gotówki.
7. Zapis: transakcje, status cyklu, reasons, nowy stan portfela.

Dopuszczalne są cykle bez transakcji:
- `NO_ACTION` z decyzji modelu,
- brak możliwych BUY z powodu `insufficient_cash`.

## Access Control Changes

- Dostęp odczytowy dla zalogowanych użytkowników.
- Brak uprawnień użytkownika do ręcznego BUY/SELL.
- Uprawnienie zapisu stanu portfela wyłącznie w backendowym flow cron+LLM.

## Non-Goals

1. Real trading z brokerem.
2. Manual approval workflow użytkownika/admina dla każdej transakcji.
3. Modelowanie kosztów transakcyjnych i poślizgu.
4. Personalizacja portfela per-user w MVP.
5. Ograniczanie retencji historii.

## Open Questions

1. Źródło kalendarza świąt/market closed dla USA (biblioteka vs własna tabela).
2. Polityka priorytetyzacji BUY, gdy sygnałów jest więcej niż dostępnej gotówki.
3. Zakres publicznej ekspozycji treści reasoningu LLM (pełny log vs zredagowany skrót).
4. Docelowy format metryk stats (np. TWR, prosty PnL, benchmark vs SPY) dla MVP.
