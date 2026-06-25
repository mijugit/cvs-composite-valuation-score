# Frame Brief: AI-Enrichment v1.0 — newsy/sentyment przez LLM użytkowników

> Krok framingu przed /10x-plan. Oddziela to, co JEST faktycznym problemem,
> od mechanizmu zaproponowanego w idea_1.md.

## Reported Observation

CVS oraz istniejąca warstwa AI (`AiDivergenceService`) są ślepe na **bieżące
wydarzenia** — newsy z ostatnich dni, żywy sentyment makro, ryzyko zdarzeniowe
(earnings). W krótkim horyzoncie (swing) wycena matematyczna potrafi się
rozjechać z zachowaniem rynku (przykłady z idea_1: tąpnięcia AI na SK Hynix,
Alphabet). Użytkownik potwierdził: rdzeń to **luka danych (świeże katalizatory)**.

## Initial Framing (preserved)

- **User's stated cause or approach**: Profesjonalne API z dostępem do internetu
  (Perplexity, Google Search) są drogie → przerzućmy koszt pozyskania newsów na
  darmowe/płatne pakiety konsumenckie użytkowników (ich ChatGPT/Gemini/Claude Pro).
- **User's proposed direction**: Modal generuje prompt z wstrzykniętymi danymi CVS
  + `session_id`; user wkleja do swojego LLM; LLM odsyła JSON zakodowany w Base64
  przez publiczny `/api/callback?session=...&data=...`; zapis do współdzielonej
  bazy sentymentu widocznej dla wszystkich userów.
- **Pre-dispatch narrowing**: Rdzeń = **luka danych (newsy/sentyment)**, NIE „zero
  kosztów API", NIE „baza sentymentu jako moat". Odczuwalna luka = **brak świeżych
  katalizatorów** (przejęcia, zarząd, rekomendacje banków, earnings risk).

## Dimension Map

