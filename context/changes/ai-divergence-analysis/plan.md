# S-01: Analiza AI — rozjazd CVS vs analitycy — Implementation Plan

## Overview

User PRO klika „Generuj analizę AI" na `/analysis/{ticker}`. System buduje
prompt EN z danych CVS + analityków, wysyła do Claude API przez istniejący
`ClaudeClient`, zwraca 4-sekcyjną narrację PL wyjaśniającą rozjazd między
oceną modelu CVS a oceną analityków Wall Street. Analiza trafia do
współdzielonego cache (`ai_analyses`), jest widoczna dla wszystkich
zalogowanych userów przez ~7 dni; user PRO może odświeżyć co 24h.
Guardrail: awaria AI nie psuje strony — CVS i prognozy działają normalnie.

## Current State Analysis

**Gotowe (prerekwizyty spełnione):**
- `ClaudeClient::sendMessage(messages, ?CacheableSystem, options): AiResult`
  — F-02, `src/Ai/ClaudeClient.php:40`; nigdy nie rzuca, typed failure.
- `CacheableSystem` — wrapper dla prompt cachingu, TTL 5m/1h.
- `ClaudeClientFactory::fromConfig(array $config): ClaudeClient` — buduje klienta.
- `ProGate::canGenerate(userId): bool` i `getSessionCode(): string` — F-05.
- `AiUsageRepository::log(userId, code, tokensIn, tokensOut)` — F-05.
- `$canGenerateAi` + `$aiUsage` w `AnalysisController::show()` i widoku — F-05.
- `$financials['forecast']` — pełne dane analityków z Yahoo Finance,
  w tym `targets.mean/median/high/low/upside`, `recommendation_mean`,
  `num_analysts`, `latest` (rozkład buy/hold/sell), `trend`.
- `config/ai.php` z limitami PRO i kluczem Anthropic.

**Czego brakuje:**
- Tabela `ai_analyses` (shared cache per ticker).
- `AiAnalysisRepository` — findFreshByTicker, save, needsRefresh.
- `AiDivergenceService` — buduje prompt, wywołuje Claude, przetwarza wynik.
- `AiAnalysisController` — endpoint POST + logika cache/gate.
- UI w `analysis.php` — przycisk, modal z animacją, sekcja narracji.

## Desired End State

- Zalogowany user widzi na `/analysis/{ticker}` sekcję „Analiza AI" pod
  prognozami analityków: jeśli cache świeży (<7 dni) — pokazuje narrację z datą.
- User PRO widzi przycisk „Generuj analizę AI" (jeśli brak lub stara).
- Klikając, widzi modal z animowanym tekstem (3 etapy co ~7s) i spinnerem.
- Po ~15-25s modal zamyka się, strona pokazuje narrację (4 sekcje).
- User PRO z aktywną analizą (<24h) widzi „Odśwież" zamiast „Generuj".
- Nie-PRO widzi istniejącą analizę z cache (bez możliwości generowania).
- Awaria API: komunikat „Analiza AI niedostępna — spróbuj za chwilę", strona działa.

### Key Discoveries

- `ClaudeClient` używa `$_SESSION['pro_code']` pośrednio przez ProGate —
  ale sam klient nie zna sesji; AiUsageRepository::log() wywołuje kontroler.
- `CacheableSystem` — system prompt to stabilny tekst (rola eksperta, instrukcje
  formatu); user message = dane per-ticker. Prompt caching działa dzięki tej split.
- Forecast data w `$financials['forecast']` — może być null jeśli Yahoo nie
  zwróciło danych; prompt musi obsługiwać brakujące pola gracefully.
- `recommendation_mean` na skali Yahoo 1-5 (1=Strong Buy, 3=Hold, 5=Strong Sell)
  — konwersja do etykiety tekstowej potrzebna w prompcie.
- Modal z animacją: JS `setInterval` co ~7s zmienia tekst; po odpowiedzi
  clearInterval i renderuje narrację bez reload strony.
- `config/ai.php → pro.refresh_min_hours` — nowy klucz (default 24) dla
  limitu odświeżania.

## What We're NOT Doing

- Web-browsing przez AI — Claude nie ma dostępu do internetu; narracja
  jest uziemiona WYŁĄCZNIE w danych przekazanych w prompcie.
- Streaming odpowiedzi (SSE) — prosty wait z animowanym tekstem wystarczy.
- Prywatnych analiz per-user — cache jest współdzielony (per ticker).
- Trackingu trafności predykcji AI — to S-02 (track record).
- Persystowania logu użycia AI dla admina — `ai_usage_log` (F-05) wystarczy.
- Zmiany istniejącej logiki CVS ani wykresu ceny.

