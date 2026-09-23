---
project: "CVS — Walidacja danych fundamentalnych przez LLM"
version: 1
status: draft
created: 2026-08-21
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

**System purpose:** CVS — Composite Valuation Score. Ocenia spółki giełdowe kompozytowym wynikiem
0–100 w dwóch trybach (Swing 1–4M / Fundamentalny 6–12M) na podstawie danych fundamentalnych
pobieranych na żywo z Yahoo Finance.

**Kluczowa architektura:** Monolit PHP bez frameworka, front controller, warstwa pobierania
danych → warstwa scoringu deterministycznego → warstwa persystencji wyników. Dane fundamentalne
nigdy nie są trwale zapisywane w bazie — liczone na nowo z zewnętrznego źródła przy każdym
przeliczeniu.

**Tech stack:** Vanilla PHP 8.2, MySQL, bez frameworka. Dzienny proces wsadowy przelicza wyniki
dla całego śledzonego uniwersum spółek, uruchamiany przez cron hostingu.

**Obecni użytkownicy:** Wewnętrzni. Widok przeglądowy spółek, portfel i historia trafności
czytają zapisane wyniki; automatyczne strategie inwestycyjne oparte o LLM egzekutują transakcje
na podstawie tych wyników.

**Rdzeń funkcjonalności dziś:** Dane fundamentalne → bramka jakości (binarny pass/fail,
sprawdzająca dziś wyłącznie obecność jednego pola przychodowego) → trzy filary scoringu
(Wycena/Momentum/Jakość) → wynik kompozytowy. System jest kontraktowo deterministyczny: te same
dane wejściowe zawsze dają identyczny wynik.

## Problem Statement & Motivation

System ufa surowym danym z zewnętrznego źródła bez żadnej weryfikacji poza jednym NULL-checkiem.
Audyt produkcyjny (2026-08-20, cała śledzona baza spółek, najnowszy wynik per spółka) i
trzykrotny niezależny eksperyment walidacyjny na jednej realnej spółce potwierdziły dwa
powtarzalne, konkretne błędy w danych źródłowych:

1. **Pole "dni od ostatnich wyników finansowych" bywa wieloletnio przestarzałe, mimo że NIE jest
   puste.** Osiem spółek miało wartości 2000-3064 dni (5.5-8.4 roku) — realny stan (potwierdzony
   niezależnie przez trzy różne modele LLM z web search dla jednej z nich) to ~50-81 dni.
2. **Pole wolnych przepływów pieniężnych bywa wewnętrznie niespójne i przestarzałe.** Dla
   testowanej spółki: źródłowa wartość FCF przewyższała jej własne przepływy operacyjne — co jest
   matematycznie niemożliwe (FCF nie może być wyższe niż OCF przy nieujemnym capexie). Trzy
   niezależne źródła zgodnie podawały wartość o ~30% niższą.

Skala luk NULL jest osobnym, mniejszym problemem (część kluczowych pól ma braki na poziomie
67–98% śledzonej bazy) — ale te są co najmniej **widoczne** (NULL sygnalizuje brak). Prawdziwy
problem to dane **obecne, ale błędne** — dziś kompletnie niewykrywalne, bo nic w systemie nie
sprawdza wiarygodności wartości innej niż jej obecność.

**Dlaczego teraz:** błąd był niewidoczny przez cały czas istnienia systemu — dane złe, ale
niepuste, nie generowały żadnego sygnału. Dopiero systematyczny audyt bazy i eksperyment z trzema
niezależnymi modelami LLM (wszystkie trzy zgodne ze sobą i rozbieżne ze źródłem) dał namacalny
dowód skali problemu oraz wzorzec wykrywania — błędy kadencji sprawozdawczej i reguły wewnętrznej
spójności między powiązanymi polami.

**Obecny workaround:** żaden. Istniejąca bramka jakości nie sprawdza wiarygodności — tylko
obecność jednego pola.

## User & Persona

**Persona:** administrator/operator systemu. To wewnętrzne narzędzie jakości danych — efekt jest
pośredni dla pozostałych użytkowników systemu (trafniejszy wynik, bo dane wejściowe są
wiarygodniejsze), ale sama walidacja/uzupełnianie nie ma bezpośredniego interfejsu poza
administratorem, który ją uruchamia i przegląda wyniki.

