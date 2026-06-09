# CVS Overlay Penalties (Faza 5, plaster 1) — Plan Brief

> Full plan: `context/changes/cvs-overlay-penalties/plan.md`
> PRD: `context/foundation/prd.md` (FR-001..005, FR-015..019)

## What & Why

Model CVS jest ślepy na **trajektorię** — czyta poziom danych trailing/bieżących, nie kierunek prognoz ani
relację ceny do konsensusu. Skutek (zwalidowany na żywych spadkach 5.06 + symulacji): pułapki wartości
punktowane wysoko (NVO), spółki notowane powyżej targetu oznaczane „kupuj". Dodajemy dwa overlaye —
**rewizja prognoz** i **cena-vs-target** — jako deterministyczne kary post-agregacyjne.

## Starting Point

Po fazie 3 model = `model_version` 3.0 (peer-group). `CVSModel::calculate()` agreguje filary i mapuje na
rekomendację; `ForecastParser` już liczy `upside`; `FinancialDataFetcher` pobiera 8 modułów Yahoo (bez
`earningsTrend`). `sim_overlay.php` to działająca specyfikacja kar, zwalidowana na NVO/AVGO/QCOM/MU.

## Desired End State

Model liczy wynik bazowy 3.0 (dalej pokazywany) ORAZ wynik **cieniowy 3.1** z karami; `CVSResult` niesie
rozbicie kar i flagi pokrycia, rescore zapisuje snapshoty 3.0 i 3.1 równolegle, a detal pokazuje chip
„Podgląd 3.1: −X pkt". Rekomendacja headline pozostaje 3.0 do rekalibracji (guardrail).

## Key Decisions Made

| Decision | Choice | Why | Source |
| --- | --- | --- | --- |
| Aktywacja 3.1 vs guardrail FR-016 | **Tryb cieniowy** (compute+persist 3.1, pokazuj 3.0) | Honoruje „rekalibracja przed live" i nazbiera dane 3.1 | Plan |
| Sygnał rewizji (Overlay A) | `epsTrend` **+1q**, current vs 90daysAgo | Reaktywny sygnał kwartalny | Plan |
| Przejrzystość | Rozbicie kar w `CVSResult` + chip na detalu | Realizuje Secondary criterion, tłumaczy obniżki | Plan |
| Braki danych | **No-op (kara 0) + flaga niskiego pokrycia** | Spójne z konwencją filarów, brak kary za niewiedzę | Plan |
| Zakres plastra | **Tylko A+B + infra shadow** | Ciasne ~2 tyg, zgodne z kolejnością PRD | Plan |
| Kształt kar | Sygnatury 1:1 z `sim_overlay.php` | Walidacja już przeprowadzona | Plan |

## Scope

**In scope:** Overlay A (rewizja) + B (target); moduł `earningsTrend` + czysty parser +1q; przepływ `upside`;
`OverlayPenalties`; wpięcie cieniowe w `CVSModel`; rozbicie w `CVSResult`; shadow persistence (migracja +
rescore); chip 3.1 na detalu; testy złote z sim.

**Out of scope:** earnings-proximity guard, normalizacja FCF, rekalibracja progów (osobne plastry); aktywacja
3.1 live; zmiany Momentum/auth/UX/peer-group.

## Architecture / Approach

Czysta warstwa post-agregacyjna. `FinancialDataFetcher` → `$financials` zyskuje `eps_revision_pct`
(z `EarningsTrendParser`) i `analyst_target_upside` (z `ForecastParser`). `CVSModel` po agregacji bazowej woła
`OverlayPenalties::revision/targetGate`, buduje wynik 3.1 i wkłada blok cienia do `CVSResult`. Persystencja i UI
czytają blok cienia; baza 3.0 nietknięta. Zero `date()/time()` → determinizm rdzenia zachowany.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Fundament | config (pokrętła + shadow_version) + `CVSResult` blok cienia | Regresja kontraktu CVSResult (mityg.: addytywnie) |
| 2. Wejścia danych | moduł `earningsTrend` + czysty parser +1q + `upside` do modelu | epsTrend null przy granicy kwartału → graceful no-op |
| 3. Silnik + testy złote | `OverlayPenalties` + wpięcie cieniowe + testy z sim | Rozjazd kod↔sim (mityg.: testy złote 1:1) |
| 4. Shadow persistence + UI | migracja UNIQUE + dual-write 3.0/3.1 + chip na detalu | Kolizja UNIQUE snapshotów (mityg.: migracja 014) |

**Prerequisites:** stan repo po fazie 3 (`model_version` 3.0, peer-group); `sim_overlay.php` jako spec.
**Estimated effort:** ~2 tygodnie po godzinach, 4 fazy (~3–4 sesje).

## Open Risks & Assumptions

- **Forward FCF (FR-011) NIE w tym plastrze** — MU pozostaje „drogo" do plastra normalizacji FCF; to świadome.
- **Pokrętła ilustracyjne** (slope/cap z sim) — finalne przy rekalibracji (OQ-2); shadow nazbiera dane do oceny rozkładu.
- **`earningsTrend` pokrycie** — część spółek bez +1q/90daysAgo → Overlay A no-op (flaga pokrycia).
- **Migracja UNIQUE** — zmiana klucza snapshotów; zakładamy brak innych zależności od `uq_ticker_day` poza repo.

## Success Criteria (Summary)

- Wynik cieniowy 3.1 odtwarza `sim_overlay.php`: NVO schodzi *SILNE KUPUJ→AKUMULUJ* (swing→NEUTRALNIE),
  AVGO nietknięte, QCOM/MU o szczebel niżej — deterministycznie, w testach.
- Baza 3.0 i istniejące widoki bez regresji; headline reco niezmieniony.
- Snapshoty 3.0 i 3.1 współistnieją; detal pokazuje przejrzysty podgląd 3.1.
