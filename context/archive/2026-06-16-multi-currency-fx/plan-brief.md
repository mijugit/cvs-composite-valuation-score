# Multi-Currency FX Conversion — Plan Brief

> Full plan: `context/changes/multi-currency-fx/plan.md`

## What & Why

Spółki spoza USA (np. 000660.KS w KRW) mają dane finansowe i cenę w walucie macierzystej. Obecnie cena KRW jest pokazywana z zahardkodowanym `$` (mylące), a dla ADR-ów (cena ≠ waluta finansów, np. TSM USD/TWD) Enterprise Value miesza waluty → zatruty CVS score. Konwertujemy wszystko do USD w jednym seamie i pokazujemy cenę + Fair Value dwuwalutowo.

## Starting Point

`FinancialDataFetcher::normalise()` produkuje płaską tablicę `$financials` z ~15 polami monetarnymi w walucie natywnej; już ekstrahuje `currency` i `financial_currency`. Determinizm seam (`referenceDate` wstrzykiwany do `normalise()`) i kanał chart Yahoo (`fetchChartData`) są gotowe do wykorzystania. EV/FCF jest bezwymiarowe — więc czysto-zagraniczne spółki mają już poprawny *score*, psuje się tylko display; ADR-y mają zepsuty score.

## Desired End State

Analiza dowolnej spółki zagranicznej pokazuje CVS policzony na danych USD (poprawny też dla ADR), cenę bieżącą i Fair Value jako `$59.20 (₩79,500)` (USD wiodące, natywna w nawiasie), spójne USD w dashboard/screener/track-record. Spółki US bez zmian. Brak kursu FX dla nie-USD → spółka pomijana z komunikatem.

## Key Decisions Made

| Decision | Choice | Why | Source |
| --- | --- | --- | --- |
| Seam konwersji | `normalise()` (jeden punkt) | Cały downstream w USD bez dotykania pillarów | Plan |
| Źródło FX | Yahoo chart `{CCY}=X` | Zero nowych zależności/kluczy | Plan |
| Determinizm | Kurs wstrzykiwany jak `referenceDate` | Trzyma FR-015, snapshoty odtwarzalne | Plan |
| Brak kursu | Pomiń spółkę + komunikat | Nigdy nie pokazuj/zapisuj błędnych liczb | Plan |
| Snapshoty | +fx_rate, +native_currency, +native_price; price=USD | Audyt + spójność track-record | Plan |
| Istniejące dane | Grandfathering — naprawi rescore | Brak historycznego kursu do backfillu | Plan |
| Zakres pól | Wszystkie monetarne | Spójny UI, żadne pole nie zostaje w obcej walucie | Plan |
| ADR | Konwertuj finanse, cena już USD | Naprawia zatruty score ADR | Plan |
| Dual-display | USD wiodące, natywna w nawiasie | USD to wspólny mianownik aplikacji | Plan |
| model_version | Bump 3.0 → 4.0 | Czysty rozdział semantyki snapshotów | Plan |

## Scope

**In scope:** Fetch kursu FX + cache; konwersja pól monetarnych w `normalise()`; obsługa ADR; migracja snapshotów + bump wersji; Fair Value w USD; dual-currency display; rebuild peer_medians.

**Out of scope:** Backfill historii; osobne API FX; konwersja alertów/watchlisty poza zmianą ceny snapshotu; cache FX w Redis; waluty bez `{CCY}=X` (skip); wykres natywna-vs-USD.

## Architecture / Approach

Konwersja żyje wyłącznie w `normalise()`. Kurs FX pobierany raz w `fetch()`, wstrzykiwany do `normalise()` (jak `referenceDate`) → scoring pozostaje czystą funkcją. Pola natywne (`native_price`, `native_currency`, `fx_rate_to_usd`) dokładane do `$financials` do prezentacji/audytu. Downstream (pillary, ValuationMetrics, fair value, SnapshotWriter, peer_medians) operuje na USD bez świadomości waluty. Snapshoty zyskują kolumny waluty; bump `model_version` + pełny rescore odbudowuje peer_medians.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. FX fetch + seam | Kurs `{CCY}=X` pobierany i wstrzykiwany; pola waluty | Kierunek pary FX (USD/CCY vs CCY/USD) |
| 2. Konwersja | Pola monetarne w USD; native zachowane; ADR | Podwójna konwersja pól pochodnych |
| 3. Migracja + bump | Kolumny waluty w snapshotach; model_version 4.0 | Cold-start peer_medians po bumpie |
| 4. Fair Value + UI | FV w USD; dual-currency display | Mapowanie symboli walut; regresja US |
| 5. Rebuild + weryfikacja | Pełny rescore odbudowuje mediany | Okno cold-startu (score 50) do repopulacji |

**Prerequisites:** Dostęp do dev DB (migracja), dostęp SSH/deploy do produkcji, możliwość uruchomienia pełnego rescore.
**Estimated effort:** ~3-4 sesje przez 5 faz.

## Open Risks & Assumptions

- **Kierunek pary FX** — `{CCY}=X` zwraca USD/CCY; trzeba zweryfikować empirycznie dla KRW/EUR/JPY i ustalić jeden spójny `fx_rate_to_usd`.
- **Cold-start po bumpie** — bump 4.0 wywołuje cold-start peer_medians; wszystkie spółki dostają valuation 50 i znikają ze "latest" do czasu pełnego rescore (Faza 5 tuż po deployu).
- **ADR-ratio** — shares_outstanding może nie pasować do ceny ADR; sanity-bounds (0.05×–10×) jako zabezpieczenie, część ADR-ów może nadal być suppress przez bounds.
- **Yahoo FX availability** — zakładamy, że Yahoo wystawia `{CCY}=X` dla używanych walut; brak → skip spółki.

## Success Criteria (Summary)

- 000660.KS: poprawny CVS na danych USD + dual-display ceny i Fair Value (`$… (₩…)`)
- Spółki US bez regresji; ADR-y (TSM) z poprawnym score i widocznym Fair Value
- Po rescore: peer_medians pod 4.0, brak cold-startu, spójne USD w całej aplikacji