## Success Criteria

### Primary
- Przepływ działa end-to-end dla co najmniej jednej realnej spółki: kliknięcie "Sprawdź dane
  brakujące" → podejrzane/puste pola wskazane lokalnymi regułami zostają poprawione/uzupełnione
  przez walidację LLM z zapisanym źródłem i datą → administrator przegląda różnicę (stare vs
  nowe wartości) i potwierdza → przeliczenie tej spółki produkuje zaktualizowany wynik na
  zwalidowanych danych.

### Secondary
- Zwalidowane pola widoczne wizualnie (inny kolor niż podejrzane) bezpośrednio w widoku surowych
  danych źródłowych spółki, z podpowiedzią daty/źródła walidacji przy najechaniu — bez
  konieczności otwierania osobnego widoku.

### Guardrails
- Determinizm silnika scoringu pozostaje nienaruszony — warstwa AI nigdy nie zapisuje
  bezpośrednio do wyniku ani etykiety rekomendacji; zapisuje wyłącznie do warstwy danych
  fundamentalnych, które przechodzą przez istniejący, deterministyczny pipeline scoringu.
- Dzienny proces wsadowy przeliczania wyników dla całego uniwersum spółek działa bez zmian —
  funkcja walidacji jest addytywna, nie zastępuje istniejącego mechanizmu.
- Istniejące bramki jakości danych nadal działają na scalonych danych (nadpisanie + świeże dane
  źródłowe) — walidacja nigdy nie omija bramki jakości.
- Administrator ma ciągły, widoczny sygnał stanu trwającej walidacji (oczekuje/gotowe/błąd) —
  nigdy cichą ciszę bez informacji zwrotnej.
- Walidacja jednej spółki nigdy nie opóźnia ani nie blokuje dziennego procesu wsadowego dla
  pozostałych spółek.
- Dane wysyłane do walidacji zewnętrznej (nazwa spółki, wartości finansowe) nie zawierają danych
  użytkowników systemu (portfele, listy obserwowanych, e-maile) — wyłącznie publiczne dane o
  spółce.

## User Stories

### US-01: Administrator waliduje dane fundamentalne dla spółki z podejrzanymi polami

- **Given** administrator przegląda widok surowych danych źródłowych spółki, która ma pola
  podświetlone jako podejrzane/puste (np. "dni od ostatnich wyników" łamie regułę kadencji
  sprawozdawczej, wolne przepływy pieniężne przewyższają przepływy operacyjne)
- **When** klika przycisk "Sprawdź dane brakujące" (albo "Sprawdź wszystkie dane")
- **Then** system: (1) zbiera listę pól do wysłania — tylko podejrzane/puste, albo pełny zestaw
  pól finansowych używanych przez model, zależnie od wybranego przycisku, (2) uruchamia zadanie w
  tle wzorem już istniejącego w systemie mechanizmu asynchronicznych zadań opartych o LLM, (3)
  zadanie wywołuje walidację LLM z tymi polami i kontekstem spółki, (4) system pokazuje
  administratorowi różnicę (stare vs nowe wartości) i czeka na potwierdzenie, (5) po potwierdzeniu
  zapisuje wyłącznie typowane pola liczbowe/daty jako nadpisania ze źródłem i datą; tekstowe
  wyjaśnienia z odpowiedzi trafiają do osobnego dziennika, nigdy do wartości liczbowych, (6)
  uruchamia przeliczenie wyniku tej spółki, (7) interfejs (odpytywanie stanu jak przy innych
  zadaniach w tle) pokazuje zwalidowane pola innym kolorem z podpowiedzią daty/źródła i
  zaktualizowany wynik

#### Acceptance Criteria
- Tekstowe komentarze/opinie z odpowiedzi LLM nigdy nie trafiają do żadnego pola liczbowego ani
  nie wpływają na wynik — trafiają wyłącznie do dziennika/adnotacji
- Jeśli walidacja nie zwróci wartości dla danego pola (brak wiarygodnych danych), pole pozostaje
  bez zmian — brak zapisu, nie zapis pustej wartości