Obserwacja („analiza nie zna bieżących newsów") może mieć źródło w jednym z:

1. **Capability — brak warstwy live-news w prompt'cie AI** — `AiDivergenceService`
   ma twardą regułę „Base your analysis ONLY on the numerical data provided... Do
   not reference any news events" (`src/Ai/AiDivergenceService.php:68-71`). To jest
   realna, potwierdzona luka zdolności. ← **gdzie żyje faktyczny problem**
2. **Cost / sourcing — skąd brać dane z internetu** — założenie idea_1: jedyna tania
   droga to crowdsourcing przez LLM-y userów. Premisa FAŁSZYWA: projekt już ma i
   opłaca integrację Claude API (`src/Ai/ClaudeClient`, CLAUDE.md: „All language-model
   calls go through a single client in src/Ai/"). ← initial framing
3. **Delivery — mechanizm zwrotki** — „no-click POST" jest nierealny (konsumenckie
   LLM-y nie robią wychodzącego HTTP); realny wektor to klik w link Base64. To jest
   faktyczny kanał ingestii i zarazem powierzchnia ataku.
4. **Trust / integrity — wiarygodność danych** — publiczny, nieuwierzytelniony
   endpoint zapisujący dowolny tekst do współdzielonej bazy pokazywanej innym userom.
   Każdy może podrobić sentyment. Łamie posturę bezpieczeństwa projektu (CLAUDE.md:
   CSRF na każdym POST, auth-guard, ORM/param queries).

## Hypothesis Investigation

| Hypothesis | Evidence | Verdict |
| --- | --- | --- |
| 1. Brak warstwy live-news w AI (capability) | `AiDivergenceService.php:68-71` reguła „ONLY numerical data, no news events"; `change.md` S-01: „bez web-browsing przez AI" | **STRONG** |
| 2. Sourcing wymaga crowdsourcingu bo API drogie (initial framing) | Projekt już ma opłacaną integrację Claude API: `src/Ai/ClaudeClient`, `ClaudeClientFactory`, migracja `007_create_ai_usage_log`, `008_create_ai_analyses`; CLAUDE.md „API key comes from .env... prompt caching to control cost" → koszt już zaakceptowany i kontrolowany | **NONE** (premisa obalona) |
| 3. „No-click POST" to działający kanał | idea_1 sam przyznaje (linie 92-94, 115-117): darmowe LLM-y mają zablokowane zapytania sieciowe → fallback to klik w link Base64 | **WEAK** (mechanizm degraduje się do ręcznego klika) |
| 4. Publiczny callback bezpiecznie zasili bazę sentymentu | Brak modelu zaufania: dowolne `data=` Base64 → DB widoczna dla userów; sprzeczne z CLAUDE.md security (CSRF/auth/generic-errors) | **NONE** dla „bezpiecznie"; integralność danych ≈ 0 |

## Narrowing Signals

- User wybrał rdzeń = **luka danych**, jawnie odrzucając „zero kosztów API" i „moat".
  → To usuwa jedyne uzasadnienie crowdsourcingu/callbacku. Mechanizm z idea_1 był
  rozwiązaniem problemu (koszt), którego user faktycznie nie ma.
- User wybrał odczuwalną lukę = **brak świeżych katalizatorów** (nie „żywy sentyment",
  nie „output i tak słaby"). → Cel to wstrzyknięcie konkretnych, datowanych wydarzeń
  do istniejącej, dobrej narracji — a nie nowy podsystem agregacji nastrojów tłumu.

## Cross-System Convention

W tym projekcie WSZYSTKIE wywołania LLM idą przez jeden klient w `src/Ai/`
(CLAUDE.md, twarda reguła — „never scatter cURL calls across controllers").
`AiDivergenceService` już buduje prompt z bloków danych i wstrzykuje je do
uziemionego system-promptu; dorzucenie bloku „RECENT CATALYSTS" to ten sam wzorzec,
co istniejące bloki (EXPECTATIONS SIGNALS, EARNINGS TIMING, EXECUTION PLAN).
Konwencja projektu = kontrolowane, uwierzytelnione, cache'owane wywołania serwera —
publiczny anonimowy write-endpoint jest jej przeciwieństwem.

## Wybrana gałąź (decyzja usera, 2026-06-25)

Po dyskusji user świadomie wybrał INNĄ odpowiedź na tę samą obserwację niż
serwerowy reframe poniżej: **narzędzie eksportu promptu („Share it for your LLM")**,
nie pozyskiwanie newsów przez aplikację.

- **Co:** przycisk/ikona „Share it for your LLM" na końcu analizy spółki → modal z
  gotowym promptem (nasza analiza AI + dane finansowe + wyliczony CVS + prośba do
  modelu usera o pogłębienie o newsy/katalizatory). User widzi prompt, może go
  edytować, kopiuje do schowka, używa w dowolnym modelu.
- **Czego NIE ma (świadomie odłożone):** żadnego powrotnego API/callbacku, żadnego
  zapisu do bazy, żadnego crowdsourcingu. „Na ten moment zostawmy wywołanie API."
- **Wartość:** druga opinia / krytyczny cross-check analizy w innym modelu — realna
  nawet jeśli dane nigdy nie wracają i nawet jeśli feature „się nie przyjmie".
- **Przyjęty tradeoff:** analiza AI w samej aplikacji DALEJ jest ślepa na newsy.
  Świeże katalizatory żyją w czacie usera, nie wracają do CVS. To narzędzie, nie
  wzbogacenie modelu.
- **Guardraile do planu:** (1) prompt czerpie dane z tego samego źródła co
  `AiDivergenceService::buildUserMessage` (jeden pakiet danych, bez drugiej wersji);
  (2) model-agnostyczny (bez założeń o narzędziach konkretnego dostawcy);
  (3) disclaimer CVS jedzie razem z promptem.

### Decyzje treściowe promptu (user, 2026-06-25)

- **Zakres danych:** PEŁNY pakiet — wszystko co składa `buildUserMessage` (4 filary,
  fair value, konsensus analityków, expectations signals, earnings timing, multiples,
  strefa ATR, zakres 52-tyg.).
- **Co recenzujemy:** twarde dane CVS **+ nasza analiza AI** (pełna „druga opinia" —
  obcy model pogłębia i krytykuje naszą narrację, nie tylko liczby).
- **Język:** przełącznik **PL/EN w modalu** (user wybiera).
- **Struktura promptu (4 zadania):** (1) świeże katalizatory z datami, (2) „czego model
  nie wie" — połączenie newsów z liczbami, (3) KRYTYKA naszej analizy, (4) dwa
  scenariusze vs strefa ATR i fair value. Zasada nadrzędna: CVS = kotwica, nie do
  zmiany; krytyka interpretacji dozwolona, podmiana liczb nie.

### Brzegi do rozstrzygnięcia w planie

1. **PL/EN vs polska narracja:** cache `ai_analyses` jest po polsku. Przy EN-prompt
   instrukcje są EN, ale wklejony `{ANALIZA_AI}` zostaje PL. Decyzja: akceptujemy mix
   języków czy tłumaczymy narrację?
2. **Zależność od istniejącej analizy:** wariant „dane + analiza" wymaga, by analiza AI
   była już wygenerowana (PRO-gated, on-demand). Fallback: przycisk nieaktywny bez
   analizy, albo cichy zjazd do „tylko twarde dane".

## Reframed Problem Statement (wariant serwerowy — NIE wybrany, zachowany jako alternatywa)

> **Faktyczny problem do zaplanowania**: istniejąca, opłacana warstwa AI generuje
> dobrą narrację, ale jest celowo odcięta od świeżych, datowanych katalizatorów
> rynkowych — i to aplikacja, nie użytkownik, powinna je pozyskać przez swój własny
> kanał Claude API.

Crowdsourcing przez LLM-y userów i publiczny callback Base64 (cała architektura
idea_1.md) były odpowiedzią na problem KOSZTU, który user właśnie zdeprioryzował —
przy jednoczesnym wprowadzeniu poważnego długu bezpieczeństwa (anonimowy write do
współdzielonej bazy) i mechanizmu, który sam idea_1 przyznaje, że nie działa
„no-click". Skoro projekt już płaci za Claude API, naturalne źródło świeżych
katalizatorów leży po stronie serwera (np. web-search jako narzędzie modelu lub
news-API zasilające istniejący uziemiony prompt) — bez modala, bez Base64, bez
publicznego endpointu, bez kryzysu integralności danych.

Pomysł „crowdsourced AI sentiment jako moat" NIE jest bezwartościowy — ale to
**osobny zakład** (ambicja danych/efekt sieciowy), który user świadomie odłożył.
Zachowany niżej jako opcja odroczona, nie część tego planu.

## Confidence

**HIGH** — reguła grounding w `AiDivergenceService.php:68-71` i opis S-01 wprost
potwierdzają lukę (capability); istnienie opłacanego `ClaudeClient` + migracji
AI obala premisę kosztową; a decyzja usera (luka danych / świeże katalizatory)
rozstrzyga kierunek. Trzy niezależne źródła zbieżne.

## What Changes for /10x-plan

Plan ma dotyczyć **wybranej gałęzi (export-only)**: przycisk „Share it for your LLM"
na stronie analizy → modal z edytowalnym, model-agnostycznym promptem składanym z
tego samego pakietu danych co `AiDivergenceService::buildUserMessage`, kopiowanym do
schowka, z dołączonym disclaimerem CVS. Plan NIE ma dotyczyć: powrotnego/publicznego
API, callbacku Base64 ani bazy crowdsourcingowej (świadomie odłożone — „zostawmy
wywołanie API"). Wariant serwerowy (web-search po stronie aplikacji) pozostaje
udokumentowaną alternatywą, gdyby kiedyś celem stało się realne domknięcie luki
newsowej w samym CVS.

## References

- Źródło: `context/foundation/idea_1.md`
- Potwierdzenie luki: `src/Ai/AiDivergenceService.php:68-71`, `:280-302`
- Obalenie premisy kosztowej: `src/Ai/ClaudeClient.php`, `src/Ai/ClaudeClientFactory.php`,
  `database/migrations/007_create_ai_usage_log.sql`, `008_create_ai_analyses.sql`
- Konwencja AI: `CLAUDE.md` → „Claude API" (sekcja Phase 2)
- Istniejący feature: `context/changes/ai-divergence-analysis/` (S-01)
- Sub-agentów nie dyspatchowano — bezpośrednie odczyty plików + decyzja usera były rozstrzygające.
