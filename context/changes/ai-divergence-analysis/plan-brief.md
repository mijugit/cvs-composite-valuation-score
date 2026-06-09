# S-01: Analiza AI — rozjazd CVS vs analitycy — Plan Brief

> Full plan: `context/changes/ai-divergence-analysis/plan.md`

## What & Why

North Star fazy 2: user PRO generuje analizę AI wyjaśniającą **dlaczego
model CVS mówi co innego niż analitycy Wall Street** i komu wierzyć w jakim
horyzoncie. Bez tego wyjaśnienia kontrariański wynik CVS (np. "przewartościowane"
przy konsensie "Kupuj") wygląda jak błąd modelu, nie jak cecha.

## Starting Point

Wszystkie prerekwizyty gotowe: `ClaudeClient` (F-02), `ProGate` + `AiUsageRepository`
(F-05), `$canGenerateAi` + `$aiUsage` w widoku (F-05), pełne dane analityków
w `$financials['forecast']` (Yahoo Finance). Brakuje tylko shared cache analiz,
serwisu AI z promptem i UI.

## Desired End State

User PRO klika przycisk na `/analysis/{ticker}`, widzi modal z animowanym
komunikatem przez ~20s, następnie narracja 4-sekcyjna (PL) pojawia się pod
prognozami analityków. Każdy zalogowany user widzi tę samą narrację przez ~7
dni. PRO może odświeżyć co 24h. Awaria Claude API nie psuje strony CVS.

## Key Decisions Made

| Decyzja | Wybór | Dlaczego |
|---|---|---|
| 4 sekcje narracji | CVS Assessment / Analyst View / Divergence / Who to Trust | Wprost odpowiada na pytanie "dlaczego model vs rynek" |
| Loading UX | Modal z animowanym tekstem (3 etapy co ~7s) | Zero SSE, żywa informacja, wystarczy dla ~10 userów |
| Force refresh | PRO-only, min 24h od poprzedniej (configurable) | Blokuje przepalanie API przy aktywnym użyciu |
| Pozycja UI | Po .forecast-card (pod prognozami analityków) | Naturalny flow: dane → synteza AI |
| Język | Prompt EN, odpowiedź PL | Claude lepszy w EN; UI po polsku |
| Dane w prompcie | Kompletne (CVS dual + pilary + analyst targets + konsensus) | AI może zbudować hipotezę na realnych liczbach |
| Anti-hallucination | Guardrail w system prompt ("ONLY provided data") | PRD FR-001: narracja uziemiona w danych |
| Cache freshness | 7 dni (tabela ai_analyses, UNIQUE ticker) | FR-002; data zawsze widoczna |
| Shared cache | Per ticker, widoczny dla wszystkich logged-in | FR-002: koszt API per ticker, nie per user |

## Scope

**In scope:** `008_create_ai_analyses.sql`, `AiAnalysisRepository`,
`AiDivergenceService` (prompt builder + Claude call), `AiAnalysisController`
(endpoint + cache logic), route, `analysis.php` updates (button + modal + section),
`app.css` modal styles, `AnalysisController::show()` z cached AI.

**Out of scope:** Streaming (SSE), prywatne analizy per-user, web-browsing przez AI,
track record trafności (S-02), screener (S-03), alerty (S-04).

## Architecture / Approach

```
POST /analysis/{ticker}/generate-ai
  └─ AiAnalysisController::generate()
       ├─ ProGate::canGenerate()     ← F-05
       ├─ AiAnalysisRepository::isFresh()  ← nowe (Phase 1)
       ├─ AiDivergenceService::generate()  ← nowe (Phase 2)
       │    ├─ buildSystemPrompt() → CacheableSystem (stable, cached)
       │    └─ buildUserMessage(ticker, cvsResult, financials)
       │         → ClaudeClient::sendMessage()  ← F-02
       ├─ AiAnalysisRepository::save()
       └─ AiUsageRepository::log()  ← F-05

analysis.php (GET)
  └─ AnalysisController::show()
       ├─ AiAnalysisRepository::findByTicker()
       └─ → $cachedAi, $aiCanRefresh do widoku
```

## Phases at a Glance

| Faza | Dowozi | Kluczowe ryzyko |
|---|---|---|
| 1. Migracja + Repo | ai_analyses table, AiAnalysisRepository (isFresh/save/needsRefresh) | Addytywna migracja — brak ryzyka |
| 2. AiDivergenceService | Prompt builder + Claude call + testy z FakeTransport | Jakość prompta — adresuje rozjazd czy jest generyczna? |
| 3. Controller + UI | Endpoint + modal + render narracji | AJAX timing, CSRF, graceful degradation |

**Prerequisites:** F-01 ✅, F-02 ✅, F-05 ✅ (wszystkie prerekwizyty spełnione).
**Estimated effort:** ~2-3 sesje, 3 fazy.

## Open Risks & Assumptions

- Jakość narracji zależy od promptu — wymaga testowego wywołania i oceny
  przed wdrożeniem (manual verification Phase 2).
- Null forecast (brak danych analityków Yahoo) — prompt musi obsługiwać
  gracefully; test z tickerami bez coverage analityków.
- Claude API latency — ClaudeClient ma total_timeout 25s; może być ciasno
  dla długich odpowiedzi; max_tokens w config/ai.php może wymagać tuningul.

## Success Criteria (Summary)

- User PRO generuje narrację 4-sekcyjną PL wyjaśniającą rozjazd CVS vs analitycy.
- Analiza widoczna dla non-PRO z cache (z datą), bez możliwości regeneracji.
- Awaria Claude API → graceful error, strona CVS działa normalnie.
