---
project: CVS — Virtual Portfolio with AI-Supported Rebalancing
context_type: brownfield
updated: 2026-06-26
checkpoint:
  current_phase: 8
  phases_completed: [1, 2, 3, 4, 5, 6, 7]
  frs_drafted: 18
  quality_check_status: accepted
product_type: web-app+api
target_scale:
  users: small
timeline_budget:
  delivery_weeks: 3
  hard_deadline: null
  after_hours_only: true
  delivery_model: phased-by-priority
---

## Current System

Produkt: CVS (Composite Valuation Score) jako aplikacja webowa PHP 8.2 (vanilla, PSR-4 `CVS\`) z MySQL i entrypointem HTTP przez `public/index.php`. System posiada auth (User/PRO/Admin), screener, analizę spółek i komponenty AI (`CVS\Ai\`) oraz cron-jobs uruchamiane na CyberFolks.

Utrzymywane ograniczenia architektury:
- brak frameworka,
- deterministyczny rdzeń scoringu CVS,
- konfiguracja wag/progów z `config/cvs-weights.php`,
- CSRF dla POST,
- brak spowolnienia kluczowego flow analizy.

## Vision & Problem Statement

Nowa funkcjonalność: wspólny (globalny) portfel wirtualny o kapitale startowym 10 000 USD, zarządzany automatycznie przez proces decyzyjny LLM + sygnały CVS ze screenera, z codziennym rebalansem przed zamknięciem rynku USA.

Cel biznesowy (dual-use):
- publiczny sandbox edukacyjny (user widzi wynik działania modelu),
- wewnętrzne laboratorium kalibracji modelu.

Kluczowy problem do rozwiązania: jak bezpiecznie dodać quasi-autonomiczny moduł portfela, który nie narusza deterministycznego rdzenia CVS i nie blokuje UX, a jednocześnie zapisuje pełną, audytowalną historię decyzji modelu.

Musi zostać zachowane:
1. brak regresji wydajności flow analizy,
2. deterministyczny rdzeń CVS (LLM nie modyfikuje scoringu),
3. wspólny screener jako źródło sygnałów,
4. brak wpływu na istniejące share/export,
5. brak blokowania UX przez proces rebalance.

## User & Persona

Tryb dualny:
- Użytkownik końcowy: obserwator (read-only) wspólnego portfela, historii i uzasadnień decyzji.
- Zespół wewnętrzny: odbiorca danych kalibracyjnych i historii decyzji (bez ręcznego zatwierdzania każdej transakcji w MVP).

## Access Control

Model dostępu (zablokowany):
- widok portfela dostępny dla zalogowanych użytkowników,
- użytkownik nie może manipulować portfelem,
- zapis/zmiana stanu portfela wyłącznie przez flow decyzyjny (LLM + cron),
- pełna widoczność aktualnego stanu i logu zmian wraz z uzasadnieniem modelu.

## Success Criteria

Primary:
1. Co najmniej jeden udany cykl rebalance zapisany i widoczny w UI.
2. Użytkownik widzi aktualny skład portfela, gotówkę i historię decyzji modelu.
3. Proces automatyczny działa bez manualnych akceptacji.

Secondary:
1. Ujęte scenariusze brzegowe: market closed, brak odpowiedzi LLM, brak gotówki, brak transakcji.
2. Retry LLM maksymalnie 1 raz, potem cykl oznaczony jako failed z logiem.

Guardrails:
- rynek zamknięty/święto -> skip z `market_closed`,
- LLM timeout/error -> 1 retry, potem fail,
- brak gotówki -> brak BUY, log `insufficient_cash`,
- dozwolone `NO_ACTION` (zero transakcji) gdy model tak decyduje,
- brak limitu historii (pełna historia transakcji i decyzji).

## Functional Requirements

### Zakres MVP (Scenario B: Minimal UI + Automation, 2-3 tygodnie)

- FR-001: System uruchamia codzienny rebalance przez cron 30 minut przed zamknięciem rynku USA. Priority: must-have. Change: new
- FR-002: Harmonogram uwzględnia strefę serwera CyberFolks (Warszawa) oraz DST względem godzin sesji USA. Priority: must-have. Change: new
- FR-003: Sygnały wejściowe pochodzą ze wspólnego screenera CVS, dane cenowe odświeżane co godzinę w trakcie sesji. Priority: must-have. Change: new
- FR-004: Na moment wykonania BUY/SELL system pobiera aktualną cenę dla wybranych tickerów. Priority: must-have. Change: new
- FR-005: LLM generuje decyzje `BUY | SELL | HOLD | NO_ACTION` bez manualnej akceptacji user/admin. Priority: must-have. Change: new
- FR-006: Portfel jest long-only (bez short, bez dźwigni), ograniczony wyłącznie dostępną gotówką. Priority: must-have. Change: new
- FR-007: Gotówka portfela aktualizuje się po każdej transakcji (sprzedaż zwiększa, zakup zmniejsza saldo). Priority: must-have. Change: new
- FR-008: Brak gotówki blokuje zakupy i zapisuje zdarzenie `insufficient_cash`; cykl może zakończyć się bez transakcji. Priority: must-have. Change: new
- FR-009: Brak odpowiedzi LLM powoduje 1 retry; po drugim błędzie cykl oznaczany jako failed, bez zmian portfela. Priority: must-have. Change: new
- FR-010: Dzień bez sesji (święto/market closed) oznacza skip cyklu i log `market_closed`. Priority: must-have. Change: new
- FR-011: System zapisuje pełną historię rebalance bez limitu dni i bez limitu liczby transakcji. Priority: must-have. Change: new
- FR-012: Widok portfela (dla zalogowanego usera) pokazuje: holdings, cash, current value, ostatni rebalance, uzasadnienie modelu. Priority: must-have. Change: new
- FR-013: Widok historii pokazuje wszystkie poprzednie cykle: data, akcje (BUY/SELL/HOLD/NO_ACTION), reason, impact. Priority: must-have. Change: new
- FR-014: Widok statystyk pokazuje wynik portfela vs kapitał startowy 10 000 USD. Priority: must-have. Change: new
- FR-015: Integracja ze screenerem pokazuje relację: rekomendowane i trzymane vs obserwowane i nietrzymane. Priority: must-have. Change: new
- FR-016: Dopuszczalny jest pełny cykl `NO_ACTION` bez zmian pozycji, jeśli model tak postanowi. Priority: must-have. Change: new
- FR-017: Portfel pozostaje wspólny (globalny), nie per-user, w MVP. Priority: must-have. Change: new
- FR-018: Rebalance i pobieranie danych nie mogą blokować interakcji usera z podstawowym flow aplikacji. Priority: must-have. Change: preserved

## User Stories

- US-01: Jako zalogowany użytkownik chcę zobaczyć aktualny stan wspólnego portfela (pozycje + gotówka), aby rozumieć bieżącą ekspozycję modelu.
- US-02: Jako zalogowany użytkownik chcę widzieć pełną historię decyzji z uzasadnieniami LLM, aby rozumieć dlaczego portfel się zmienił lub nie zmienił.
- US-03: Jako operator systemu chcę, aby cykle działały automatycznie przez cron i poprawnie logowały błędy brzegowe, aby utrzymać audytowalność i stabilność procesu.

## Business Logic

Reguła decyzyjna:
1. Cron uruchamia cykl wg harmonogramu (Warsaw-time, DST-aware, okno przed close USA).
2. System sprawdza czy jest aktywna sesja; jeśli nie -> `market_closed` i stop.
3. System zbiera sygnały ze screenera oraz aktualne ceny wymagane dla potencjalnych transakcji.
4. LLM otrzymuje stan portfela + sygnały i zwraca decyzje BUY/SELL/HOLD/NO_ACTION z uzasadnieniem.
5. Przy błędzie LLM wykonywany jest 1 retry; po kolejnej porażce cykl failed bez zmian.
6. Silnik wykonuje transakcje wirtualne (instant), respektując ograniczenie gotówki i long-only.
7. Zapis: nowy stan portfela, cash, historia operacji, uzasadnienia, status cyklu.

Założenia domenowe MVP:
- brak kosztów transakcyjnych,
- brak wpływu płynności i poślizgu,
- brak odmów brokera,
- portfel jest symulacyjny.

## Non-Functional Requirements

- NFR-001: Proces rebalance nie degraduje czasu odpowiedzi podstawowej analizy użytkownika.
- NFR-002: Wszystkie cykle są audytowalne (status, reason, timestamp, akcje).
- NFR-003: Logika CVS pozostaje deterministyczna i odseparowana od niedeterministycznych decyzji LLM.
- NFR-004: Harmonogram poprawny dla timezone Europe/Warsaw i zmian DST względem rynku USA.
- NFR-005: Operacje cron są idempotentne na poziomie pojedynczego okna czasowego cyklu.

## Constraints & Preserved Behavior

- zachowanie architektury vanilla PHP + MySQL + cron,
- brak zmian naruszających entrypoint HTTP (`public/index.php`),
- brak zmian w istniejącej logice share/export,
- konfiguracja parametrów biznesowych poza hardkodem,
- minimalny i bezpieczny zakres zmian w MVP.

## Non-Goals

1. Brak realnego handlu u brokera.
2. Brak manualnego approval flow dla każdej decyzji w MVP.
3. Brak modelowania spread/slippage/prowizji/płynności.
4. Brak personal portfolio per-user w MVP.
5. Brak limitowania historii (pełna historia jest wymaganiem, nie celem optymalizacji).

## Product Framing

- Typ: quasi-autonomiczny moduł/sub-app w istniejącym CVS.
- Zakres MVP: Scenario B (automation + minimalny UI użytkownika + historia + statystyki + integracja ze screenerem).
- Czas: 2-3 tygodnie po godzinach.
- Strategia ryzyka: logowanie + retry + jawna obsługa scenariuszy brzegowych bez zatrzymywania aplikacji.

## Open Questions

1. Dokładna definicja i źródło kalendarza sesji/świąt USA dla harmonogramu cron.
2. Strategia ekspozycji w przypadku konfliktu między rekomendacjami CVS a ograniczeniem gotówki (np. priorytety BUY).
3. Docelowy poziom szczegółowości reasoningu LLM pokazywanego publicznie (skrót vs pełny log).

## Quality cross-check

Status: accepted.

- Access Control: zamknięty (read-only user, write przez LLM/cron).
- Business Logic: zamknięta reguła decyzyjna i scenariusze brzegowe.
- MVP Scope: zablokowany Scenario B + wszystkie 4 flowy.
- Timeline: realny (2-3 tygodnie).
- Guardrails: potwierdzone i audytowalne.