- Przeliczenie wyniku uruchamia się dopiero po jawnym potwierdzeniu administratora po przejrzeniu
  różnicy, nigdy automatycznie od razu po odpowiedzi walidacji
- Przeliczenie po walidacji korzysta z nadpisań scalonych z resztą świeżych danych źródłowych, nie
  z samych nadpisań w izolacji
- Dzienny proces wsadowy dla innych spółek w tym samym dniu nie jest zakłócony ani opóźniony

## Scope of Change

### Detekcja lokalna (bez wywołania zewnętrznego)
- [new] FR-001: Administrator widzi pola oznaczone kolorem jako podejrzane/puste bezpośrednio w
  widoku surowych danych źródłowych spółki — bez osobnego licznika. Priority: must-have.
  > Socrates: Kontrargument rozważony: "sam licznik bez kontekstu może mylić — administrator nie
  > wie czy to pola krytyczne czy peryferyjne". Rozwiązanie: kolorowanie bezpośrednio przy
  > polu, nie licznik osobno.
- [new] FR-002: System wykrywa podejrzane pola lokalnymi regułami wewnętrznej spójności (np.
  wolne przepływy pieniężne nie mogą przewyższać przepływów operacyjnych) i kadencji
  sprawozdawczej (np. dni od ostatnich wyników niespójne z typowym cyklem kwartalnym spółki)
  PRZED jakimkolwiek wywołaniem zewnętrznej walidacji. Priority: must-have.
  > Socrates: Brak kontrargumentu; FR stoi jak napisano.
- [new] FR-003: System liczy 200-dniową średnią kroczącą lokalnie z własnych danych cenowych już
  posiadanych w systemie, zamiast zgłaszać jako brak wymagający zewnętrznej walidacji. Priority:
  must-have.
  > Socrates: Brak kontrargumentu; FR stoi jak napisano (świadomie w zakresie tego MVP, mimo że
  > technicznie niezależny od reszty walidacji LLM).

### Walidacja przez LLM
- [new] FR-004: Widok surowych danych źródłowych ma DWA przyciski, dostępne zawsze na każdej
  karcie spółki (niezależnie od tego czy wykryto podejrzane pola): "Sprawdź wszystkie dane" i
  "Sprawdź dane brakujące". Priority: must-have.
  > Socrates: Kontrargument rozważony: "pojedynczy trigger nie skaluje się na całą bazę spółek".
  > Rozwiązanie: MVP celowo zostaje per-spółka (walidujemy i przeliczamy jedną naraz, nie całe
  > uniwersum) — skalowanie na całą bazę to świadomie odłożony kolejny krok, nie blocker MVP.
- [new] FR-005: "Sprawdź wszystkie dane" wysyła do walidacji pola finansowe istotne dla scoringu
  (pomija pola niewpływające na wynik) — NIE pełen surowy zestaw wszystkich pobieranych pól.
  "Sprawdź dane brakujące" wysyła wyłącznie pola oznaczone lokalnie jako podejrzane/puste. W obu
  przypadkach dochodzi kontekst spółki (sektor, identyfikator, aktualne wartości do porównania).
  Priority: must-have.
  > Socrates: Kontrargument rozważony i przyjęty: "pełny zestaw pól jest zbędnie drogi, skoro
  > tylko garstka pól bywa błędna". Rozwiązanie: nawet "sprawdź wszystkie" ogranicza się do pól
  > faktycznie używanych przez model scoringowy, nie do całego surowego payloadu.
- [new] FR-006: System zapisuje wyłącznie typowane pola liczbowe/daty z odpowiedzi walidacji jako
  nadpisania, ze źródłem i datą; TEKSTOWE wyjaśnienia z odpowiedzi są zapisywane jako
  dziennik/adnotacja widoczna dla administratora, ale nigdy nie wpływają na żadną wartość
  liczbową, pole ani na wynik. Priority: must-have.
  > Socrates: Kontrargument rozważony i przyjęty: "tekstowe wyjaśnienia mają wartość
  > diagnostyczną (np. wyjaśnienie jednorazowego zdarzenia księgowego), odrzucenie ich w całości
  > traci kontekst". Rozwiązanie: trafiają do dziennika do przeglądu przez administratora,
  > oddzielnie od ścieżki danych liczbowych — nigdy nie mieszają się z wartościami wpływającymi
  > na model.