## Implementation Approach

3 fazy: (1) dane — migracja + repo, (2) logika — serwis AI z promptem,
(3) integracja — controller + UI. Każda faza jest niezależnie testowalna.
Prompt jest EN po stronie systemu (stabilny, cacheable) + EN po stronie user
message (dane); odpowiedź PL narzucona przez instrukcję.

## Critical Implementation Details

**Grounding instrukcja w prompcie:** system prompt musi zawierać jawną regułę:
"Base your analysis ONLY on the numerical data provided below. Do not speculate
about, invent, or reference any financial facts not present in this data."
To jest główna ochrona przed halucynacją.

**CacheableSystem split:** stabilna część (rola eksperta, 4-sekcyjna struktura,
język PL, guardrails) idzie do system prompt (cache'owana). Dane per-ticker
(CVS scores, pilary, forecast) idą do user message (nie cache'owane). Dzięki
temu każde kolejne wywołanie Claude z tym samym system promptem korzysta
z cache (oszczędność tokenów).

**Null forecast:** Yahoo Finance nie zawsze zwraca prognozy. Prompt musi
obsługiwać `null` gracefully — np. "No analyst data available for this ticker"
w odpowiedniej sekcji, zamiast próbować interpretować puste dane.

---

## Phase 1: Migracja + AiAnalysisRepository

### Overview

Tabela `ai_analyses` jako shared cache analiz per ticker oraz repozytorium
do zarządzania tym cache.

### Changes Required

#### 1. Migracja: tabela ai_analyses

**File:** `database/migrations/008_create_ai_analyses.sql`

**Intent:** Shared cache analiz AI — jeden wiersz per ticker (nadpisywany
przy odświeżeniu). Zawiera treść narracji, metadata tokeny, datę i autora.

**Contract:**
```sql
CREATE TABLE IF NOT EXISTS ai_analyses (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    ticker          VARCHAR(20)   NOT NULL,
    content         TEXT          NOT NULL,
    model           VARCHAR(80)   NULL,
    tokens_input    INT UNSIGNED  NOT NULL DEFAULT 0,
    tokens_output   INT UNSIGNED  NOT NULL DEFAULT 0,
    generated_by    INT UNSIGNED  NULL,
    generated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ticker (ticker),
    INDEX idx_generated_at (generated_at)
)
```

#### 2. AiAnalysisRepository

**File:** `src/Ai/AiAnalysisRepository.php` (namespace `CVS\Ai`)

**Intent:** Jedyne miejsce w kodzie czytające i piszące cache analiz AI.
Używane przez AiAnalysisController do sprawdzania/zapisywania.

**Contract:** Klasa z PDO injection. Metody:
- `findByTicker(string $ticker): ?array` — ostatnia analiza dla tickera lub null.
- `isFresh(string $ticker, int $days = 7): bool` — czy analiza istnieje
  i ma mniej niż $days dni.
- `needsRefresh(string $ticker, int $minHours = 24): bool` — czy analiza
  ma co najmniej $minHours godzin (PRO może odświeżyć).
- `save(string $ticker, string $content, string $model, int $tokensIn,
  int $tokensOut, ?int $generatedBy): void` — INSERT OR UPDATE przez
  ON DUPLICATE KEY (MySQL) / fallback (SQLite) — ten sam wzorzec co
  CvsSnapshotRepository.

#### 3. Nowy klucz konfiguracyjny

**File:** `config/ai.php`

**Intent:** Dodać limit min-age odświeżania w config, zgodnie z zasadą
FR-010 (brak hardcodowanych wartości).

**Contract:** Dodać do sekcji `'pro'`:
`'refresh_min_hours' => (int) ($_ENV['AI_PRO_REFRESH_MIN_HOURS'] ?? 24)`

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony (testy AiAnalysisRepository: findByTicker, isFresh, needsRefresh, save idempotent)
- `vendor/bin/phpstan analyse` zielony

#### Manual Verification
- Migracja 008 wykonana na CF: `SHOW CREATE TABLE ai_analyses` — tabela z UNIQUE(ticker)

---

## Phase 2: AiDivergenceService

### Overview

Serwis AI — buduje prompt EN z danych CVS + analityków, wywołuje Claude API,
zwraca ustrukturyzowany wynik lub typed failure.

### Changes Required

#### 1. AiDivergenceService

**File:** `src/Ai/AiDivergenceService.php` (namespace `CVS\Ai`)

**Intent:** Jedyny serwis generujący narrację AI; izoluje całą logikę
prompt-buildingu i wywołania Claude od controllera i widoku.

**Contract:** Klasa z konstruktorem przyjmującym `ClaudeClient` i array `$config`
(z config/ai.php). Jedna publiczna metoda:

```php
public function generate(
    string $ticker,
    array  $cvsResult,   // CVSResult::toArray()
    array  $financials   // z FinancialDataFetcher::fetch()
): AiResult
```

Wewnętrzna struktura:
- `buildSystemPrompt(): CacheableSystem` — rola eksperta inwestycyjnego,
  instrukcja 4-sekcyjna, wymóg PL, guardrail anty-halucynacyjny (ONLY use
  provided data), disclaimer. Stabilna → cacheable (TTL_5M).
- `buildUserMessage(string $ticker, array $cvsResult, array $financials): string`
  — formatuje dane: CVS swing/fund z rekomendacjami, 4 pilary z wynikami,
  dane analityków (targets.mean/high/low/upside, num_analysts,
  recommendation_mean jako label, latest buy/hold/sell counts). Jeśli
  forecast null → "No analyst data available".
- Wywołuje `$this->client->sendMessage([['role'=>'user','content'=>$msg]], $system)`
- Zwraca `AiResult` bez modyfikacji (kontroler obsługuje failure).

**Struktura 4 sekcji w prompcie (nagłówki EN, treść PL):**
```
## CVS Model Assessment
## Market (Analyst) View
## Divergence Analysis
## Who to Trust and When
```

**Prompt guardrails:**
```
IMPORTANT: Base your analysis ONLY on the numerical data provided below.
Do not speculate about, invent, or reference any financial facts not present
in this data. If a data point is missing, acknowledge the absence instead
of assuming a value.
```

#### 2. Test AiDivergenceService (unit)

**File:** `tests/Ai/AiDivergenceServiceTest.php`

**Intent:** Weryfikacja że:
- `generate()` z FakeTransport success → AiResult::ok === true, text nie pusty.
- `generate()` z FakeTransport failure → AiResult::ok === false.
- Prompt zawiera ticker, CVS scores, kluczowe dane analityków.
- Prompt zawiera guardrail "ONLY on the numerical data provided".
- Null forecast → prompt zawiera "No analyst data available".

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony (testy AiDivergenceService z FakeTransport)
- `vendor/bin/phpstan analyse` zielony

#### Manual Verification
- Wywołanie testowe przez SSH: `php -r` snippet wywołujący serwis z prawdziwym klientem → log response (weryfikacja jakości narracji)

---

## Phase 3: Controller + Route + UI

### Overview

Endpoint AJAX, integracja ProGate + cache, modal z animacją, sekcja narracji
w analysis.php.

### Changes Required

#### 1. AiAnalysisController

**File:** `src/Ai/AiAnalysisController.php` (namespace `CVS\Ai`)

**Intent:** Obsługa `POST /analysis/{ticker}/generate-ai` — waliduje dostęp PRO,
sprawdza/aktualizuje cache, wywołuje serwis AI, loguje zużycie.

**Contract:**
```php
public function generate(Request $req): void
```
Przepływ:
1. `AuthController::requireAuth()`
2. `$req->verifyCsrf()` → 403 json na fail
3. `$ticker = strtoupper($req->param('ticker', ''))`
4. Załaduj ProGate, UserRepository; `$userId = $_SESSION['user_id']`
5. `$gate->canGenerate($userId)` → false: `Response::json(['ok'=>false, 'message'=>'...'], 403)`
6. `$isForceRefresh = (bool) $req->input('force', false)` (tylko PRO)
7. Jeśli !$isForceRefresh i `$repo->isFresh($ticker)` → zwróć cached: `['ok'=>true, 'cached'=>true, 'content'=>..., 'generated_at'=>...]`
8. Jeśli $isForceRefresh i `!$repo->needsRefresh($ticker, $aiConfig['pro']['refresh_min_hours'])`:
   → `Response::json(['ok'=>false, 'message'=>'Odświeżenie możliwe co 24h.'], 429)`
9. Pobierz `$financials = $fetcher->fetch($ticker)` → null: 503 json
10. `$cvsResult = $model->calculate($ticker, $financials)->toArray()`
11. `$result = $service->generate($ticker, $cvsResult, $financials)`
12. Jeśli `!$result->ok` → `Response::json(['ok'=>false, 'message'=>'Analiza AI niedostępna — spróbuj ponownie za chwilę.'], 503)`
13. `$repo->save($ticker, $result->text, $result->model ?? '', $usage->inputTokens, $usage->outputTokens, $userId)`
14. `$usageRepo->log($userId, $gate->getSessionCode(), $usage->inputTokens, $usage->outputTokens)`
15. `Response::json(['ok'=>true, 'cached'=>false, 'content'=>$result->text, 'generated_at'=>date('Y-m-d H:i')])`

#### 2. Route

**File:** `src/Core/routes.php`

**Contract:** Dodać w sekcji PRO:
`$router->post('/analysis/{ticker}/generate-ai', fn($req) => $ai->generate($req));`

#### 3. AnalysisController::show() — przekazanie cached analizy

**File:** `src/CVS/AnalysisController.php`

**Intent:** Przekazać do widoku istniejącą analizę z cache (jeśli świeża)
i informację czy PRO może odświeżyć — widok pokazuje ją bez generowania.

**Contract:** W `show()` dodać:
```php
$aiRepo    = new AiAnalysisRepository();
$aiConfig  = require dirname(__DIR__, 2) . '/config/ai.php';
$cachedAi  = $aiRepo->findByTicker($ticker);
$aiIsFresh = $aiRepo->isFresh($ticker, 7);
$aiCanRefresh = $gate->canGenerate($userId)
    && $cachedAi
    && $aiRepo->needsRefresh($ticker, $aiConfig['pro']['refresh_min_hours']);
```
I przekazać do widoku: `'cachedAi' => $aiIsFresh ? $cachedAi : null`,
`'aiCanRefresh' => $aiCanRefresh`.

#### 4. UI — sekcja AI w analysis.php

**File:** `templates/analysis.php`

**Intent:** Po `.forecast-card` dodać sekcję AI z: cached narracja (dla
wszystkich zalogowanych gdy świeża), przycisk „Generuj AI" (PRO only gdy brak
lub stara), przycisk „Odśwież" (PRO only gdy $aiCanRefresh).

**Contract:**
- Sekcja `div.card.ai-analysis-card` po forecast-card (lub po disclaimerze
  jeśli brak forecast).
- Jeśli `$cachedAi`: render `div.ai-narrative` z narracji + data + disclaimer.
- Jeśli `$canGenerateAi` i !$cachedAi: przycisk `btn--primary` „Generuj analizę AI".
- Jeśli `$aiCanRefresh`: przycisk `btn--ghost btn--sm` „Odśwież analizę".
- Modal `#ai-modal`: overlay z `div.ai-modal__inner`, spinner CSS, div
  z tekstem animowanym (`#ai-modal-status`), przycisk Anuluj.
- Sekcja `#ai-result` (hidden): gdzie JS wstrzykuje wygenerowaną narrację.

#### 5. JS obsługa modalu i AJAX

**File:** `public/js/app.js` (lub osobny blok `<script>` w analysis.php)

**Intent:** Klika przycisk → modal → AJAX → animacja (3 etapy co ~7s) → render.

**Contract:** Inline `<script>` w analysis.php (izolowane od dashboard JS):
- `showAiModal()` — pokazuje overlay, startuje `setInterval(rotateStatus, 7000)`
  z array `['Pobieranie danych…', 'Analizuję CVS vs analitycy…', 'Piszę raport…', 'Finalizuję…']`
- AJAX `POST /analysis/{ticker}/generate-ai` z CSRF + `force` param
- `hideAiModal()` — clearInterval, ukrywa overlay
- Na sukces: `renderAiAnalysis(data.content, data.generated_at)`
  — wstrzyknij HTML do `#ai-result`, pokaż sekcję, ukryj przyciski generowania
- Na błąd: pokaż alert--error z `data.message`

#### 6. CSS dla modalu AI

**File:** `public/css/app.css`

**Intent:** Style dla overlay modalu i narracji AI (view-specific, nie
w components.css).

**Contract:** `.ai-modal` (fixed overlay), `.ai-modal__inner` (centered card),
`.ai-modal__spinner` (CSS keyframe animation), `#ai-modal-status` (tekst),
`.ai-narrative` (prose styling: section headers, line-height), `.ai-analysis-card`
(card variant z subtle border).

### Success Criteria

#### Automated Verification
- `vendor/bin/phpunit` zielony (brak regresji, testy AiAnalysisController z FakeTransport)
- `vendor/bin/phpstan analyse` zielony
- Trasa `POST /analysis/{ticker}/generate-ai` zarejestrowana w routes.php

#### Manual Verification
- Zalogowany non-PRO na `/analysis/AAPL` — widzi sekcję AI bez przycisku generowania
- User PRO (aktywny kod) — widzi przycisk „Generuj analizę AI", klikając → modal z animacją
- Po generacji: narracja 4-sekcyjna PL renderuje się w sekcji AI, data widoczna
- Non-PRO na tej samej stronie po wygenerowaniu — widzi narrację z cache
- User PRO po <24h — zamiast „Generuj" widzi „Odśwież" (jeszcze niedostępne → 429)
- Błąd API (wyłącz klucz tymczasowo) → komunikat graceful, strona działa
- Disclaimer widoczny przy narracji AI
- `ai_usage_log` + `ai_analyses` — nowe wiersze po generacji na CF

---

## Testing Strategy

### Unit Tests

- `AiAnalysisRepository`: save idempotent (INSERT + UPDATE), findByTicker, isFresh, needsRefresh
- `AiDivergenceService` (FakeTransport): success path, failure path, prompt contains ticker/CVS/analyst data/guardrail, null forecast path

### Manual Testing Steps

1. Deploy + migracja 008 na CF
2. Panel `/admin/pro` → dodaj/zweryfikuj aktywny kod
3. Zaloguj jako PRO → `/analysis/AAPL` → kliknij „Generuj" → obserwuj modal
4. Sprawdź narrację: czy 4 sekcje? Czy adresuje rozjazd? Czy po polsku?
5. Zaloguj jako non-PRO → ta sama strona → narracja widoczna bez przycisku
6. Sprawdź: `SELECT ticker, generated_at, tokens_input FROM ai_analyses` na CF
7. Kliknij „Odśwież" < 24h → komunikat 429
8. Wymuś błąd API (ustaw zły klucz) → graceful error, strona działa

## Performance Considerations

Jedna rozmowa Claude to ~15-25s (istniejący guardrail w ClaudeClient). Cached
odpowiedź (isFresh) zwraca natychmiast z DB. Prompt caching przez CacheableSystem
redukuje koszt tokenów kolejnych wywołań. Dwa dodatkowe SELECT per render
`show()` (findByTicker + isFresh) — pomijalny koszt.

## Migration Notes

Migracja `008_create_ai_analyses.sql` jest addytywna. Rollback: `DROP TABLE ai_analyses`.

## References

- Roadmap: `context/foundation/roadmap.md` (S-01)
- PRD: `context/foundation/prd.md` (US-01, FR-001, FR-002, FR-004)
- ClaudeClient: `src/Ai/ClaudeClient.php:40` (`sendMessage`)
- CacheableSystem: `src/Ai/CacheableSystem.php:21`
- ClaudeClientFactory: `src/Ai/ClaudeClientFactory.php:19`
- FakeTransport (dla testów): `tests/Ai/FakeTransport.php`
- ProGate: `src/Pro/ProGate.php:54` (`canGenerate`, `getSessionCode`)
- AiUsageRepository: `src/Pro/AiUsageRepository.php:30` (`log`)
- AnalysisController::show(): `src/CVS/AnalysisController.php:111`
- analysis.php forecast sekcja: `templates/analysis.php:251` (~.forecast-card)
- cvs-analyze skill (wzorzec analizy): `C:\Users\Michał\.claude\skills\cvs-analyze\scripts\cvs_analyze.py`

---

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Migracja + AiAnalysisRepository

#### Automated
- [x] 1.1 `vendor/bin/phpunit` zielony (testy AiAnalysisRepository) — 80a0df5
- [x] 1.2 `vendor/bin/phpstan analyse` zielony — 80a0df5

#### Manual
- [x] 1.3 Migracja 008 wykonana na CF — tabela `ai_analyses` istnieje

### Phase 2: AiDivergenceService

#### Automated
- [x] 2.1 `vendor/bin/phpunit` zielony (testy AiDivergenceService) — 4c64132
- [x] 2.2 `vendor/bin/phpstan analyse` zielony — 4c64132

#### Manual
- [x] 2.3 Wywołanie testowe przez SSH — narracja PL generuje się poprawnie

### Phase 3: Controller + Route + UI

#### Automated
- [x] 3.1 `vendor/bin/phpunit` zielony — a6d0721
- [x] 3.2 `vendor/bin/phpstan analyse` zielony — a6d0721
- [x] 3.3 Trasa `POST /analysis/{ticker}/generate-ai` zarejestrowana — a6d0721

#### Manual
- [x] 3.4 User PRO: przycisk „Generuj AI", modal z animacją, narracja 4-sekcyjna PL
- [x] 3.5 Non-PRO: narracja z cache widoczna bez przycisku generowania
- [x] 3.6 Odśwież <24h → 429; awaria API → graceful error
- [x] 3.7 Disclaimer przy narracji; `ai_analyses` + `ai_usage_log` — wiersze w DB
