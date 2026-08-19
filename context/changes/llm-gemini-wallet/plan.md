# Trzeci portfel eksperymentalny — LLM Gemini — Implementation Plan

## Overview

Klon istniejącego portfela "LLM Free" (`src/LlmFree/`, tabele `llm_free_*`, `/llm-free`)
jako trzeci, w pełni izolowany portfel eksperymentalny: identyczny mechanizm (brak
DecisionEnforcer, mark-to-market od pierwszej linii, legenda/pamięć per cykl, kapitał
startowy 10 000 USD), ale wykonawczym LLM jest Gemini zamiast Claude, z własnym,
natywnym web-searchem (`googleSearch` grounding) zamiast dzielenia infrastruktury z
Claude.

## Current State Analysis

- `CVS\Ai\ClaudeClient` jest `final class` całkowicie zbudowana wokół Anthropic
  Messages API: auth przez nagłówek `x-api-key`, model w body, `cache_control`
  (prompt caching), kształt odpowiedzi `content[]` z blokami `text`/`web_search_tool_result`.
  Nie ma żadnego wspólnego interfejsu LLM — `HttpTransport`/`CurlTransport` to jedyna
  warstwa nadająca się do reużycia 1:1 (czysty seam niezależny od dostawcy).
- `CVS\LlmFree\*` to gotowy, sprawdzony wzorzec modułu portfela bez server-side'owego
  nadpisywania decyzji: `LlmFreeService` (zapis/transakcje), `LlmFreeController`
  (GET `/llm-free`), `LlmFreeContextGatherer` (web-search kontekstowy przed decyzją),
  `LlmFreeCycleRepository` (audyt cyklu + idempotencja), `LlmFreeDecisionParser`
  (walidacja `{"decisions":[...],"legend":"..."}` — bezstanowa, provider-agnostic),
  `LlmFreeDecisionService` (system prompt + wywołanie modelu + zapis audytu),
  `LlmFreeRepository` (odczyt stanu/holdings/legendy).
- Migracja `035_create_llm_free_wallet_tables.sql` tworzy 4 w pełni izolowane tabele
  (`llm_free_cycle/state/holdings/transactions`, zero FK do `portfolio_*`). Kolejny
  wolny numer migracji: **`038`** (035=llm_free, 036=valuation_reference,
  037=peer_bucket_override — potwierdzone przez `ls database/migrations/`).
- `bin/llm-free-wallet-rebalance.php` (CLI-only cron entrypoint) i
  `bin/portfolio-rebalance.php` (bazowy portfel) mają niemal identyczny szkielet:
  guard `.env`/`$_SESSION`, bramka `MarketCalendar`, idempotentne `claimForRun()`,
  zbieranie danych (`ScreenerRepository`, `SnapshotFreshness::partition()`,
  `PeerCoverage::isThin()`), wywołanie silnika decyzyjnego w try/catch, wstrzyknięcie
  realnych cen wykonania, `Database::reconnect()` przed zapisem.
- `config/llm-free-wallet.php['market']` = pełne godziny NYSE (open 09:30, close_time
  **17:00** — celowo szerszy niż realny close 16:00 ET, to praktyczna granica okna),
  `rebalance_window_minutes=90` → efektywne okno **[15:30, 17:00) ET**. Kontrastuje z
  `config/portfolio.php` (bazowy portfel): `close_time=16:00`, okno **390 minut**
  (cała sesja 09:30–16:00 ET) — bazowy portfel nie jest zawężony do okolic close,
  Free tak (celowe, blisko-close wykonanie).
- Harmonogram cronów już istniejących (Europe/Warsaw, docblocki w `bin/*.php`):
  - Bazowy: `30 20 */30 21 * * 1-5` na `php84`, pełne okno sesji.
  - Free: `50 21 / 50 22 * * 1-5` na `php82`, wąskie okno blisko close. Prymarny
    21:50→15:50 ET (nominalny offset 6h), backup 22:50 dormant w normalnym tygodniu,
    staje się efektywnym prymarnym przy 7h-offsetowym mismatchu DST (późny
    październik/wczesny listopad). Ten sam wzorzec działa odwrotnie przy 5h-offsetowym
    mismatchu (połowa marca): prymarny nadal w oknie (16:50 ET), backup poza oknem.
- `deployment/cvs-composite-valuation-score.deploy.json` → `remote_path`:
  `/home/amjsystem/sites/cvs.timeflow.fun`, `php_bin` dla Free = `/usr/local/bin/php82`.
- `templates/layout.php:45-53` — dropdown „Portfele" z `<li>` na `/portfolio` i
  `/llm-free`. `src/Core/routes.php:170-174` — jeden `GET` route per portfel.
- Weryfikacja zewnętrzna (WebFetch `ai.google.dev`, 2026-08-19): Gemini REST API —
  endpoint `POST https://generativelanguage.googleapis.com/v1beta/{model}:generateContent`,
  auth **header `x-goog-api-key`** (nie query param), request `contents[]` +
  `systemInstruction` + `generationConfig.maxOutputTokens` + `tools[]`, response
  `candidates[0].content.parts[].text` + `usageMetadata.{promptTokenCount,
  candidatesTokenCount}` + `candidates[0].finishReason` + `candidates[0].groundingMetadata`
  (cytowania przy `googleSearch`). Błędy: `429`→rate/quota, `401`→auth, `403`→permission,
  `500/502/503`→transient, `504`→deadline. Brak odpowiednika `pause_turn`
  (kontynuacja tool-loop) — nasze wywołania są jednoturowe, więc `GeminiClient` nie
  potrzebuje pętli kontynuacji jaką ma `ClaudeClient`.

