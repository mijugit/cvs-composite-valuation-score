# Lessons Learned

> Append-only register of recurring rules and patterns. Re-read at start by /10x-frame, /10x-research, /10x-plan, /10x-plan-review, /10x-implement, /10x-impl-review.

## UserRepository SELECT musi zawierać wszystkie potrzebne kolumny

**Context:** `src/Auth/UserRepository.php`, `findByEmail()`.

**Problem:** `findByEmail()` miało `SELECT id, email, password_hash` — bez `is_admin`. Po dodaniu nowej kolumny `is_admin` do tabeli `users` i wpisaniu `$_SESSION['is_admin'] = (bool) $user['is_admin']` w login(), wartość była zawsze `false` mimo `is_admin=1` w DB, bo kolumna nie była pobierana.

**Rule:** Przy dodawaniu nowych kolumn do tabeli `users` (lub innej tabeli z wieloma SELECT-ami) — zawsze przejrzeć WSZYSTKIE metody w odpowiednim Repository i zaktualizować ich SELECT-y. `findByEmail()` i `findById()` mogą mieć różne pola.

**Applies to:** Każda zmiana schematu tabeli `users` (i innych tabel z wieloma read-methodami). Szczególnie gdy nowa kolumna ma być dostępna przy logowaniu — sprawdź `findByEmail()`, nie tylko `findById()`.

## Szablony PHP sprawdzać `php -l` przed deployem

**Context:** `templates/analysis.php`, hotfix `0eebbc4`.

**Problem:** Podwójny `<?php` w szablonie zepsuł produkcję. Deploy poszedł bez walidacji składni.

**Rule:** Przed każdym commitem zawsze uruchom `php -l templates/*.php` na zmienionych szablonach. Szczególnie ważne przy ręcznym mergowaniu zmian lub kopiowaniu fragmentów między plikami.

**Applies to:** Każda zmiana pliku `.php` w `templates/`.

## PowerShell heredoc: znak specjalny ▾ łamie `@'...'@`

**Context:** `git commit -m @'...'@` z emoji/unicode w treści.

**Problem:** Commit message zawierający ▾ (lub inne znaki spoza ASCII) w PowerShell here-string `@'...'@` powoduje błąd parsowania — git interpretuje część wiadomości jako pathspec.

