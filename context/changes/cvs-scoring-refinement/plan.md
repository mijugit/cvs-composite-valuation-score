# CVS Faza 3 — Warstwa peer-group dla Valuation (Implementation Plan)

## Overview

Pierwszy plaster fazy 3 doskonalenia oceny CVS: filar Valuation przestaje porównywać
spółkę do jednej grubej mediany sektorowej (12 zahardkodowanych stałych), a zaczyna
porównywać ją do **podsektora (peer-group)** wyznaczanego **empirycznie** z populacji
spółek — z progiem liczebności i fallbackiem do sektora, z **kotwicą absolutną** broniącą
przed pułapką wyceny względnej, i z **wersjonowaniem modelu** (`model_version`) izolującym
ciągłość track-record. Sigmoid/Quality (P3/P4) są świadomie poza tym planem.

## Current State Analysis

- **`ValuationPillar`** ([src/CVS/Pillars/ValuationPillar.php](../../../cvs-composite-valuation-score/src/CVS/Pillars/ValuationPillar.php)) liczy `ratio = (EV/forward_FCF) / median_ev_fcf[sektor]` (lub wariant EV/Sales), gdzie mediana to stała z configu. Sektor rozwiązuje wewnątrz po `financials['sector']`. Sigmoid k=3 → score.
- **`CVSModel`** ([CVSModel.php:71-82](../../../cvs-composite-valuation-score/src/CVS/CVSModel.php)) konstruuje `ValuationPillar($config['benchmarks'])`, sektor i benchmark rozwiązuje wewnątrz; wynik deterministyczny, wagi/progi z configu.
- **`config/cvs-weights.php`** — `benchmarks` (11 sektorów + DEFAULT, klucze `median_ev_fcf`, `median_ev_sales`, `median_gm`, `max_growth`), `thresholds` (72/58/42/28). Brak `model_version`, brak progu populacji.
- **`cvs_snapshots`** (migr. 004/009/010) — ma `ticker`, `sector`, `pillar_scores` JSON, `price_at_snapshot`. **Brak `industry`, brak `model_version`**.
- **`bin/rescore.php`** — scoruje **unię watchlist** (`WatchlistRepository::findAllDistinctTickers()`), zapisuje przez `CvsSnapshotRepository::save($ticker, $result, $price, $sector)`. Nie używa pełnego uniwersum, nie zna `industry`.
- **`FinancialDataFetcher`** ([FinancialDataFetcher.php:414](../../../cvs-composite-valuation-score/src/Api/FinancialDataFetcher.php)) **już pobiera `industry`** z assetProfile (nieużywany w scoringu); zwraca też wszystkie pola potrzebne do EV/FCF (cena, shares, debt, cash, FCF, revenue, growth).
- **`public/data/tickers.json`** — uniwersum autocomplete = ~177 tickerów (symbol+name, bez sektora/industry).
- **`TrackRecordRepository`** / **`TrackRecordCalculator`** — self-join po `(ticker, score_date)`, liczy hit/miss vs kierunek ceny. Nie zna pojęcia wersji modelu.

### Key Discoveries:

- EV/FCF (i EV/Sales) liczone w `ValuationPillar::score()` to **dokładnie ta sama matematyka**, której potrzebuje pipeline median → wydzielić do reużywalnego komponentu, by uniknąć dryfu dwóch implementacji.
- Wszystkie dane do liczenia metryki wyceny per spółka pochodzą z istniejącego `FinancialDataFetcher::fetch()` — pipeline median reużywa go bez nowych integracji.
- `CvsSnapshotRepository::save()` ma stały podpis i obsługuje upsert MySQL+SQLite — rozszerzenie o `industry`/`model_version` musi zachować tę kompatybilność.
- Determinizm: mediany muszą być **wejściem zamrożonym** (czytane z tabeli), nigdy liczone na żywo per request.

## Desired End State

