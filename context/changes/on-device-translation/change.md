---
change-id: on-device-translation
title: "On-device tłumaczenie PL ⇄ EN (Chrome Translator API / Built-in AI)"
status: implemented
created: 2026-06-14
updated: 2026-06-14
roadmap_ref: null
prd_refs: []
---

# On-device tłumaczenie PL ⇄ EN (Chrome Translator API / Built-in AI)

Dokument powykonawczy (CHG) — opisuje pracę zrealizowaną i wdrożoną w tej sesji.
Plan nie był tworzony przed implementacją (mała zmiana, iteracyjna współpraca
z użytkownikiem), więc ten dokument zastępuje `plan.md`/`research.md` jako
zapis decyzji i zakresu.

## Cel

Dodać darmowe, on-device tłumaczenie EN ⇄ PL (Chrome Translator API, Gemini Nano,
`window.Translator`) bez kosztów API i bez zewnętrznych usług:

1. Opisy spółek (`long_description`) na stronie `/analysis/{ticker}` — **etap 1**
   (zrealizowany wcześniej, commit `2be4dcc`, opisany tylko skrótowo poniżej dla kompletności).
2. Cała strona dokumentacji modelu `/model` (publiczna) — **etap 2**, główny temat
   tej sesji.

## Mechanizm (wspólny dla obu etapów)

- **Wykrywanie wsparcia**: `'Translator' in self` → feature detection, brak wymuszania
  na użytkownikach starszych przeglądarek.
- **Tłumaczenie on-device**: `Translator.availability({sourceLanguage, targetLanguage})`
  → `Translator.create(...)` → `.translate(text)`. Bez API key, bez kosztu, działa offline
  po pierwszym pobraniu modelu Gemini Nano przez Chrome.
- **Cache po stronie serwera**: wynik tłumaczenia pierwszego użytkownika z działającym
  Translator API jest POSTowany do `/api/translation/save` i zapisywany w tabeli
  `company_translations` (migracja 019, już wdrożona wcześniej). Kolejni użytkownicy
  (także bez Translator API) dostają tłumaczenie z cache przy renderze strony.
- **CSRF**: standardowy `$_SESSION['csrf_token']` + `Request::verifyCsrf()`,
  nagłówek `X-CSRF-Token` + pole `_csrf`, czytane z `meta[name="csrf-token"]`.

## Etap 1 — opisy spółek `/analysis/{ticker}` (kontekst, zrealizowane wcześniej)

- Commit `2be4dcc` — przycisk EN ⇄ PL nad opisem spółki (`templates/analysis.php`).
- `TranslationRepository` / `TranslationController` — generyczny cache
  `(ticker, lang, field) → translation`, tabela `company_translations`.
