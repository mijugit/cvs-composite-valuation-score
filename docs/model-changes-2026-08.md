# Zmiany modelu — 15–16 sierpnia 2026

Zapis dwóch dni pracy, które zaczęły się od jednego pytania: **dlaczego LLM twierdzi, że nie
dostał danych scoringowych o MU?** Odpowiedź okazała się warstwowa i odsłoniła serię wad, z
których żadna nie była widoczna z poziomu interfejsu.

Dokument opisuje, co się zmieniło i **dlaczego** — nie jest listą commitów (ta jest w git logu),
tylko wyjaśnieniem decyzji, żeby za pół roku dało się je podważyć na właściwych podstawach.

---

## 1. Punkt wyjścia: zamrożona pozycja

Portfel LLM Free trzymał MU jako największą pozycję (37% księgi). Model przez trzy kolejne cykle
pisał w legendzie, że chce ją ciąć — i za każdym razem pozycja zostawała nietknięta. Model nie
halucynował: MU faktycznie zniknął z jego uniwersum.

Łańcuch przyczynowy miał sześć ogniw:

1. Yahoo przestał publikować dla MU sprawozdanie finansowe (`incomeStatementHistory` = 0 wierszy),
   przy działającej cenie i pozostałych modułach.
2. `QualityGate` czytał `($financials['revenue'] ?? 0) <= 0` — **brak danych był rzutowany na
   zero**, czyli „spółka bez przychodów". Warunki 2–4 zawsze pomijały się przy `null`; ten jeden nie.
3. Odrzucenie zapisywało się jako snapshot **bez `model_version`**. W MySQL każdy NULL w indeksie
   UNIQUE jest inny, więc wiersze się mnożyły (5 dziennie) i — jako najnowsze — zasłaniały ostatni
   dobry snapshot.
4. Oba crony portfeli budowały `ScreenerRepository` **bez `liveModelVersion`**, więc trafiały na
   gałąź zapytania bez filtra wersji, gdzie ten scoreless wiersz maskował MU całkowicie.
5. `$priceMap` powstaje wyłącznie z wierszy screenera → `SELL` na MU był **cicho porzucany**.
6. Log raportował `success=103 failed=0`, bo `failed` liczyło tylko błąd pobrania — odrzucenie
   bramki szło jako sukces. Dlatego problem żył trzy tygodnie niezauważony.

**Jedna pusta tablica u dostawcy danych unieruchomiła największą pozycję portfela.**

---

## 2. Warstwa danych

### Przychody z drugiego źródła
Yahoo nie miał dla części spółek ani rocznych, ani kwartalnych sprawozdań — ale
`financialData.totalRevenue` (TTM) było wypełnione. Parsowaliśmy tylko
`incomeStatementHistory[0].totalRevenue`.

Dodany fallback z zapisem źródła (`revenue_source`: `annual` | `ttm`). **Roczne zawsze wygrywa** —
to nie jest kosmetyka: TTM bywał +2…+18% powyżej rocznego dla spółek amerykańskich, ale −64% dla
LPP.WA, gdzie okresy się nie pokrywają. Dzięki tej kolejności TTM dostają wyłącznie spółki, które
wcześniej nie miały przychodu w ogóle.

### Waluty
Cena notowania i waluta sprawozdań to dwie różne rzeczy. ASBIS notowany w PLN raportuje w USD —
cena 135,20 PLN była traktowana jak $135,20, co zawyżało EV 3,7×. Ten sam błąd dotknął potem
`book_value_per_share`: wartość księgowa w PLN kontra cena w USD.

**Reguła:** wielkości *na akcję*, porównywane z `current_price`, konwertuje się kursem **ceny**,
nie kursem sprawozdań.

### Niekompletny payload
`PayloadCompleteness` — payload strukturalnie poprawny, ale materialnie pusty, jest pomijany **bez
zapisu**, tak jak nieudany fetch. Zapisany scoreless snapshot jest gorszy niż brak snapshotu, bo
staje się najnowszym i zasłania ostatni użyteczny.

### Weryfikacja: Yahoo nas nie dławi
Sprawdzone pomiarem, nie założeniem: zapytanie o 1 moduł i o 13 modułów zwracają odpowiedzi **co do
bajta identyczne**, 3/3 próby, poniżej sekundy, z ważnym crumb. Hipoteza o rate-limitingu jest
fałszywa — braki są realnymi lukami po stronie dostawcy dla konkretnych spółek.

---

## 3. Grupy porównawcze

### Problem: fallback bez śladu
`MedianResolver` schodzi z branży na sektor, gdy kubełek ma mniej niż `min_sample_count` spółek.
To poprawna decyzja scoringowa, ale **niewidoczna**. ASBIS był jedynym dystrybutorem elektroniki
w uniwersum (n=1), więc porównywano go do mnożników software'u (24,4×) — awansował na drugie
miejsce rankingu z SILNE KUPUJ. Prawdziwa mediana jego branży to 10,3×, czyli dokładnie tyle, ile
wynosi jego własny wskaźnik. **Spółka była wyceniona uczciwie; „okazja" była artefaktem.**

