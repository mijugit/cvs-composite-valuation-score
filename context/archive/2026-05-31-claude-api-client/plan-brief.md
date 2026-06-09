# F-02: Klient Claude API — Plan Brief

> Full plan: `context/changes/claude-api-client/plan.md`

## What & Why

Zbudować jeden reużywalny klient Claude API (Anthropic Messages API) w `src/Ai/` (`CVS\Ai\`) —
pierwszą warstwę AI w projekcie. To fundament F-02 z roadmapy fazy 2: north star S-01 (analiza
wyjaśniająca rozjazd CVS vs analitycy) stoi na tym kliencie. Budujemy go z guardrailami od pierwszej
linii, bo to pierwsza płatna zależność zewnętrzna z latencją i limitami.

## Starting Point

`src/Ai/` nie istnieje. Repo ma własny parser `.env`→`$_ENV`, configi-tablice wstrzykiwane przez
konstruktor, wzorzec typed value-object (`CVSResult`) i gotową graceful-degradation w
`AnalysisController`. Istnieje sprawdzony, ale prymitywny wzorzec klienta w innym projekcie
(`MiJuLinguo/api/claude-client.php`) — rzuca wyjątki, bez retry/cachingu/typed-failure — baza do
przepisania, nie do skopiowania.

## Desired End State

`CVS\Ai\ClaudeClient` wysyła wiadomości (z opcjonalnym cacheowalnym system promptem) i zwraca
typowany `AiResult` (success z tekstem + tokenami | failure z rozróżnianym `kind`), **nigdy nie
rzucając** do strony. Ponawia ≤2× na błędy przejściowe w budżecie <25s, wspiera prompt caching,
czyta config z `.env`, składa się przez factory i jest w pełni przetestowany offline.

## Key Decisions Made

| Decision | Choice | Why (1 zdanie) | Source |
| --- | --- | --- | --- |
| Granica zakresu | Sam klient + seam + config | Najmniejszy fundament; analiza/DB/PRO to S-01/F-05 | Plan |
| Kształt odpowiedzi | Typed `AiResult` z `usage` | Wystawia tokeny pod późniejszy tracking kosztu (FR-004) bez zmiany klienta | Plan |
| Streaming | Nie teraz (sygnatura gotowa na później) | Mniejszy fundament, łatwe testy offline; progres = spinner | Plan |
| Retry | ≤2, backoff, cap <~25s | Odporność na 429/529 bez łamania NFR <30s | Plan |
| Typed failure | Rozróżniane `kind` (timeout/rate/overloaded/auth/quota/bad/network) | UI/S-01 pokaże konkretny komunikat i zdecyduje o retry | Plan |
| Prompt caching | Opcjonalny blok 5m (GA) + opt-in 1h (beta) | Kontrola kosztu wg CLAUDE.md, domyślnie GA-safe | Plan |
| Config/wiring | `config/ai.php` + `.env` + factory | Zgodne z istniejącym wzorcem, jeden punkt konstrukcji | Plan |
| Testy | Pełne guardraile przez `FakeTransport` | Każdy tryb porażki i retry zablokowany testem offline | Plan |

## Scope

**In scope:** klient `ClaudeClient`, `CurlTransport` + interfejs `HttpTransport`, `AiResult`/`AiUsage`/
`AiFailureKind`, `config/ai.php` + klucze `.env`, `ClaudeClientFactory`, pełne testy offline.

**Out of scope:** logika analizy / prompt rozjazdu (S-01), współdzielony cache w DB (S-01), brama PRO
i persystencja zużycia (F-05), streaming/SSE, live smoke test, mailer/scheduler/screener.

## Architecture / Approach

`ClaudeClient` zależy od wąskiego interfejsu `HttpTransport` (prod: `CurlTransport`; test:
`FakeTransport`) — sieć odizolowana, więc cała logika (budowa requestu z `cache_control`, retry/backoff,
mapowanie status→`AiFailureKind`, parsowanie `usage`) jest testowalna bez realnych wywołań. Klient
składany przez `ClaudeClientFactory::fromConfig(require config/ai.php)`. Konwencje skopiowane z
`CVSResult` (typed result) i `AnalysisController` (graceful degradation).

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Kontrakty + config + seam | `AiResult`/`AiUsage`/`AiFailureKind`, `config/ai.php`, `.env`, `HttpTransport` | Kształt typed-result musi pasować pod FR-004 (tokeny) |
| 2. Klient + transport + factory | `CurlTransport`, `ClaudeClient` (caching, retry, mapowanie), factory | Budżet retry vs NFR <30s; poprawne mapowanie 429/529 |
| 3. Matryca testów offline + polish | `FakeTransport` + testy każdego guardrailu, PHPStan, notka | Pokrycie wszystkich trybów porażki + redakcja klucza |

**Prerequisites:** klucz Anthropic API w lokalnym `.env` (tylko do ręcznej weryfikacji; testy są offline).
**Estimated effort:** ~2-3 sesje, 3 fazy.

## Open Risks & Assumptions

- **ID modelu bywa nieaktualne w docsach** → trzymane w `.env`/`config`, dev potwierdza aktualne ID przy wdrożeniu.
- **Prompt caching 1h** wymaga nagłówka beta (`extended-cache-ttl-2025-04-11`); domyślnie używamy 5m (GA).
- **Budżet czasu**: per-próba timeout + ≤2 retry + backoff musi zsumować się < ~25s — do zweryfikowania testem.
- Pełna wartość prompt-cachingu materializuje się dopiero w S-01 (stabilny system prompt).

## Success Criteria (Summary)

- `ClaudeClient` zwraca typed `AiResult` i **nigdy nie rzuca** — błąd/timeout/limit degraduje się gracefully.
- Wszystkie tryby porażki i retry pokryte testami offline; PHPStan zielony; pełny suite zielony.
- Klucz API nigdy nie trafia do logów/`toArray()`/repo; brak cURL do Anthropic poza `src/Ai/`.
