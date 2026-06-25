# „Share it for your LLM" — Plan Brief

> Full plan: `context/changes/ai-catalyst-enrichment/plan.md`
> Frame brief: `context/changes/ai-catalyst-enrichment/frame.md`

## What & Why

Dodajemy przycisk „Share it for your LLM" na stronie analizy spółki, który generuje gotowy,
edytowalny prompt (twarde dane CVS + nasza analiza AI + prośba o pogłębienie o newsy i krytykę)
do skopiowania i wklejenia do dowolnego modelu użytkownika. Powód: istniejąca, opłacana warstwa
AI jest celowo odcięta od świeżych katalizatorów rynkowych — to narzędzie domyka tę lukę przez
prywatny czat użytkownika i daje cross-check analizy w innym modelu.

## Starting Point

Strona `/analysis/{ticker}` już renderuje analizę AI z cache (`$cachedAi`), widoczną dla każdego
zalogowanego usera. `AiDivergenceService::buildUserMessage()` już składa kompletny pakiet danych
CVS, a `AiAnalysisController::generate()` to gotowy wzorzec AJAX (auth → CSRF → dane → JSON).
Brakuje tylko: reużycia tego bloku, endpointu bez wołania Claude, oraz przycisku/modala/schowka.

## Desired End State

Użytkownik na spółce z istniejącą analizą widzi przycisk „Share it for your LLM". Klik otwiera
modal z edytowalnym promptem, przełącznikiem PL/EN i przyciskiem „Kopiuj". Skopiowany prompt
wklejony do dowolnego LLM daje pogłębioną, krytyczną analizę z newsami — bez zmiany liczb CVS.

## Key Decisions Made

| Decision | Choice | Why | Source |
| --- | --- | --- | --- |
| Mechanizm | Eksport jednokierunkowy (bez powrotnego API) | Zero powierzchni ataku; callback Base64 odrzucony | Frame |
| Zawartość promptu | Pełne dane CVS + nasza analiza AI | Pełna „druga opinia", obcy model recenzuje narrację | Frame |
| Składanie promptu | Endpoint AJAX (leniwie) | Zero kosztu przy renderze; reuse `buildUserMessage` + wzorca `generate-ai` | Plan |
| Język | Przełącznik PL/EN, mix akceptowany | Dane EN + analiza PL łykane przez LLM bez problemu; tłumaczenie = zbędny koszt | Frame/Plan |
| Dostępność Share | Tylko gdy analiza AI istnieje | Spójne z „dane + analiza"; zero martwych stanów UI | Plan |
| Reuse danych | Wypromowanie `buildUserMessage`→publiczna | Gwarancja, że prompt eksportowy nie rozjedzie się z produkcyjnym | Plan |

## Scope

**In scope:** przycisk Share (warunkowy), modal z edytowalnym promptem, przełącznik PL/EN,
copy-to-clipboard, endpoint `POST /share-prompt`, builder promptu + testy.

**Out of scope:** powrotne/publiczne API, callback, Base64, zapis do bazy, crowdsourcing,
tłumaczenie narracji, wariant „tylko dane", persystencja edycji, nowy PRO-gate, migracje.

## Architecture / Approach

Trzy warstwy: (1) `ExportPromptBuilder` — czysta funkcja składająca tekst (kotwica + dane +
analiza + 4 zadania + disclaimer, PL/EN); (2) endpoint `sharePrompt` w `AiAnalysisController`
odtwarza pipeline danych z `generate-ai` (bez wołania Claude), pobiera cached analizę, woła
builder, zwraca JSON; (3) UI w `templates/analysis.php` — przycisk + `.ai-modal` + JS (fetch
z CSRF, PL/EN, `navigator.clipboard`).

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Backend builder | Reusable blok danych + `ExportPromptBuilder` + testy | Rozjazd z promptem produkcyjnym (mityg.: wspólna metoda) |
| 2. Endpoint + route | `POST /share-prompt` zwraca prompt jako JSON | Ścieżka braku analizy (409) i bramki CSRF |
| 3. UI + schowek | Przycisk + modal + PL/EN + copy | Kompatybilność schowka (fallback execCommand) |

**Prerequisites:** brak — wszystko bazuje na istniejących komponentach.
**Estimated effort:** ~1–2 sesje, 3 fazy.

## Open Risks & Assumptions

- Mix językowy (dane EN + analiza PL) przyjęty świadomie jako kosmetyka — gdyby raził, można
  później dotłumaczyć narrację (osobny zakład).
- Share zależy od istnienia analizy AI (PRO-gated do wygenerowania); to zgodne z zamysłem.
- `navigator.clipboard` wymaga HTTPS/секure context — fallback `execCommand('copy')` dla starszych.

## Success Criteria (Summary)

- Przycisk Share pojawia się tylko dla spółek z analizą; modal pokazuje poprawny prompt PL/EN.
- Kopiowanie działa (potwierdzone wklejeniem), edycja promptu jest kopiowana.
- Prompt nie zawiera żadnego callbacku/Base64/POST (test negatywny); PHPStan i testy zielone.
