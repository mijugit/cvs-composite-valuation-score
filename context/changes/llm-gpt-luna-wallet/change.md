---
change_id: llm-gpt-luna-wallet
title: Czwarty portfel eksperymentalny — klon LLM Gemini wykonywany przez GPT (wariant Luna)
status: implementing
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