`ValuationPillar` ocenia spółkę względem mediany jej podsektora (gdy bucket ma ≥ N próbek
tej samej wersji modelu), inaczej spada do mediany sektora; dodatkowo liczy score sektorowy
jako kotwicę i ścina nim wynik, gdy cały podsektor wygląda na przewartościowany. Każdy
snapshot niesie `model_version` i `industry`; track-record liczony w obrębie jednej wersji.
Mediany peer-group pochodzą z cyklicznego, rolującego batcha po rozszerzonej populacji,
zapisane w tabeli `peer_medians` z `sample_count`. Skala rekomendacji zrekalibrowana tak,
by progi 72/58/42/28 znaczyły to co dziś. Weryfikacja: na znanych spółkach (TTWO vs NFLX)
wyniki Valuation są rozróżnione i zgodne z osądem eksperckim; `composer stan` + PHPUnit zielone.

## What We're NOT Doing

- **Nie** ruszamy sigmoidu Valuation (P3/FR-013) ani wygładzania Quality (P4/FR-014) — osobne, późniejsze plastry.
- **Nie** schodzimy głębiej niż 2 poziomy drzewa (sektor → podsektor). Żadnego per-company bucketu.
- **Nie** dotykamy filaru Momentum, logowania, ról ani redesignu UI.
- **Nie** robimy twardego backtestu ani autokalibracji ML — rekalibracja oceniana okiem eksperta.
- **Nie** wprowadzamy płatnych źródeł danych — wyłącznie darmowe Yahoo Finance.
- **Nie** kurujemy ręcznie mapy sektor→podsektor — drzewo budowane z wartości `industry` Yahoo.

## Implementation Approach

Pięć faz w kolejności zależności: najpierw fundament danych (tabele, populacja, config),
potem silnik median (pipeline + repo), potem zmiana scoringu (resolver + kotwica + wersja),
potem opcjonalna multi-soczewka AI i przejrzystość, na końcu rekalibracja skali na realnym
rozkładzie. Każda faza zostawia system zielony (testy + PHPStan) i wdrażalny.

## Critical Implementation Details

- **Reużycie matematyki wyceny:** metryka forward EV/FCF i EV/Sales musi być liczona jednym
  komponentem używanym i przez `ValuationPillar`, i przez pipeline median — inaczej mediana
  i ocena rozjadą się przy każdej przyszłej zmianie wzoru.
- **Determinizm:** `ValuationPillar` nadal nie wykonuje I/O — resolver median dostaje gotowe
  dane (wstrzyknięty), a wartości median są zamrożone w tabeli; brak `date()`/zapytań w scoringu.
- **Cron CF:** pipeline jako CLI z jawną ścieżką PHP 8.2 (CF CLI default 7.4), idempotentny,
  guard „last run" — wzorzec z `bin/rescore.php`. Rolujący po sektorach, by nie wejść w rate-limit Yahoo.

---

## Phase 1: Fundament danych i populacja

### Overview

Schemat i konfiguracja pod resztę: tabela median, stempel wersji/industry w snapshotach,
rozszerzona populacja, parametry modelu w configu. Po tej fazie nic jeszcze nie zmienia
oceny — tylko grunt.

### Changes Required:

#### 1. Migracja: tabela peer_medians

**File**: `database/migrations/012_create_peer_medians.sql`

**Intent**: Magazyn empirycznych median per podsektor i wersja modelu, z licznością próbki
napędzającą próg N.

**Contract**: Tabela `peer_medians` z kolumnami: `id`, `level` (`industry`|`sector`),
`bucket_key` (nazwa podsektora lub sektora), `parent_sector`, `model_version`, `metric_type`
(`ev_fcf`|`ev_sales`|`gm`), `median_value` DECIMAL, `sample_count` INT, `computed_at` DATETIME.
UNIQUE(`level`,`bucket_key`,`model_version`,`metric_type`). Addytywna, nie łamie istniejących tabel.

#### 2. Migracja: model_version + industry w snapshotach

**File**: `database/migrations/013_add_version_industry_to_snapshots.sql`

**Intent**: Pozwolić track-record filtrować po wersji metodyki i znać podsektor spółki.

**Contract**: `ALTER TABLE cvs_snapshots ADD COLUMN model_version VARCHAR(20) NULL AFTER sector,
ADD COLUMN industry VARCHAR(100) NULL AFTER sector`. Istniejące wiersze NULL (akceptowalne —
to stara, nieoznaczona wersja).

