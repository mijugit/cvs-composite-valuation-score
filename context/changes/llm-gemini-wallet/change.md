---
change_id: llm-gemini-wallet
title: Trzeci portfel eksperymentalny — LLM Free zarządzany przez Gemini zamiast Claude
status: implemented
created: 2026-08-19
updated: 2026-08-19
archived_at: null
---

## Notes

Klon istniejącego portfela "LLM Free" (`src/LlmFree/`, tabele `llm_free_*`, `/llm-free`),
identyczny mechanizm i parametry startowe (kapitał 10 000 USD, brak DecisionEnforcer,
legenda/pamięć per cykl, mark-to-market), ale wykonawczym LLM jest Gemini zamiast Claude.

Klucz API Gemini już istnieje w `.env` na serwerze pod nazwą `Gemini_CVS`.
Rebalansowanie ma iść przez osobny cron na Cyber_Folks — użytkownik sam go utworzy,
potrzebuje tylko dokładnej ścieżki/komendy z planu.

Kontekst z researchu (agent, 2026-08-19):
- `ClaudeClient` (`src/Ai/`) jest `final` i całkowicie Anthropic-specific (nagłówki
  x-api-key, `cache_control`, kształt `content[]`) — brak wspólnego interfejsu LLM.
  Najbezpieczniejsza droga: równoległa klasa `GeminiClient`/`GeminiClientFactory`
  zwracająca ten sam `AiResult`, żeby reszta pipeline'u (parser, decision service)
  dała się skopiować bez zmian.
- Migracja: kolejny wolny numer to `038` (035=llm_free, 036/037 zajęte).
- Cron istniejącego LLM Free: `50 21`/`50 22` Warsaw na `bin/llm-free-wallet-rebalance.php`,
  portfel bazowy: `30 20`/`30 21` na `bin/portfolio-rebalance.php` (różne okna, różne
  binarki PHP: base=php84, free=php82) — trzeci cron musi dostać własne, nie kolidujące okno.
- `deployment/cvs-composite-valuation-score.deploy.json` → `remote_path`:
  `/home/amjsystem/sites/cvs.timeflow.fun`.
- Model Gemini NIE powinien być hardcodowany w kodzie — w sierpniu 2026 seria 2.5 Flash
  wciąż działa (brak ogłoszonej daty wygaszenia), ale są już nowsze darmowe modele
  (3.5/3.6/3.7 Flash) — trzymać jako zmienną configu/env, mirror wzorca `AI_MODEL`.
- Rady Gemini o cache'owaniu/kolejkowaniu pod limit 10 RPM są w dużej mierze nieadekwatne:
  rebalansowanie już dziś idzie przez jeden cron dziennie, nie przez synchroniczne żądania
  wielu userów — realny wolumen wywołań/dzień jest rzędu pojedynczych cyfr.
