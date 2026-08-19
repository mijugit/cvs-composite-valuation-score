---
topic: Klon portfela LLM Free z Gemini jako wykonawczym modelem
researcher: general-purpose agent (via /Agent), 2026-08-19
status: complete
---

## Cel

Zbadać dokładną implementację istniejącego portfela "LLM Free" (`src/LlmFree/`), żeby
zaplanować analogiczny trzeci portfel, w którym wykonawczym LLM jest Gemini zamiast Claude.

## 1. Moduł `src/LlmFree/`

- **`LlmFreeService.php`** — jedyna klasa piszącą do bazy. `executeCycle()` (linie 67–122)
  wykonuje cały cykl w jednej transakcji PDO: match na `action` (BUY/SELL/HOLD/NO_ACTION,
  linie 87–99) → `handleBuy`/`handleSell`/`recordHoldInternal`/`recordNoActionInternal`.
  Jedyne twarde ograniczenia to fizyczne guardy: BUY droższy niż gotówka jest pomijany
  (linie 140–147), SELL przycinany do faktycznie posiadanej ilości (linia 221) —
  **brak jakiegokolwiek DecisionEnforcer** (w odróżnieniu od `CVS\Portfolio\PortfolioService`).
  `computeHoldingsValue()` (linie 320–335) liczy mark-to-market wg dzisiejszej ceny
  snapshotu (poprawnie od początku, w odróżnieniu od bazowego portfela — patrz
  `cvs-portfolio-mark-to-market-fix`).
- **`LlmFreeController.php`** — kontroler tylko do odczytu dla `GET /llm-free`
  (`index()`, linie 26–98). Ładuje `config/cvs-weights.php` + `config/llm-free-wallet.php`,
  dogrywa live ceny przez `LivePriceProvider` z własnym kluczem cache w sesji
  `cvs_llmfree_px` (linia 113, żeby nie kolidować z cache bazowego portfela), pobiera
  historię legendy i buduje wykres NAV przez `WalletNavChartService`.
- **`LlmFreeContextGatherer.php`** — przed wywołaniem głównego modelu decyzyjnego
  sprawdza świeżość istniejących analiz (`AiCriticalReviewRepository::isFresh()`,
  `AiAnalysisRepository::isFresh()`, linie 57–71 — zero-cost), a dla brakujących robi
  ograniczoną liczbę (`context_search_cap`, domyślnie 3) świeżych wywołań Claude z
  narzędziem `web_search_20260209` (linie 97–118). To osobne, mniejsze wywołanie
  Claude API, budowane z surowego `config/ai.php` (**nie** z sekcją `llm` wallet-configu)
  — zawsze idzie na globalny `AI_MODEL`, niezależnie od modelu flagowego wallet.
- **`LlmFreeCycleRepository.php`** — CRUD na `llm_free_cycle`: `claimForRun()`
  (linie 74–117) implementuje idempotentne zajęcie dnia (UNIQUE na `cycle_date`,
  retry tylko dla `failed`/`llm_failed`, max attempts), `updateLlmRecord()`
  (linie 137–163) zapisuje audyt LLM łącznie z legendą i tokenami w jednym UPDATE
  (poza transakcją zapisu portfela — musi przetrwać rollback).
- **`LlmFreeDecisionParser.php`** — bezstanowy walidator odpowiedzi JSON. Oczekuje
  jednego obiektu `{"decisions": [...], "legend": "..."}` (nie samej tablicy jak
  `CVS\Portfolio\DecisionParser`). Legenda musi być niepustym stringiem (ucinana do
  `legendMaxChars`=4000 znaków), decyzje walidowane per-item z odpornością na błędy
  pojedynczego elementu — jeden zepsuty item nie wywala całej paczki.
- **`LlmFreeDecisionService.php`** — serce integracji z Claude: buduje system prompt
  (`buildSystemPrompt()`, linie 138–201) i blok decyzyjny w heredoc PHP, niezależne od
  providera — nadają się do reużycia bez zmian.
- **`LlmFreeRepository.php`** — czysto odczytowa warstwa: `getCurrentState()`,
  `getCurrentHoldings()`, `getCurrentHoldingsWithPrice()` (JOIN do `cvs_snapshots`
  filtrowany po `model_version` i `origin='RESCORE'`), `getLegendHistory($limit)`
  (linie 146–164) — ta sama metoda czytana zarówno przez `/llm-free`, jak i przez
  sam model jako pamięć.