### Key Discoveries:

- `HttpTransport`/`CurlTransport` (`src/Ai/HttpTransport.php`, `CurlTransport.php`)
  są całkowicie provider-agnostic (seam `send(url, jsonBody, headers, timeout)`) —
  `GeminiClient` je reużywa bez zmian.
- `AiResult`/`AiUsage`/`AiFailureKind` (`src/Ai/`) są neutralnymi value objects — ich
  kształt (`ok/text/usage/stopReason/model/failureKind/citations/searchDegraded`)
  pasuje do wypełnienia z odpowiedzi Gemini bez zmian w tych klasach.
- `LlmFreeDecisionParser` nie odwołuje się do niczego provider-specific — waliduje
  wyłącznie kształt JSON `{"decisions":[...],"legend":"..."}`. **Reużywamy tę klasę
  bez zmian** dla portfela Gemini zamiast duplikować identyczną logikę walidacji.
- `tests/Ai/FakeTransport.php` implementuje `HttpTransport` i jest już używany przez
  `ClaudeClientTest` — reużywalny 1:1 dla `GeminiClientTest` (inny provider, ten sam seam).

## Desired End State

Działający trzeci portfel pod `/llm-gemini`: strona pokazuje stan gotówki, holdingi
wycenione mark-to-market, historię legendy i wykres NAV — analogicznie do `/llm-free`.
Cron na CF wywołuje `bin/llm-gemini-wallet-rebalance.php`, który każdego dnia sesyjnego
NYSE (w wąskim oknie blisko close) woła Gemini z pełną swobodą decyzyjną (bez
DecisionEnforcera), z własnym web-searchem `googleSearch` jako jedynym źródłem
świeżego kontekstu, zapisuje transakcje i nowy wpis legendy. Kapitał startowy 10 000 USD,
identyczny jak w obu pozostałych portfelach.

**Weryfikacja:** `/llm-gemini` renderuje się bez błędów przy pustym portfelu (seed
10 000 USD gotówki, brak holdingów); ręczne uruchomienie
`bin/llm-gemini-wallet-rebalance.php` z CLI w oknie rynkowym kończy się `exit(0)` i
zapisuje wiersz w `llm_gemini_cycle` ze statusem `completed` lub `llm_failed`
(nigdy nieobsłużonym wyjątkiem); `vendor/bin/phpunit` i `composer stan` przechodzą
zielono.

## What We're NOT Doing

- Nie zmieniamy portfela bazowego (`CVS\Portfolio\*`) ani portfela LLM Free
  (`CVS\LlmFree\*`) — oba pozostają całkowicie nietknięte.
- Nie budujemy współdzielonego interfejsu `LlmClientInterface` dla Claude/Gemini —
  `GeminiClient` to równoległa, niezależna klasa (świadoma decyzja, patrz Key
  Discoveries: zero ryzyka regresji w działającym module Claude).
- Nie reużywamy cache'u `ai_analyses`/`ai_critical_reviews` (generowanego przez
  Claude) w portfelu Gemini — decyzja usera: zawsze własne, świeże wyszukiwanie
  Gemini, zero mieszania providerów w jednym cyklu decyzyjnym.
- Nie implementujemy prompt cachingu dla Gemini (`cachedContent`) — Gemini ma inny,
  bardziej złożony mechanizm (jednorazowe utworzenie + referencja) niż Anthropicowe
  `cache_control`; przy obecnym wolumenie wywołań (rząd pojedynczych cyfr/dzień) nie
  jest to wąskie gardło kosztowe.
- Nie zmieniamy nazwy zmiennej `Gemini_CVS` na serwerze — czytamy ją wprost pod tą
  nazwą (decyzja usera).
- Nie tworzymy nowego crona automatycznie — użytkownik zakłada go ręcznie w panelu
  CF; ten plan dostarcza dokładną ścieżkę/komendę.

## Implementation Approach

Cztery fazy, każda niezależnie weryfikowalna: (1) fundament klienta Gemini w
`src/Ai/` obok istniejącego `ClaudeClient`, (2) natywny context gatherer używający
`googleSearch`, (3) klon modułu portfela (`src/LlmGemini/`, migracja, config, routing,
nawigacja), (4) cron entrypoint + finalna ścieżka dla usera. Kolejność wymuszona
zależnościami: DecisionService i ContextGatherer (fazy 2-3) potrzebują gotowego
`GeminiClient` (faza 1); cron (faza 4) potrzebuje całego modułu gotowego.

## Critical Implementation Details

**Kontrakt `GeminiClient::sendMessage()` musi być 1:1 zgodny z `ClaudeClient`** —
`sendMessage(array $messages, ?CacheableSystem $system, array $options): AiResult` —
żeby `LlmGeminiDecisionService`/`LlmGeminiContextGatherer` dały się napisać jako
niemal dosłowne kopie `LlmFreeDecisionService`/`LlmFreeContextGatherer` (tylko
podmiana klasy klienta + fabryki + kształtu `tools`). `$system->ttl` jest przyjmowany
dla zgodności sygnatury, ale ignorowany w budowie requestu (Gemini nie ma
odpowiednika `cache_control`) — udokumentować to jednym zdaniem w docblocku klasy,
żeby nikt nie próbował „naprawić" brakującej obsługi TTL.

