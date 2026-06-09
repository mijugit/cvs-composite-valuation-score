---
project: CVS — Composite Valuation Score
version: 5
status: current
created: 2026-05-29
updated: 2026-06-09
current_model_version: "3.0"
next_model_version: "3.1"
---

# Roadmap: CVS — Composite Valuation Score

> Stan na 2026-06-09. Fazy 1–5 ukończone lub aktywne. Kolejna praca to Faza 5 plaster 3
> (cvs-fcf-normalization, model_version 3.1 w pełni) i rekalibracja progów.

## Vision

CVS to system sygnalizacyjny dla inwestorów detalicznych — ocenia spółki US (NYSE/NASDAQ)
kompozytowym wynikiem 0–100 w dwóch trybach (Swing 1–4M / Fundamentalny 6–12M), odkrywa
okazje (Screener), pilnuje ich za użytkownika (alerty) i wyjaśnia ludzkim językiem rozjazd
CVS vs analitycy (AI PRO). Cel: trafność i zaufanie — model świadomy zarówno poziomów
danych jak i ich trajektorii i świeżości.

---

## Faza 1 — MVP ✅ ukończona (2026-05-23)

Minimum viable: analiza pojedynczej spółki, auth, deploy na Cyber_Folks.

- Auth (email + hasło, sesje, CSRF, bcrypt)
- CVSModel v1 (3 filary × 2 tryby, wagi z config)
- FinancialDataFetcher (Yahoo Finance, cache SESSION)
- Front controller + Router + templates
- Deploy SSH + git na Cyber_Folks

---

## Faza 2 — Warstwa interpretacji i zaufania ✅ ukończona (2026-06-01)

**North star:** użytkownik PRO generuje analizę AI wyjaśniającą rozjazd CVS vs analitycy.

| ID   | Change ID              | Outcome                                              | Status   |
|------|------------------------|------------------------------------------------------|----------|
| F-01 | visual-template        | Lekki szablon wizualny, dark theme, design tokens    | **done** |
| F-02 | claude-api-client      | Klient Claude API (`src/Ai/`), typed-failure         | **done** |
| F-03 | transactional-email    | Serwis maili (PHPMailer/SMTP) — gotowy, nieużywany   | **done** |
| F-04 | daily-rescore-engine   | Cron re-scoring + tabela `cvs_snapshots`             | **done** |
| F-05 | pro-access             | Kody PRO per-user, brama, licznik API                | **done** |
| S-01 | ai-divergence-analysis | Analiza AI (shared cache, modal, 4-sekcyjny prompt)  | **done** |
| S-02 | model-track-record     | Track record CVS vs cena                             | **done** |
| S-03 | cvs-screener           | Screener z filtrami (sektor, próg, złoty sygnał)     | **done** |
| S-04 | watchlist-alerts       | Alerty mailowe na zmianie stanu watchlisty           | **done** |
| S-05 | pro-request-form       | Formularz prośby o kod PRO                           | **done** |
| X-01 | cvs-fair-value         | Implied fair value + żółta linia na fan chart        | **done** |
| X-02 | company-info-modal     | Modal informacji o spółce z Yahoo assetProfile       | **done** |
| X-03 | detail-page-ux         | Radar + wykres side-by-side, layout porządek         | **done** |

**Ad-hoc (2026-06-02):** tło atmosferyczne, favicon GD, glassmorphism cards, dashboard
accordion, etykieta wykresu "12M baza=100", tooltip SPY, README + CVS_opis.md.

---

## Faza 3 — Peer-group i model_version 3.0 ✅ ukończona (2026-06-05)

Refaktoryzacja silnika wyceny: zamiast benchmarków absolutnych — mediany **peer-group**
(podsektor → sektor) z tabeli `peer_medians`, zasilane cronem.

- `peer_medians` — tabela median EV/FCF i marży brutto per podsektor/sektor
- `ValuationPillar` — EV/FCF vs mediana peer-group (sigmoid k=3)
- `QualityPillar` — marża brutto vs mediana sektora
- `model_version` 3.0 w `config/cvs-weights.php` — snapshoty znaczone wersją
- Admin: `/admin/sectors` — ręczne odświeżanie median sektorowych (`admin-sector-refresh`)
- Screener + Track Record filtrowane po `model_version` (hotfix 2026-06-08: shadow rows)

---

## Faza 4 — Screener i Track Record (refinement) ✅ ukończona (2026-06-06)

Dopracowanie screenerów, poprawa widoków Track Record, rekalibracja punktów Quality Gate.
Change ID: `cvs-scoring-refinement`.

---

