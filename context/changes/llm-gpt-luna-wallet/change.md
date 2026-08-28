---
change_id: llm-gpt-luna-wallet
title: Czwarty portfel eksperymentalny — klon LLM Gemini wykonywany przez GPT (wariant Luna)
status: implemented
created: 2026-08-28
updated: 2026-08-28
archived_at: null
---

## Notes

Klon `CVS\LlmGemini\` — identyczna mechanika (kapitał 10 000 USD, brak
DecisionEnforcer, mark-to-market, legenda/pamięć per cykl, natywny web-search
zamiast dzielonej infrastruktury z Claude), ale wykonawczym LLM jest GPT
(OpenAI) wariant "Luna" zamiast Gemini. User: "Mechanika działania identyczna
jak dla portfela gemini nic nie zmieniamy tylko używamy innego modelu."

Klucze/model GPT Luna JUŻ istnieją w repo: `config/gpt.php['luna']`
(`$_ENV['GPT_Luna_CVS']` / `$_ENV['GPT_MODEL_Luna']`), używane dziś tylko
przez `src/Ai/GPTCriticalReviewService.php` (recenzja krytyczna, nie portfel).

Research (agent Explore, 2026-08-28) — pełny raport w konwersacji planującej,
kluczowe ustalenia:
- Moduł `CVS\LlmGemini\` (6 klas: Controller/Repository/CycleRepository/
  DecisionService/Service/ContextGatherer) to bezpośredni, mechaniczny wzorzec
  do sklonowania — nazewnictwo `LlmFree*→LlmGemini*` jest w 100% regularne.
- `GPTClientFactory::fromConfig(array $config, string $flavor, ...)` —
  INNA sygnatura niż `GeminiClientFactory::fromConfig()` (Gemini nie ma
  parametru flavor) — wywołanie portfela: `GPTClientFactory::fromConfig($gptConfig, 'luna')`.
- GPT ma natywny web search (`tools: [['type' => 'web_search']]`,
  potwierdzone w `GPTCriticalReviewService.php:72`), analogicznie do Gemini's
  `googleSearch` — nie trzeba dzielić infrastruktury z Claude.
- Migracje 035 (baza)/038 (Gemini) to dosłowny strukturalny klon (4 tabele:
  cycle/state/holdings/transactions, prefiks `llm_{name}_`). Aktualny
  najwyższy numer migracji: **042** (`ticker_logos`, ta sama sesja) →
  następny wolny to **043**.
- `WalletNavChartService` NIE jest generyczne (pozycyjna sygnatura
  konstruktora, 4. param opcjonalny dla Gemini, już "mandatory-in-practice"
  na wszystkich 3 kontrolerach portfeli po dzisiejszej zmianie) — 5. seria
  (GPT Luna) wymaga decyzji: kolejny opcjonalny param vs refaktor na
  generyczną listę.
- `.env.example` NIE dokumentuje sekcji `GPT_*` wcale (tylko `# --- Gemini API ---`)
  — do dodania jako drobny follow-up.
- Trzy istniejące wzorce okien cronowych (Warsaw): Portfolio 20:30/21:30,
  LLM Free 21:50/22:50, LLM Gemini szerokie okno 420 min — czwarte okno musi
  nie kolidować.

Powiązane wcześniejsze zmiany: [[cvs-llm-gemini-wallet-plan]] (bezpośredni
wzorzec), [[cvs-llm-free-wallet-plan]] (wzorzec bazowy).

## Deploy i weryfikacja na produkcji (2026-08-28)

`git pull` + `composer dump-autoload --optimize --no-dev` (workaround na
`ext-cf:-version-hardening`, patrz [[cf-composer-install-hardening-bug]]) +
migracja 043 na CF. Zweryfikowane na żywo na `cvs.timeflow.fun`:

- Seed `llm_gpt_luna_state` (10 000/10 000 USD) potwierdzony SELECT-em.
- Smoke-testy `LlmGptLunaContextGatherer`/`LlmGptLunaDecisionService` z
  prawdziwym kluczem `GPT_Luna_CVS` — web_search działa, decyzja+legenda
  poprawne (dry-run wiersz z `cycle_date='1999-01-01'` usunięty po teście).
- `/llm-gpt-luna` renderuje się poprawnie; dropdown "Portfele" ma 4 wpisy z
  poprawnym `aria-current`; wszystkie 4 strony portfeli
  (`/portfolio`, `/llm-free`, `/llm-gemini`, `/llm-gpt-luna`) pokazują
  identyczny zestaw 6 serii na wykresie NAV (4 portfele + S&P 500 + Nasdaq 100).
- **Pierwszy prawdziwy cykl** `bin/llm-gpt-luna-wallet-rebalance.php`
  uruchomiony ręcznie z CLI w oknie sesji NYSE (2026-08-28, ~13:37 ET):
  status `completed`, 5 wykonanych transakcji BUY (MU, ADBE, AMD, PFE,
  ENA.WA), gotówka 10 000,00 → 50,48 USD, `holdings` spójne z
  `transactions`. W logu odnotowany jeden transient `429 rate_limited` w
  trakcie context-gatheringu (jeden z 3 wywołań web_search) — nie przerwał
  cyklu, brak nieobsłużonego wyjątku. Item 3.8 planu potwierdzony w pełni.
- Cron w panelu CF pozostaje do samodzielnego założenia przez użytkownika
  (przykładowa linia w nagłówku `bin/llm-gpt-luna-wallet-rebalance.php`) —
  cel Fazy 3 (real cron firing on schedule) jest teraz nieformalnie
  potwierdzony ręcznym uruchomieniem tego samego skryptu; automatyczne
  uruchomienia zależą wyłącznie od konfiguracji crona przez użytkownika.