### Trwałość i integracja
- [new] FR-007: Zwalidowane dane mają pierwszeństwo nad świeżo pobranymi danymi źródłowymi
  bezterminowo, do następnego ręcznego triggera walidacji dla tej spółki. Priority: must-have.
  > Socrates: Kontrargument rozważony i przyjęty: "bez własnego okresu ważności zwalidowane dane
  > same staną się starą prawdą — to ten sam problem, przesunięty w czasie". Rozwiązanie:
  > bezterminowość zostaje w MVP (świadomy kompromis), ale mechanizm wygasania/przypominania o
  > ponownej walidacji trafia do `## Open Questions` jako kolejny krok, nie blocker.
- [new] FR-008: Warstwa pobierania danych fundamentalnych scala persystowane nadpisania ze
  świeżo pobranymi danymi źródłowymi przed przekazaniem do silnika scoringu. Priority: must-have.
  > Socrates: Brak kontrargumentu; FR stoi jak napisano — scalanie w warstwie pobierania danych
  > to naturalne miejsce, każdy odbiorca danych i tak przez nią przechodzi.
- [new] FR-009: Po walidacji system pokazuje administratorowi różnicę (stare vs nowe wartości
  pól) PRZED zastosowaniem; administrator potwierdza, dopiero wtedy system zapisuje nadpisania i
  uruchamia przeliczenie wyniku tej spółki. Priority: must-have.
  > Socrates: Kontrargument rozważony i przyjęty: "automatyczne przeliczenie bez przeglądu może
  > zaskoczyć administratora zmianą wyniku". Rozwiązanie: dodany krok przeglądu przed
  > zastosowaniem — różnica widoczna, administrator świadomie potwierdza zanim cokolwiek się
  > zmieni.
- [new] FR-010: Zwalidowane pola zmieniają kolor w tym samym widoku surowych danych źródłowych, z
  podpowiedzią pokazującą datę i źródło walidacji — bez osobnego licznika/odznaki. Priority:
  must-have.
  > Socrates: Kontrargument rozważony i przyjęty: "osobna odznaka dubluje kolorowanie pól z
  > FR-001". Rozwiązanie: ten sam widok, ten sam mechanizm koloru — jeden kolor dla podejrzanych,
  > inny dla zwalidowanych, źródło/data w podpowiedzi zamiast osobnego elementu interfejsu.

### Zachowane (must not break)
- [preserved] FR-011: Dzienny proces wsadowy przeliczania wyników dla całego uniwersum spółek
  działa bez zmian kodu — funkcja walidacji jest addytywna. Priority: must-have.
  > Socrates: Brak kontrargumentu; FR stoi jak napisano — "zachowane" odnosi się do kodu i
  > harmonogramu, nie do konkretnych wyników. Dla zwalidowanych spółek wynik procesu wsadowego
  > świadomie się zmieni (bo dane wejściowe są inne) — to zamierzony efekt FR-008, nie regresja.
- [preserved] FR-012: Istniejące bramki jakości danych nadal działają na scalonych danych
  (nadpisanie + świeże dane źródłowe) — walidacja nigdy nie omija bramki jakości. Priority:
  must-have.
  > Socrates: Brak kontrargumentu; FR stoi jak napisano — dane zwalidowane przez LLM też mogą się
  > mylić, nie zasługują na wyższe zaufanie automatyczne niż surowe dane źródłowe.

## Constraints & Compatibility

- **Migracja danych:** nowa tabela do przechowywania nadpisań danych fundamentalnych —
  addytywna, zero zmian w istniejących tabelach, zero migracji istniejących danych (nowa tabela
  startuje pusta).
- **Kompatybilność wsteczna:** kontrakt wyjściowy istniejącej warstwy pobierania danych
  fundamentalnych (kształt zwracanych pól) NIE zmienia się — tylko wartości mogą być nadpisane,
  więc odbiorcy tych danych (silnik scoringu, strategie inwestycyjne, widok przeglądowy) nie
  wymagają żadnych zmian.