## 2. Klient AI (`src/Ai/`) — hard-wired na Anthropic, brak abstrakcji

**Nie ma żadnego wspólnego interfejsu LLM.** `ClaudeClient` (`src/Ai/ClaudeClient.php`)
to `final class` całkowicie zbudowana wokół Anthropic Messages API:
- Endpoint: `https://api.anthropic.com/v1/messages` (config `base_url`).
- Auth: nagłówek `x-api-key` + `anthropic-version` — nie Bearer token jak Gemini.
- Format żądania: `{"model","max_tokens","messages","system":[{"type":"text","text":...,
  "cache_control":{"type":"ephemeral","ttl":...}}],"tools":[...]}` (`buildBody()`) —
  `cache_control`/`CacheableSystem` to koncept Anthropicowy (prompt caching), Gemini go nie ma.
- Parsowanie odpowiedzi: `content` jako lista bloków (`text`, `web_search_tool_result`,
  `stop_reason`, `pause_turn` continuation loop) — struktura Anthropic-specific, różna
  od Gemini (`candidates[].content.parts[]`).
- `AiFailureKind` enum mapuje kody 429/529/401/403/5xx wg konwencji Anthropic (529 =
  Overloaded — Gemini go nie zwraca, ma inne kody/format quota errora).
- `HttpTransport` (interfejs) + `CurlTransport` — jedyna warstwa nadająca się do
  reużycia 1:1: czysty seam `send(url, jsonBody, headers, timeout): {status, body, error}`,
  kompletnie niezależny od Anthropic.
- `AiResult`/`AiUsage` — neutralne value objects (ok/text/usage/stopReason/model/
  failureKind), kształt da się zachować dla klienta Gemini, wypełniany z innej struktury.

**Wniosek:** brak `LlmClientInterface`. Najbezpieczniejsza droga: równoległa klasa
`GeminiClient`/`GeminiClientFactory` implementująca tę samą nieformalną sygnaturę
(`sendMessage(messages, ?CacheableSystem, options): AiResult`) — zero ryzyka regresji
w istniejącym module Claude (S-01, critical review, LlmFree). Wydzielanie formalnego
interfejsu byłoby większą refaktoryzacją dotykającą działającego kodu bez potrzeby.

## 3. Konfiguracja

- **`config/ai.php`** — globalny klient Anthropic: `ANTHROPIC_API_KEY`, `AI_BASE_URL`,
  `AI_MODEL`, `AI_ANTHROPIC_VERSION`, `AI_MAX_TOKENS`, `AI_TIMEOUT`, `AI_MAX_RETRIES`,
  `AI_TOTAL_TIMEOUT`, `AI_RETRY_BASE_DELAY_MS` + sekcje `pro`/`critical_review`.
- **`config/llm-free-wallet.php`**:
  - `initial_capital_usd => 10000.0` — identyczny jak bazowy portfel (komentarz w
    kodzie wprost mówi, że inna kwota złamałaby porównanie).
  - `market` (open/close/timezone), `rebalance_window_minutes => 90`.
  - `legend_context_count => 10`, `context_search_cap => 3`, `legend_max_chars => 4000`.
  - `max_candidates => 40` — cap na liczbę wierszy screenera w prompcie (guardrail kosztowy).
  - Sekcja `llm` — override dla wywołania decyzyjnego: `'model' => 'claude-sonnet-5'`
    (**hardcoded string, nie z env** — świadomy wybór, żeby zmiana globalnego `AI_MODEL`
    nie złamała porównania z portfelem bazowym), `max_retries=>0`, `max_tokens=>8192`,
    `timeout=>180`, `total_timeout=>200`, `retry_delay_seconds=>2`, `system_prompt_ttl=>'5m'`.
  - `LlmFreeContextGatherer` nie dziedziczy tego override'u — zawsze idzie na globalny `AI_MODEL`.

Brak osobnego `config/gemini*.php` — nie istnieje.

## 4. Baza danych — migracja 035 (wzorzec do powielenia)

`database/migrations/035_create_llm_free_wallet_tables.sql`. Cztery tabele,
`ENGINE=InnoDB, utf8mb4`, w pełni izolowane (brak FK do `portfolio_*`/`rebalance_cycle`):