**Rule:** Unikaj znaków specjalnych (emoji, strzałki unicode) w treści commit message pisanego przez PowerShell heredoc. Zamień na ASCII (`v`, `->`, `(dropdown)`) lub użyj wieloliniowego `-m` z backtick `n.

**Applies to:** Każdy `git commit` przez PowerShell z niestandardowymi znakami unicode.

## exec() fire-and-forget: dołącz ` &` do polecenia

**Context:** `src/Admin/SectorsController::refresh()`.

**Problem:** `exec($cmd)` blokuje request do zakończenia procesu PHP. Refresh peer-median trwa ~2-5 min.

**Rule:** Dla długich procesów CLI uruchamianych z web PHP: `exec($cmd . ' &')` — ampersand odcina proces od rodzica i natychmiast zwraca kontrolę. Bez niego request czeka.

**Applies to:** Każde `exec()` w kontrolerach webowych gdy czas wykonania > 1s.

## Skrypty CLI/cron: nie używaj `error_log()` — pisz do własnego pliku logów

**Context:** `bin/check_price_alerts.php`, `bin/rescore.php`, `bin/refresh_peer_medians.php`. Odkryto 2026-06-25.

**Problem:** `error_log()` w PHP CLI wysyła wiadomości do systemowego logu PHP (ścieżka z `php.ini`) — **nie** na stdout ani stderr widoczne w przekierowaniu crona. Cron CF zbiera stdout do pliku, który pozostaje pusty mimo poprawnie działającego skryptu. Przez to:
- nie widać czy cron w ogóle się uruchomił
- wyjątki z DB/sieci lecą w `/dev/null` (brak `try/catch`) i są całkowicie niewidoczne
- debugowanie wymaga dostępu do systemowych logów serwera (brak dostępu na CF shared hosting)

W tym przypadku ukryło to realny bug: `TypeError` w `PriceAlertService::buildHtml()` (stop_swing jako string zamiast float z PDO) — skrypt crashował cicho przez tygodnie.

**Rule:**
1. **Zawsze** otwieraj dedykowany plik logów na początku każdego skryptu CLI/cron:
   ```php
   $logFile = ROOT_PATH . '/logs/<script>.log';
   $log = static function (string $msg) use ($logFile): void {
       file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL, FILE_APPEND | LOCK_EX);
   };
   $log('<script>: start');
   ```
2. **Zawsze** owijaj główną logikę w `try/catch (Throwable $e)` z wpisem do logu + `exit(1)`.
3. **Nigdy** `error_log()` w skryptach CLI — niewidoczne na CF shared hosting.
4. **Zawsze** cast kolumn numerycznych z PDO przed przekazaniem do typed PHP: `(float) $row['stop_swing']`, `(int) $row['id']` itp. PDO z MySQL zwraca `string` dla DOUBLE/INT gdy `ATTR_STRINGIFY_FETCHES` nie jest wyłączone.

**Applies to:** Każdy nowy skrypt w `bin/` oraz każda nowa metoda w klasach wywoływanych przez cron.

## Filtruj shadow model_version przy każdym odczycie "latest snapshot"

- **Context**: Repozytoria czytające "latest snapshot" z cvs_snapshots (CvsSnapshotRepository, ScreenerRepository i każdy nowy odczyt MAX(score_date)).
- **Problem**: Gdy persystencja w trybie cieniowym (np. cvs-overlay-penalties, model_version 3.1) zaczyna pisać drugi wiersz per (ticker, score_date), niefiltrowany JOIN na MAX(score_date) zwraca OBA wiersze tego samego dnia — podwajając listingi (screener pokazał 68 zamiast 34 spółek, dashboard miał zdublowaną mapę watchlisty). Hotfix: commit 442689d, 2026-06-08.
- **Rule**: Każdy nowy lub istniejący odczyt "najnowszego snapshotu" (MAX(score_date) self-join na cvs_snapshots) MUSI przyjmować i filtrować po żywym model_version z config/cvs-weights.php — nigdy nie polegać na samym MAX(score_date), gdy w tabeli mogą współistnieć wiersze cieniowe (shadow) i live dla tej samej daty.
- **Applies to**: plan, implement, impl-review

## Reguły arytmetyczne z "sumą kroczącą" egzekwuj po stronie serwera, nie w prompcie

- **Context**: Decyzje LLM dla wirtualnego portfela (DecisionService → DecisionParser → DecisionEnforcer). Odkryto 2026-06-29 przy pierwszych autonomicznych rebalansach.
- **Problem**: Model potrafi *opisać* w uzasadnieniu poprawne respektowanie limitu („redukuję CSCO do 5 szt., bo sektor Tech dobił 40%"), ale w polu strukturalnym `quantity` wpisuje wartość nieprzyciętą (8). Efekt: limit sektorowy 40% przebity (~57% Tech), mimo że model „rozumował" poprawnie. To samo dotyczy stop-loss — LLM zostawia stratną pozycję jako HOLD wbrew regule. LLM nie utrzymuje sumy kroczącej (per-sektor, per-pozycja) ani progów P&L w polach decyzji, nawet przy jawnej instrukcji.
- **Rule**: Każda reguła wymagająca akumulacji/porównania liczbowego (limit sektorowy, limit na pozycję, stop-loss, budżet gotówki) MUSI być wymuszana deterministycznie po stronie serwera (`DecisionEnforcer`: przycina/odrzuca/wymusza), niezależnie od tego co zwrócił LLM. Prompt opisuje regułę dla spójności uzasadnień, ale NIE jest warstwą egzekwującą. Wyjątkiem mogą być reguły wymagające osądu kontekstowego (np. take-profit „chyba że spółka wciąż przyspiesza") — te mogą zostać miękkie, ale ochrona kapitału nigdy.
- **Applies to**: każda nowa reguła strategii/ryzyka w portfelu i każdy nowy kontrakt decyzji LLM, gdzie model zwraca pola liczbowe podlegające limitom.

## Brak danych to nie zero — nigdy nie koaleskuj nulla do wartości, którą reguła ocenia

- **Context**: `QualityGate::evaluate()`, ale reguła jest ogólna. Odkryto 2026-08-15 przy śledztwie, dlaczego MU zniknął z uniwersum.
- **Problem**: warunek `($financials['revenue'] ?? 0) <= 0` zamieniał *brak danych* w *twierdzenie*: „spółka nie ma przychodów". Trzy pozostałe warunki bramki poprawnie pomijały nulla, ten jeden nie. Gdy Yahoo przestał publikować dla MU `incomeStatementHistory` (przy działającej cenie i reszcie modułów), największa pozycja portfela została odrzucona jako startup bez sprzedaży — i zamrożona na trzy tygodnie, bo egzekutor nie miał jej ceny.
- **Rule**: pytanie „czy wartość łamie próg?" i „czy wartość w ogóle istnieje?" to dwa różne pytania i muszą być dwiema różnymi gałęziami. `?? 0`, `?? ''`, `(int)$null` w warunku oceniającym to bug, nie skrót. Jeśli danej brakuje, właściwą reakcją jest pominięcie warunku albo odmowa oceny — nigdy ocena negatywna. Zanim uznasz brak za wadę spółki, sprawdź drugie źródło tej samej wielkości (`financialData.totalRevenue` zawierało to, czego nie było w sprawozdaniu).
- **Applies to**: plan, implement, impl-review — każda bramka, filtr i próg czytający dane zewnętrzne.

## NULL w kolumnie indeksu UNIQUE nie chroni przed duplikatami (MySQL)

- **Context**: `cvs_snapshots` / `uq_ticker_day_version`. Odkryto 2026-08-15.
- **Problem**: `CVSResult::failed()` zapisywał odrzucenie bez `model_version`. MySQL traktuje każdy NULL w indeksie UNIQUE jako różny, więc ograniczenie milczało i powstawało 5 wierszy dziennie (tyle, ile przebiegów rescore). Gorzej: te wiersze były najświeższe, więc każde zapytanie typu `MAX(score_date)` *bez* filtra wersji widziało je zamiast ostatniego dobrego snapshotu. Ta sama metoda repozytorium miała gałąź z filtrem (web) i bez (cron) — więc dwa konteksty widziały dwa różne uniwersa.
- **Rule**: kolumna wchodząca w skład indeksu UNIQUE musi być `NOT NULL`, a każda ścieżka zapisu — także ta „negatywna" (odrzucenie, błąd, pominięcie) — musi ją wypełniać. Odrzucenie bramki to nadal obserwacja z konkretnej wersji modelu. Nie dopuszczaj też do tego, by jedna metoda repozytorium miała opcjonalny filtr zmieniający zbiór wyników — jeżeli filtr jest wymagany do poprawności, uczyń go wymaganym w sygnaturze.
- **Applies to**: plan, implement, migracje.

## Liczniki podsumowania muszą rozróżniać porażkę od odrzucenia

- **Context**: `bin/rescore.php`. Odkryto 2026-08-15.
- **Problem**: log raportował `success=103 failed=0`, gdzie `failed` liczyło wyłącznie błąd pobrania danych. Spółka odrzucona przez bramkę jakości szła do `success`. Metryka wyglądała idealnie przez trzy tygodnie, w których największa pozycja portfela była niewidoczna dla modelu. **Zielony licznik jest groźniejszy niż brak licznika** — powstrzymuje przed patrzeniem.
- **Rule**: rozbij wynik na kategorie odpowiadające realnym stanom (`scored` / `rejected` / `skipped`), nie na „udało się / nie udało". Przy kategoriach innych niż `scored` wypisuj nazwy — agregat bez identyfikatorów nie pozwala zauważyć, że ta sama spółka wypada codziennie. Pytanie kontrolne: czy ten licznik pokazałby, że coś jest nie tak, gdyby było nie tak?
- **Applies to**: każdy skrypt wsadowy w `bin/`.

## Dwie implementacje jednej reguły zawsze się rozjadą

- **Context**: cztery niezależne defekty z 15–16.08.2026, wszystkie tej samej klasy.
- **Problem**: `FairPriceCalculator` czytał statyczny benchmark, a `ValuationPillar` medianę grupy — ta sama wycena, dwa komparatory, sprzeczny wynik obok siebie na ekranie (ASB.WA: „wyceniona uczciwie" i „+722%"). Szablon analizy miał własną kopię wzoru na wartość godziwą zamiast wołać kalkulator. `PeerCoverage` wnioskował pokrycie z `ev_fcf`, choć spółki wariantu B używają `ev_sales`. `ScreenerRepository` miał dwie gałęzie zapytania dla dwóch kontekstów.
- **Rule**: gdy dwa miejsca odpowiadają na to samo pytanie, jedno z nich jest źródłem, a drugie musi je zawołać — nawet jeśli kopia wygląda na trywialną i wygodniejszą. Dotyczy szczególnie szablonów: „to tylko wzór w widoku" to sposób, w jaki kopia powstaje. Praktycznie: benchmark rozwiązuje wyłącznie `MedianResolver` (budowany przez `fromConfig()`), a jeżeli obliczenie *musi* być powtórzone gdzie indziej, zapisz wynik pierwszego przy danych i czytaj go, zamiast wyprowadzać ponownie.
- **Applies to**: plan, implement, impl-review.

## Konwersja walut: wielkości „na akcję" idą kursem ceny, nie kursem sprawozdań

- **Context**: `FinancialDataFetcher`. Dwa wystąpienia: ASBIS (2026-08-15) i `book_value_per_share` (2026-08-16).
- **Problem**: waluta notowania i waluta sprawozdań to dwie różne rzeczy i Yahoo podaje obie. ASBIS notowany w PLN raportuje w USD — cena 135,20 PLN traktowana jak $135,20 zawyżała EV 3,7×. Ten sam błąd wracał przy wartości księgowej na akcję: gdyby przeszedł, każdy warszawski bank porównywałby księgową w PLN do ceny w USD.
- **Rule**: przed użyciem dowolnej wielkości per-share sprawdź, z czym będzie zestawiana. Jeżeli z `current_price` — konwertuj kursem ceny (`currency`), nie kursem sprawozdań (`financialCurrency`). Test kontrolny jest tani: spółka notowana poza swoją walutą sprawozdawczą (ASB.WA, LPP.WA, 005930.KS) musi dawać sensowny wynik.
- **Applies to**: implement, impl-review — każde nowe pole liczbowe z Yahoo.

## Kanarek podwójnego notowania: trzymaj w uniwersum tę samą spółkę na dwóch giełdach

- **Context**: SK hynix jako `000660.KS` (Seul) i `HY9H.F` (Frankfurt). Sprawdzone 2026-08-15.
- **Problem/zysk**: to jedna spółka, więc *musi* scorować identycznie we wszystkim poza momentum, które słusznie zależy od rynku notowania. Każdy rozjazd w wycenie czy jakości jest z definicji błędem — waluty, liczby akcji, klasyfikacji. Sonda wyłapała skalę napraw obiektywnie: 15,2 pkt rozjazdu przed, 1,2 pkt po, a reszta to czyste momentum.
- **Rule**: zostaw ten test na stałe i sprawdzaj go po każdej zmianie w warstwie danych lub w filarach. Tani, samoweryfikujący się test regresji, którego nie da się oszukać dopasowaniem oczekiwań — bo prawidłowa odpowiedź („obie mają być równe") jest znana z góry, bez znajomości modelu.
- **Applies to**: impl-review, każda zmiana w `FinancialDataFetcher` i `Pillars/`.

## Klucz zewnętrzny nie jest tożsamością — weryfikuj, że symbol nadal znaczy to samo

- **Context**: tickery giełdowe w `public/data/tickers.json`, wykryte 2026-08-16. Reguła dotyczy każdego identyfikatora nadawanego przez stronę trzecią.
- **Problem**: `GOLD` przestał być Barrickiem (ten przeniósł się na `B`) i zaczął rozwiązywać się na **Gold.com, Inc.** — inną spółkę, z innego sektora. Cały potok był zadowolony: fetch się udał, payload kompletny, bramka przeszła, snapshot zapisany. Przez sześć dni wyniki jednej spółki lądowały w historii pod nazwą drugiej. Żadna warstwa tego nie zauważyła, bo **każda ufała symbolowi**. Wyszło przypadkiem, przy okazji zupełnie innej weryfikacji.
- **Rule**: symbol nadany przez dostawcę (ticker, ISIN, ID zewnętrznego API) jest kluczem *wyszukiwania*, nie dowodem tożsamości — bywa wygaszany, przemianowywany i **przypisywany innemu podmiotowi**. Przy każdym cyklu porównuj drugi, niezależny atrybut (nazwa, waluta, giełda) z tym, co masz zapisane, i zgłaszaj rozjazd. Reakcją jest **ostrzeżenie dla operatora, nigdy automatyczne działanie** — zgadnięcie „to już inna spółka, usuwam" może po cichu skasować pozycję. I odwrotnie: brak atrybutu po stronie dostawcy to nie rozjazd (patrz reguła o nullu wyżej).
- **Uwaga o detektorze**: naiwne porównanie tekstu jest bezużyteczne. Na 590 tickerach `similar_text` zgłosił 6 rozjazdów, z czego **5 to były nasze własne skróty** (IBM Corp. vs International Business Machines, BBVA, PSEG, Siemens AG, Bank of Nova Scotia). Detektor, który krzyczy fałszywie, zostaje zignorowany — dlatego te 5 par jest teraz przypadkami testowymi, a dopasowanie idzie po tokenach znaczących plus akronim-vs-inicjały.
- **Applies to**: plan, implement, impl-review — każda integracja z zewnętrznym rejestrem identyfikatorów.