**Auth Gemini to nagłówek, nie query param.** `x-goog-api-key: {klucz}` +
`content-type: application/json`. Klucz w konfiguracji: `$_ENV['Gemini_CVS']`
(dokładnie ta wielkość liter — zmienne `$_ENV` w PHP są case-sensitive).

**Endpoint zawiera model w ścieżce URL, nie w body** (różnica względem Claude, gdzie
model jest polem JSON): `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent`.
`GeminiClient` musi zbudować URL dynamicznie z `$this->config['model']`.

**Kształt `tools` dla web-search różni się całkowicie od Claude.** Claude:
`{"type":"web_search_20260209","name":"web_search","max_uses":2}`. Gemini (REST,
camelCase jak reszta API): `{"googleSearch": {}}`. Ponieważ oba call site'y (Claude
gatherer, Gemini gatherer) to osobne klasy budujące własne `options['tools']`, nie
potrzeba warstwy tłumaczącej — każda przekazuje kształt właściwy swojemu klientowi.
**Zweryfikować ten literalny kształt jednym ręcznym smoke-testem (curl) w Fazie 1**
przed wpięciem go w Fazę 2 — dokumentacja REST nie pokazała pełnego przykładu
`googleSearch`, tylko wzorzec camelCase innych pól (`systemInstruction`,
`generationConfig`).

**Brak pętli kontynuacji (`pause_turn`) w `GeminiClient`.** `ClaudeClient` ma
`MAX_CONTINUATIONS` do obsługi wielotorowych wywołań tool-use. Gemini zwraca
`finishReason` (`STOP`/`MAX_TOKENS`/`SAFETY`/`OTHER`) bez odpowiednika `pause_turn`
dla naszych jednoturowych wywołań (jeden request → jedna odpowiedź, `googleSearch`
jest obsługiwany przez model wewnętrznie w ramach jednego wywołania) — `GeminiClient`
jest prostszy niż `ClaudeClient` w tym miejscu, nie trzeba portować tej pętli.

**Harmonogram crona wynika bezpośrednio z Free's, przesunięty o 10 minut wcześniej.**
Portfel Gemini mirroruje `config/llm-free-wallet.php['market']` 1:1 (close_time=17:00,
window=90min → efektywne okno [15:30,17:00) ET) — więc dziedziczy też Free'owy wymóg
wąskiego okna blisko close. Wybrane pary crona: **21:40/22:40 Warsaw** — to czysta
translacja czasowa sprawdzonego harmonogramu Free (21:50/22:50) o 10 minut wcześniej,
więc dziedziczy identyczne własności bezpieczeństwa DST (patrz Faza 4) i nie koliduje
z żadnym z dwóch istniejących cronów (Base 20:30/21:30, Free 21:50/22:50) — minimum
10-minutowy odstęp w obie strony.

## Phase 1: Fundament klienta Gemini

### Overview

Nowy, niezależny klient AI dla Gemini w `src/Ai/`, obok `ClaudeClient` (bez zmian w
nim). Reużywa `HttpTransport`/`CurlTransport`/`AiResult`/`AiUsage`/`AiFailureKind`
bez modyfikacji.

### Changes Required:

#### 1. `src/Ai/GeminiClient.php`

**Intent**: Klient REST Gemini `generateContent` z tym samym kontraktem publicznym
co `ClaudeClient` (patrz Critical Implementation Details), żeby dalsze fazy mogły
kopiować `LlmFreeDecisionService`/`LlmFreeContextGatherer` niemal bez zmian
strukturalnych.

**Contract**:
- `final class GeminiClient` w namespace `CVS\Ai`, konstruktor
  `(array $config, HttpTransport $transport)` — identyczny kształt jak `ClaudeClient`.
- `sendMessage(array $messages, ?CacheableSystem $system = null, array $options = []): AiResult`.
- Buduje URL `{$config['base_url']}/models/{$config['model']}:generateContent`, header
  `x-goog-api-key` (nie `x-api-key`).
- Mapuje `$messages` (kształt `[{role, content}]` jak w wywołaniach LlmFree) na
  `contents: [{role: 'user'|'model', parts: [{text: ...}]}]` (Claude `assistant` →
  Gemini `model`), `$system->text` na `systemInstruction.parts[0].text`,
  `$options['max_tokens']` na `generationConfig.maxOutputTokens`,
  `$options['tools']` przekazane wprost do body (Gemini-native kształt, patrz
  Critical Implementation Details).
