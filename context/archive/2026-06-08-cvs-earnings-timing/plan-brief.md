# CVS Earnings Timing Awareness (Faza 5, plaster 2) — Plan Brief

> Full plan: `context/changes/cvs-earnings-timing/plan.md`
> PRD: `context/foundation/prd.md` (FR-006..010, FR-015..019)

## What & Why

Model CVS jest dziś ślepy na **moment w cyklu wyników** — punktuje spółkę identycznie tuż przed
ogłoszeniem kwartalnych wyników i miesiąc po. To podbija ryzyko fałszywych sygnałów momentum w oknie
przed-wynikowym (zmienność napędzana oczekiwaniami, nie fundamentami) i tuż po (reakcja na nowe dane,
jeszcze nie „uspokojona"). Dodajemy deterministyczne „dni od/do wyników" jako wejście liczone przy
pobraniu oraz **earnings-proximity guard** — cieniową karę tłumiącą konwersję momentum w ~5-sesyjnym
oknie wokół daty wyników — plus widoczny dla użytkownika badge stanu.

## Starting Point

Po plastrze 1 (`cvs-overlay-penalties`, wdrożony na CF, SHA `3a7b279`) model liczy bazowy wynik 3.0
ORAZ cień 3.1 z dwoma overlayami (rewizja prognoz, cena-vs-target) — wzorzec `OverlayPenalties` +
`computeOverlay()` w `CVSModel`. `defaultKeyStatistics.mostRecentQuarter` jest już pobierane (niesparsowane);
`calendarEvents` (data następnych wyników) — nie. Migracja 014 poszerzyła UNIQUE snapshotów o `model_version`,
co umożliwia dual-write 3.0/3.1.

## Desired End State

`$financials` niesie deterministyczne `days_since_earnings`/`days_to_earnings` (liczone raz, przy pobraniu,
wstrzykiwane jako wejście — zero `date()`/`time()` w scoringu). `CVSResult` niesie dwa nowe addytywne bloki:
zawsze-obecny `earnings_timing` (badge: stan przed/po/w tranzycie + dni — działa niezależnie od shadow-mode)
i rozszerzony cień 3.1 z `penalties.earnings_guard` (kara tłumiąca w oknie K=5 sesji, symetrycznie).
Migracja 015 dokłada 4 kolumny do snapshotów; detal i screener pokazują jeden spójny chip stanu.

## Key Decisions Made

| Decision | Choice | Why | Source |
| --- | --- | --- | --- |
| Mechanizm guard'u | **Kara cieniowa post-agregacyjna** (jak overlay A/B z plastra 1) | Zero ryzyka regresji bazy 3.0; spójne z FR-016 i już wdrożonym wzorcem `computeOverlay` | Pytania |
| Okno K i symetria | **K=5 sesji, symetrycznie przed i po** | Zgodne z OQ-1 z shape-notes; najprostszy model mentalny do wytłumaczenia | Pytania |
| FR-006 (malejąca waga starych danych) | **Tylko sygnał/flaga pokrycia w `earnings_timing`** | Realna recalibracja wag wymaga danych historycznych z crona — dopiero plaster 4; tu tylko deklaratywny sygnał | Pytania |
| Migracja 015 — zakres kolumn | **4 kolumny**: `days_since/to_earnings`, `earnings_state`, `earnings_guard_active` | Kompletny zestaw do badge'a + guard + przyszłej rekalibracji bez kolejnej migracji | Pytania |
| Badge UI | **Jeden chip stanu** (przed/po/w tranzycie + dni), wzorzec `signal-pill` | Minimalny, spójny z istniejącym sygnałem na screenerze; łatwy do doszycia | Pytania |
| Brak danych Yahoo | **Cicho `null` + flaga `coverage`** (jak `eps_revision_pct`) | Spójne z konwencją parserów Phase 5 — brak kary/szumu za niewiedzę | Pytania |
| Przedział dat wyników | **Pierwsza (najwcześniejsza) data z `earningsDate[]`** | Konserwatywne — guard nie przegapi okna | Pytania |
| Kształt config guard'u | **`earnings_guard`: enabled/window_sessions/penalty(slope,cap)** | Spójny styl z `overlays`; łatwe do przestrojenia w plastrze rekalibracji | Pytania |
| Zakres testów | **Unit (parser + guard) + integracja CVSModel** | Pełne pokrycie wzdłuż granic odpowiedzialności, zgodne z `EarningsTrendParserTest`/`OverlayPenaltiesTest` | Pytania |
| Architektura `earnings_timing` | **Osobny, zawsze-obecny blok w `CVSResult`** (NIE zagnieżdżony w `overlay`) | Badge musi działać niezależnie od `overlays.enabled`/`earnings_guard.enabled` (FR-010 dla wszystkich użytkowników) | Plan |

## Scope

**In scope:** moduł Yahoo `calendarEvents` + czysty `EarningsCalendarParser` (dni od/do, fetch-time
reference date); `EarningsGuard` (stan + kara proximity-based, config `earnings_guard`); zawsze-obecny
blok `earnings_timing` w `CVSResult` (badge); rozszerzenie cienia 3.1 o `penalties.earnings_guard`;
migracja 015 (4 kolumny addytywne) + `CvsSnapshotRepository`; chip stanu na detalu i screenerze.

**Out of scope:** normalizacja FCF, rekalibracja progów/skali (osobne plastry); modyfikacja
`MomentumPillar`/wag agregacji; aktywacja 3.1 live; realna recalibracja wagi „starych" danych z FR-006
(dopiero z danymi z crona).

## Architecture / Approach

Czterofazowa kolejność odzwierciedla zależności danych: fetch & parse (moduł + czysty parser → `$financials`)
→ guard logic (config + `EarningsGuard` + dwa nowe bloki w `CVSResult`, jeden zawsze-obecny dla badge'a,
jeden gated w cieniu 3.1) → persystencja (migracja 015 + repo) → UI (chip, wzorzec `signal-pill`).
Determinizm: data odniesienia wyznaczana raz w `fetch()`, wstrzykiwana w dół jako parametr — parser i
guard to czysta arytmetyka na gotowych `int|null`, zero `date()/time()` w scoringu (FR-015).

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Fetch & parse | moduł `calendarEvents` + `EarningsCalendarParser` (dni od/do, fetch-time date) → `$financials` | `earningsDate` jako przedział / ujemne `days_to` (data-lag) — mityg.: jawne reguły parsera + testy graniczne |
| 2. Guard logic | config `earnings_guard` + `EarningsGuard` + zawsze-obecny `earnings_timing` + rozszerzony cień 3.1 | Pomieszanie badge z guard'em (regresja FR-010 bez shadow-mode) — mityg.: dwa rozdzielone bloki |
| 3. Persystencja | migracja 015 (4 kolumny addytywne) + `CvsSnapshotRepository::save()` | Błędna pozycja `AFTER` w DDL — mityg.: weryfikacja realnego układu kolumn przed pisaniem SQL |
| 4. UI | chip stanu na detalu + screenerze, wzorzec `signal-pill` | Badge na screenerze wymaga danych z DB (po migracji + rescore) — mityg.: kolejność faz to wymusza |

**Prerequisites:** stan repo po plastrze 1 (`model_version` 3.0 + cień 3.1, migracja 014, `computeOverlay`
jako wzorzec). **Estimated effort:** ~2 tygodnie po godzinach, 4 fazy (~3-4 sesje).

## Open Risks & Assumptions

- **`earningsDate` jako przedział dat** — bierzemy pierwszą datę konserwatywnie; jeśli realne dane Yahoo
  mają inny kształt niż zakładany, parser i jego testy będą wymagały korekty w Fazie 1.
- **Pokrętła guard'u ilustracyjne** (`slope`/`cap`/`window_sessions=5`) — finalne dopiero przy rekalibracji
  (plaster 4, OQ-1/OQ-2); shadow nazbiera dane do oceny rozkładu.
- **Pokrycie `calendarEvents`** — część spółek (zwłaszcza mniejszych) może nie mieć tych danych; badge i
  guard po prostu się nie aktywują (flaga `coverage`/`null`), zgodnie z konwencją.
- **Pozycja kolumn w migracji 015** — dokładny układ `AFTER` do potwierdzenia względem realnego DDL po 013/014.

## Success Criteria (Summary)

- `earnings_timing` (badge) obecny i poprawny niezależnie od stanu flag `overlays`/`earnings_guard.enabled`;
  działa dla wszystkich użytkowników, nie tylko w trybie cieniowym.
- Cień 3.1 odzwierciedla karę guard w oknie K=5 sesji (symetrycznie przed/po), deterministycznie, w testach;
  baza 3.0 i headline reco bez regresji (FR-016).
- Snapshoty niosą 4 nowe kolumny (FR-008); detal i screener pokazują spójny chip stanu (FR-010).