- **Istniejące integracje:** dla spółek bez nadpisania zachowanie dziennego procesu wsadowego
  jest identyczne jak dziś (scalanie nadpisań to operacja bez efektu, gdy nadpisania brak).
- **Reużycie istniejącej infrastruktury:** ten sam dostawca LLM i ten sam wzorzec zadania w tle,
  który system już wykorzystuje do innej, istniejącej funkcji opartej o LLM (asynchroniczne
  wywołanie z odpytywaniem stanu) — zero nowej infrastruktury kolejkowej.

## Business Logic Changes

System oznacza pole fundamentalne jako podejrzane, gdy łamie regułę wewnętrznej spójności (np.
wolne przepływy pieniężne nie mogą przewyższać przepływów operacyjnych) lub wykracza poza
wiarygodny zakres dla swojej kadencji sprawozdawczej (np. dni od ostatnich wyników niespójne z
typowym cyklem kwartalnym), i dopuszcza do modelu scoringowego wyłącznie typowane, opatrzone
źródłem wartości zwrotne z zewnętrznej walidacji — nigdy surowy tekst — jako nadpisanie ponad
danymi źródłowymi.

To NOWA reguła domenowa (nie zmiana istniejącej, nie infrastruktura) — obecny system nie ma dziś
żadnego mechanizmu oceny wiarygodności danych innego niż sprawdzenie obecności jednego pola.

## Access Control Changes

**Zmienia się.** Mechanika jest ręcznym triggerem w interfejsie (dwa przyciski w widoku surowych
danych spółki), nie cichym procesem w tle — wymaga więc tego samego poziomu autoryzacji co inne
akcje administracyjne już istniejące w systemie. Przyciski widoczne i klikalne wyłącznie dla
administratora; pozostali użytkownicy nie widzą tej akcji ani jej wyników poza efektem końcowym
(poprawiony wynik po przeliczeniu).

## Non-Goals

- **Automatyczny dzienny proces wsadowy na całe uniwersum spółek** — oryginalny pomysł na start;
  świadomie odrzucony na rzecz ręcznego triggera per spółka. Skalowanie na całą śledzoną bazę to
  osobny, przyszły krok, nie ten MVP.
- **Okres ważności/wygasanie zwalidowanych danych** — nadpisania trwają bezterminowo do ręcznego
  triggera; mechanizm przypominania/wygaszania odłożony (patrz FR-007 i Open Questions).
- **Inni dostawcy LLM w interfejsie** — tylko jeden, już zintegrowany dostawca w MVP, mimo że
  eksperyment porównawczy pokazał wyższą jakość uzupełniania danych u innych dostawców.
  Architektura (zadanie w tle, zapis źródła) zostawia miejsce na innego dostawcę później, ale
  interfejs/integracja obsługuje tylko jednego.
- **Walidacja pól niezwiązanych z modelem scoringowym** — nawet "Sprawdź wszystkie dane" nie
  wysyła pól, które nie wpływają na wynik (np. liczba pracowników, adres strony internetowej
  spółki) — tylko pola finansowe faktycznie używane przez model.
- **Widoczność dla zwykłych użytkowników** — zero zmian w interfejsie/danych widocznych dla
  nie-administratorów w tym MVP; efekt jest wyłącznie pośredni (trafniejszy wynik).
- **Gwarancja czasu odpowiedzi walidacji** — wywołanie z przeszukiwaniem internetu może trwać
  1,5-2,5 minuty (zgodnie z pomiarem na istniejącej, analogicznej funkcji w systemie); nie
  próbujemy tego przyspieszać w MVP, to akceptowalny koszt ręcznej akcji administratora.

## Open Questions

1. **Czy nadpisane dane powinny mieć własny okres ważności (wygasanie/przypominanie o ponownej
   walidacji)?** — Świadomie odłożone poza MVP (patrz FR-007, Non-Goals). Owner: administrator
   systemu. Block: nie — MVP działa bez tego, bezterminowość do ręcznego triggera jest
   akceptowalnym kompromisem startowym.