- Parsuje sukces: konkatenacja `candidates[0].content.parts[].text`,
  `usageMetadata.{promptTokenCount→inputTokens, candidatesTokenCount→outputTokens}`
  do `AiUsage`, `candidates[0].finishReason` jako `stopReason`, cytowania z
  `candidates[0].groundingMetadata.groundingChunks[].web.{uri→url, title}`
  zdeduplikowane po url (mirror `ClaudeClient::parseSuccess()`'s `$citationsByUrl`).
- Mapuje błędy HTTP: `429`→`AiFailureKind::RateLimited` (retryable), `500/502/503`→
  `Overloaded` (retryable), `504`→`Timeout` (retryable), `401/403`→`Auth`
  (nie-retryable), body zawierające `quota`/`billing` przy dowolnym 4xx→`Quota`
  (nie-retryable), inaczej→`BadResponse`. Transport-level error (connection/timeout)
  jak w `ClaudeClient::interpret()`.
- Retry/backoff: dosłownie ten sam mechanizm co `ClaudeClient::sendMessage()`
  (`max_retries`, `retry_base_delay_ms`, `total_timeout` budget pętla) — bez pętli
  kontynuacji (patrz Critical Implementation Details).
- Pusty `api_key` → natychmiastowy `AiResult::failure(AiFailureKind::Auth, ...)`
  bez wywołania sieciowego (mirror `ClaudeClient`).

#### 2. `src/Ai/GeminiClientFactory.php`

**Intent**: Jedyny punkt konstrukcji `GeminiClient`, mirror `ClaudeClientFactory`.

**Contract**: `final class GeminiClientFactory` z jedną metodą statyczną
`fromConfig(array $config, ?HttpTransport $transport = null): GeminiClient` —
identyczne ciało jak `ClaudeClientFactory::fromConfig()`, inny typ zwracany.

#### 3. `config/gemini.php`

**Intent**: Konfiguracja klienta Gemini analogiczna do `config/ai.php`, czytana
z `.env` (nigdy hardcoded klucz/model — mirror zasady FR-010/CLAUDE.md).

**Contract**: Zwraca tablicę:
- `'api_key' => (string) ($_ENV['Gemini_CVS'] ?? '')` — dokładnie ta nazwa
  zmiennej (decyzja usera, klucz już istnieje na serwerze).
- `'base_url' => (string) ($_ENV['GEMINI_BASE_URL'] ?? 'https://generativelanguage.googleapis.com/v1beta')`.
- `'model' => (string) ($_ENV['GEMINI_MODEL'] ?? 'gemini-3.7-flash')` — env-driven
  (decyzja usera), default = model wybrany na start.
- `'max_tokens' => (int) ($_ENV['GEMINI_MAX_TOKENS'] ?? 8192)`,
  `'timeout' => (int) ($_ENV['GEMINI_TIMEOUT'] ?? 180)`,
  `'max_retries' => (int) ($_ENV['GEMINI_MAX_RETRIES'] ?? 2)`,
  `'total_timeout' => (int) ($_ENV['GEMINI_TOTAL_TIMEOUT'] ?? 200)`,
  `'retry_base_delay_ms' => (int) ($_ENV['GEMINI_RETRY_BASE_DELAY_MS'] ?? 500)`
  — te same rzędy wielkości co `config/ai.php`, dostosowane do braku presji czasu
  requestu HTTP (wywołania tylko z crona, mirror `llm-free-wallet.php['llm']`).

#### 4. `.env.example`

**Intent**: Udokumentować nowe zmienne env dla operatora, mirror istniejącej sekcji
Claude.

**Contract**: Nowa komentowana sekcja `# --- Gemini API (CVS\Ai\GeminiClient) ---`
z `# Gemini_CVS=` (nazwa zmiennej zgodna z tym, co już jest na serwerze — NIE
`GEMINI_API_KEY`), `# GEMINI_MODEL=gemini-3.7-flash`, oraz pozostałe `GEMINI_*`
z configu powyżej, z tym samym ostrzeżeniem o braku inline-komentarzy po wartości
co istniejąca sekcja `AI_*`.

#### 5. `tests/Ai/GeminiClientTest.php`

**Intent**: Testy jednostkowe klienta offline przez `FakeTransport` (już istnieje,
reużyty bez zmian), mirror struktury `tests/Ai/ClaudeClientTest.php`.

**Contract**: Pokrycie: udany request z tekstową odpowiedzią (poprawny parse
`candidates[0].content.parts[].text` + `usageMetadata`); `429`→`RateLimited` +
retry aż do `max_retries`; `401`→`Auth` bez retry; pusty `api_key`→`Auth` bez
wywołania transportu; malformed JSON body→`BadResponse`; poprawne zbudowanie URL
z modelem w ścieżce; poprawny nagłówek `x-goog-api-key`.

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit tests/Ai/GeminiClientTest.php` przechodzi zielono
- `composer stan` (PHPStan level 6) nie zgłasza błędów w `src/Ai/GeminiClient.php`/`GeminiClientFactory.php`
- Pełny `vendor/bin/phpunit` nadal zielony (zero regresji w `ClaudeClient`/innych testach `src/Ai/`)

#### Manual Verification:

- Ręczny smoke-test `curl` (lub mały skrypt PHP) do `generateContent` z prawdziwym
  kluczem `Gemini_CVS` z serwera potwierdza dokładny kształt `tools: [{"googleSearch":{}}]`
  i `groundingMetadata` w odpowiedzi — koryguje `GeminiClient`, jeśli rzeczywisty
  kształt różni się od założonego w Critical Implementation Details

---

## Phase 2: Natywny context gatherer (Gemini)

### Overview

Odpowiednik `LlmFreeContextGatherer`, ale zawsze wykonujący własne, świeże
wyszukiwanie `googleSearch` przez `GeminiClient` — bez sprawdzania cache'u
`ai_analyses`/`ai_critical_reviews` generowanego przez Claude (decyzja usera: pełna
izolacja providerów).

### Changes Required:

#### 1. `src/LlmGemini/LlmGeminiContextGatherer.php`

**Intent**: Dla każdego kandydata (do limitu `context_search_cap`, ta sama logika
cost-bounding co Free) wywołuje `GeminiClient` z narzędziem `googleSearch`, zwraca
mapę `ticker => tekst kontekstu` w identycznym kształcie jak `LlmFreeContextGatherer::gather()`
(`array<string, string>`), żeby `LlmGeminiDecisionService::buildDataBlock()` (klon
`LlmFreeDecisionService`'a) mógł go skonsumować bez zmian w formacie promptu.

**Contract**: `class LlmGeminiContextGatherer` w namespace `CVS\LlmGemini`, konstruktor
`(array $geminiConfig, int $searchCap, ?GeminiClient $clientOverride = null)` — bez
zależności od `AiAnalysisRepository`/`AiCriticalReviewRepository` (brak kroku
sprawdzania cache'u, w odróżnieniu od `LlmFreeContextGatherer`). Metoda
`gather(array $candidateTickers): array` iteruje pierwsze `$searchCap` tickerów z
listy (reszta bez kontekstu — ten sam guardrail kosztowy co Free), woła
`GeminiClient::sendMessage()` z `options['tools'] = [['googleSearch' => new \stdClass()]]`
(pusty obiekt JSON, nie tablica — inaczej `json_encode` wyrenderuje `[]` zamiast `{}`),
system prompt i user message analogiczne treściowo do `LlmFreeContextGatherer`'s
(szukaj newsów z ~14 dni, po polsku, z datami), zapisuje `error_log()` przy porażce
pojedynczego tickera bez przerywania pętli (mirror `LlmFreeContextGatherer::search()`).

#### 2. `tests/LlmGemini/LlmGeminiContextGathererTest.php`

**Intent**: Testy z `FakeTransport`/fake `GeminiClient`, mirror
`tests/LlmFree/LlmFreeContextGathererTest.php` pomniejszony o testy cache'u (bo go nie ma).

**Contract**: Pokrycie: cap ogranicza liczbę wywołań do `searchCap`; porażka
pojedynczego tickera nie przerywa pętli; pusta lista kandydatów zwraca `[]` bez
wywołania klienta.

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit tests/LlmGemini/LlmGeminiContextGathererTest.php` przechodzi zielono
- `composer stan` czysty dla `src/LlmGemini/LlmGeminiContextGatherer.php`

#### Manual Verification:

- Ręczne wywołanie `gather()` z 2-3 realnymi tickerami i prawdziwym kluczem zwraca
  sensowny, świeży, polskojęzyczny kontekst z datami (weryfikacja jakościowa, nie
  tylko strukturalna)

---

## Phase 3: Moduł portfela LLM Gemini + baza danych

### Overview

Klon `src/LlmFree/*` → `src/LlmGemini/*` (poza `DecisionParser`, reużytym z
`LlmFree` bez zmian — patrz Key Discoveries), nowa migracja `038`, nowy config,
routing, nawigacja.

### Changes Required:

#### 1. `database/migrations/038_create_llm_gemini_wallet_tables.sql`

**Intent**: Cztery tabele izolowane 1:1 jak `035_create_llm_free_wallet_tables.sql`,
tylko z prefiksem `llm_gemini_` zamiast `llm_free_`.

**Contract**: Dosłowna kopia struktury `035` (kolumny, typy, `UNIQUE`/`FOREIGN KEY`,
komentarze) z podmienionymi nazwami tabel (`llm_gemini_cycle/state/holdings/transactions`),
kluczy obcych (`fk_llm_gemini_tx_cycle`) i indeksów (`idx_llm_gemini_tx_cycle_id`).
Seed `llm_gemini_state`: `INSERT IGNORE ... VALUES (1, 10000.00, 10000.00, NOW())`
— identyczny kapitał startowy. Nagłówek migracji dopisuje, że tabela jest izolowana
też od `llm_free_*` (zero FK, zero współdzielonego stanu między dwoma eksperymentalnymi
portfelami).

#### 2. `config/llm-gemini-wallet.php`

**Intent**: Konfiguracja portfela mirror `config/llm-free-wallet.php` 1:1 w
parametrach startowych, bez klucza `llm.model` (bo model już jest env-driven w
`config/gemini.php`).

**Contract**: Ta sama struktura co `llm-free-wallet.php`: `initial_capital_usd
=> 10000.0`, identyczny blok `market` (open 09:30/close 17:00/America/New_York),
`rebalance_window_minutes => 90`, `legend_context_count => 10`,
`context_search_cap => 3`, `legend_max_chars => 4000`, `max_candidates => 40`.
Blok `llm` bez klucza `'model'` (czytany z `config/gemini.php`), z
`max_retries => 0` (retry na poziomie serwisu, mirror Free), `max_tokens => 8192`,
`timeout => 180`, `total_timeout => 200`, `retry_delay_seconds => 2`,
`system_prompt_ttl => '5m'` (parametr przyjmowany dla zgodności sygnatury, ignorowany
przez `GeminiClient` — patrz Critical Implementation Details).

#### 3. `src/LlmGemini/LlmGeminiRepository.php`

**Intent**: Warstwa czysto odczytowa, klon `LlmFreeRepository` z podmienioną
nazwą tabel (`llm_gemini_state/holdings/cycle`) i namespace `CVS\LlmGemini`.

**Contract**: Metody identyczne co do sygnatury: `getCurrentState()`,
`getCurrentHoldings()`, `getCurrentHoldingsWithPrice()` (ten sam JOIN do
`cvs_snapshots` filtrowany po `model_version` i `origin='RESCORE'`),
`getLegendHistory(int $limit)`.

#### 4. `src/LlmGemini/LlmGeminiCycleRepository.php`

**Intent**: Klon `LlmFreeCycleRepository`, operuje na `llm_gemini_cycle`.

**Contract**: `claimForRun(string $cycleDate, int $maxAttempts): ?int`,
`updateLlmRecord(...)`, `updateCycleSummary(...)`, `updateStatus(int $id, string $status)`
— identyczne sygnatury.

#### 5. `src/LlmGemini/LlmGeminiService.php`

**Intent**: Klon `LlmFreeService` — jedyna klasa piszącą do bazy portfela Gemini,
te same guardy fizyczne (BUY ≤ gotówka, SELL ≤ posiadana ilość), **brak
DecisionEnforcera**, mark-to-market od pierwszej linii kodu.

**Contract**: `executeCycle(int $cycleId, array $decisions, array $priceMap, ?string $dropNote): void`
— identyczna sygnatura i logika transakcyjna (jedna transakcja PDO,
`handleBuy`/`handleSell`/`recordHoldInternal`/`recordNoActionInternal`).

#### 6. `src/LlmGemini/LlmGeminiDecisionService.php`

**Intent**: Klon `LlmFreeDecisionService`, ale konstruujący `GeminiClient` przez
`GeminiClientFactory::fromConfig()` zamiast `ClaudeClient`/`ClaudeClientFactory`.
System prompt (metodologia CVS, brak przymusu działania, format JSON
`{decisions, legend}`) pozostaje **treściowo identyczny** — to sedno eksperymentu
(ta sama instrukcja, inny wykonawca).

**Contract**: `generate(int $cycleId, array $portfolioState, array $holdings, array $screenerRows, array $legendHistory, array $contextByTicker = []): array{ok,decisions,legend,retryCount,rawResponse,failureKind}`
— identyczna sygnatura. Parsowanie odpowiedzi przez **reużyty**
`CVS\LlmFree\LlmFreeDecisionParser` (import cross-namespace, świadoma decyzja —
patrz Key Discoveries), nie duplikat. `$this->aiConfig` to merge
`config/gemini.php` + `config/llm-gemini-wallet.php['llm']` (mirror
`array_merge($aiConfig, $config['llm'])` z `bin/llm-free-wallet-rebalance.php`).

#### 7. `src/LlmGemini/LlmGeminiController.php`

**Intent**: Kontroler tylko-do-odczytu dla `GET /llm-gemini`, klon
`LlmFreeController::index()`.

**Contract**: Ładuje `config/cvs-weights.php` + `config/llm-gemini-wallet.php`,
dogrywa live ceny przez `LivePriceProvider` z **własnym kluczem cache w sesji**
(np. `cvs_llmgemini_px` — musi różnić się od `cvs_llmfree_px` i bazowego, żeby nie
kolidować z cache pozostałych dwóch portfeli), pobiera historię legendy i buduje
wykres NAV przez `WalletNavChartService` (reużyty bez zmian — provider-agnostic).

#### 8. Routing i nawigacja

**Intent**: Wpiąć trzeci portfel w istniejący routing i menu „Portfele".

**Contract**: `src/Core/routes.php` — dodać `use CVS\LlmGemini\LlmGeminiController;`
i blok analogiczny do linii 169-174 (`$llmGemini = new LlmGeminiController();
$router->get('/llm-gemini', fn($req) => $llmGemini->index($req));`).
`templates/layout.php:49-52` — dodać trzeci `<li>` w `admin-menu__dropdown`:
`<li><a href="/llm-gemini" role="menuitem"<?= str_starts_with(...) ? ' aria-current="page"' : '' ?>>LLM Gemini</a></li>`.

#### 9. `templates/llm-gemini.php` (widok)

**Intent**: Szablon widoku portfela, klon `templates/llm-free.php` (jeśli istnieje
pod tą nazwą — zweryfikować dokładną nazwę pliku przy implementacji) z podmienionym
tytułem/etykietami na „LLM Gemini".

**Contract**: Ta sama struktura co szablon Free: kafelki stanu (gotówka/wartość
portfela), tabela holdingów, wykres NAV, akordeon historii legendy.

#### 10. Testy modułu

**Intent**: Klon całego `tests/LlmFree/*` → `tests/LlmGemini/*` (poza testami
parsera — reużywamy istniejący `LlmFreeDecisionParserTest`, nic nowego do
przetestowania tam).

**Contract**: `LlmGeminiRepositoryTest`, `LlmGeminiServiceTest`,
`LlmGeminiCycleRepositoryTest`, `LlmGeminiDecisionServiceTest` — te same scenariusze
co odpowiedniki Free (deterministyczna „hydraulika": budowanie promptu, parsowanie,
zapis, idempotencja — nigdy „czy model podjął dobrą decyzję", mirror
`FakeTransport`-owego wzorca testowania modułów niedeterministycznych).

### Success Criteria:

#### Automated Verification:

- Migracja aplikuje się czysto: uruchomienie `038_*.sql` na bazie deweloperskiej/testowej
- `vendor/bin/phpunit tests/LlmGemini/` przechodzi zielono
- Pełny `vendor/bin/phpunit` zielony (zero regresji w `tests/LlmFree/`)
- `composer stan` czysty dla `src/LlmGemini/`

#### Manual Verification:

- `/llm-gemini` renderuje się bez błędów w przeglądarce, pokazuje 10 000 USD gotówki
  i brak holdingów (świeży seed)
- Link „LLM Gemini" w dropdownie „Portfele" działa i podświetla `aria-current` na
  właściwej stronie
- Ręczny insert testowego wiersza do `llm_gemini_cycle`/`llm_gemini_transactions`
  potwierdza że `/llm-gemini` poprawnie odczytuje i renderuje dane (bez kolizji z
  cache sesyjnym pozostałych portfeli)

---

## Phase 4: Cron entrypoint i deployment

### Overview

CLI entrypoint mirror `bin/llm-free-wallet-rebalance.php`, plus finalna ścieżka i
komenda crona do ręcznego założenia przez usera na CF.

### Changes Required:

#### 1. `bin/llm-gemini-wallet-rebalance.php`

**Intent**: Dokładny klon `bin/llm-free-wallet-rebalance.php` (guard CLI, log do
`logs/llm-gemini-wallet-rebalance.log`, `.env` parser, `$_SESSION=[]` workaround,
bramka `MarketCalendar` z `config/llm-gemini-wallet.php['market']`, idempotentne
`claimForRun`, zbieranie danych przez `ScreenerRepository`/`SnapshotFreshness`/`PeerCoverage`,
try/catch wokół silnika decyzyjnego → `llm_failed` przy crashu, wstrzyknięcie cen
wykonania, `Database::reconnect()` przed zapisem, `LlmGeminiService::executeCycle()`).

**Contract**: Podmienione klasy: `LlmGeminiContextGatherer` (bez
`AiAnalysisRepository`/`AiCriticalReviewRepository` — patrz Faza 2),
`LlmGeminiCycleRepository`, `LlmGeminiDecisionService`, `LlmGeminiRepository`,
`LlmGeminiService`. Config: `require config/llm-gemini-wallet.php` +
`require config/gemini.php` (zamiast `config/ai.php`) +
`array_merge($geminiConfig, $walletConfig['llm'])`. Docblock dokumentuje wybrany
harmonogram (patrz Critical Implementation Details) i jego wyprowadzenie z
harmonogramu Free.

**Cron entries (CyberFolks panel → typ "Ścieżka", PHP 8.2, mirror Free):**
```
40 21 * * 1-5  /usr/local/bin/php82 /home/amjsystem/sites/cvs.timeflow.fun/bin/llm-gemini-wallet-rebalance.php
40 22 * * 1-5  /usr/local/bin/php82 /home/amjsystem/sites/cvs.timeflow.fun/bin/llm-gemini-wallet-rebalance.php
```

#### 2. `logs/.gitkeep` lub istniejący katalog `logs/`

**Intent**: Upewnić się że `logs/` istnieje na serwerze (mirror istniejących
entrypointów, `mkdir` fallback już jest w kodzie skryptu, więc to tylko weryfikacja,
nie zmiana).

**Contract**: Brak zmiany kodu — `bin/llm-gemini-wallet-rebalance.php` tworzy katalog
sam (`if (!is_dir(...)) mkdir(...)`), mirror Free.

### Success Criteria:

#### Automated Verification:

- `php -l bin/llm-gemini-wallet-rebalance.php` (składnia czysta — krytyczne przed
  deployem, mirror lekcji z `cvs-project-state`: podwójny `<?php` zepsuł produkcję
  raz już w innym pliku)
- Ręczne uruchomienie `php bin/llm-gemini-wallet-rebalance.php` lokalnie (albo na
  serwerze przez SSH) w oknie rynkowym kończy się `exit(0)` lub `exit(1)` z czytelnym
  logiem w `logs/llm-gemini-wallet-rebalance.log`, nigdy nieobsłużonym fatal errorem

#### Manual Verification:

- Pierwsze prawdziwe uruchomienie na serwerze (po ręcznym założeniu crona przez
  usera) zapisuje wiersz `llm_gemini_cycle` ze statusem `completed`, niepusty
  `legend`, `tokens_input`/`tokens_output` > 0
- `/llm-gemini` po pierwszym cyklu pokazuje ewentualne transakcje i nowy wpis
  legendy w akordeonie historii

---

## Testing Strategy

### Unit Tests:

- `GeminiClient`: parsowanie sukcesu/błędu, mapowanie kodów HTTP na `AiFailureKind`,
  retry/backoff, budowa URL z modelem w ścieżce, nagłówek `x-goog-api-key`.
- `LlmGeminiContextGatherer`: cap wyszukiwań, odporność na porażkę pojedynczego tickera.
- `LlmGeminiDecisionService`/`LlmGeminiCycleRepository`/`LlmGeminiRepository`/`LlmGeminiService`:
  deterministyczna „hydraulika" na `FakeTransport`, mirror testów Free.

### Integration Tests:

- Pełny cykl `bin/llm-gemini-wallet-rebalance.php` z fake/staged danymi (jeśli
  istnieje analogiczny harness do tego używanego dla Free — zweryfikować przy
  implementacji; jeśli nie istnieje, integracja weryfikowana manualnie w Fazie 4).

### Manual Testing Steps:

1. Ręczny smoke-test `curl`/PHP do Gemini API z prawdziwym kluczem — potwierdzić
   kształt `tools`/`groundingMetadata` (Faza 1).
2. Ręczne wywołanie `LlmGeminiContextGatherer::gather()` na 2-3 realnych tickerach
   — ocena jakościowa kontekstu (Faza 2).
3. `/llm-gemini` w przeglądarce ze świeżym seedem (Faza 3).
4. Pierwsze prawdziwe uruchomienie crona na serwerze po deployu (Faza 4).

## Performance Considerations

Realny wolumen wywołań Gemini/dzień: 1 wywołanie decyzyjne + do `context_search_cap`=3
wywołania kontekstowe (jednoturowe, bez retry w środku pętli serwisowej) — rząd
pojedynczych cyfr, dobrze poniżej darmowego limitu 10 RPM niezależnie od wybranego
modelu Flash. Dwa cron entries (primary+backup) w normalnym dniu wykonują tylko
jeden efektywny cykl (idempotencja `claimForRun`), więc nie podwajają wolumenu.

## Migration Notes

Migracja `038` jest czysto addytywna (`CREATE TABLE IF NOT EXISTS`), zero zmian w
istniejących tabelach. Zgodnie z ustalonym workflow projektu (patrz pamięć
`ssh-credentials-pattern`), migracja uruchamiana ręcznie na produkcyjnej bazie CF
przez SSH: `source .env` + `MYSQL_PWD=$DB_PASS mysql ...` (NIGDY `-p"hasło"` w
argumencie — klasyfikator bezpieczeństwa to blokuje nawet gdy hasło pochodzi z `.env`).

## References

- Wzorzec do klonowania: `src/LlmFree/*`, `database/migrations/035_create_llm_free_wallet_tables.sql`,
  `bin/llm-free-wallet-rebalance.php`, `config/llm-free-wallet.php`.
- Research: `context/changes/llm-gemini-wallet/research.md`.
- Klient Claude jako punkt odniesienia kontraktu: `src/Ai/ClaudeClient.php`,
  `src/Ai/ClaudeClientFactory.php`, `src/Ai/AiResult.php`, `src/Ai/AiFailureKind.php`,
  `src/Ai/HttpTransport.php`, `src/Ai/CacheableSystem.php`.
- Gemini REST API (zweryfikowane 2026-08-19): `https://ai.google.dev/api/generate-content`,
  `https://ai.google.dev/gemini-api/docs/api-key`, `https://ai.google.dev/gemini-api/docs/api-errors`,
  `https://ai.google.dev/gemini-api/docs/google-search`, `https://ai.google.dev/gemini-api/docs/deprecations`.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands.

### Phase 1: Fundament klienta Gemini

#### Automated

- [x] 1.1 `vendor/bin/phpunit tests/Ai/GeminiClientTest.php` zielony — 156f10b
- [x] 1.2 `composer stan` czysty dla `src/Ai/GeminiClient.php`/`GeminiClientFactory.php` — 156f10b
- [x] 1.3 Pełny `vendor/bin/phpunit` zielony (zero regresji) — 156f10b

#### Manual

- [x] 1.4 Smoke-test curl potwierdza kształt `tools`/`groundingMetadata` — 156f10b

### Phase 2: Natywny context gatherer (Gemini)

#### Automated

- [x] 2.1 `vendor/bin/phpunit tests/LlmGemini/LlmGeminiContextGathererTest.php` zielony — 198f373
- [x] 2.2 `composer stan` czysty dla `LlmGeminiContextGatherer.php` — 198f373

#### Manual

- [ ] 2.3 Ręczne `gather()` na realnych tickerach — ocena jakościowa kontekstu (ODROCZONE do Fazy 4 — kod jeszcze niewdrożony na serwer; Faza 1 smoke-test już potwierdził mechanizm googleSearch na żywo, pełny gather() z polskim promptem zweryfikuje pierwsze prawdziwe uruchomienie crona)

### Phase 3: Moduł portfela LLM Gemini + baza danych

#### Automated

- [x] 3.1 Migracja `038_*.sql` aplikuje się czysto (strukturalny klon 035, weryfikacja pełna w Fazie 4 na produkcji)
- [x] 3.2 `vendor/bin/phpunit tests/LlmGemini/` zielony
- [x] 3.3 Pełny `vendor/bin/phpunit` zielony (zero regresji w `tests/LlmFree/`)
- [x] 3.4 `composer stan` czysty dla `src/LlmGemini/`

#### Manual

- [ ] 3.5 `/llm-gemini` renderuje się, 10 000 USD gotówki, brak holdingów (ODROCZONE do Fazy 4 — wymaga wdrożenia + migracji na produkcji, weryfikowane razem z pierwszym uruchomieniem crona)
- [ ] 3.6 Link „LLM Gemini" w menu działa, `aria-current` poprawny (ODROCZONE do Fazy 4)
- [ ] 3.7 Testowy wiersz w `llm_gemini_cycle`/`llm_gemini_transactions` renderuje się poprawnie (ODROCZONE do Fazy 4)

### Phase 4: Cron entrypoint i deployment

#### Automated

- [ ] 4.1 `php -l bin/llm-gemini-wallet-rebalance.php` czysty
- [ ] 4.2 Ręczne uruchomienie kończy się `exit(0)`/`exit(1)` z czytelnym logiem

#### Manual

- [ ] 4.3 Pierwsze uruchomienie na serwerze zapisuje `completed` cycle z legendą i tokenami
- [ ] 4.4 `/llm-gemini` pokazuje transakcje/legendę po pierwszym cyklu
