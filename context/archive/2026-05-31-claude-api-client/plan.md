# F-02: Klient Claude API w `src/Ai/` — Implementation Plan

## Overview

Zbudować jeden reużywalny klient Claude API (Anthropic Messages API) w `src/Ai/`
(`CVS\Ai\`), zgodny z guardrailami fazy 2: timeout < 30s, retry z backoffem na błędy
przejściowe, **typed failure** zamiast wyjątku psującego stronę, prompt caching pod
kontrolę kosztu, klucz z `.env`. To fundament F-02 z roadmapy — sam klient + wzorzec do
reużycia. Nie zawiera logiki analizy (S-01), współdzielonego cache w DB (S-01), ani bramy
PRO / trackingu zużycia (F-05). Klient ma jednak **wystawiać dane o tokenach**, by FR-004
mógł je później wykorzystać bez zmiany klienta.

## Current State Analysis

- **Brak warstwy AI** w kodzie — `src/Ai/` nie istnieje. To pierwsza płatna zależność zewnętrzna.
- **Env**: własny parser `.env` → `$_ENV` w `public/index.php:9-19` (tylko `$_ENV`, bez `getenv()`).
- **Config**: pliki w `config/` zwracają tablice i są `require`-owane + wstrzykiwane przez
  konstruktor (`config/cvs-weights.php`; `AnalysisController.php:28-35`; `CVSModel.php:38`).
- **Typed result — wzorzec do naśladowania**: `src/CVS/CVSResult.php` (prywatny `__construct`,
  statyczne named-constructors `::passed()/::failed()`, `public readonly` props, `toArray()`).
  `QualityGateResult` analogicznie.
- **Graceful degradation już istnieje**: `AnalysisController::show()` (~116-140) przy błędzie
  fetcha przekazuje `'error' => <pl> + null` do `Response::view`, nie rzuca do użytkownika.
- **cURL w repo**: `FinancialDataFetcher` ma prywatny helper cURL (`~276-322`), timeout z configu,
  `JSON_THROW_ON_ERROR`/`catch (\JsonException)`. Sygnalizuje błąd przez `null`.
- **Brak abstrakcji HTTP** w `src/` (zero interface'ów). DI w całym repo = konstruktorowe,
  `private readonly`.
- **Brak `error_log` w `src/`** — ten klient zakłada pierwszy site; konwencja z CLAUDE.md:
  loguj i zwróć typed failure. Prefiks `[Ai]`.
- **Testy offline**: namespace `CVS\Tests\…`, `extends TestCase`, config przez `require`,
  fixture przez `array_merge` z override. `FinancialDataFetcher` nie jest testowany (sieć).
- **Wzorzec MiJuLinguo** (`C:\python\MiJuLinguo\api\claude-client.php`): prosty cURL, **rzuca
  wyjątki**, bez retry/cachingu/typed-failure, `anthropic-version: 2023-06-01`. Baza do
  przepisania pod guardraile, nie do skopiowania 1:1.
- **API (ugruntowane w Context7, `/websites/platform_claude_en_api`)**: `POST /v1/messages`;
  `anthropic-version: 2023-06-01`; prompt caching = `cache_control: {type:"ephemeral", ttl:"5m"|"1h"}`
  na bloku (5m = GA, 1h = beta `extended-cache-ttl-2025-04-11`); odpowiedź ma `content[].text`,
  `stop_reason`, `usage{input_tokens, output_tokens, cache_creation_input_tokens, cache_read_input_tokens}`;
  błędy przejściowe to **429** (rate limit) i **529** (overloaded). ⚠️ Indeks zwracał starsze ID
  modeli — dlatego ID modelu trzymamy w configu/`.env` (dev potwierdza aktualne ID przy wdrożeniu).

## Desired End State

Po ukończeniu planu w repo istnieje `CVS\Ai\ClaudeClient`, który:
- przyjmuje wiadomości + opcjonalny (cacheowalny) system prompt i zwraca **`AiResult`** —
  `success(text, usage, stopReason, model)` albo `failure(kind, message)` — **nigdy nie rzuca**
  do warstwy wyżej;
- ponawia ≤2 razy na 429/529/timeout/sieć z backoffem, mieszcząc się w budżecie < ~25s;
- ustawia `cache_control` ephemeral na oznaczonym bloku system (5m domyślnie, 1h opcjonalnie);
- czyta konfigurację z `config/ai.php` + `.env`, jest składany przez `ClaudeClientFactory::fromConfig()`;
- jest w pełni przetestowany **offline** przez wstrzykiwany `FakeTransport` (zero realnych wywołań);
- nigdy nie loguje klucza API.

**Weryfikacja**: `vendor/bin/phpunit` zielone (nowe testy `CVS\Tests\Ai\…`), `vendor/bin/phpstan
analyse` bez błędów, `git grep` nie pokazuje cURL do Anthropic poza `src/Ai/`.

### Key Discoveries:

- Wzorzec value-objektu: `src/CVS/CVSResult.php` (named constructors + `readonly` + `toArray()`).
- Graceful degradation do skopiowania: `src/CVS/AnalysisController.php:116-140`.
- Konwencja cURL/JSON: `src/Api/FinancialDataFetcher.php:276-322`.
- Env/config: `public/index.php:9-21`, `src/Core/Database.php:29-34`, `config/cvs-weights.php`.
- Retry tylko na 429/529 + timeout/sieć; `anthropic-version: 2023-06-01`; usage zawiera tokeny cache.

## What We're NOT Doing

- **Logika analizy AI / prompt rozjazdu CVS vs analitycy** — to S-01 (osobny skill analizy).
- **Współdzielony cache analiz w DB / migracje** — S-01.
- **Brama PRO i tracking zużycia per user (persystencja)** — F-05/S-01. (Klient tylko *wystawia*
  `usage`; nie zapisuje go.)
- **Streaming / SSE** — nie teraz; sygnatura zaprojektowana tak, by dało się dodać później.
- **Live smoke test na realnym API** — testy wyłącznie offline (FakeTransport).
- **Mailer, scheduler, screener** — inne fundamenty/slice'y.

## Implementation Approach

Trzy przyrostowe fazy: (1) typowane kontrakty + config + interfejs transportu (bez zachowania,
testowalne w izolacji), (2) implementacja klienta + transport cURL + factory (rdzeń: budowa
requestu, caching, retry, mapowanie błędów, redakcja klucza), (3) pełna matryca testów offline +
PHPStan + notka. Seam `HttpTransport` izoluje sieć, więc cała logika klienta jest testowalna bez
realnych wywołań — kluczowe dla guardrailu „awaria AI nie psuje strony" (testujemy każdy tryb porażki).

## Critical Implementation Details

- **Budżet czasu vs retry**: całkowity czas (próby + backoff) musi zostać < ~25s, by user-perceived
  total trzymał NFR < 30s. Przy `AI_TIMEOUT` per-próba ~7-8s i ≤2 retry, backoff musi być krótki
  (np. 0.5s, 1s) i respektować nagłówek `Retry-After`, jeśli krótszy niż budżet. Backoff bazuje na
  czasie zegarowym tylko w warstwie transportu/retry — **nie** w logice CVS (determinizm nietknięty).
- **Redakcja sekretu**: klucz API trafia tylko do nagłówka `x-api-key`; nigdy do `error_log`,
  komunikatu `AiFailure`, ani `toArray()`. To explicit guardrail z testem.

## Phase 1: Kontrakty + config + seam transportu

### Overview
Typowane wartości zwracane, konfiguracja i interfejs transportu — bez sieci i bez zachowania klienta.

### Changes Required:

#### 1. Typed result (value objects)

**File**: `src/Ai/AiResult.php`, `src/Ai/AiUsage.php`, `src/Ai/AiFailureKind.php`

**Intent**: Reprezentować wynik wywołania AI jako jeden typowany obiekt sukces|porażka, wzorowany
na `CVSResult`. Sukces niesie tekst, `AiUsage` (tokeny) , `stopReason`, `model`; porażka niesie
`kind` (enum) + komunikat PL. Nigdy nie zawiera klucza API.

**Contract**:
- `AiFailureKind` — enum: `Timeout`, `RateLimited`, `Overloaded`, `Auth`, `Quota`, `BadResponse`, `Network`.
- `AiUsage` — `readonly` int: `inputTokens, outputTokens, cacheCreationInputTokens, cacheReadInputTokens`.
- `AiResult` — `private __construct`; `public readonly` props; statyczne `AiResult::success(string $text,
  AiUsage $usage, string $stopReason, string $model)` i `AiResult::failure(AiFailureKind $kind, string $message)`;
  `bool $ok`; `toArray(): array` (bez sekretów). Wzorzec: `src/CVS/CVSResult.php`.

#### 2. Konfiguracja

**File**: `config/ai.php`, `.env.example`

**Intent**: Wystawić ustawienia klienta jako tablicę z `.env` (mirror `config/cvs-weights.php`),
nigdy hardkodować klucza ani ID modelu.

**Contract**: `config/ai.php` zwraca tablicę z kluczami: `api_key` (`$_ENV['ANTHROPIC_API_KEY']`),
`base_url` (default `https://api.anthropic.com/v1/messages`), `model` (`$_ENV['AI_MODEL']`),
`anthropic_version` (default `2023-06-01`), `max_tokens` (int), `timeout` (int sek.),
`max_retries` (int, default 2). `.env.example` dostaje zakomentowane: `ANTHROPIC_API_KEY=`,
`AI_MODEL=`, `AI_MAX_TOKENS=`, `AI_TIMEOUT=`, `AI_MAX_RETRIES=` z komentarzem, że `.env` nie jest commitowany.

#### 3. Interfejs transportu

**File**: `src/Ai/HttpTransport.php`

**Intent**: Izolować realne wywołanie HTTP za wąskim interfejsem, by klient był testowalny offline.

**Contract**: `interface HttpTransport { public function send(string $url, string $jsonBody, array<string> $headers, int $timeout): array; }`
zwraca `array{status:int, body:string, error:?string}` (bez wyjątków — błąd sieci/timeout jako
`error` + `status=0`).

### Success Criteria:

#### Automated Verification:
- PHPStan zielony: `vendor/bin/phpstan analyse`
- Testy value-objektów przechodzą: `vendor/bin/phpunit tests/Ai/AiResultTest.php`
- `config/ai.php` zwraca tablicę z wymaganymi kluczami (test ładujący plik)

#### Manual Verification:
- `.env.example` czytelnie dokumentuje nowe klucze; brak realnego klucza w repo

---

## Phase 2: Klient + transport + factory

### Overview
Rdzeń: implementacja cURL transportu, budowa/parsowanie requestu Messages API, prompt caching,
retry/backoff, mapowanie statusów na `AiFailureKind`, redakcja klucza, factory.

### Changes Required:

#### 1. Transport cURL

**File**: `src/Ai/CurlTransport.php`

**Intent**: Jedyne miejsce z realnym cURL do Anthropic; spójne z konwencją `FinancialDataFetcher`.

**Contract**: `implements HttpTransport`. `curl_setopt_array` z `CURLOPT_POST`, `CURLOPT_RETURNTRANSFER`,
`CURLOPT_TIMEOUT` = przekazany timeout; zwraca `{status, body, error}` (nigdy nie rzuca). Nagłówki
przekazane przez wołającego (zawierają `x-api-key`, `anthropic-version`, `content-type`).

#### 2. ClaudeClient

**File**: `src/Ai/ClaudeClient.php`

**Intent**: Złożyć request Messages API z opcjonalnym cacheowalnym blokiem system, wykonać go przez
`HttpTransport` z retry/backoffem, sparsować odpowiedź do `AiResult`, a każdy błąd zmapować na
typed `AiFailure` — nigdy nie rzucając wyżej.

**Contract**:
- Konstruktor: `__construct(private readonly array $config, private readonly HttpTransport $transport)`.
- Metoda publiczna: `sendMessage(array $messages, ?CacheableSystem $system = null, array $options = []): AiResult`
  (sygnatura zostawia miejsce na przyszły streaming jako osobną metodę). `messages` = lista
  `{role, content}`. `$system` opcjonalnie niesie tekst + flagę `ttl` (`5m`|`1h`).
- Budowa body: `model`, `max_tokens`, `messages`, oraz `system` jako blok z `cache_control:
  {type:"ephemeral", ttl}` gdy `$system` podany. Dla `ttl=1h` dołącz nagłówek
  `anthropic-beta: extended-cache-ttl-2025-04-11`.
- Retry: ponów na `status ∈ {429,529}` lub `error≠null` (timeout/sieć), ≤ `max_retries`, exponential
  backoff z poszanowaniem `Retry-After`; sumaryczny czas w budżecie < ~25s.
- Mapowanie porażek: `0/error`→`Timeout`/`Network`, `401/403`→`Auth`, `429`→`RateLimited`,
  `529`→`Overloaded`, `400` z typem `…quota…`/`billing`→`Quota`, parsowanie/`content` puste→`BadResponse`.
  Każda porażka: `error_log('[Ai] …')` (bez klucza) + `AiResult::failure(...)`.
- Parsowanie sukcesu: `content[0].text`, `stop_reason`, `usage{...}` → `AiResult::success(...)`.

#### 3. Factory

**File**: `src/Ai/ClaudeClientFactory.php`

**Intent**: Jeden punkt konstrukcji klienta z configu (domyślny `CurlTransport`), by S-01 i przyszłe
miejsca nie powielały wiringu.

**Contract**: `ClaudeClientFactory::fromConfig(array $config, ?HttpTransport $transport = null): ClaudeClient`
(domyślnie `new CurlTransport()`).

### Success Criteria:

#### Automated Verification:
- PHPStan zielony: `vendor/bin/phpstan analyse`
- Testy klienta (offline, FakeTransport) przechodzą: `vendor/bin/phpunit tests/Ai/ClaudeClientTest.php`
- Pełny suite zielony: `vendor/bin/phpunit`

#### Manual Verification:
- (Opcjonalnie, poza repo) ręczne wywołanie z realnym kluczem w lokalnym `.env` zwraca tekst —
  weryfikacja kontraktu API. Nie część testów automatycznych.

---

## Phase 3: Pełna matryca testów offline + polish

### Overview
Zablokować każdy guardrail testem i domknąć jakość.

### Changes Required:

#### 1. Fake transport + testy guardraili

**File**: `tests/Ai/FakeTransport.php`, `tests/Ai/ClaudeClientTest.php`, `tests/Ai/AiResultTest.php`

**Intent**: Sterowalny `HttpTransport` zwracający zaplanowane odpowiedzi/sekwencje, pozwalający
przetestować sukces i każdy tryb porażki oraz retry — bez sieci.

**Contract**: `FakeTransport implements HttpTransport`, kolejka odpowiedzi `{status,body,error}`
(by zasymulować 429→200). Testy: (a) sukces + poprawne sparsowanie `usage`/`stopReason`/`model`;
(b) `429` potem `200` → sukces po retry; (c) `529` wyczerpuje retry → `Overloaded`; (d) `error`
(timeout) → `Timeout`/`Network`; (e) zły JSON / puste `content` → `BadResponse`; (f) klucz API
**nie** pojawia się w `error_log` ani w `AiResult::toArray()`; (g) `cache_control`/nagłówek beta
obecne w body gdy `ttl=1h`.

#### 2. Polish

**File**: `CLAUDE.md` (sekcja Architecture — dopisać `CVS\Ai\`), `.env.example`

**Intent**: Zaktualizować mapę namespace'ów i upewnić się, że konfiguracja jest udokumentowana.

**Contract**: Krótka adnotacja, że `CVS\Ai\` zawiera klient LLM (Messages API) z typed-failure;
bez zmian w regułach.

### Success Criteria:

#### Automated Verification:
- Pełny suite zielony: `vendor/bin/phpunit`
- PHPStan zielony: `vendor/bin/phpstan analyse`
- Brak cURL do Anthropic poza `src/Ai/`: `git grep -n "api.anthropic.com" src/` zwraca tylko `src/Ai/`

#### Manual Verification:
- Przegląd: żaden test nie wykonuje realnego wywołania sieciowego (wszystko przez `FakeTransport`)
- Klucz API nie występuje nigdzie w repo ani w logach

---

## Testing Strategy

### Unit Tests:
- `AiResult`/`AiUsage`: poprawne `success`/`failure`, `toArray()` bez sekretów.
- `ClaudeClient` przez `FakeTransport`: sukces, retry-then-success (429), wyczerpany retry (529),
  timeout/sieć, zły JSON/puste `content`, redakcja klucza, obecność `cache_control` + nagłówka beta dla 1h.

### Integration Tests:
- Brak (offline-only). Realny kontrakt API weryfikowany ręcznie poza CI.

### Manual Testing Steps:
1. Skopiuj `.env.example` → `.env`, ustaw `ANTHROPIC_API_KEY` i `AI_MODEL` (aktualne ID).
2. Lokalny skrypt ad-hoc: zbuduj klienta przez factory, wyślij prosty prompt, potwierdź zwrot tekstu + `usage`.
3. Wymuś błędny klucz → potwierdź `AiFailureKind::Auth` i brak wycieku klucza w logu.

## Performance Considerations

Budżet < 30s user-perceived: per-próba timeout ~7-8s, ≤2 retry, krótki backoff z `Retry-After` →
suma < ~25s. Prompt caching (system block) tnie koszt i latencję powtarzalnych wywołań — pełne
wykorzystanie dopiero w S-01 (stabilny system prompt).

## Migration Notes

Brak zmian schematu/DB w tym fundamencie. Dochodzi nowy namespace `CVS\Ai\` (PSR-4, `src/Ai/`) i
plik `config/ai.php`. Nowe klucze `.env` są opcjonalne do czasu użycia klienta przez S-01.

## References

- Roadmap: `context/foundation/roadmap.md` (F-02)
- PRD: `context/foundation/prd.md` (FR-001, guardrails AI)
- Wzorzec value-objektu: `src/CVS/CVSResult.php`
- Graceful degradation: `src/CVS/AnalysisController.php:116-140`
- Konwencja cURL/JSON: `src/Api/FinancialDataFetcher.php:276-322`
- Env/config: `public/index.php:9-21`, `src/Core/Database.php:29-34`, `config/cvs-weights.php`
- Wzorzec bazowy (do przepisania): `C:\python\MiJuLinguo\api\claude-client.php`
- API ugruntowane: Context7 `/websites/platform_claude_en_api` (Messages API, prompt caching, retry codes)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles.

### Phase 1: Kontrakty + config + seam transportu

#### Automated
- [x] 1.1 PHPStan zielony — 690ccaa
- [x] 1.2 Testy value-objektów przechodzą (`tests/Ai/AiResultTest.php`) — 690ccaa
- [x] 1.3 `config/ai.php` zwraca tablicę z wymaganymi kluczami — 690ccaa

#### Manual
- [x] 1.4 `.env.example` dokumentuje nowe klucze; brak realnego klucza w repo — 690ccaa

### Phase 2: Klient + transport + factory

#### Automated
- [x] 2.1 PHPStan zielony — 126dada
- [x] 2.2 Testy klienta offline przechodzą (`tests/Ai/ClaudeClientTest.php`) — 126dada
- [x] 2.3 Pełny suite zielony — 126dada

#### Manual
- [x] 2.4 (Opcjonalnie) ręczne wywołanie z realnym kluczem zwraca tekst — 126dada

### Phase 3: Pełna matryca testów offline + polish

#### Automated
- [x] 3.1 Pełny suite zielony — d710145
- [x] 3.2 PHPStan zielony — d710145
- [x] 3.3 Brak cURL do Anthropic poza `src/Ai/` (`git grep`) — d710145

#### Manual
- [x] 3.4 Żaden test nie wykonuje realnego wywołania sieciowego — d710145
- [x] 3.5 Klucz API nie występuje w repo ani w logach — d710145