#### 3. Rozszerzenie populacji

**File**: `public/data/tickers.json`

**Intent**: Zagęścić podsektory — z ~177 do ~500 dużych/średnich spółek US, by buckety industry
częściej przekraczały próg N. Wyłącznie publicznie znane nazwy (np. konstytuenci S&P 500).

**Contract**: Ten sam format `[{symbol, name}, …]`, posortowany, bez duplikatów. Lista pozostaje
jedynym źródłem populacji (autocomplete i batch median).

#### 4. Parametry modelu w configu

**File**: `config/cvs-weights.php`

**Intent**: Wprowadzić wersjonowanie i parametry peer-group bez hardkodu (FR-010).

**Contract**: Nowe klucze: `model_version` (np. `'3.0'`), `peer_group` => [`min_sample_count` (N),
`anchor_blend` (parametr ścinania kotwicą), `enabled` bool]. Istniejące `benchmarks` zostają jako
fallback sektorowy i baza kotwicy.

### Success Criteria:

#### Automated Verification:
- Migracje aplikują się czysto na świeżej bazie (MySQL i SQLite in-memory testów)
- `composer stan` zielony (PHPStan level 6)
- PHPUnit zielony: `vendor/bin/phpunit`
- `tickers.json` jest poprawnym JSON i ma ≥ 450 unikalnych symboli

#### Manual Verification:
- Przegląd listy tickerów pod kątem sensowności (brak delistowanych/śmieci)
- Config czytelny, `model_version` i `peer_group` opisane komentarzem

**Implementation Note**: Po tej fazie zatrzymaj się na manualne potwierdzenie przed Fazą 2.

---

## Phase 2: Pipeline median peer-group

### Overview

Silnik danych: reużywalny komponent liczenia metryki wyceny, rolujący batch po populacji,
repozytorium median, agregacja per industry z `sample_count`, stemplowane wersją.

### Changes Required:

#### 1. Reużywalny komponent metryki wyceny

**File**: `src/CVS/Valuation/ValuationMetrics.php` (nowy)

**Intent**: Jedno źródło prawdy dla forward EV/FCF i EV/Sales — wyciągnięte z dzisiejszego
`ValuationPillar`, by pipeline median i pillar liczyły identycznie.

**Contract**: Czysta klasa (bez I/O): `forwardEvFcf(array $financials): ?float`,
`forwardEvSales(array $financials): ?float`, plus istniejąca logika `extractForwardGrowth()`.
`ValuationPillar` zostaje przełączony na ten komponent (zachowanie identyczne — pokryte testami).

#### 2. Repozytorium median

**File**: `src/CVS/Valuation/PeerMedianRepository.php` (nowy)

**Intent**: Zapis i odczyt median z `peer_medians`; odczyt zwraca medianę + `sample_count`
dla danego industry/sektora i wersji.

**Contract**: `upsertMedian(...)`, `findByBucket(string $level, string $key, string $version, string $metric): ?array`
(zwraca `['median'=>float,'sample_count'=>int]`). Wstrzykiwalny PDO (test SQLite), wzorzec jak `CvsSnapshotRepository`.

#### 3. Rolujący batch median

**File**: `bin/refresh_peer_medians.php` (nowy)

**Intent**: Cyklicznie przeliczyć mediany podsektorów z populacji, rozkładając pobieranie po
sektorach przez dni tygodnia, idempotentnie.

**Contract**: CLI-only guard (jak `rescore.php`), ładuje `.env`+config, `$_SESSION=[]`. Czyta
populację z `tickers.json`, dla podzbioru sektorów przypadających na dany dzień: `fetch()` →
`ValuationMetrics` → zbiera surowe metryki per (industry, sektor) → liczy medianę i `sample_count`
→ `PeerMedianRepository::upsertMedian(..., model_version)`. Liczy też mediany na poziomie `sector`
(kotwica/fallback). Guard „last run" per sektor, log success/failed per ticker. Mapowanie
dzień→sektory z configu.

### Success Criteria:

