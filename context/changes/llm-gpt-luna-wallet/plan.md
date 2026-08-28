# Czwarty portfel eksperymentalny — LLM GPT Luna — Implementation Plan

## Overview

Klon istniejącego portfela "LLM Gemini" (`CVS\LlmGemini\`, tabele
`llm_gemini_*`, `/llm-gemini`) jako czwarty, w pełni izolowany portfel
eksperymentalny: identyczny mechanizm (kapitał startowy 10 000 USD, brak
DecisionEnforcer, mark-to-market, legenda/pamięć per cykl, natywny web-search
zamiast dzielonej infrastruktury z Claude), ale wykonawczym LLM jest GPT
(OpenAI) wariant "Luna" zamiast Gemini. User: "Mechanika działania identyczna
jak dla portfela gemini nic nie zmieniamy tylko używamy innego modelu."

## Current State Analysis

- `CVS\LlmGemini\` (6 klas) to bezpośredni, w 100% mechaniczny wzorzec:
  `LlmGeminiController` (widok `/llm-gemini`, live-repricing),
  `LlmGeminiRepository` (read-only SELECT-y stanu/holdingów/legendy),
  `LlmGeminiCycleRepository` (CRUD na `llm_gemini_cycle`: `claimForRun()`
  idempotentne, `getValueSeries()` dla wykresu NAV, `updateStatus/
  updateLlmRecord/updateCycleSummary`), `LlmGeminiDecisionService` (pipeline
  decyzyjny), `LlmGeminiService` (atomowy write model, `executeCycle()` w
  transakcji PDO), `LlmGeminiContextGatherer` (świeży web-search per cykl,
  bez cache `ai_analyses`).
- `src/Ai/GPTClient.php` + `GPTClientFactory.php` **już istnieją** (change:
  critical-review-openai), używane dziś tylko przez
  `src/Ai/GPTCriticalReviewService.php`. Kontrakt `sendMessage(messages,
  ?system, options): AiResult` jest identyczny jak `GeminiClient`/
  `ClaudeClient` — jedyna różnica sygnatury: `GPTClientFactory::fromConfig(array
  $config, string $flavor, ...)` wymaga 2. argumentu `'luna'|'terra'`
  (`GPTClientFactory.php:22-30`), którego `GeminiClientFactory::fromConfig()`
  nie ma.
- `config/gpt.php` już ma gotowy wariant `'luna'` (`$_ENV['GPT_Luna_CVS']` /
  `$_ENV['GPT_MODEL_Luna']`, `config/gpt.php:43-46`) — **nie trzeba tworzyć
  żadnych nowych kluczy API ani zmiennych .env**, są już na serwerze.
- GPT ma natywny web search analogiczny do Gemini's `googleSearch`, ale inny
  kształt: `'tools' => [['type' => 'web_search']]` (potwierdzone w produkcyjnym
  użyciu, `GPTCriticalReviewService.php:72`) vs Gemini's `'tools' =>
  [['googleSearch' => new \stdClass()]]` (`LlmGeminiContextGatherer.php:72`).
  To jedyna faktyczna różnica w wywołaniu API między portfelem Gemini a
  portfelem GPT Luna — reszta obu klas jest tekstualnie identyczna.
- `WalletNavChartService` (`src/Charts/WalletNavChartService.php`) nie jest
  generyczne — pozycyjny konstruktor z opcjonalnym 4. parametrem dla Gemini,
  dziś już przekazywanym przez wszystkie 3 istniejące kontrolery portfeli
  (`PortfolioController`, `LlmFreeController`, `LlmGeminiController` —
  ujednolicone w zmianie `screener-ux-polish` tego samego dnia).
- Migracje: najwyższy numer to `042` (`ticker_logos`) → następny wolny to
  **`043`**. Migracja `038_create_llm_gemini_wallet_tables.sql` to dosłowny
  strukturalny klon `035_create_llm_free_wallet_tables.sql` — 4 tabele,
  prefiks `llm_{name}_`.
- `.env.example` dokumentuje sekcję `# --- Gemini API ---`
  (`.env.example:28-31`) ale **nie ma żadnej sekcji `GPT_*`** mimo że
  `config/gpt.php` już czyta 7+ zmiennych stamtąd — luka w dokumentacji do
  domknięcia.
- Trzy istniejące wzorce okien cronowych (Warsaw): Portfolio `30 20`/`30 21`,
  LLM Free `50 21`/`50 22`, LLM Gemini szerokie okno 420 min z jednym
  przykładowym wpisem `40 21` (operator wybiera realną minutę).

### Key Discoveries:

- Ponieważ `GPTClient`/`GPTClientFactory` już istnieją i mają identyczny
  kontrakt do `GeminiClient`, **nie ma fazy "fundament klienta"** — to co dla
  portfela Gemini było Fazą 1 (budowa `GeminiClient` od zera) tu jest
  zerokosztowe. Cały ten plan jest przez to znacząco mniejszy niż plan
  `llm-gemini-wallet`.
- `LlmGeminiDecisionService::generate()` przekazuje `$system` jako
  `CacheableSystem` z `system_prompt_ttl` — `GPTClient::buildBody()`
  (`GPTClient.php:131-133`) używa tylko `$system->text` jako pole
  `instructions`, TTL jest ignorowany (ta sama "parity sygnatury, ignorowane
  przez klienta" konwencja co już przyjęto dla Gemini).
- `tests/Ai/FakeTransport.php` jest provider-agnostyczne i już reużywane
  przez `GPTClientTest.php` — ten sam fake nadaje się wprost do
  `LlmGptLunaContextGathererTest`/`LlmGptLunaDecisionServiceTest`, tak jak
  reużyto go dla Gemini.

## Desired End State

Działający czwarty portfel pod `/llm-gpt-luna`: strona pokazuje stan
gotówki, holdingi wycenione mark-to-market, historię legendy i wykres NAV —
analogicznie do `/llm-gemini`. Cron na CF wywołuje
`bin/llm-gpt-luna-wallet-rebalance.php`, który każdego dnia sesyjnego NYSE (w
szerokim oknie zbliżonym do sesji) woła GPT-Luna z pełną swobodą decyzyjną
(bez DecisionEnforcera), z własnym natywnym web-searchem jako jedynym
źródłem świeżego kontekstu, zapisuje transakcje i nowy wpis legendy. Kapitał
startowy 10 000 USD, identyczny jak w pozostałych trzech portfelach. Wykres
porównawczy NAV na **wszystkich czterech** stronach portfeli (`/portfolio`,
`/llm-free`, `/llm-gemini`, `/llm-gpt-luna`) pokazuje wszystkie 4 portfele +
2 benchmarki.

**Weryfikacja**: `/llm-gpt-luna` renderuje się bez błędów przy pustym
portfelu (seed 10 000 USD gotówki, brak holdingów); ręczne uruchomienie
`bin/llm-gpt-luna-wallet-rebalance.php` z CLI w oknie rynkowym kończy się
`exit(0)` i zapisuje wiersz w `llm_gpt_luna_cycle` ze statusem `completed`
lub `llm_failed` (nigdy nieobsłużonym wyjątkiem); `vendor/bin/phpunit` i
`composer stan` przechodzą zielono.

## What We're NOT Doing

- Nie zmieniamy żadnego z trzech istniejących portfeli (`CVS\Portfolio\`,
  `CVS\LlmFree\`, `CVS\LlmGemini\`) poza dodaniem 5. parametru do
  `WalletNavChartService`'s wywołań w ich kontrolerach — logika biznesowa
  tych modułów pozostaje nietknięta.
- Nie budujemy wspólnego interfejsu `LlmClientInterface` dla Claude/Gemini/GPT
  — `LlmGptLunaDecisionService`/`LlmGptLunaContextGatherer` to równoległe,
  niezależne klasy (świadoma decyzja, ten sam precedens co przy
  `llm-gemini-wallet`: zero ryzyka regresji w działających modułach).
- Nie reużywamy cache'u `ai_analyses`/`ai_critical_reviews` w portfelu GPT
  Luna — zawsze własne, świeże wyszukiwanie web-search, zero mieszania
  providerów w jednym cyklu decyzyjnym (ten sam wzorzec co Gemini).
- Nie nadpisujemy `reasoning_effort` na poziomie portfela — dziedziczy
  wartość domyślną z `config/gpt.php` (`'medium'`), tak jak dziś robi to
  `GPTCriticalReviewService` (decyzja usera).
- Nie refaktoryzujemy `WalletNavChartService` na generyczną listę serii —
  kolejny mechaniczny opcjonalny parametr, spójny z historią (decyzja usera).
- Nie tworzymy nowego crona automatycznie — użytkownik zakłada go ręcznie w
  panelu CF; ten plan dostarcza dokładną ścieżkę/komendę/przykładową minutę.
- Nie zmieniamy treści system promptu ani formatu odpowiedzi JSON — tekstualnie
  identyczne z `LlmGeminiDecisionService`, żeby "ten sam instruction, inny
  wykonawca" pozostało jedyną zmienną eksperymentu.

## Implementation Approach

Trzy fazy, każda niezależnie weryfikowalna: (1) warstwa danych i config —
migracja, config portfela, `.env.example`, oraz trzy klasy modułu bez
żadnej logiki specyficznej dla LLM (`LlmGptLunaRepository`,
`LlmGptLunaCycleRepository`, `LlmGptLunaService`) — czysto mechaniczne klony
tabeli/nazwy; (2) silnik decyzyjny — jedyna faza z realną różnicą wobec
Gemini (`GPTClientFactory` zamiast `GeminiClientFactory`, `web_search` zamiast
`googleSearch`), więc jedyna faza wymagająca faktycznej weryfikacji że GPT-Luna
rzeczywiście generuje poprawny JSON i wspiera web search w tym kontekście;
(3) cron entrypoint + widoczność (kontroler/widok/routing/nawigacja/wykres
porównawczy). Kolejność wymuszona zależnościami: Faza 2 potrzebuje
`LlmGptLunaCycleRepository` z Fazy 1; Faza 3 (cron) potrzebuje obu
poprzednich.

## Phase 1: Warstwa danych i config

### Overview

Fundament: tabele, config portfela, dokumentacja `.env.example`, oraz trzy
klasy bez żadnej logiki LLM-specyficznej — czysty mechaniczny klon nazw
tabel/klas z `CVS\LlmGemini\`. Zero wpływu na istniejące portfele.

### Changes Required:

#### 1. `database/migrations/043_create_llm_gpt_luna_wallet_tables.sql`

**Intent**: Dosłowny strukturalny klon `038_create_llm_gemini_wallet_tables.sql`
— cztery tabele, prefiks `llm_gpt_luna_` zamiast `llm_gemini_`. Kapitał
startowy identyczny (10 000.00) — inna kwota złamałaby porównanie 4 portfeli.

**Contract**: `llm_gpt_luna_cycle` (id, cycle_date UNIQUE, status,
attempt_count, started_at, finished_at, cash_before, cash_after,
portfolio_value_usd, executed_count, skipped_count, notes, retry_count,
llm_raw_response, llm_failure_kind, llm_decision_json, legend, tokens_input,
tokens_output), `llm_gpt_luna_state` (singleton: cash, initial_capital,
updated_at; `INSERT IGNORE ... VALUES (1, 10000.00, 10000.00, NOW())`),
`llm_gpt_luna_holdings` (ticker UNIQUE, quantity, avg_entry_price,
updated_at), `llm_gpt_luna_transactions` (FK cycle_id →
llm_gpt_luna_cycle.id, ticker, action, quantity, price_usd, cash_before,
cash_after, status, reason, executed_at). Identyczne typy kolumn/komentarze
co migracja 038, zero FK do innych modułów.

#### 2. `config/llm-gpt-luna-wallet.php`

**Intent**: Strukturalny klon `config/llm-gemini-wallet.php` — te same
parametry startowe, brak własnego klucza `'model'` (model już env-driven w
`config/gpt.php` przez `GPT_MODEL_Luna`).

**Contract**: `initial_capital_usd => 10000.0`, `market` (open 09:30, close
16:30 ET), `rebalance_window_minutes => 420` (szerokie okno jak Gemini —
decyzja usera), `legend_context_count => 10`, `context_search_cap => 3`
(identycznie jak Gemini — decyzja usera), `legend_max_chars => 4000`,
`max_candidates => 40`, `llm => [max_retries=>0, max_tokens=>8192,
timeout=>180, total_timeout=>200, retry_base_delay_ms=>0,
retry_delay_seconds=>2, system_prompt_ttl=>'5m']` (TTL zachowane dla parity
sygnatury, ignorowane przez `GPTClient`, jak dziś dla Gemini). Brak klucza
`reasoning_effort` — dziedziczy `config/gpt.php`'s domyślne `'medium'`
(decyzja usera).

#### 3. `.env.example`

**Intent**: Domknięcie luki w dokumentacji — `config/gpt.php` czyta 7+
zmiennych `GPT_*`/`GPT_Terra_CVS`/`GPT_Luna_CVS`/`GPT_MODEL_Terra`/
`GPT_MODEL_Luna` od dawna (change: critical-review-openai), ale
`.env.example` nigdy nie dostał analogicznej sekcji do istniejącej
`# --- Gemini API ---`.

**Contract**: nowa sekcja `# --- GPT (OpenAI) API ---` z przykładowymi
(pustymi/placeholder) wartościami dla wszystkich zmiennych, które
`config/gpt.php` faktycznie czyta — mirror formatu istniejącej sekcji
Gemini.

#### 4. `src/LlmGptLuna/LlmGptLunaRepository.php`, `LlmGptLunaCycleRepository.php`, `LlmGptLunaService.php`

**Intent**: Mechaniczne klony `LlmGeminiRepository`/`LlmGeminiCycleRepository`/
`LlmGeminiService` — zero logiki LLM-specyficznej w tych trzech klasach
(SQL na tabelach, transakcyjny write model), tylko nazwa tabeli/klasy/
namespace się zmienia.

**Contract**: identyczne publiczne metody co odpowiedniki Gemini —
`LlmGptLunaRepository::getCurrentState()/getCurrentHoldings()/
getCurrentHoldingsWithPrice()/getLatestCycle()/getLegendHistory()`;
`LlmGptLunaCycleRepository::findTodayCycle()/getValueSeries()/
claimForRun($cycleDate, $maxAttempts)/updateStatus()/updateLlmRecord()/
updateCycleSummary()`; `LlmGptLunaService::executeCycle($cycleId, $decisions,
$priceMap, $dropNote)` w jednej transakcji PDO, te same guardy (SELL capped
do posiadanej ilości, BUY skip przy braku gotówki).

#### 5. Testy: `tests/LlmGptLuna/LlmGptLunaRepositoryTest.php`, `LlmGptLunaCycleRepositoryTest.php`, `LlmGptLunaServiceTest.php`

**Intent**: Klony odpowiedników `tests/LlmGemini/*`, SQLite in-memory,
zero mocków LLM (te trzy klasy nie wołają żadnego klienta AI).

**Contract**: te same przypadki testowe co pliki Gemini, zmieniona tylko
nazwa tabeli/klasy.

### Success Criteria:

#### Automated Verification:

- Migracja aplikuje się czysto: uruchomienie `043_*.sql` na bazie deweloperskiej/testowej
- `vendor/bin/phpunit tests/LlmGptLuna/` przechodzi zielono
- Pełny `vendor/bin/phpunit` nadal zielony (zero regresji)
- `composer stan` czysty dla `src/LlmGptLuna/`

#### Manual Verification:

- Ręczny SELECT na świeżo zmigrowanej bazie potwierdza wiersz w
  `llm_gpt_luna_state` (cash=10000.00, initial_capital=10000.00)

---

## Phase 2: Silnik decyzyjny (GPT-Luna-specyficzny)

### Overview

Jedyna faza z faktyczną różnicą wobec portfela Gemini: wywołania API idą
przez `GPTClientFactory::fromConfig($gptConfig, 'luna')` zamiast
`GeminiClientFactory::fromConfig()`, i web search ma inny kształt `tools`.
System prompt i logika parsowania odpowiedzi pozostają tekstualnie
identyczne z Gemini.

### Changes Required:

#### 1. `src/LlmGptLuna/LlmGptLunaContextGatherer.php`

**Intent**: Klon `LlmGeminiContextGatherer` — świeże wywołanie z natywnym
web-searchem per kandydat (do `context_search_cap`), bez cache
`ai_analyses`/`ai_critical_reviews`, identyczne user/system prompty
(po polsku, cytowanie dat, brak rekomendacji inwestycyjnych).

**Contract**: `gather(array $candidateTickers): array<string,string>`,
identyczna sygnatura konstruktora co `LlmGeminiContextGatherer`
(`array $gptConfig, int $searchCap, ?GPTClient $clientOverride = null`).
Jedyna różnica kodu: `GPTClientFactory::fromConfig($this->gptConfig, 'luna')`
zamiast `GeminiClientFactory::fromConfig($this->geminiConfig)`, i
`'tools' => [['type' => 'web_search']]` zamiast `'tools' =>
[['googleSearch' => new \stdClass()]]`.

#### 2. `src/LlmGptLuna/LlmGptLunaDecisionService.php`

**Intent**: Klon `LlmGeminiDecisionService` — pipeline decyzyjny do 2 prób,
identyczny system prompt (pełna swoboda interpretacyjna, brak twardych
progów, wymóg nowego wpisu legendy co cykl), parsowanie przez REUŻYTY
`CVS\LlmFree\LlmFreeDecisionParser` bez zmian (provider-agnostyczny).

**Contract**: `generate($cycleId, $portfolioState, $holdings, $screenerRows,
$legendHistory, $contextByTicker = []): array{ok, decisions, legend,
retryCount, rawResponse, failureKind}`, identyczna sygnatura konstruktora co
`LlmGeminiDecisionService` (`LlmGptLunaCycleRepository $cycleRepo,
array $gptConfig, array $walletConfig, ?GPTClient $clientOverride = null`).
Jedyna różnica kodu: `GPTClientFactory::fromConfig($this->gptConfig, 'luna')`
zamiast `GeminiClientFactory::fromConfig($this->geminiConfig)` w linii
budującej klienta. System prompt (`buildSystemPrompt()`) i budowanie data
bloku (`buildDataBlock()`) — **kopiowane 1:1, bez modyfikacji tekstu**.

#### 3. Testy: `tests/LlmGptLuna/LlmGptLunaContextGathererTest.php`, `LlmGptLunaDecisionServiceTest.php`

**Intent**: Klony odpowiedników `tests/LlmGemini/*`, reużywają
`tests/Ai/FakeTransport.php` (provider-agnostyczne, już używane przez
`GPTClientTest.php`) do mockowania odpowiedzi GPT-Luna bez realnych wywołań
sieciowych.

**Contract**: te same przypadki testowe co pliki Gemini (sukces, parse
error z retry, wyczerpane próby, brak kandydatów dla gatherera), zmieniony
tylko klient/tools shape w asercjach.

### Success Criteria:

#### Automated Verification:

- `vendor/bin/phpunit tests/LlmGptLuna/LlmGptLunaContextGathererTest.php tests/LlmGptLuna/LlmGptLunaDecisionServiceTest.php` przechodzi zielono
- Pełny `vendor/bin/phpunit` nadal zielony (zero regresji w `tests/LlmGemini/`, `tests/Ai/`)
- `composer stan` czysty dla `src/LlmGptLuna/`

#### Manual Verification:

- Ręczny smoke-test: mały skrypt/REPL wołający
  `LlmGptLunaContextGatherer::gather(['AAPL'])` z prawdziwym kluczem
  `GPT_Luna_CVS` zwraca niepusty tekst kontekstu dla AAPL
- Ręczny smoke-test: `LlmGptLunaDecisionService::generate(...)` z syntetycznym
  stanem portfela i prawdziwym kluczem zwraca `ok=true` z niepustą listą
  `decisions` i niepustą `legend`

---

## Phase 3: Cron entrypoint + widoczność

### Overview

Ostatnia faza: skrypt CLI/cron, warstwa widoku (kontroler + template),
routing, nawigacja, i rozszerzenie wykresu porównawczego NAV o 4. portfel na
wszystkich stronach portfeli.

### Changes Required:

#### 1. `src/LlmGptLuna/LlmGptLunaController.php` + `templates/llm-gpt-luna.php`

**Intent**: Klon `LlmGeminiController`/`templates/llm-gemini.php` —
live-repricing, cache sesyjny cen, buduje `WalletNavChartService` (5
argumentów po tej fazie), renderuje identyczny layout strony portfela.

**Contract**: `GET /llm-gpt-luna` → `LlmGptLunaController::index()`,
identyczny kształt danych przekazywanych do widoku co
`LlmGeminiController::index()`.

#### 2. `bin/llm-gpt-luna-wallet-rebalance.php`

**Intent**: Klon `bin/llm-gemini-wallet-rebalance.php` — CLI guard, log
`logs/llm-gpt-luna-wallet-rebalance.log`, ręczny `.env` parser,
`MarketCalendar::getStatus()` gate, `LlmGptLunaCycleRepository::claimForRun()`
idempotencja, `Database::reconnect()` przed wywołaniem LLM i przed fazą
zapisu (CF `wait_timeout` guard), try/catch → `updateStatus($id,
'llm_failed')` na crash, price injection z `screenerRows` (model nigdy nie
zwraca ceny), `dropNote` gdy BUY/SELL bez znanej ceny odrzucony.

**Contract**: guard `PHP_SAPI !== 'cli'`; binarka `/usr/local/bin/php82`;
przykładowa (nie narzucona) linia crona w komentarzu nagłówkowym —
`10 20 * * 1-5` Warsaw (odróżnia się od Portfolio `30 20`/`30 21`, LLM Free
`50 21`/`50 22`, LLM Gemini `40 21` — operator wybiera realną minutę w
panelu CF, tak jak dla Gemini).

#### 3. `src/Core/routes.php`

**Intent**: Nowa sekcja routingu dla czwartego portfela.

**Contract**: `$router->get('/llm-gpt-luna', fn($req) =>
$llmGptLuna->index($req));`, poprzedzone komentarzem analogicznym do sekcji
Gemini (`// LLM_GPT_Luna_Wallet — fourth, GPT-Luna-executed portfolio
(change: llm-gpt-luna-wallet)`).

#### 4. `templates/layout.php`

**Intent**: Czwarty wpis w dropdownie "Portfele".

**Contract**: nowy `<li>` po istniejącym wpisie "LLM Gemini"
(`templates/layout.php:52`), `href="/llm-gpt-luna"`, etykieta "LLM GPT
Luna", ten sam wzorzec `aria-current` przez `str_starts_with($_SERVER['REQUEST_URI'],
'/llm-gpt-luna')`.

#### 5. `src/Charts/WalletNavChartService.php` + `PortfolioController.php`, `LlmFreeController.php`, `LlmGeminiController.php`, `LlmGptLunaController.php`

**Intent**: 5. opcjonalny parametr konstruktora dla serii GPT Luna,
mechaniczne powielenie wzorca 4. parametru (Gemini) — decyzja usera. Wszystkie
4 kontrolery portfeli przekazują `LlmGptLunaCycleRepository`, spójnie z
dzisiejszą zmianą która zrobiła to samo dla Gemini na wszystkich 3
ówczesnych kontrolerach.

**Contract**: `__construct(..., private readonly ?LlmGeminiCycleRepository
$llmGeminiCycles = null, private readonly ?LlmGptLunaCycleRepository
$llmGptLunaCycles = null)`; `fetch()`/`build()` dokładają
`$this->llmGptLunaCycles?->getValueSeries()` jako 6. argument `build()`;
`build()` dokłada `if ($llmGptLunaSeries !== null) { $chartSeries['LLM GPT
Luna'] = LabMetrics::normaliseToBase100($llmGptLunaSeries, 'value'); }` po
istniejącym bloku Gemini.

#### 6. `templates/partials/wallet-nav-chart.php`

**Intent**: Kolor dla nowej serii w `$walletChartPalette` — odróżnialny od
istniejących 3 kolorowych linii portfeli (niebieski/żółty/zielony) i od
szarej rodziny benchmarków.

**Contract**: `'LLM GPT Luna' => 'rgba(251,146,60,0.9)'` (pomarańczowy) —
dodane do istniejącej mapy, bez zmian w reszcie partiala.

### Success Criteria:

#### Automated Verification:

- `php -l bin/llm-gpt-luna-wallet-rebalance.php` — składnia czysta
- `vendor/bin/phpunit tests/Charts/WalletNavChartServiceTest.php` przechodzi zielono (rozszerzony o przypadek 5. serii)
- Pełny `vendor/bin/phpunit` zielony (zero regresji w `tests/LlmGemini/`, `tests/Portfolio/`, `tests/LlmFree/`)
- `composer stan` czysty dla `src/LlmGptLuna/`, `src/Charts/`, zmienionych kontrolerów

#### Manual Verification:

- `/llm-gpt-luna` renderuje się bez błędów w przeglądarce, pokazuje 10 000 USD gotówki, brak pozycji
- Nawigacja "Portfele" pokazuje wszystkie 4 wpisy, aktywny stan podświetla się poprawnie na `/llm-gpt-luna`
- `/portfolio`, `/llm-free`, `/llm-gemini`, `/llm-gpt-luna` — wszystkie 4 pokazują identyczny wykres z 4 liniami portfeli + 2 szarymi przerywanymi benchmarkami
- Pierwsze prawdziwe uruchomienie `bin/llm-gpt-luna-wallet-rebalance.php` na serwerze (po ręcznym założeniu crona przez usera, lub ręcznie z CLI w oknie rynkowym) kończy się zapisem w `llm_gpt_luna_cycle` ze statusem `completed` lub `llm_failed` — nigdy nieobsłużonym wyjątkiem w logu

---

## Testing Strategy

### Unit Tests:

- `LlmGptLunaCycleRepository`/`LlmGptLunaRepository`/`LlmGptLunaService` —
  SQLite in-memory, identyczne przypadki co odpowiedniki Gemini
- `LlmGptLunaContextGatherer`/`LlmGptLunaDecisionService` — `FakeTransport`
  (provider-agnostyczne), sukces/parse-error-z-retry/wyczerpane-próby
- `WalletNavChartService::build()` — nowy przypadek: 5 serii (3 portfele + 2
  benchmarki), oraz przypadek gdy `$llmGptLunaSeries === null` (backward
  compat, seria pominięta)

### Integration Tests:

- Brak — projekt nie ma warstwy integration testów dla zewnętrznych API
  (ani `FinancialDataFetcher`, ani `ClaudeClient`/`GeminiClient`/`GPTClient`
  nie są nią objęte); ten feature jest z nimi spójny

### Manual Testing Steps:

1. Uruchom migrację `043_*.sql`, potwierdź seed `llm_gpt_luna_state`
2. Ręczny smoke-test `LlmGptLunaContextGatherer`/`LlmGptLunaDecisionService`
   z prawdziwym kluczem `GPT_Luna_CVS` (Faza 2)
3. Otwórz `/llm-gpt-luna` w przeglądarce — pusty portfel renderuje się
   poprawnie
4. Sprawdź dropdown "Portfele" — 4 wpisy, aktywny stan na `/llm-gpt-luna`
5. Sprawdź wykres NAV na wszystkich 4 stronach portfeli — 4 linie + 2 szare
   przerywane benchmarki wszędzie
6. Ręczne uruchomienie `bin/llm-gpt-luna-wallet-rebalance.php` w oknie
   rynkowym — sprawdź log i wiersz w `llm_gpt_luna_cycle`
7. Załóż cron w panelu CF wg instrukcji z Fazy 3

## Performance Considerations

Brak nowego obciążenia poza tym co portfel Gemini już generuje —
`context_search_cap=3` ogranicza wywołania web-search per cykl identycznie
jak dla Gemini. Wykres porównawczy dokłada jedną dodatkową bulk-query
(`LlmGptLunaCycleRepository::getValueSeries()`) na każdej z 4 stron portfeli
— pomijalny narzut (ta sama klasa zapytania co już istniejące 3-4 serie).

## Migration Notes

Tabela jest czysto addytywna. Brak istniejących danych do migracji — portfel
startuje od zera (seed 10 000 USD) jak każdy poprzedni.

## References

- Wzorzec do klonowania: `src/LlmGemini/*`, `database/migrations/038_create_llm_gemini_wallet_tables.sql`,
  `bin/llm-gemini-wallet-rebalance.php`, `config/llm-gemini-wallet.php`,
  `templates/llm-gemini.php`, `tests/LlmGemini/*`.
- Klient LLM już gotowy: `src/Ai/GPTClient.php`, `GPTClientFactory.php`,
  `config/gpt.php`, wzorzec użycia w `src/Ai/GPTCriticalReviewService.php`.
- Kontekst i pełny research: `context/changes/llm-gpt-luna-wallet/change.md`.

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Warstwa danych i config

#### Automated

- [ ] 1.1 Migracja aplikuje się czysto: uruchomienie `043_*.sql` na bazie deweloperskiej/testowej
- [x] 1.2 `vendor/bin/phpunit tests/LlmGptLuna/` przechodzi zielono — 54e313c
- [x] 1.3 Pełny `vendor/bin/phpunit` nadal zielony (zero regresji) — 54e313c
- [x] 1.4 `composer stan` czysty dla `src/LlmGptLuna/` — 54e313c

#### Manual

- [x] 1.5 Ręczny SELECT na świeżo zmigrowanej bazie potwierdza wiersz w `llm_gpt_luna_state` (cash=10000.00, initial_capital=10000.00)

### Phase 2: Silnik decyzyjny (GPT-Luna-specyficzny)

#### Automated

- [x] 2.1 `vendor/bin/phpunit tests/LlmGptLuna/LlmGptLunaContextGathererTest.php tests/LlmGptLuna/LlmGptLunaDecisionServiceTest.php` przechodzi zielono — c0ba7ac
- [x] 2.2 Pełny `vendor/bin/phpunit` nadal zielony (zero regresji w tests/LlmGemini/, tests/Ai/) — c0ba7ac
- [x] 2.3 `composer stan` czysty dla `src/LlmGptLuna/` — c0ba7ac

#### Manual

- [x] 2.4 Ręczny smoke-test `LlmGptLunaContextGatherer::gather(['AAPL'])` z prawdziwym kluczem zwraca niepusty kontekst
- [x] 2.5 Ręczny smoke-test `LlmGptLunaDecisionService::generate(...)` z prawdziwym kluczem zwraca ok=true, niepuste decisions i legend

### Phase 3: Cron entrypoint + widoczność

#### Automated

- [x] 3.1 `php -l bin/llm-gpt-luna-wallet-rebalance.php` — składnia czysta — d783edd
- [x] 3.2 `vendor/bin/phpunit tests/Charts/WalletNavChartServiceTest.php` przechodzi zielono — d783edd
- [x] 3.3 Pełny `vendor/bin/phpunit` zielony (zero regresji w tests/LlmGemini/, tests/Portfolio/, tests/LlmFree/) — d783edd
- [x] 3.4 `composer stan` czysty dla src/LlmGptLuna/, src/Charts/, zmienionych kontrolerów — d783edd

#### Manual

- [x] 3.5 `/llm-gpt-luna` renderuje się bez błędów, pokazuje 10 000 USD gotówki, brak pozycji
- [x] 3.6 Nawigacja "Portfele" pokazuje 4 wpisy, aktywny stan poprawny na /llm-gpt-luna
- [x] 3.7 Wszystkie 4 strony portfeli pokazują identyczny wykres z 4 liniami portfeli + 2 benchmarkami
- [x] 3.8 Pierwsze prawdziwe uruchomienie bin/llm-gpt-luna-wallet-rebalance.php kończy się completed lub llm_failed, nigdy nieobsłużonym wyjątkiem