Skala okazała się szersza: 33 ze 113 spółek (29%) wyceniano przez fallback sektorowy.

### Rozwiązania
- **`valuation_source` zapisywany na snapshocie** (migracja 036). `CVSModel` liczył tę informację
  od zawsze i ją wyrzucał. To odpowiedź autorytatywna i automatycznie poprawna per metryka —
  filar rozwiązuje kubełek tej metryki, której faktycznie użył.
- **`PeerCoverage`** — badge „◍ brak peerów" w screenerze; autonomiczne portfele nie dostają takich
  spółek jako kandydatów. **Pozycje posiadane są zwolnione** — inaczej powtórzylibyśmy pułapkę MU,
  bo egzekutor wycenia transakcje właśnie z tych wierszy.
- **Dosypanie peerów** — 54 nowe tickery w 22 branżach poniżej progu, każdy zweryfikowany przez API
  pod kątem *zgodności branży*, nie samego istnienia. 18 z 87 kandydatów odpadło, bo Yahoo
  klasyfikuje je gdzie indziej (NKE to „Footwear & Accessories", nie Apparel Manufacturing;
  ETN/EMR/ROK to „Specialty Industrial Machinery", nie Electrical Equipment).
- **Nadpisania administratora** (migracja 037) — patrz niżej.

### Nadpisania: własne grupy porównawcze
Klasyfikacja Yahoo idzie za formą korporacyjną, nie za tym, czym spółka konkuruje. Dwa różne
przypadki, celowo rozróżnione w projekcie:

| Typ | Przykład | Charakter | `review_date` |
|---|---|---|---|
| Dominacja segmentu | Samsung → pamięci | zmienny w czasie | **wymagany** |
| Region + regulator | banki GPW | strukturalnie trwały | pusty |

Warunki, na których to jest bezpieczne:
- **addytywnie** — `industry` z Yahoo nietknięte, override zmienia tylko cel porównania;
- **stemplowane na snapshocie** — historyczny wynik pozostaje wytłumaczalny, a grupowanie
  **falsyfikowalne**: track record można czytać per grupowanie zamiast mieszać reżimy;
- **z powodem i autorem** — override to decyzja **klasyfikacyjna** („ta spółka konkuruje z tamtymi"),
  nigdy wynikowa („ta spółka powinna mieć wyższy wynik"). Ślad audytowy pilnuje tej różnicy.

**Znane ograniczenie:** własny kubełek nie może rozciągać się na dwa sektory Yahoo — crawl median
flushuje per sektor i nadpisywałby go naprzemiennie.

**Napięcie, którego nie rozstrzygamy kodem:** przypisanie Samsunga do producentów pamięci kasuje
dywersyfikację, która sama w sobie bywa wartością („fosa"). Dlatego grupy segmentowe mają datę
przeglądu — mają żyć w czasie, a nie zastygnąć jako etykieta.

---

## 4. Model dla spółek finansowych

Depozyty i dług są dla banku **surowcem**, a nie roszczeniem wobec majątku — Enterprise Value nie
jest wielkością, którą ktokolwiek wycenia, a „wolne przepływy" nie mierzą niczego. Yahoo raportuje
zysk brutto banku jako 0. Model przepuszczał je mimo to przez EV/FCF i marżę brutto, przez co
kubełek `Banks - Regional` miał **n=0** przy sześciu dużych bankach amerykańskich w uniwersum.

**Wariant C** (wycena): P/B wobec mediany grupy porównawczej. Ta sama sigmoida, ta sama kotwica
sektorowa, ten sam kierunek. Sprawdzany **przed** wzrostem i EV, bo żadne z nich nie ma tu
zastosowania.

**Ścieżka finansowa** (jakość): ROE (4 pkt) + ROA (4 pkt) + rozsądek wypłaty dywidendy (2 pkt), na
tej samej skali 0–10, więc ważenie filarów wyżej nie wymaga wyjątku.

**Crawl median** zbiera `pb` **przed** bramką wzrostu — banki często nie mają prognozy wzrostu, a
gating ich mnożnika księgowego za nią pozostawiłby kubełki puste na zawsze.

Efekt: kubełki wcześniej strukturalnie puste są wypełnione (`Banks - Regional` n=22,
`Capital Markets` n=6 wobec 1).

### Obserwacja rynkowa
```
Polskie banki:  P/B 2,07–2,96   ROE 14–24%
Amerykańskie:   P/B 1,68–1,79   ROE ~12,6%
```
Porównywanie PKO do globalnej mediany zdominowanej przez USA czyni polskie banki drogimi mimo
wyższych zwrotów. To argument za grupowaniem regionalnym — czyli za mechanizmem z sekcji 3.

---

## 5. Wartość godziwa

`FairPriceCalculator` czytał **statyczny** benchmark sektorowy, pomijając całą warstwę median
z fazy 3. Skutek był jawnie sprzeczny: filar Wyceny mówił „wyceniona uczciwie" (ASB.WA, 10,2 vs
mediana branży 10,3), a kolumna FV obok twierdziła **+722%**.

- FV rozwiązuje mnożnik przez ten sam `MedianResolver` co filar.
- Dla finansów: `median_pb × book_value_per_share`.
- Szablon analizy miał **własną kopię wzoru** zamiast wołać kalkulator — dlatego kafelek nie znał
  median i dla banków w ogóle się nie renderował. Została jedna implementacja.

`max_growth` pozostaje sektorowy — to ogranicznik ekstrapolacji, nie mnożnik peer-group, i
`peer_medians` nie ma odpowiednika. Do rewizji osobno.

---

## 6. Świeżość i widoczność

`findAllLatest()` nie ma górnej granicy wieku snapshotu, więc spółka, której rescore przestał
działać, prezentowała miesięczne dane wyglądające identycznie jak dzisiejsze.

- **Znacznik wieku** w screenerze po przekroczeniu `warn_after_days`, z rozróżnieniem przyczyny:
  `⚠ N dni` = rescore nie przechodzi; `👁 N dni` = nikt nie obserwuje spółki, więc nie jest
  przeliczana (naprawialne jednym kliknięciem — to inna sytuacja i inna reakcja).
- **Odcięcie dla modeli** powyżej `llm_max_age_days` — model nie potrafi ocenić wieku swoich
  danych, więc to próg, nie ostrzeżenie. Pozycje posiadane zwolnione.
- **Liczniki w logu** rozbite na `scored` / `rejected` / `skipped` z nazwami tickerów. Poprzednia
  para myliła odrzucenie bramki z sukcesem — to jest powód, dla którego problem MU żył trzy tygodnie.
- **Porzucone `BUY`/`SELL`** trafia do notatki cyklu i jest widoczne na `/llm-free`, zamiast ginąć
  w logu.

---

## 7. Kanarek regresji

W uniwersum celowo siedzą **dwa notowania SK hynix** (`000660.KS` Seul, `HY9H.F` Frankfurt). To
jedna spółka, więc musi scorować identycznie **poza momentum**, które słusznie różni się rynkiem
notowań. Sonda zadziałała:

| | valuation | quality | momentum_fund | momentum_swing | swing |
|---|---|---|---|---|---|
| przed naprawami (14.08) | 50,0 / 88,66 | — | — | — | **63,30 vs 78,50** |
| po naprawach (15.08) | 88,70 / 88,93 | 100 / 100 | 95 / 95 | 59,29 / 61,74 | **77,20 vs 78,40** |

Rozjazd spadł z **15,2 pkt do 1,2 pkt**, a reszta to czyste momentum. **Zostawić na stałe.**

---

## 8. Wzorzec, który się powtórzył

Cztery z dzisiejszych wad to ta sama klasa błędu: **dwie implementacje jednej rzeczy, które się
rozjechały**.

- `FairPriceCalculator` vs `ValuationPillar` — dwa benchmarki tej samej wyceny.
- Szablon analizy vs `FairPriceCalculator` — dwie kopie wzoru na wartość godziwą.
- `PeerCoverage` (proxy `ev_fcf`) vs `valuation_source` — dwa źródła jednej odpowiedzi.
- Web vs cron w `ScreenerRepository` — dwa różne uniwersa z jednej metody.

Stąd reguła zapisana w `CLAUDE.md`: **kto potrzebuje benchmarku, rozwiązuje go przez
`MedianResolver`** i buduje go przez `MedianResolver::fromConfig()`, żeby wszyscy wołający dzielili
jedną konfigurację.

---

## Migracje

| Nr | Co dodaje |
|---|---|
| 036 | `valuation_source`, `valuation_bucket`, `valuation_variant` na `cvs_snapshots` |
| 037 | tabela `peer_bucket_override` (własne grupy porównawcze) |

Obie addytywne i nullable. Wiersze sprzed migracji czyta się jako „nieznane", **nigdy** jako
„fallback".

## Sprzątanie danych

`DELETE FROM cvs_snapshots WHERE model_version IS NULL` — ok. 780 wierszy artefaktowych
(`cvs_swing` NULL, `quality_gate` 0), które nie niosły informacji, a truły `MAX(score_date)`.

## Do rozstrzygnięcia osobno

- `max_growth` w wartości godziwej pozostaje sektorowy — 60% dla Technology jest hojne dla dystrybutora.
- Analiza liczy FV na żywo, screener czyta ze snapshotu — mogą się nieznacznie różnić.
  Świadomie zaakceptowane 16.08.2026.
- `Real Estate` nadal bez własnej metodyki (REIT-y raportują sensowne przepływy, więc to inny
  problem niż finanse).
- Branże, które zostały poniżej progu mimo dosypania peerów — część składu nie ma dodatniego FCF.