- **`llm_free_cycle`** — `id` PK, `cycle_date DATE UNIQUE`, `status VARCHAR(32)`
  (started/completed/failed/llm_failed), `attempt_count TINYINT UNSIGNED`,
  `started_at`/`finished_at DATETIME`, `cash_before`/`cash_after`/
  `portfolio_value_usd DECIMAL(12,2)`, `executed_count`/`skipped_count SMALLINT UNSIGNED`,
  `notes TEXT`, `retry_count TINYINT UNSIGNED`, `llm_raw_response TEXT`,
  `llm_failure_kind VARCHAR(32)`, `llm_decision_json TEXT`, `legend TEXT`,
  `tokens_input`/`tokens_output INT UNSIGNED`.
- **`llm_free_state`** — singleton: `id` PK, `cash DECIMAL(12,2)`,
  `initial_capital DECIMAL(12,2)`, `updated_at`. Seed: `INSERT IGNORE ... VALUES
  (1, 10000.00, 10000.00, NOW())`.
- **`llm_free_holdings`** — `id` PK, `ticker VARCHAR(20) UNIQUE`, `quantity INT UNSIGNED`,
  `avg_entry_price DECIMAL(12,4)`, `updated_at`.
- **`llm_free_transactions`** — `id` PK, `cycle_id INT UNSIGNED` (FK do
  `llm_free_cycle.id`), `ticker`, `action VARCHAR(20)` (BUY/SELL/HOLD/NO_ACTION/SKIP_*),
  `quantity INT UNSIGNED NULL`, `price_usd DECIMAL(12,4) NULL`, `cash_before`/
  `cash_after DECIMAL(12,2) NULL`, `status VARCHAR(32)`, `reason TEXT`, `executed_at`.

Kolejny wolny numer migracji: **`038`** (035=llm_free_wallet, 036=valuation_reference,
037=peer_bucket_override — potwierdzone bezpośrednio przez `ls database/migrations/`).

## 5. Routing i kontroler

`src/Core/routes.php`, linie 170–174:
```php
$llmFree = new LlmFreeController();
$router->get('/llm-free', fn($req) => $llmFree->index($req));
```
Jeden endpoint, tylko `GET`, tylko `index()`.

## 6. Cron / rebalansowanie

**`bin/llm-free-wallet-rebalance.php`** (361 linii) — CLI-only (guard `PHP_SAPI !== 'cli'`):
1. Log do `logs/llm-free-wallet-rebalance.log`.
2. Ręczny parser `.env` (brak dotenv library, ta sama logika co `public/index.php`).
3. Bramka kalendarza rynkowego (`MarketCalendar`, `config/llm-free-wallet.php['market']`
   + `config/portfolio.php['holidays']`).
4. Bramka idempotencji DB (`claimForRun($cycleDate, maxAttempts=3)`).
5. Zbiera dane: `screenerRepo->getFiltered()`, filtruje przez `SnapshotFreshness::partition()`
   (odrzuca stale kandydatów, trzyma held tickers) i `PeerCoverage::isThin()`.
6. `LlmFreeContextGatherer::gather()` → `LlmFreeDecisionService::generate()` — całość
   w try/catch (crash = `llm_failed`).
7. Wstrzykuje realne ceny wykonania z `screenerRows` (model NIGDY nie zwraca `price_usd`
   — halucynacje cen zabronione), odrzuca BUY/SELL bez znanej ceny.
8. `Database::reconnect()` przed fazą zapisu (CF MySQL `wait_timeout` obserwowany na żywo).
9. `LlmFreeService::executeCycle()`.

**Harmonogram cron (docblock, linie 49–53):**
```
50 21 * * 1-5  /usr/local/bin/php82 /home/amjsystem/sites/cvs.timeflow.fun/bin/llm-free-wallet-rebalance.php
50 22 * * 1-5  /usr/local/bin/php82 /home/amjsystem/sites/cvs.timeflow.fun/bin/llm-free-wallet-rebalance.php
```
Dwa wpisy (Warsaw 21:50 primary + 22:50 backup) zamiast trzech pokrywających DST —
świadomy wybór kompensujący przesunięcia CET/CEST↔EST/EDT względem
`rebalance_window_minutes=90`.

**Dla porównania — `bin/portfolio-rebalance.php`** (bazowy portfel, 277 linii):
niemal identyczny szkielet, ale różnice kluczowe:
- Cron na `/usr/local/bin/php84` (nie php82!) o `30 20`/`30 21` Warsaw.
- Ma krok `DecisionEnforcer` (linie 239–254) — LLM Free tego nie ma.
- Nie ma odpowiednika `LlmFreeContextGatherer`, nie ma legendy.

