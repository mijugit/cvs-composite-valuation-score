# Trzeci portfel eksperymentalny — LLM Gemini — Plan Brief

> Full plan: `context/changes/llm-gemini-wallet/plan.md`
> Research: `context/changes/llm-gemini-wallet/research.md`

## What & Why

Klon portfela "LLM Free" — identyczny mechanizm (brak DecisionEnforcera, mark-to-market,
legenda/pamięć per cykl, kapitał 10 000 USD) — ale wykonawczym LLM jest Gemini zamiast
Claude. Trzeci punkt danych w eksperymencie porównawczym: bazowy portfel systematyczny
vs Claude bez ograniczeń vs Gemini bez ograniczeń.

## Starting Point

`src/LlmFree/` to gotowy, sprawdzony wzorzec (7 klas + migracja 035 + config +
route `/llm-free` + cron). `CVS\Ai\ClaudeClient` jest jednak `final` i całkowicie
zbudowana wokół Anthropic Messages API — brak wspólnego interfejsu LLM do reużycia
dla Gemini. `HttpTransport`/`AiResult`/`AiUsage`/`AiFailureKind` SĄ provider-agnostic
i reużywalne bez zmian.

## Desired End State

`/llm-gemini` działa analogicznie do `/llm-free`: stan portfela, holdingi
mark-to-market, historia legendy, wykres NAV. Cron na CF wywołuje codziennie
`bin/llm-gemini-wallet-rebalance.php`, Gemini decyduje z pełną swobodą, z własnym
natywnym web-searchem (`googleSearch`), zapisuje transakcje i nowy wpis legendy.

## Key Decisions Made

| Decyzja | Wybór | Dlaczego | Source |
| --- | --- | --- | --- |
| Nazwa klucza API | Czytać `Gemini_CVS` wprost z env | Zero zmian na serwerze, klucz już tam jest | Plan (pytanie usera) |
| Model startowy | `gemini-3.7-flash`, env-driven (`GEMINI_MODEL`) | Najnowszy model (sierpień 2026); env-driven bo Google wygasza modele szybciej niż Anthropic (2.0 Flash po ~4 mies.) | Plan (pytanie usera) |
| Web-search kontekstowy | Własny, natywny gatherer Gemini (`googleSearch`), zawsze świeże wyszukiwanie | Pełna izolacja eksperymentu — zero mieszania z cache'em Claude | Plan (pytanie usera) |
| Architektura klienta AI | Równoległa klasa `GeminiClient`, nie wspólny interfejs | `ClaudeClient` jest `final`/Anthropic-specific; zero ryzyka regresji w działającym module Claude | Research |
| Parser decyzji JSON | Reużyty `CVS\LlmFree\LlmFreeDecisionParser` bez zmian | Provider-agnostic — nie duplikować identycznej logiki walidacji | Research |
| Harmonogram crona | 21:40/22:40 Warsaw (10 min przed Free) | Czysta translacja czasowa sprawdzonego harmonogramu Free — dziedziczy jego bezpieczeństwo DST, nie koliduje z Base (20:30/21:30) ani Free (21:50/22:50) | Plan |

## Scope

**In scope:** `GeminiClient`+`GeminiClientFactory` w `src/Ai/`; natywny context
gatherer z `googleSearch`; pełny klon modułu portfela (`src/LlmGemini/`, migracja 038,
config, route, nawigacja); cron entrypoint + dokładna komenda dla usera.

**Out of scope:** zmiany w portfelu bazowym lub LLM Free; wspólny interfejs
`LlmClientInterface`; prompt caching dla Gemini; reużycie cache'u analiz Claude;
automatyczne założenie crona (user robi to ręcznie w panelu CF).

## Architecture / Approach

`src/Ai/GeminiClient.php` implementuje ten sam publiczny kontrakt co `ClaudeClient`
(`sendMessage(messages, ?CacheableSystem, options): AiResult`), ale mówi natywnym
REST-em Gemini (`generateContent`, auth `x-goog-api-key`, model w URL, `contents`/
`systemInstruction`/`generationConfig`). Dzięki identycznemu kontraktowi cała reszta
pipeline'u (`LlmGeminiDecisionService`, `LlmGeminiContextGatherer`) to niemal
dosłowne kopie odpowiedników z `LlmFree`, tylko z podmienionym klientem. Baza danych,
config i routing to czysty mirror wzorca `035`/`llm-free-wallet.php`/routing.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Fundament klienta Gemini | `GeminiClient`, config, testy | Nieznany dokładny kształt `googleSearch`/`groundingMetadata` w REST — wymaga smoke-testu |
| 2. Natywny context gatherer | `LlmGeminiContextGatherer` z `googleSearch` | Jakość/trafność wyszukiwania Gemini vs Claude — nieznana do czasu użycia na żywo |
| 3. Moduł portfela + baza | `src/LlmGemini/*`, migracja 038, `/llm-gemini`, nawigacja | Objętość klonowania (7 klas) — ryzyko drobnych rozbieżności od wzorca Free |
| 4. Cron entrypoint | `bin/llm-gemini-wallet-rebalance.php` + komenda dla usera | Harmonogram crona wyprowadzony analitycznie, nie zweryfikowany na żywo do pierwszego uruchomienia |

**Prerequisites:** klucz `Gemini_CVS` już w `.env` serwera (potwierdzone przez usera).
**Estimated effort:** ~1 sesja implementacyjna na fazę, 4 fazy — realnie 1 dzień pracy
zgodnie z założeniem "zadanie na dzisiaj", przy czym Faza 4 (pierwsze prawdziwe
uruchomienie crona) domyka się dopiero po ręcznym założeniu crona przez usera.

## Open Risks & Assumptions

- Dokładny REST-owy kształt `{"googleSearch": {}}` w `tools[]` nie został
  zweryfikowany na żywym wywołaniu (dokumentacja pokazała wzorzec dla
  `functionDeclarations`, nie dla `googleSearch` wprost) — Faza 1 zawiera explicit
  smoke-test przed wpięciem w Fazę 2.
- Harmonogram crona (21:40/22:40 Warsaw) jest wyprowadzony przez analogię do
  sprawdzonego harmonogramu Free, nie zweryfikowany empirycznie na produkcji —
  pierwsze uruchomienie w Fazie 4 to realna weryfikacja.
- Gemini 3.7 Flash został wydany dosłownie w sierpniu 2026 — brak długiej historii
  produkcyjnej u innych; jeśli okaże się niestabilny, `GEMINI_MODEL` w `.env` pozwala
  zmienić model bez deployu kodu.

## Success Criteria (Summary)

- `/llm-gemini` renderuje stan portfela analogicznie do `/llm-free`, seed 10 000 USD.
- Cron na serwerze wykonuje codzienny cykl decyzyjny Gemini bez nieobsłużonych wyjątków.
- `vendor/bin/phpunit` i `composer stan` zielone, zero regresji w istniejących portfelach.
