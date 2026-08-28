# Czwarty portfel eksperymentalny — LLM GPT Luna — Plan Brief

> Full plan: `context/changes/llm-gpt-luna-wallet/plan.md`

## What & Why

Klon istniejącego portfela "LLM Gemini" — identyczna mechanika (kapitał
10 000 USD, brak DecisionEnforcer, mark-to-market, legenda/pamięć per cykl,
natywny web-search), ale wykonawcą decyzji jest GPT (OpenAI) wariant "Luna"
zamiast Gemini. User: "Mechanika działania identyczna jak dla portfela
gemini nic nie zmieniamy tylko używamy innego modelu."

## Starting Point

`CVS\LlmGemini\` (6 klas) to sprawdzony, trzeci raz już użyty wzorzec
(Free→Gemini→teraz GPT-Luna). `src/Ai/GPTClient.php`/`GPTClientFactory.php`
**już istnieją** (change: critical-review-openai) z gotowym wariantem
`'luna'` w `config/gpt.php` — klucze API już są na serwerze. Jedyna
rzeczywista różnica wobec Gemini: `GPTClientFactory::fromConfig($config,
'luna')` wymaga 2. argumentu, i web search ma inny kształt (`tools:
[['type'=>'web_search']]` zamiast `googleSearch`).

## Desired End State

Czwarty portfel pod `/llm-gpt-luna`, widoczny w nawigacji "Portfele", z
własnym cronem CF. Wykres porównawczy NAV na wszystkich 4 stronach portfeli
pokazuje wszystkie 4 portfele + 2 benchmarki (S&P 500, Nasdaq 100).

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Nazewnictwo | `CVS\LlmGptLuna\` / `/llm-gpt-luna` / `llm_gpt_luna_*` / "LLM GPT Luna" | Pełna nazwa, spójna z tym że config/gpt.php już rozróżnia terra/luna — zero kolizji jeśli kiedyś dojdzie Terra | Plan |
| Okno crona | Szerokie (420 min) jak Gemini, przykład `10 20 * * 1-5` Warsaw | Dokładnie ten sam wzorzec co bezpośredni szablon (Gemini) | Plan |
| WalletNavChartService | Kolejny opcjonalny 5. parametr (nie refaktor) | Zero ryzyka regresji w działającym kodzie 3 pozostałych portfeli | Plan |
| context_search_cap | 3 (identycznie jak Gemini) | Dosłowne "nic nie zmieniamy" | Plan |
| reasoning_effort | Domyślne z config/gpt.php ('medium'), bez override | Ten sam wzorzec co istniejący GPTCriticalReviewService | Plan |

## Scope

**In scope:**
- Migracja 043 (4 tabele `llm_gpt_luna_*`), `config/llm-gpt-luna-wallet.php`, `.env.example` uzupełnienie
- `CVS\LlmGptLuna\*` (6 klas, klon `CVS\LlmGemini\*`)
- `bin/llm-gpt-luna-wallet-rebalance.php`, routing, nawigacja
- `WalletNavChartService` 5. seria na wszystkich 4 stronach portfeli

**Out of scope:**
- Zmiany w istniejących 3 portfelach poza dodaniem 5. parametru do wykresu
- Wspólny interfejs LLM dla Claude/Gemini/GPT
- Nowy cron zakładany automatycznie (user robi to ręcznie w panelu CF)
- Zmiana treści system promptu lub formatu odpowiedzi JSON

## Architecture / Approach

Mechaniczne klonowanie sprawdzonego wzorca w 3 fazach: (1) warstwa danych —
migracja, config, 3 klasy bez logiki LLM; (2) silnik decyzyjny — jedyne
miejsce z realną różnicą wobec Gemini (klient/tools shape); (3) cron +
widoczność (kontroler/widok/routing/nawigacja/wykres). System prompt i
parser odpowiedzi (`LlmFreeDecisionParser`) reużyte bez zmian — identyczne
instrukcje, inny wykonawca, to cały sens eksperymentu.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Warstwa danych i config | Migracja, config, 3 mechaniczne klony klas | Niski — czysty SQL/CRUD, zero logiki LLM |
| 2. Silnik decyzyjny | Context gatherer + decision service z GPT-Luna | Jedyna faza z realnym ryzykiem — trzeba potwierdzić że GPT-Luna faktycznie zwraca poprawny JSON + działa web_search w tym kontekście |
| 3. Cron + widoczność | Strona `/llm-gpt-luna`, nawigacja, wykres 4-portfelowy | Kolizja okna crona z 3 istniejącymi — plan daje przykładową, nie narzuconą minutę |

**Prerequisites:** klucze `GPT_Luna_CVS`/`GPT_MODEL_Luna` już w `.env` na
serwerze (potwierdzone przez usera i istniejące użycie w
`GPTCriticalReviewService`).
**Estimated effort:** ~2-3 sesje, po jednej-dwóch na fazę — istotnie mniej
niż `llm-gemini-wallet` (tam Faza 1 budowała klienta Gemini od zera; tu
klient GPT już istnieje).

## Open Risks & Assumptions

- Zakładamy że `GPTClient`'s web search (`tools: [['type'=>'web_search']]`)
  faktycznie działa w praktyce dla tego typu zapytań — kod istnieje i jest
  używany produkcyjnie przez `GPTCriticalReviewService`, ale nie było
  dotąd używane w kontekście "krótkie zapytanie per ticker, do 3 razy na
  cykl" jak w portfelu. Faza 2 ma dedykowany manualny smoke-test właśnie po
  to, żeby to zweryfikować przed przejściem do Fazy 3.
- Citation-parsing GPT (`annotations[].type==='url_citation'`) jest
  oznaczone w kodzie jako "nie potwierdzone niezależnie na żywej
  odpowiedzi" — ale portfel GPT Luna nie używa cytowań (tylko tekst
  kontekstu), więc to ryzyko go nie dotyczy.

## Success Criteria (Summary)

- `/llm-gpt-luna` działa identycznie do `/llm-gemini` — ta sama mechanika, inny wykonawca
- Cron uruchamia się bez nieobsłużonych wyjątków, zapisuje cykl ze statusem `completed` lub `llm_failed`
- Wszystkie 4 strony portfeli pokazują spójne porównanie 4 portfeli + 2 benchmarki