- `ALLOWED_FIELDS = ['long_description']`, `ALLOWED_LANGS = ['pl', 'en']`.
- Wdrożone i potwierdzone przez użytkownika jako działające dobrze
  ("Za pierwszym razem trwało dość długo, ale potem już każda spółka tłumaczyła
  się błyskawicznie" — efekt cache).

## Etap 2 — strona `/model` (główny zakres tej sesji)

### Decyzje implementacyjne

- **Reużycie istniejącego mechanizmu** — bez nowej migracji SQL. Tabela
  `company_translations` ma kolumny `(ticker, lang, field, text)`; strona `/model`
  nie jest przypisana do tickera, więc użyto syntetycznego klucza
  `ticker = '_MODEL_PAGE'`, `field = 'model_page'`, `lang = 'en'`.
  Wartość to **JSON-encoded array** tłumaczeń — jeden string per przetłumaczony
  element DOM, w kolejności selektora.
- **Tłumaczenie blokowe (block-level)** — całość `el.textContent` per element,
  bez zachowania formatowania inline (`<strong>`, `<em>`, kod, `<br>`). Świadomy
  trade-off — strona ma głównie akapity/listy/tabele, utrata formatowania inline
  jest kosmetyczna.
- **Selektor elementów** (`templates/model.php`, sekcja `<script>` na końcu pliku):
  ```
  .model-page h1, .model-page .subtitle, .model-page h2, .model-page h3,
  .model-page h4, .model-page p, .model-page li:not(.toc li),
  .model-page .toc a, .model-page dt, .model-page dd, .model-page td,
  .model-page th, .model-page summary, .model-page .faq__body,
  .model-page .pillar-card__name, .model-page .pillar-card__weight,
  .model-page .pillar-card__desc, .model-page .reco-badge, .model-page .callout
  ```
- **Toggle button** "PL ⇄ EN" w nagłówku strony (`#btn-translate-model`),
  `data-lang` atrybut przełącza kierunek (PL→EN tłumaczy i cache'uje,
  EN→PL przywraca oryginalne `textContent` z pamięci JS — bez ponownego
  tłumaczenia).
- **Cache po stronie serwera**: `/model` route w `src/Core/routes.php` odpytuje
  `TranslationRepository::find('_MODEL_PAGE', 'en', 'model_page')` i przekazuje
  `$cachedModelPageEn` do widoku jako JSON array; JS waliduje
  `arr.length === elements.length` przed użyciem (zabezpieczenie przed
  desynchronizacją po zmianie selektora — patrz "Iteracja 2" poniżej).
- **`/model` jest publiczna** (bez `AuthController::requireAuth()`) — dla
  niezalogowanych POST do `/api/translation/save` jest przekierowywany na
  `/login` (auth guard w `TranslationController::save()`), co JS łapie przez
  `.catch(() => {})`. Tłumaczenie client-side działa mimo to; tylko cache
  nie zapisze się dla niezalogowanych użytkowników.

### Pliki zmienione (etap 2)

- `src/Translation/TranslationController.php` — `ALLOWED_FIELDS` rozszerzone
  o `'model_page'`.
- `src/Core/routes.php` — route `/model` ładuje `cachedModelPageEn` z repo
  i przekazuje do widoku.
- `templates/model.php`:
  - header strony owinięty w flex + przycisk `#btn-translate-model`,
  - na końcu artykułu dodany blok `<script>` z logiką tłumaczenia
    (feature detection, cache, translate, save).

### Iteracja 1 → Iteracja 2 (poprawka TOC)

Po wdrożeniu commit `c1dfb9d` użytkownik zgłosił, że spis treści
(`nav.toc` w `article.model-page`) nie jest tłumaczony. Pierwotny selektor
świadomie wykluczał `.toc h4` i `.toc li` (`:not()`), bo zamiana
`el.textContent` na `<li>` zawierającym `<a href="#...">` zniszczyłaby link
(usunęłaby tag `<a>` razem z `href`).

**Fix (commit `dcaeba6`)**: zamiast tłumaczyć `<li>`, tłumaczone jest
`textContent` samego `<a>` (`.model-page .toc a`) — `href` jest atrybutem,
nie częścią `textContent`, więc link zostaje zachowany. `.toc h4` (nagłówek
"Spis treści") dodany do ogólnego `.model-page h4` (usunięto `:not(.toc h4)`,
bo `<h4>` nie ma dzieci wymagających ochrony).

## Testy i jakość

- `vendor/bin/phpunit` — 426 testów, 943 asercje, **OK** (oba commity).
- `vendor/bin/phpstan analyse` — 46/46, **No errors** (commit `c1dfb9d`).

## Deployment

| Commit | Opis | Status |
|---|---|---|
| `c1dfb9d` | feat: on-device translation (PL<->EN) for /model page | ✅ wdrożony |
| `dcaeba6` | fix: translate TOC heading and links on /model page | ✅ wdrożony |

- Wdrożenie via `/MiJu-CF-Deploy` (mode: update), serwer Cyber_Folks,
  repo root `/home/amjsystem/sites/cvs.timeflow.fun/`.
- Brak nowych migracji SQL — tabela `company_translations` istnieje od migracji 019.
- Smoke test: `GET /model` → `200`, przycisk `#btn-translate-model` obecny w HTML.
- `deployment/cvs-composite-valuation-score.deploy.json` →
  `last_deploy_sha: "dcaeba6"`, `last_deploy_mode: "update"`.

## Znane ograniczenia / możliwe dalsze kroki

- Tłumaczenie blokowe gubi formatowanie inline (`<strong>`, `<em>`, kod) —
  akceptowalne dla tej strony, ale przy rozszerzeniu na inne strony z bogatszym
  formatowaniem warto rozważyć tłumaczenie per-text-node.
- Cache `_MODEL_PAGE` jest jednym blobem JSON — każda zmiana struktury/treści
  strony `/model` (liczba lub kolejność elementów selektora) inwaliduje cache
  (długość tablicy się nie zgadza → JS ignoruje cache i tłumaczy ponownie
  przy pierwszym użytkowniku z Translator API). To zachowanie jest poprawne,
  ale oznacza, że po każdej zmianie treści `/model` pierwszy użytkownik z Chrome
  Translator API "odświeży" cache.
- Brak mechanizmu czyszczenia/wersjonowania starych wpisów `_MODEL_PAGE` w
  `company_translations` — stary wpis po prostu nie jest już używany (długość
  się nie zgadza), ale zostaje w tabeli. Niewielki narzut, nie wymaga akcji.