#### Automated Verification:
- `ValuationPillar` daje **identyczne** wyniki po refaktorze (testy regresji na fixture'ach `CVSModelTest::baseFinancials()`)
- Testy `ValuationMetrics` (EV/FCF, EV/Sales, brak danych → null)
- Testy `PeerMedianRepository` (upsert + odczyt, SQLite)
- `composer stan` zielony, PHPUnit zielony
- `php bin/refresh_peer_medians.php` uruchamia się bez błędu na sztucznym podzbiorze (dry-run/log)

#### Manual Verification:
- Jednorazowy bieg batcha na realnej populacji zapisuje sensowne mediany (np. `Electronic Gaming` ≠ `Entertainment`)
- `sample_count` per industry zgodny z oczekiwaniem (chude buckety widoczne)
- Czas biegu dziennego segmentu mieści się w oknie crona CF, brak rate-limit Yahoo

**Implementation Note**: Po tej fazie zatrzymaj się na manualne potwierdzenie przed Fazą 3.

---

## Phase 3: ValuationPillar — resolver median + kotwica absolutna

### Overview

Rdzeń zmiany oceny: pillar dostaje wstrzyknięty resolver median (podsektor→fallback sektor wg
progu N), liczy score podsektorowy i sektorowy (kotwica), ścina wynik kotwicą; wynik stemplowany
`model_version`; rescore i track-record świadome wersji.

### Changes Required:

#### 1. Resolver median z fallbackiem

**File**: `src/CVS/Valuation/MedianResolver.php` (nowy)

**Intent**: Oddzielić „skąd mediana" od scoringu — pillar pozostaje czysty i deterministyczny.

**Contract**: `resolve(string $industry, string $sector, string $metric): MedianResolution`
gdzie wynik niesie `value`, `source` (`subsector`|`sector_fallback`), `sample_count`. Logika:
weź medianę podsektora gdy `sample_count >= N`; inaczej medianę sektora (z `peer_medians` poziom
`sector`); a gdy i tej brak (cold-start) — stałą z `config['benchmarks'][sektor]` (twardy fallback).
Czyta przez `PeerMedianRepository` (wstrzyknięty), nie w trakcie scoringu pojedynczego ratio.

#### 2. ValuationPillar: peer-group + kotwica

**File**: `src/CVS/Pillars/ValuationPillar.php`

**Intent**: Liczyć score względem mediany podsektora ORAZ względem sektora (kotwica), i ścinać
wynik w dół gdy kotwica sygnalizuje przewartościowanie całego podsektora (obrona przed pułapką
wyceny względnej, FR-015).

**Contract**: Konstruktor przyjmuje `MedianResolution` (lub resolver + industry/sector) zamiast
surowego `benchmarks`. Liczy `subScore = sigmoid(ratio_subsector)` i `anchorScore = sigmoid(ratio_sector)`;
wynik = reguła łączenia sterowana `config['peer_group']['anchor_blend']`. **Domyślny start (Faza 3):**
`min(subScore, anchorScore)` — kotwica może tylko ścinać wynik w dół (nigdy go nie podbija), więc
przewartościowany cały podsektor ogranicza ocenę. Konkretny tryb/parametr `anchor_blend` (czysty `min`
vs ważona mieszanka) **dobierany na realnych danych w manualnej weryfikacji tej fazy** — domyślny `min`
jest bezpiecznym wariantem, który nie blokuje implementacji. Zwraca w `steps()` użytą jednostkę
odniesienia (`source`) dla FR-005. Sigmoid k bez zmian (P3 poza zakresem).

#### 3. Wiring CVSModel + stempel wersji

**File**: `src/CVS/CVSModel.php`, `src/CVS/CVSResult.php`

**Intent**: Przekazać industry + resolver do pillara i opieczętować wynik wersją modelu.

**Contract**: `CVSModel` buduje `MedianResolver` z `PeerMedianRepository` i przekazuje `industry`
(z `financials`) + `model_version` (z configu). `CVSResult::toArray()` niesie `model_version`
i `valuation_reference` (source). Determinizm zachowany (resolver pobiera dane raz, przed scoringiem).

#### 4. Rescore + track-record świadome wersji

**File**: `bin/rescore.php`, `src/TrackRecord/CvsSnapshotRepository.php`, `src/TrackRecord/TrackRecordRepository.php`

**Intent**: Zapisywać `industry`+`model_version` i liczyć trafność w obrębie jednej wersji.

**Contract**: `CvsSnapshotRepository::save()` rozszerzony o `?string $industry`, `?string $modelVersion`
(zachowując kompatybilność upsert MySQL+SQLite). `rescore.php` przekazuje oba z `financials`/configu.
`TrackRecordRepository` self-join dodatkowo po `model_version` (nie miesza metodyk).

### Success Criteria:

#### Automated Verification:
- Testy `MedianResolver` (podsektor ≥N, fallback sektor, cold-start do stałej)
- Testy `ValuationPillar` peer-group: TTWO-like vs NFLX-like z różnymi medianami → różne score; kotwica ścina przewartościowany podsektor
- Test determinizmu: ten sam input → identyczny wynik (z zamrożonymi medianami)
- Testy `CvsSnapshotRepository` upsert z `industry`/`model_version` (SQLite)
- Test `TrackRecordRepository` izoluje wersje
- `composer stan` zielony, PHPUnit zielony

#### Manual Verification:
- Analiza TTWO i NFLX na żywo: Valuation rozróżnia profile, wynik zgodny z osądem eksperckim
- Detal pokazuje użytą jednostkę odniesienia (podsektor vs fallback)
- Snapshot z crona ma wypełnione `industry` i `model_version`
- Brak regresji na kilku znanych spółkach (np. AAPL, XOM)
- `anchor_blend` dobrany na realnych danych: porównaj `min` vs ważoną mieszankę na próbce spółek z przewartościowanych podsektorów; zapisz wybór w configu z uzasadnieniem

**Implementation Note**: Po tej fazie zatrzymaj się na manualne potwierdzenie przed Fazą 4.

---

## Phase 4: Multi-soczewka AI + przejrzystość (nice-to-have)

### Overview

Wzbogacenie interpretacji: gdy mediana podsektora różni się istotnie od sektora, podaj obie do
warstwy AI; pokaż użytą jednostkę odniesienia użytkownikowi. Bez regresji istniejącej analizy.

### Changes Required:

#### 1. AI dostaje obie mediany warunkowo

**File**: `src/Ai/AiDivergenceService.php`

**Intent**: Pokazać spółkę w wielu perspektywach tylko gdy to sygnał, nie szum (FR-006).

**Contract**: Do promptu (grounded data) dołącz medianę podsektora obok sektorowej **tylko gdy**
różnica względna przekracza próg z configu. Kontrakt wyniku i istniejące sekcje narracji bez zmian
(rozbudowa, nie regresja). CacheableSystem/koszt bez zmian zasady.

#### 2. Przejrzystość jednostki odniesienia

**File**: `src/CVS/AnalysisController.php`, `templates/` (karta Valuation)

**Intent**: Użytkownik widzi, do czego porównano spółkę (podsektor vs fallback do sektora) — FR-005.

**Contract**: Przekazać `valuation_reference` z `CVSResult` do szablonu; dyskretna etykieta przy
filarze Valuation. Bez zmian w layoutcie poza tą etykietą.

### Success Criteria:

#### Automated Verification:
- Test: gdy różnica < próg → prompt jak dziś (brak dodatkowej mediany); gdy ≥ próg → obie obecne
- Test: `AiDivergenceService` nadal zwraca typed result, nigdy nie rzuca (guardrail AI)
- `composer stan` zielony, PHPUnit zielony

#### Manual Verification:
- Analiza AI na spółce z rozjazdem podsektor/sektor czyta się sensownie, nie jest rozmyta
- Etykieta jednostki odniesienia widoczna i poprawna na detalu
- Istniejąca analiza AI bez regresji (spółka bez rozjazdu wygląda jak dziś)

**Implementation Note**: Faza nice-to-have. Po niej zatrzymaj się na manualne potwierdzenie przed Fazą 5.

---

## Phase 5: Rekalibracja skali rekomendacji

### Overview

Zmiana jednostki odniesienia przesuwa rozkład wyników. Ta faza mierzy przesunięcie i koryguje
progi w configu tak, by „AKUMULUJ" znaczyło to co dziś — oceniane okiem eksperta (bez backtestu).

### Changes Required:

#### 1. Raport rozkładu stary vs nowy

**File**: `bin/score_distribution_report.php` (nowy)

**Intent**: Pokazać, jak zmiana peer-group przesunęła rozkład wyników i etykiet na populacji.

**Contract**: CLI-only. Liczy CVS dla populacji starą i nową metodyką (lub czyta snapshoty obu
wersji), wypisuje histogram wyników i liczność per rekomendacja (SILNE KUPUJ … UNIKAJ) dla obu.
Tylko raport — nic nie zapisuje do oceny produkcyjnej.

#### 2. Korekta progów (jeśli potrzebna)

**File**: `config/cvs-weights.php`

**Intent**: Przywrócić znaczenie progów rekomendacji po przesunięciu rozkładu.

**Contract**: Ewentualna zmiana wartości `thresholds` (72/58/42/28) na podstawie raportu i osądu
eksperckiego. Zmiana wyłącznie w configu (FR-010). Jeśli rozkład stabilny — bez zmian (udokumentować).

### Success Criteria:

#### Automated Verification:
- `php bin/score_distribution_report.php` generuje raport bez błędu
- `composer stan` zielony, PHPUnit zielony (gdyby progi zmienione — testy progów zaktualizowane)

#### Manual Verification:
- Raport rozkładu przejrzany; decyzja o progach udokumentowana (zmiana lub świadome „bez zmian")
- Na próbce znanych spółek etykiety rekomendacji są spójne ze zdrowym rozsądkiem
- Guardrail skali spełniony: „AKUMULUJ" obejmuje porównywalny zakres jakości co przed zmianą

**Implementation Note**: Ostatnia faza. Po niej zmiana gotowa do archiwizacji (`/10x-archive`).

---

## Testing Strategy

### Unit Tests:
- `ValuationMetrics` — EV/FCF, EV/Sales, ścieżki brakujących danych (null).
- `MedianResolver` — próg N (podsektor vs sektor), cold-start do stałej, brakujące buckety.
- `ValuationPillar` — rozróżnienie podsektorów (TTWO vs NFLX fixtures), ścinanie kotwicą, determinizm.
- `PeerMedianRepository`, `CvsSnapshotRepository` (industry/version), `TrackRecordRepository` (izolacja wersji) — SQLite in-memory.
- `AiDivergenceService` — warunkowe dołączanie mediany podsektora, typed result.

### Integration Tests:
- Pełna ścieżka `CVSModel::calculate()` z zamrożonymi medianami w SQLite → stabilny, deterministyczny wynik dual-mode.
- Bieg `refresh_peer_medians.php` na sztucznym zestawie → poprawne mediany + `sample_count`.

### Manual Testing Steps:
1. Bieg batcha median na realnej populacji; sprawdź `Electronic Gaming` ≠ `Entertainment`.
2. Analiza TTWO vs NFLX — Valuation rozróżnione, etykieta jednostki odniesienia widoczna.
3. Spółka z przewartościowanego podsektora — kotwica ścina wynik (nie wychodzi „fair").
4. Cron snapshot ma `industry`+`model_version`; track-record nie miesza wersji.
5. Raport rozkładu — decyzja o progach.

## Performance Considerations

- Mediany czytane z tabeli (zamrożone) — zero ciężkich obliczeń per request użytkownika (NFR czasu reakcji).
- Batch median rozłożony po sektorach przez tydzień — chroni przed rate-limit Yahoo i mieści się w oknie crona CF.
- Determinizm: brak I/O w scoringu pojedynczego ratio; resolver pobiera dane raz na analizę.

## Migration Notes

- Migracje 012/013 addytywne; istniejące snapshoty mają `industry`/`model_version` NULL = stara wersja (świadomie poza nowym track-record).
- Cold-start: dopóki `peer_medians` pusta/chuda, twardy fallback do dzisiejszych stałych — zachowanie produkcyjne nie regresuje od dnia wdrożenia.
- Nowy cron na CF: `refresh_peer_medians.php` typ Ścieżka/Komenda z jawną ścieżką PHP 8.2, rozkład dzień→sektor wg configu.

## References

- Frame brief: `context/changes/cvs-scoring-refinement/frame.md`
- PRD: `context/foundation/prd.md`
- Shape: `context/foundation/shape-notes.md`
- Kluczowy kod: `src/CVS/Pillars/ValuationPillar.php`, `src/CVS/CVSModel.php`, `bin/rescore.php`, `src/TrackRecord/CvsSnapshotRepository.php`, `src/Api/FinancialDataFetcher.php:414`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Fundament danych i populacja

#### Automated
- [x] 1.1 Migracje aplikują się czysto (MySQL + SQLite) — 85e6ddb
- [x] 1.2 `composer stan` zielony — 85e6ddb
- [x] 1.3 PHPUnit zielony — 85e6ddb
- [x] 1.4 `tickers.json` poprawny JSON, ≥ 450 unikalnych symboli — 10b97e4

#### Manual
- [x] 1.5 Przegląd listy tickerów (sensowność) — 85e6ddb
- [x] 1.6 Config czytelny, `model_version` + `peer_group` opisane — 85e6ddb

### Phase 2: Pipeline median peer-group

#### Automated
- [x] 2.1 `ValuationPillar` identyczny po refaktorze (regresja na fixture'ach) — 81e7a17
- [x] 2.2 Testy `ValuationMetrics` — 81e7a17
- [x] 2.3 Testy `PeerMedianRepository` (SQLite) — 81e7a17
- [x] 2.4 `composer stan` + PHPUnit zielone — 81e7a17
- [x] 2.5 `refresh_peer_medians.php` biegnie bez błędu na podzbiorze — 81e7a17

#### Manual
- [x] 2.6 Bieg na populacji zapisuje sensowne mediany (Gaming ≠ Entertainment)
- [x] 2.7 `sample_count` zgodny (chude buckety widoczne)
- [x] 2.8 Czas segmentu mieści się w oknie crona, brak rate-limit

### Phase 3: ValuationPillar — resolver median + kotwica absolutna

#### Automated
- [x] 3.1 Testy `MedianResolver` (próg N, fallback, cold-start) — ddc6741
- [x] 3.2 Testy `ValuationPillar` peer-group + kotwica — ddc6741
- [x] 3.3 Test determinizmu — ddc6741
- [x] 3.4 Testy `CvsSnapshotRepository` (industry/version) — ddc6741
- [x] 3.5 Test izolacji wersji w `TrackRecordRepository` — ddc6741
- [x] 3.6 `composer stan` + PHPUnit zielone — ddc6741

#### Manual
- [x] 3.7 TTWO vs NFLX rozróżnione, zgodne z osądem
- [x] 3.8 Detal pokazuje jednostkę odniesienia
- [x] 3.9 Snapshot z crona ma `industry`+`model_version`
- [x] 3.10 Brak regresji (AAPL, XOM)
- [x] 3.11 `anchor_blend` dobrany na danych i zapisany w configu z uzasadnieniem

### Phase 4: Multi-soczewka AI + przejrzystość

#### Automated
- [x] 4.1 Warunkowe dołączanie mediany podsektora (test progu) — 29b3b99
- [x] 4.2 `AiDivergenceService` nadal typed result, nie rzuca — 29b3b99
- [x] 4.3 `composer stan` + PHPUnit zielone — 29b3b99

#### Manual
- [x] 4.4 Analiza AI z rozjazdem czyta się sensownie
- [x] 4.5 Etykieta jednostki odniesienia poprawna
- [x] 4.6 Brak regresji istniejącej analizy AI

### Phase 5: Rekalibracja skali rekomendacji

#### Automated
- [ ] 5.1 `score_distribution_report.php` generuje raport bez błędu
- [ ] 5.2 `composer stan` + PHPUnit zielone (progi, jeśli zmienione)

#### Manual
- [ ] 5.3 Raport przejrzany, decyzja o progach udokumentowana
- [ ] 5.4 Etykiety rekomendacji spójne ze zdrowym rozsądkiem
- [ ] 5.5 Guardrail skali spełniony
