# Architect Report — Module 4 (10xArchitect track)

All four artifacts below were produced on the same repository:
**mijugit/cvs-composite-valuation-score** (PHP 8.2, vanilla PSR-4, MySQL, no framework;
270 commits, solo contributor, ~2 months old).

## 1. Opisane projekty

| Repo | Stack | Scale | Artifact |
|---|---|---|---|
| cvs-composite-valuation-score | PHP 8.2 / MySQL / PSR-4, no framework | 270 commits, ~17 `CVS\` namespaces, 480+ tests | L2 map, L3 research, L4 refactor, L5 DDD |

Single repo used for all four artifacts — no cross-repo work this pass.

## 2. Mapa projektu (z L2)

Full artifact: [`context/map/repo-map.md`](map/repo-map.md).

- **Highest risk zone:** `src/Portfolio` — a one-month-old, LLM-driven autonomous paper-trading
  agent already responsible for more bugfix commits per week-of-existence than any other module
  (parser fragility on LLM output, a deadlock under concurrent rebalance, two timezone bugs).
- **Local centers:** `CVS\Core` (26 fan-in — router/DB substrate) and `CVS\Auth` (14 fan-in — the
  entire access-control surface, two files deep) are thin but load-bearing; `CVS\CVS` (15 files)
  is the biggest namespace and the deterministic scoring core the product's credibility rests on.
- **Entry points:** one HTTP front controller (`public/index.php`) + 8 CLI/cron scripts (`bin/`) —
  a parallel entry surface governed by its own conventions (idempotency, file logging).
- **Biggest unknown:** no architecture-boundary test exists (no `deptrac` equivalent) — the clean
  namespace layering holds by convention today, not by anything that would catch a violation.

## 3. Analiza ficzera (z L3)

Full artifact: [`context/changes/cvs-math-research/deep-focus-summary.md`](changes/cvs-math-research/deep-focus-summary.md)
(companion to the earlier full [research.md](changes/cvs-math-research/research.md), 2026-05-31).

**Studied flow:** the CVS scoring engine (`CVSModel` + 3 pillars) — chosen because the repo map
flagged it as the busiest, highest-consequence local center.

**Feature overview:** three independently-scored pillars (Valuation/Momentum/Quality) combined by
a config-driven weighted sum, gated by a binary Quality Gate, deterministic by construction (no
randomness/`date()` inside scoring), with two non-blocking "shadow" model versions computed in
parallel for future calibration.

**Technical debt** (one structurally confirmed via `grep` fan-out count, matching the ast-grep
verification step from the lesson): (1) the external-data contract (`$financials`) is a plain
untyped `array` consumed directly by **12 files** across 6 namespaces — confirmed by
`grep -rl '\$financials' src/`; (2) `FinancialDataFetcher` is explicitly excluded from the test
suite (per CLAUDE.md) — the riskiest, least-stable dependency has zero automated coverage; (3) a
fixed sigmoid steepness (`k=3`) over-punishes tail scores, already flagged in an earlier
architecture-framing pass and deliberately deferred in favor of peer-group work.

## 4. Plan refaktoryzacji (z L4)

Full artifact: [`context/changes/cvs-math-research/refactor-plan.md`](changes/cvs-math-research/refactor-plan.md).

Two refactors — both already implemented, tested, and merged — documented retrospectively in the
L4 ranking/plan format: **`ValuationMetrics`** (EV/FCF math extracted from `ValuationPillar` so the
peer-median batch crawl reuses the same formula instead of re-deriving it — anti-drift) and
**`SnapshotWriter`** (CVS-result-to-database fan-out extracted from `rescore.php`, ported 1:1, so
the calibration-corpus crawler reuses it instead of duplicating it byte-for-byte). Both shipped
with before/after golden-value tests and were proven by a second real caller consuming the
extracted class within the same change. A third, real candidate — de-duplicating "latest
snapshot" SQL across 7 read sites — was **deliberately not chosen** for this pass (larger blast
radius, needs its own plan); see artifact 5 below, where it resurfaced as the DDD invariant.

## 5. Domena wg DDD (z L5)

Full artifacts: [`context/domain/`](domain/) (3 files).

- **Ubiquitous language:** "model" is overloaded three ways (the CVS methodology, `model_version`
  the stamped scoring-version number, and `config['model']` the Claude LLM ID) — same word, three
  referents, colliding across `config/ai.php` and `config/cvs-weights.php`. "Alert" spans two
  unrelated trigger mechanisms (`AlertService` = recommendation-change, `PriceAlertService` = ATR
  price-zone crossing). `goldenSignal`'s value `'watchlist'` collides with the unrelated Watchlist
  feature name.
- **Invariant #1 + aggregate:** *"the latest CVS snapshot for a ticker must be the live
  `model_version` row, never a shadow row"* — currently enforced independently by **7 files**
  across 4 namespaces (already caused one production bug, hotfix `442689d`). Proposed aggregate:
  `LiveCvsSnapshot`, the single sanctioned query surface, making the invariant impossible to
  violate from a new call site instead of documented-and-hoped-for.
- **Anti-Corruption Layer:** Yahoo's raw fields *are* renamed on the way in
  (`FinancialDataFetcher::normalise()`) and the `Forecast/` parsers are genuinely well-isolated —
  but the renamed output stays an untyped array with no declared shape, so the translation exists
  without an enforced contract (same 12-file fan-out as artifact 3's technical-debt #1).

## 6. Decisions that are mine

AI suggested *where* to look (grep/git-log analysis, concrete fan-in/fan-out counts, refactor
candidates) and proposed three naming-collision findings plus one candidate aggregate. I decided:
which two refactors were actually worth shipping earlier (`ValuationMetrics`, `SnapshotWriter`) and
why the third one (de-duplicating "latest snapshot") waited for its own plan instead of riding
along in the same commit; that the naming collisions ("model", "alert", `goldenSignal`) get
documented rather than renamed immediately — because the migration cost on data already
serialized (cache, snapshots) outweighs the value of an instant fix; and that `LiveCvsSnapshot`
and a typed `$financials` contract are candidates for the next `/10x-plan`, not changes to sneak
in while writing this report.

---

# Raport Architekta — Moduł 4 (ścieżka 10xArchitect)

Wszystkie cztery poniższe artefakty powstały na tym samym repozytorium:
**mijugit/cvs-composite-valuation-score** (PHP 8.2, vanilla PSR-4, MySQL, bez frameworka;
270 commitów, jeden autor, ~2 miesiące istnienia).

## 1. Opisane projekty

| Repo | Stack | Skala | Artefakt |
|---|---|---|---|
| cvs-composite-valuation-score | PHP 8.2 / MySQL / PSR-4, bez frameworka | 270 commitów, ~17 namespace'ów `CVS\`, 480+ testów | mapa L2, research L3, refaktoryzacja L4, DDD L5 |

Jedno repozytorium dla wszystkich czterech artefaktów — bez pracy cross-repo w tym podejściu.

## 2. Mapa projektu (z L2)

Pełny artefakt: [`context/map/repo-map.md`](map/repo-map.md).

- **Największa strefa ryzyka:** `src/Portfolio` — miesięczny, autonomiczny agent handlu
  papierowego sterowany LLM, już odpowiedzialny za więcej commitów naprawczych na tydzień
  istnienia niż jakikolwiek inny moduł (kruchość parsera na wyjściu LLM, deadlock przy
  równoległym rebalansie, dwa błędy stref czasowych).
- **Lokalne centra:** `CVS\Core` (26 zależności przychodzących — substrat routera/DB) i
  `CVS\Auth` (14 — cała powierzchnia kontroli dostępu, dwa pliki głęboko) są cienkie, ale
  nośne; `CVS\CVS` (15 plików) to największy namespace i deterministyczny rdzeń scoringu, na
  którym opiera się wiarygodność produktu.
- **Punkty wejścia:** jeden HTTP front controller (`public/index.php`) + 8 skryptów CLI/cron
  (`bin/`) — równoległa powierzchnia wejścia rządząca się własnymi konwencjami (idempotencja,
  logowanie do pliku).
- **Największa niewiadoma:** nie istnieje test granic architektury (brak odpowiednika
  `deptrac`) — czyste warstwowanie namespace'ów trzyma się dziś konwencją, nie czymkolwiek co
  złapałoby naruszenie.

## 3. Analiza ficzera (z L3)

Pełny artefakt: [`context/changes/cvs-math-research/deep-focus-summary.md`](changes/cvs-math-research/deep-focus-summary.md)
(towarzyszy pełnemu [research.md](changes/cvs-math-research/research.md), 2026-05-31).

**Badany przepływ:** silnik scoringu CVS (`CVSModel` + 3 filary) — wybrany, bo mapa repo
oznaczyła go jako najbardziej zajęte, najbardziej konsekwentne lokalne centrum.

**Feature overview:** trzy niezależnie liczone filary (Wycena/Momentum/Jakość) łączone
ważoną sumą sterowaną configiem, bramkowane binarnym Quality Gate, deterministyczne z
założenia (brak losowości/`date()` w logice scoringu), z dwoma nieblokującymi "shadow"
wersjami modelu liczonymi równolegle pod przyszłą kalibrację.

**Technical debt** (jedno potwierdzone strukturalnie przez licznik fan-out z `grep`,
odpowiednik kroku weryfikacji ast-grepem z lekcji): (1) kontrakt danych zewnętrznych
(`$financials`) to zwykła nietypowana `array` konsumowana bezpośrednio przez **12 plików**
w 6 namespace'ach — potwierdzone przez `grep -rl '\$financials' src/`; (2)
`FinancialDataFetcher` jest jawnie wyłączony z zestawu testów (wg CLAUDE.md) — najbardziej
ryzykowna, najmniej stabilna zależność ma zerowe pokrycie automatyczne; (3) stała stromość
sigmoidy (`k=3`) zbyt mocno karze wyniki brzegowe, już oflagowana we wcześniejszym przejściu
ramującym architekturę i świadomie odłożona na rzecz pracy nad peer-group.

## 4. Plan refaktoryzacji (z L4)

Pełny artefakt: [`context/changes/cvs-math-research/refactor-plan.md`](changes/cvs-math-research/refactor-plan.md).

Dwie refaktoryzacje — obie już wdrożone, przetestowane i zmergowane — udokumentowane
retrospektywnie w formacie rankingu/planu L4: **`ValuationMetrics`** (matematyka EV/FCF
wydzielona z `ValuationPillar`, dzięki czemu batch peer-median reużywa tego samego wzoru
zamiast wyprowadzać go od nowa — anty-dryf) oraz **`SnapshotWriter`** (fan-out wyniku CVS
do bazy wydzielony z `rescore.php`, przeniesiony 1:1, dzięki czemu crawler korpusu
kalibracyjnego reużywa go zamiast duplikować bajt w bajt). Obie wysłane z testami
złotej wartości przed/po i potwierdzone przez drugiego realnego wywołującego w tej samej
zmianie. Trzeci, realny kandydat — deduplikacja SQL "najnowszego snapshotu" w 7 miejscach
odczytu — **świadomie nie został wybrany** w tym podejściu (większy blast radius, wymaga
osobnego planu); patrz artefakt 5 poniżej, gdzie wypłynął ponownie jako niezmiennik DDD.

## 5. Domena wg DDD (z L5)

Pełne artefakty: [`context/domain/`](domain/) (3 pliki).

- **Ubiquitous language:** słowo "model" jest przeciążone na trzy sposoby (metodologia CVS,
  `model_version` czyli wersja scoringu wbita w snapshot, oraz `config['model']` czyli ID
  modelu LLM Claude) — to samo słowo, trzy różne referenty, kolidujące między
  `config/ai.php` a `config/cvs-weights.php`. "Alert" obejmuje dwa niepowiązane mechanizmy
  wyzwalania (`AlertService` = zmiana rekomendacji, `PriceAlertService` = przekroczenie
  strefy ceny ATR). Wartość `'watchlist'` w `goldenSignal` koliduje z niepowiązaną nazwą
  funkcji Watchlist.
- **Niezmiennik #1 + agregat:** *"najnowszy snapshot CVS dla tickera musi być wierszem
  żywego `model_version`, nigdy shadow"* — dziś egzekwowane niezależnie przez **7 plików**
  w 4 namespace'ach (już spowodowało jeden bug produkcyjny, hotfix `442689d`). Proponowany
  agregat: `LiveCvsSnapshot`, jedyna usankcjonowana powierzchnia zapytań, czyniąca
  niezmiennik niemożliwym do złamania z nowego miejsca odczytu, zamiast udokumentowanym-i-
  liczonym-na-pamięć.
- **Anti-Corruption Layer:** surowe pola Yahoo *są* przemianowywane na wejściu
  (`FinancialDataFetcher::normalise()`), a parsery `Forecast/` są naprawdę dobrze
  odizolowane — ale przemianowany wynik zostaje nietypowaną tablicą bez zadeklarowanego
  kształtu, więc tłumaczenie istnieje bez wymuszonego kontraktu (ten sam fan-out 12 plików
  co dług techniczny #1 z artefaktu 3).

## 6. Decyzje, które należą do mnie

AI podpowiedziało *gdzie* szukać (analiza grep/git-log, konkretne liczby fan-in/fan-out,
kandydaci do refaktoryzacji) i zaproponowało trzy znaleziska kolizji nazewniczych oraz
jeden kandydacki agregat. Ja rozstrzygnąłem: które dwa refaktory faktycznie było warto
wdrożyć wcześniej (`ValuationMetrics`, `SnapshotWriter`) i dlaczego trzeci (deduplikacja
"najnowszego snapshotu") czekał na osobny plan zamiast jechać w tym samym commicie; że
kolizje nazewnicze ("model", "alert", `goldenSignal`) zostają udokumentowane, a nie od razu
przemianowane — bo koszt migracji nazw w danych już zserializowanych (cache, snapshoty)
przewyższa wartość natychmiastowej poprawki; oraz że `LiveCvsSnapshot` i typowany
kontrakt `$financials` to kandydaci na kolejny `/10x-plan`, nie zmiany wprowadzone
przy okazji pisania tego raportu.
