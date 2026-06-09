# CVS Engine Extend — Plan Brief

> Full plan: `context/changes/s-01-cvs-engine-extend/plan.md`
> Roadmap ref: `context/foundation/roadmap.md § S-01`

## What & Why

Dwa z czterech filarów PHP modelu CVS są dziś efektywnie niedziałające.
`SectorBenchmarkPillar` zawsze zwraca score = 50 (mediany sektorowe = null).
`PriceHistoryPillar` (52-week/200MA) nie dodaje wartości analitycznej.
Naprawiamy Sektor (EV/FCF z benchmarkami z Python v1.6) i zastępujemy PriceHistory
Momentum (ROC 6M+3M vs SPY) — aby model CVS faktycznie różnicował spółki.

## Starting Point

Działający 4-filarowy model PHP z `GrowthPillar` i `FundamentalQualityPillar` w dobrej
kondycji, ale `SectorBenchmarkPillar` zwracający stałe 50 (brak danych sektorowych)
i `PriceHistoryPillar` do zastąpienia. `FinancialDataFetcher` fetuje quoteSummary z Yahoo
Finance, ale brakuje mu: `assetProfile` (sector), chart endpoint (ceny historyczne), SPY.

## Desired End State

Zalogowany użytkownik wpisuje np. `AAPL MSFT NVDA`, klika "Analiza" i widzi ranking CVS
z rekomendacjami. Wszystkie 4 pillar scores są **różne od 50** — SectorBenchmarkPillar
używa EV/FCF vs hardkodowane benchmarki sektorowe, MomentumPillar liczy ROC 6M+3M vs SPY.
CVS poprawnie rozróżnia spółki drogie/tanie względem sektora i spółki z silnym/słabym
momentum. Kod wdrożony na cvs.timeflow.fun.

## Key Decisions Made

| Decision | Choice | Why | Source |
|---|---|---|---|
| Model scope | Extend 4-pillar PHP (nie port Python 1:1) | Mniej zmian, zachowuje Growth + Quality które działają | Plan |
| Sektor: metryka wyceny | EV/FCF (Wariant A) / EV/Sales (Wariant B) | Taki sam mechanizm jak przetestowany Python v1.6 | Plan |
| Sektor: źródło benchmarków | Hardkodowane w config (z Python BENCHMARKS dict) | Nie ma sektorowych median w Yahoo Finance API | Plan |
| Momentum | ROC 6M+3M vs SPY excess return | Identycznie jak Python Filar 2, przetestowany | Plan |
| Wagi | Zachowane 30/25/25/20 | Minimalna zmiana konfiguracji | Plan |
| Quality Gate | Bez zmian | Działa poprawnie, nie naruszamy | Plan |
| Deploy | Local dev → git push → git pull na CF | Bezpieczeństwo: test przed produkcją | Plan |

## Scope

**In scope:**
- `FinancialDataFetcher` — assetProfile module + chart endpoint + SPY
- `SectorBenchmarkPillar` — kompletne przepisanie (EV/FCF logic)
- `PriceHistoryPillar` → DELETE, `MomentumPillar` → NEW
- `CVSModel` — swap klasy i klucza weight (`history` → `momentum`)
- `config/cvs-weights.php` — sekcja `benchmarks` + sekcja `momentum`
- `CVSModelTest.php` — rozszerzenie fixture + 4 nowe testy
- `CLAUDE.md` — aktualizacja przestarzałej noty o null medianach
- `templates/analysis.php` — etykieta "Momentum"

**Out of scope:**
- GrowthPillar, FundamentalQualityPillar, QualityGate — bez zmian
- CVSResult, AnalysisController, routes.php — bez zmian
- Radar chart (S-02), detail panel (S-03) — osobne slice'y
- CI/CD pipeline

## Architecture / Approach

```
FinancialDataFetcher::fetch($ticker)
  → callApi() [quoteSummary + assetProfile]  → sector, shares_out, forward_eps, ...
  → fetchChartData($ticker, '3y')            → monthly_closes[]
  → fetchSpyCloses() [lazy, session cache]   → spy_closes[]
  → normalise($raw, $closes, $spy) → $financials[]

CVSModel::calculate($ticker, $financials)
  → QualityGate::evaluate()   [unchanged]
  → GrowthPillar::score()     [unchanged, 30%]
  → SectorBenchmarkPillar::score()  [REWRITTEN, 25%]
      EV/FCF or EV/Sales vs benchmarks[$sector]
  → MomentumPillar::score()   [NEW replaces PriceHistory, 25%]
      ROC 6M+3M vs SPY, excess return sigmoid
  → FundamentalQualityPillar::score() [unchanged, 20%]
  → CVS = weighted sum → CVSResult
```

## Phases at a Glance

| Phase | What it delivers | Key risk |
|---|---|---|
| 1. FinancialDataFetcher | `sector`, `monthly_closes`, `spy_closes` w normalized output | Yahoo Finance może zablokować chart endpoint (different domain) |
| 2. SectorBenchmarkPillar | Prawdziwe score dla Sektora (EV/FCF) | Brak FCF u wielu spółek → fallback Wariant B musi działać |
| 3. MomentumPillar | Prawdziwe score dla Momentum (ROC vs SPY) | Za mała historia cen (<7 mies.) → neutral 50 fallback |
| 4. Config + Tests | `phpunit` 100% zielone, CLAUDE.md aktualny | baseFinancials() fixture musi mieć wszystkie nowe pola |
| 5. Deploy | cvs.timeflow.fun z działającym modelem | Parity test może pokazać dużą rozbieżność vs Python (różna architektura) |

**Prerequisites:** PHP 8.2 lokalnie, `composer install` zrobiony, sesja na CF aktywna
**Estimated effort:** ~2–3 wieczory programowania (fazy 1–3 są niezależne prac, fazy 4–5 krótkie)

## Open Risks & Assumptions

- Yahoo Finance chart endpoint (`query1.finance.yahoo.com/v8/finance/chart/`) może wymagać
  innych nagłówków lub crumb niż quoteSummary — jeśli 403, trzeba będzie dodać cookies/crumb flow
- Dla Financial Services i Real Estate spółek: model działa, ale accuracy jest niższa
  (brak dedykowanych metryk dla tych sektorów — znana limitacja z Python v1.6)
- Parity test PHP vs Python nie da identycznych wyników (różna architektura pillarów,
  inny czas pobrania danych) — cel to ten sam próg rekomendacji, nie ten sam CVS score

## Success Criteria (Summary)

- `vendor/bin/phpunit --testdox` — wszystkie testy zielone (stare + 4 nowe)
- Analiza AAPL MSFT NVDA: `pillar_breakdown.sector` ≠ 50, `pillar_breakdown.momentum` ≠ 50
- cvs.timeflow.fun HTTP 200 po deploy, analiza spółek działa bez 500