**`deployment/cvs-composite-valuation-score.deploy.json`** (struktura, bez sekretów):
`repo_url`, `branch: main`, `repo_structure: flat`, `ssh_host`, `ssh_user`, `ssh_key`,
**`remote_path: /home/amjsystem/sites/cvs.timeflow.fun`** (ścieżka użyta w crontab),
`remote_home` (`.../public_html`), `php_bin: /usr/local/bin/php82`,
`app_url: https://cvs.timeflow.fun`, pola `db_*` = "from .env on server, never copied
locally". Plik generowany przez skill `MiJu-CF-Deploy`.

## 7. `.env` / `.env.example`

`.env.example` (repo root) — tylko wzorzec Claude:
```
# ANTHROPIC_API_KEY=
# AI_MODEL=claude-sonnet-4-6
# AI_MAX_TOKENS=2048
# AI_TIMEOUT=20
# AI_MAX_RETRIES=2
# AI_TOTAL_TIMEOUT=25
# AI_RETRY_BASE_DELAY_MS=500
# AI_ANTHROPIC_VERSION=2023-06-01
# AI_BASE_URL=https://api.anthropic.com/v1/messages
```
plus osobna sekcja `AI_CRITICAL_REVIEW_*`. Wzorzec: prefiks `AI_` dla wspólnych
parametrów klienta, klucz sam w sobie bez prefiksu (`ANTHROPIC_API_KEY`). Użytkownik
potwierdził: klucz Gemini na serwerze już istnieje pod nazwą **`Gemini_CVS`** — inna
konwencja niż `ANTHROPIC_API_KEY`. Lokalny `.env` nie istnieje (gitignored) — dane
produkcyjne żyją tylko na serwerze.

## 8. Nawigacja / UI

`templates/layout.php`, linie 45–53 — dropdown „Portfele" z dwoma pozycjami:
```php
<div class="admin-menu">
    <button class="admin-menu__trigger" type="button" aria-haspopup="true">
        Portfele <span class="admin-menu__caret">▾</span>
    </button>
    <ul class="admin-menu__dropdown" role="menu">
        <li><a href="/portfolio" ...>LLM Bazowy</a></li>
        <li><a href="/llm-free" ...>LLM Free</a></li>
    </ul>
</div>
```
Trzeci wpis dla Gemini = kolejny `<li><a href="/llm-gemini" ...>...</a></li>` w tej
samej liście — wzorzec `aria-current="page"` sprawdzany przez
`str_starts_with($_SERVER['REQUEST_URI'], '/...')`.

## Weryfikacja zewnętrzna: modele Gemini, sierpień 2026 (WebFetch ai.google.dev, 2026-08-19)

- Aktualne modele bez ogłoszonej daty wygaszenia: `gemini-2.5-flash` (od czerwca 2025),
  `gemini-2.5-pro`, `gemini-2.5-flash-lite`, `gemini-3.5-flash` (maj 2026),
  `gemini-3.6-flash` (lipiec 2026), `gemini-3.7-flash` (sierpień 2026, najnowszy).
- Potwierdzone wygaszenia: Gemini 1.0/1.5 (już wygaszone), Gemini 2.0 Flash/Flash-Lite
  (1 czerwca 2026).
- Sugestia Gemini z promptu usera (użyć 2.5 Flash jako "najlepszego wyboru na ten
  moment") jest częściowo nieaktualna — 2.5 Flash wciąż działa, ale nie jest już
  najnowszą darmową opcją; 3.5/3.6/3.7 Flash też mają darmowy tier.
- Wniosek: model NIE powinien być hardcodowany w kodzie — trzymać jako zmienną configu
  czytaną z env (mirror wzorca `AI_MODEL`, ale per-wallet override tak jak
  `config/llm-free-wallet.php['llm']['model']` dla Claude), żeby zmiana modelu przy
  przyszłym retirement nie wymagała edycji kodu.
- Realny wolumen wywołań/dzień jest rzędu pojedynczych cyfr (1 decyzyjne + do 3
  context-gatherer, ×2 crony backup) — limit darmowego tieru (10 RPM niezależnie od
  wybranego modelu Flash) nie jest realnym ryzykiem przy architekturze cron-batch,
  wbrew sugestiom Gemini o cache'owaniu/kolejkowaniu pod wielu userów.