## Faza 5 — Świadomość trajektorii i czasu wyników 🔄 aktywna

Model przechodzi od oceny opartej na **poziomie** danych trailing/bieżących → do oceny
świadomej **trajektorii i świeżości**. Nowa `model_version` 3.1.

### Plaster 1 — Overlaye (model_version 3.1) ✅ ukończony (2026-06-07)

Change ID: `cvs-overlay-penalties`

- **Overlay A** — kara za rewizję prognoz (celowana: wysoka wycena × cięcie EPS)
- **Overlay B** — kara cena-vs-target (gdy cena > konsensus analityków)
- Shadow rows per (ticker, score_date) pod model_version 3.1 obok live 3.0
- `epsTrend` z Yahoo — realny kierunek rewizji EPS (90 dni)

### Plaster 2 — Świadomość czasu wyników ✅ ukończony (2026-06-08)

Change ID: `cvs-earnings-timing` (zarchiwizowany: `context/archive/2026-06-08-cvs-earnings-timing/`)

- `calendarEvents` — dni do następnych wyników (FR-007)
- `mostRecentQuarter` — dni od ostatnich wyników (FR-006)
- `EarningsGuard` — temperuje momentum w oknie przed-wynikowym (~5 sesji)
- Snapshoty wzbogacone o `days_since_earnings`, `days_to_earnings`, `earnings_state`, `earnings_guard_active`
- UI: badge "wyniki za N dni" / "po wynikach N dni temu" na detalu i screenerze

### Plaster 3 — Normalizacja FCF 🔄 w toku

Change ID: `cvs-fcf-normalization`

- `ValuationPillar` używa forward FCF z estymat zamiast trailing FCF (FR-011)
- Fix: trough-FCF cyklu inwestycyjnego (case MU) nie zawyża "drogości"
- Yahoo: `forwardEps` × FCF/EPS ratio jako proxy forward FCF

### Plaster 4 — Rekalibracja progów ⏳ planowana

Change ID: `cvs-scoring-refinement` (nowa iteracja po 3.1)

- Raport rozkładu CVS na pełnych `peer_medians` (FR-012)
- Rekalibracja progów 72/58/42/28 pod model_version 3.1 (FR-013)
- Aktywacja 3.1 jako live (zastąpienie 3.0)

---

## Infrastruktura CI/CD ✅ dodana (2026-06-09)

Change ID: `ci-cd-pipeline` — [context/changes/ci-cd-pipeline/](../changes/ci-cd-pipeline/)

GitHub Actions pipeline na każdy push/PR do `main`:

| Krok | Narzędzie | Blokuje? |
|------|-----------|---------|
| PHP 8.2 setup | shivammathur/setup-php | tak |
| composer install | Composer (z lock) | tak |
| testy jednostkowe | PHPUnit 11 — 340 testów | tak |
| statyczna analiza | PHPStan level 6 | tak |
| audit CVE | composer audit | nie (sygnalizuje) |

**CI bez CD** — deploy pozostaje ręczny przez `/MiJu-CF-Deploy`.
Powód: CF brak deploy API, brak stagingu, ryzyko automatycznego deployu na produkcję.

---

## Aktywne zmiany (context/changes/)

| Change ID                | Status       | Opis                                           |
|--------------------------|--------------|------------------------------------------------|
| cvs-overlay-penalties    | implemented  | Overlaye A+B, shadow rows model_version 3.1   |
| cvs-fcf-normalization    | implemented  | Forward FCF w ValuationPillar (FR-011)        |
| cvs-scoring-refinement   | in-progress  | Rekalibracja skali + Faza 4 refinements       |
| admin-sector-refresh     | implemented  | Admin UI odświeżania median sektorowych       |

---

## Backlog / następne kroki

1. **Aktywacja model_version 3.1** — po rekalibracji progów (plaster 4); zmiana 1 linii w config
2. **ATVI/K/SQ cleanup** — usunięcie delisted tickers z peer group (niska priorytet)
3. **Staging subdomain** — `staging.cvs.timeflow.fun` (wg risk register z infrastructure.md)
4. **php-cs-fixer** — formatter PSR-12 (opcjonalne ulepszenie)

---

## Parked (świadome — poza planem)

- Portfel / holdings — broker wystarcza; narzędzie sygnalizuje, nie księguje
- Self-service PRO / płatności — admin wydaje kody ręcznie
- AI modyfikujące model CVS — AI tylko interpretuje; rdzeń deterministyczny
- Scoring pełnego uniwersum ~600+, dane intraday, rynki spoza US
- Anonimowy/publiczny dostęp — konto wymagane przed każdym wynikiem
