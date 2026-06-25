# „Share it for your LLM" — Eksport promptu do zewnętrznego modelu — Implementation Plan

## Overview

Dodajemy na stronie analizy spółki (`/analysis/{ticker}`) przycisk **„Share it for your LLM"**,
który otwiera modal z gotowym, edytowalnym promptem. Prompt zawiera: twarde dane CVS
(ten sam pakiet co realne wywołanie AI), naszą wygenerowaną analizę AI oraz prośbę do
modelu użytkownika o **pogłębienie o świeże katalizatory rynkowe i krytyczną recenzję**.
Użytkownik kopiuje prompt do schowka i wkleja do dowolnego modelu (ChatGPT/Gemini/Claude).

Jest to narzędzie **jednokierunkowe** — żadnego powrotnego/publicznego API, callbacku ani
zapisu do bazy. Domyka odczuwalną lukę („analiza ślepa na świeże newsy") przez własny
czat użytkownika, a przy okazji daje cross-check analizy w innym modelu.

## Current State Analysis

- Strona analizy renderuje się przez `AnalysisController::show()` → `templates/analysis.php`;
  w zasięgu widoku są już `$ticker`, `$financials`, `$cachedAi` (treść analizy AI) oraz dane CVS.
- Sekcja analizy AI: `templates/analysis.php:834`. Cached narrative dostępny pod
  `$cachedAi['content']`, widoczny dla **każdego zalogowanego** usera (nie tylko PRO) —
  `AiAnalysisController` PRO-gate'uje wyłącznie *generowanie*, nie odczyt.
- **Pakiet danych do promptu już istnieje:** `AiDivergenceService::buildUserMessage()`
  (`src/Ai/AiDivergenceService.php:145`) składa dokładnie ten blok, który chcemy w promptcie
  (CVS swing/fund, 4 filary, fair value + premia/dyskonto, konsensus analityków, expectations
  signals, trajektoria, earnings timing, strefa ATR, zakres 52-tyg., multiples). Metoda jest
  `private` — trzeba ją udostępnić bez duplikowania logiki.
- **Wzorzec AJAX do naśladowania:** `AiAnalysisController::generate()` (`src/Ai/AiAnalysisController.php:56`):
  `set_time_limit` → `requireAuth` → `verifyCsrf` → walidacja tickera → fetch financials →
  `CVSModel::calculate` → fair value → trajektoria → ATR → (tu: zamiast wołać Claude, składamy prompt) → `Response::json`.
- **Wzorzec modala + przycisków:** `.ai-modal` (ukryty `<div>` przełączany JS) — `templates/analysis.php:945`
  (ai-modal), `:40` (company-modal). Brak helpera schowka — dodajemy `navigator.clipboard`.
- CSRF: `Request::verifyCsrf()` wymagany na każdym POST (CLAUDE.md). Front czyta token z istniejącego
  mechanizmu (jak w `generate-ai`/translation fetch).

## Desired End State

Zalogowany użytkownik, dla którego spółki istnieje już analiza AI w cache, widzi na
`/analysis/{ticker}` przycisk „Share it for your LLM" (ikona udostępniania). Klik otwiera modal
z edytowalnym promptem złożonym serwerowo, przełącznikiem języka PL/EN i przyciskiem „Kopiuj".
Kopiowanie wkleja prompt do schowka. Prompt zawiera kotwicę CVS, twarde dane, naszą analizę,
4 zadania (katalizatory / czego model nie wie / krytyka / scenariusze) i disclaimer.
Weryfikacja: ręczne wklejenie do dowolnego LLM daje pogłębioną, krytyczną analizę; PHPStan
i testy zielone.

### Key Discoveries:

- Reuse zamiast duplikacji: `buildUserMessage` (`src/Ai/AiDivergenceService.php:145`) = gotowy blok danych.
- Lazy assembly: nowy endpoint AJAX, nie render-time — ciężki blok (trajektoria/ATR) liczony tylko na żądanie.
- PRO-gate dotyczy tylko generowania analizy; Share działa dla każdego zalogowanego, o ile `$cachedAi` istnieje.
- Mieszany język (dane EN + analiza PL) jest akceptowany świadomie — bez tłumaczenia narracji.

## What We're NOT Doing

- **Żadnego powrotnego/publicznego API, callbacku, kodowania Base64 ani zapisu do bazy** (odrzucone w frame).
- Żadnego crowdsourcingu ani agregacji sentymentu z odpowiedzi userów.
- Żadnego tłumaczenia narracji PL→EN (akceptujemy mix językowy).
- Żadnego wariantu „tylko dane" — Share pojawia się wyłącznie, gdy analiza AI istnieje.
- Brak persystencji edycji promptu — `<textarea>` jest efemeryczny (edytuj → kopiuj).
- Brak nowego PRO-gate'u — odczyt/eksport nie są limitowane.

## Implementation Approach

Trzy fazy idące od rdzenia (reusable builder) przez transport (endpoint) do UI.
Rdzeń to czysta funkcja składająca tekst — deterministyczna, łatwa do testu jednostkowego,
bez I/O. Endpoint odtwarza pipeline danych z `generate-ai` (bez wywołania Claude) i woła builder.
UI to przycisk warunkowy + modal + schowek, zgodne z istniejącymi wzorcami `.ai-modal`.

## Phase 1: Backend — reusable prompt builder

### Overview

Udostępnić blok danych z `AiDivergenceService` i stworzyć `ExportPromptBuilder`, który składa
kompletny prompt eksportowy w dwóch wariantach językowych.

### Changes Required:

#### 1. Udostępnienie bloku danych

**File**: `src/Ai/AiDivergenceService.php`

**Intent**: Pozwolić innym klasom użyć dokładnie tego samego pakietu danych co realne wywołanie
AI, bez kopiowania logiki — gwarancja, że prompt eksportowy i prompt produkcyjny nie rozjadą się.

**Contract**: Wypromować składanie bloku danych do metody publicznej (np. `public function buildDataBlock(...)`
z tą samą sygnaturą argumentów co dzisiejsze `buildUserMessage`: `ticker, cvsResult, financials,
cvsFairPrice, trajectory, execPlan, subsectorDiffThreshold`), a `generate()`/dotychczasowe
`buildUserMessage` mają z niej korzystać. Zachować istniejące typy i `declare(strict_types=1)`.
Zwracany kształt (string blok danych) bez zmian — istniejące zachowanie `generate()` niezmienione.

#### 2. Klasa składająca prompt

**File**: `src/Ai/ExportPromptBuilder.php` (nowy)

**Intent**: Złożyć finalny tekst promptu: zasada-kotwica + blok danych + nasza analiza AI +
4 zadania + disclaimer, w wariancie PL lub EN.

**Contract**: `final class ExportPromptBuilder` z metodą
`public function build(string $ticker, string $sector, string $dataBlock, string $aiAnalysis, string $lang = 'pl'): string`.
`$lang` ∈ {`pl`,`en`} (inne → fallback `pl`). Statyczne sekcje instrukcji w obu językach
(role + zasada nadrzędna „CVS = kotwica, nie zmieniaj liczb" + 4 zadania: świeże katalizatory z
datami / czego model nie wie / krytyka naszej analizy / dwa scenariusze vs ATR i fair value).
Model-agnostyczny — bez nazw narzędzi konkretnego dostawcy („przeszukaj dostępne źródła", nie
„użyj Google Search"). **Brak** jakiejkolwiek instrukcji technicznej o callbacku/JSON/Base64.
Na końcu doklejony disclaimer CVS (`⚠️ … nie rekomendacja inwestycyjna. Inwestuj świadomie.`).
Czysta funkcja — bez I/O, bez `date()`/losowości.

#### 3. Test jednostkowy buildera

**File**: `tests/Ai/ExportPromptBuilderTest.php` (nowy)

**Intent**: Zablokować kontrakt promptu i zapobiec regresji bezpieczeństwa (brak callbacku).

**Contract**: Asercje: (a) wynik zawiera ticker, blok danych i treść analizy; (b) zawiera zasadę-kotwicę
i disclaimer; (c) PL vs EN różnią się instrukcjami; (d) **negatywna**: wynik NIE zawiera `http`,
`callback`, `base64`, `POST` (gwarancja jednokierunkowości); (e) nieznany `$lang` → wariant PL.

### Success Criteria:

#### Automated Verification:

- Testy jednostkowe przechodzą: `vendor/bin/phpunit tests/Ai/ExportPromptBuilderTest.php`
- Pełny suite zielony: `vendor/bin/phpunit`
- PHPStan level 6 zielony: `composer stan`

#### Manual Verification:

- Wygenerowany prompt (PL i EN) jest czytelny i logiczny po wklejeniu do LLM.
- Sekcja krytyki faktycznie skłania obcy model do kwestionowania naszej analizy.

---

## Phase 2: Endpoint + route

### Overview

Nowy endpoint AJAX, który odtwarza pipeline danych z `generate-ai` (bez wołania Claude),
pobiera cached analizę i zwraca złożony prompt jako JSON.

### Changes Required:

#### 1. Akcja kontrolera

**File**: `src/Ai/AiAnalysisController.php`

**Intent**: Obsłużyć żądanie zbudowania promptu eksportowego dla tickera w wybranym języku.

**Contract**: Nowa metoda `public function sharePrompt(Request $req): void`. Sekwencja jak w
`generate()` aż do złożenia danych: `set_time_limit` → `AuthController::requireAuth()` →
`verifyCsrf()` (403 przy błędzie) → walidacja `ticker` → odczyt `lang` z inputu (`pl`/`en`, default `pl`).
**Bez PRO-gate'u.** Pobranie analizy: `aiRepo->findByTicker($ticker)`; gdy brak → `Response::json(['ok'=>false,
'message'=>'Najpierw wygeneruj analizę AI dla tej spółki.'], 409)`. Następnie fetch financials
(503 przy null), `CVSModel::calculate`, `calcFairPrice`, trajektoria, ATR — identycznie jak
`generate()`. Złożyć: `dataBlock = service->buildDataBlock(...)`, `prompt = (new ExportPromptBuilder)->build(
ticker, sector, dataBlock, cachedContent, lang)`. Zwrócić `['ok'=>true, 'prompt'=>$prompt, 'lang'=>$lang]`.
Nigdy nie rzuca — błędy jako typed JSON (guardrail).

#### 2. Rejestracja trasy

**File**: `src/Core/routes.php`

**Intent**: Wystawić endpoint w sekcji „AI Analysis (S-01)".

**Contract**: `$router->post('/analysis/{ticker}/share-prompt', fn($req) => $aiAnalysis->sharePrompt($req));`
obok istniejącej trasy `generate-ai` (`src/Core/routes.php:122`).

#### 3. Test endpointu / kontrolera

**File**: `tests/Ai/AiAnalysisControllerShareTest.php` (nowy) lub dorzucić do istniejącego testu kontrolera

**Intent**: Zweryfikować bramki (auth/CSRF/brak-analizy) i kształt odpowiedzi bez sieci.

**Contract**: Z wstrzykniętym fake repo/fetcherem (wzorzec testów offline — brak Yahoo/Claude):
(a) brak analizy → `ok:false` + 409; (b) analiza istnieje → `ok:true` + niepusty `prompt`
zawierający ticker; (c) `lang=en` → wariant EN. Reużyć syntetyczne `$financials` z
`CVSModelTest::baseFinancials()` jako fixture.

### Success Criteria:

#### Automated Verification:

- Testy endpointu przechodzą: `vendor/bin/phpunit tests/Ai`
- Pełny suite zielony: `vendor/bin/phpunit`
- PHPStan level 6 zielony: `composer stan`

#### Manual Verification:

- `POST /analysis/{TICKER}/share-prompt` z poprawnym CSRF zwraca `ok:true` i prompt dla spółki z analizą.
- Bez analizy zwraca `ok:false` z czytelnym komunikatem (409), strona pozostaje sprawna.
- Bez CSRF → 403.

---

## Phase 3: UI — przycisk + modal + schowek

### Overview

Warunkowy przycisk „Share it for your LLM", modal z edytowalnym promptem, przełącznik PL/EN
i kopiowanie do schowka.

### Changes Required:

#### 1. Przycisk Share

**File**: `templates/analysis.php`

**Intent**: Udostępnić eksport tylko gdy istnieje analiza AND użytkownik zalogowany.

**Contract**: W `ai-analysis-card__actions` (`templates/analysis.php:837`) dodać `<button id="btn-share-llm">`
renderowany **tylko** wewnątrz `<?php if (!empty($cachedAi)): ?>`. Etykieta tekstowa + ikona udostępniania
(SVG „share", jak w załączniku usera — trzy węzły połączone liniami). `data-ticker` jak inne przyciski.

#### 2. Modal eksportu

**File**: `templates/analysis.php`

**Intent**: Pokazać edytowalny prompt z przełącznikiem języka i akcją kopiowania.

**Contract**: Nowy `<div id="share-modal" class="ai-modal" hidden>` wg wzorca istniejących modali
(`:945`). Zawiera: nagłówek + przyciski zamknięcia, przełącznik PL/EN (dwa przyciski lub toggle),
duży `<textarea id="share-prompt-text">` (edytowalny, monospace), przyciski „Kopiuj" i „Zamknij",
miejsce na status („Kopiowanie…", „Skopiowano ✓", błąd) oraz `disclaimer-inline`. Domyślny język PL.

#### 3. Logika JS

**File**: `templates/analysis.php` (blok `<script>` sekcji AI)

**Intent**: Pobrać prompt z endpointu, obsłużyć PL/EN i schowek.

**Contract**: Po kliknięciu Share: otwórz modal, `fetch('/analysis/'+ticker+'/share-prompt', {method:'POST', ...})`
z CSRF (ten sam mechanizm co `generate-ai`) i `lang`, wstaw `prompt` do textarea (stan „ładowanie" w trakcie).
Przełącznik PL/EN: ponowny fetch z nowym `lang`, podmiana textarea (zachowaj prosty cache odpowiedzi per język,
by nie wołać dwa razy). „Kopiuj": `navigator.clipboard.writeText(textarea.value)` z fallbackiem
`textarea.select()+document.execCommand('copy')` dla starszych przeglądarek; pokaż status „Skopiowano ✓".
Zamknięcie: przycisk + klik w tło (`e.target===this`), jak company-modal (`:100`). Błąd fetch → komunikat
w modalu, strona sprawna.

#### 4. Style (jeśli potrzebne)

**File**: `public/` CSS (ten sam arkusz co `.ai-modal`)

**Intent**: Dopasować szerszy modal z textarea do istniejącego stylu.

**Contract**: Reużyć `.ai-modal` / `.ai-modal__inner`; dodać minimalne reguły dla textarea i przełącznika
języka tylko jeśli istniejące klasy nie wystarczą. Bez nowego frameworka.

### Success Criteria:

#### Automated Verification:

- Pełny suite zielony: `vendor/bin/phpunit`
- PHPStan level 6 zielony: `composer stan`
- Brak błędów składni PHP w szablonie: `php -l templates/analysis.php`

#### Manual Verification:

- Przycisk Share widoczny tylko dla spółek z istniejącą analizą AI.
- Klik otwiera modal; prompt ładuje się i wypełnia textarea.
- Przełącznik PL/EN podmienia treść promptu.
- „Kopiuj" wkłada prompt do schowka (sprawdzone wklejeniem); status „Skopiowano ✓".
- Edycja promptu w textarea działa i jest kopiowana w zmienionej formie.
- Zamknięcie przez przycisk i klik w tło; brak regresji w sekcji generowania/odświeżania AI.

---

## Testing Strategy

### Unit Tests:

- `ExportPromptBuilder`: obecność kluczowych sekcji, różnica PL/EN, fallback języka, **negatywna**
  asercja braku callbacku/Base64/POST/http.

### Integration Tests:

- `sharePrompt`: bramki auth/CSRF, ścieżka braku analizy (409), ścieżka sukcesu (prompt z tickerem),
  wariant `lang=en` — wszystko offline z fixturami (`CVSModelTest::baseFinancials()`).

### Manual Testing Steps:

1. Otwórz `/analysis/{TICKER}` dla spółki **bez** analizy → przycisku Share nie ma.
2. Wygeneruj analizę (PRO) lub wejdź na spółkę z analizą → przycisk Share widoczny.
3. Klik Share → modal, prompt ładuje się do textarea.
4. Przełącz PL/EN → treść się zmienia.
5. Edytuj textarea, „Kopiuj" → wklej w zewnętrzny edytor/LLM, potwierdź zawartość.
6. Wklej prompt do realnego LLM → sprawdź, że pogłębia o newsy i krytykuje naszą analizę, nie zmieniając liczb CVS.
7. Zamknij modal (przycisk + tło).

## Performance Considerations

Endpoint odtwarza pipeline danych z `generate-ai` (fetch financials + trajektoria + ATR), ale
**bez** wywołania Claude — koszt zdominowany przez fetch financials, który korzysta z cache
sesyjnego (`data_source.cache_ttl`). Składanie leniwe (na klik), nie przy renderze strony.
PL/EN cache'owane po stronie JS, by uniknąć podwójnego round-tripu.

## Migration Notes

Brak. Zero zmian w schemacie bazy, zero migracji.

## References

- Frame brief: `context/changes/ai-catalyst-enrichment/frame.md`
- Reuse bloku danych: `src/Ai/AiDivergenceService.php:145`
- Wzorzec endpointu AJAX: `src/Ai/AiAnalysisController.php:56`
- Wzorzec modala/przycisków: `templates/analysis.php:834`, `:945`, `:40`
- Trasa do naśladowania: `src/Core/routes.php:122`
- Źródło idei (zrewidowane): `context/foundation/idea_1.md`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Backend — reusable prompt builder

#### Automated

- [x] 1.1 Testy jednostkowe buildera przechodzą: `vendor/bin/phpunit tests/Ai/ExportPromptBuilderTest.php` — 27f3e0c
- [x] 1.2 Pełny suite zielony: `vendor/bin/phpunit` — 27f3e0c
- [x] 1.3 PHPStan level 6 zielony: `composer stan` — 27f3e0c

#### Manual

- [ ] 1.4 Prompt PL i EN czytelny i logiczny po wklejeniu do LLM
- [ ] 1.5 Sekcja krytyki skłania obcy model do kwestionowania naszej analizy

### Phase 2: Endpoint + route

#### Automated

- [x] 2.1 Testy endpointu przechodzą: `vendor/bin/phpunit tests/Ai` — 96393cc
- [x] 2.2 Pełny suite zielony: `vendor/bin/phpunit` — 96393cc
- [x] 2.3 PHPStan level 6 zielony: `composer stan` — 96393cc

#### Manual

- [ ] 2.4 POST z poprawnym CSRF zwraca `ok:true` i prompt dla spółki z analizą
- [ ] 2.5 Bez analizy → `ok:false` (409), strona sprawna
- [ ] 2.6 Bez CSRF → 403

### Phase 3: UI — przycisk + modal + schowek

#### Automated

- [x] 3.1 Pełny suite zielony: `vendor/bin/phpunit` — 527c85e
- [x] 3.2 PHPStan level 6 zielony: `composer stan` — 527c85e
- [x] 3.3 Składnia szablonu OK: `php -l templates/analysis.php` — 527c85e

#### Manual

- [ ] 3.4 Przycisk Share widoczny tylko dla spółek z analizą AI
- [ ] 3.5 Klik otwiera modal; prompt ładuje się do textarea
- [ ] 3.6 Przełącznik PL/EN podmienia treść
- [ ] 3.7 „Kopiuj" wkłada prompt do schowka (potwierdzone wklejeniem) + status „Skopiowano ✓"
- [ ] 3.8 Edycja w textarea kopiowana w zmienionej formie
- [ ] 3.9 Zamknięcie przyciskiem i klikiem w tło; brak regresji w sekcji AI
