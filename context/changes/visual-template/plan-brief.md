# F-01: Szablon wizualny — Plan Brief

> Full plan: `context/changes/visual-template/plan.md`

## What & Why

Świeży, spójny system wizualny (tokeny + komponenty + styleguide) wygenerowany przez
`/frontend-design`, zastosowany do wszystkich istniejących widoków. To fundament FR-013 fazy 2 —
nowe widoki (S-01 analiza AI, S-02 track record, S-03 screener) będą na nim stać. Dziś styl jest
rozproszony (140-liniowy inline `<style>` w panelu szczegółów, zduplikowane komponenty, zaszyte
kolory); F-01 to porządkuje i odświeża.

## Starting Point

Front działa i ma już dark-theme: `app.css` (454 l.) z tokenami w `:root` i komponentami (card,
btn, form, table, chip, badge, alert, autocomplete). Ale: panel szczegółów trzyma większość stylu
w inline `<style>`, „score tile" i „signal pill" istnieją w dwóch wariantach (template vs render JS),
gold `#eab308` zaszyty w 5+ miejscach. Vanilla PHP + plain CSS + Chart.js, bez frameworka/buildu.

## Desired End State

`tokens.css` + `components.css` + odchudzony `app.css`, ładowane w tej kolejności. Jeden kanoniczny
zestaw komponentów (w tym ujednolicony score-tile i signal-pill), warianty przycisków, pełna skala
tokenów. Strona `/styleguide` jako żywa galeria. Wszystkie widoki zmigrowane, markup kart w `app.js`
przepisany na kanon — przy zachowaniu wszystkich hooków JS. Świeży, spójny look na całej aplikacji.

## Key Decisions Made

| Decyzja | Wybór | Dlaczego | Źródło |
| --- | --- | --- | --- |
| Podejście wizualne | Świeży pass `/frontend-design` | Najwyższy poziom wizualny; fundament pod nowe widoki | Plan |
| Duplikaty score-tile/signal-pill | Pełne ujednolicenie (też markup `app.js`) | Jeden kanon, koniec rozdwojenia | Plan |
| Struktura CSS | Podział: `tokens.css` + `components.css` + `app.css` | Czytelna separacja warstw | Plan |
| Styleguide | Tak — `/styleguide` żywa galeria | Wzorzec dla S-01/02/03 + powierzchnia weryfikacji | Plan |
| Zakres tokenów | Pełna lekka skala (kolory+space+text+radius+shadow) | Solidny fundament pod świeży look | Plan |
| Przyciski | primary + secondary + ghost + danger | Nowe widoki ich potrzebują | Plan |
| Zasięg migracji | Wszystkie istniejące widoki + chrome | Świeża paleta wymusza spójność wszędzie | Plan |
| Weryfikacja | Pełny przegląd wizualny + smoke JS | PHPUnit nie pokrywa CSS/JS; krytyczne przy zmianie markup | Plan |

## Scope

**In scope:** `tokens.css`, `components.css`, odchudzenie `app.css`, `styleguide.php` + trasa,
migracja `analysis/dashboard/login/register/404` + chrome, przepisanie markup kart w `app.js`,
warianty przycisków, ujednolicenie score-tile/signal-pill, pełna skala tokenów.

**Out of scope:** zmiana frameworka/build/npm/SCSS, zmiana logiki PHP/modelu CVS/danych, nowe widoki
funkcjonalne (S-01/02/03), zmiana zachowania JS (tylko nazwy klas), zmiana treści disclaimera.

## Architecture / Approach

Warstwy: `tokens.css` (zmienne) → `components.css` (komponenty konsumujące tokeny) → `app.css`
(resztki widoków), ładowane w tej kolejności w `layout.php` (wiele `<link>`, bez `@import`). Look
generuje `/frontend-design` (fazy 1–2). Migracja widoków + ujednolicenie markup JS (faza 4) używa
kontraktu DOM z `app.js` jako checklisty. Kontrakt DOM (id/`data-*`/logika) zachowany; zmieniają się
tylko nazwy klas — w parze CSS↔JS.

## Phases at a Glance

| Faza | Dowozi | Kluczowe ryzyko |
| --- | --- | --- |
| 1. Tokeny + fundament | `tokens.css` + style bazowe + load order | Brak wizualnego złamania na tym etapie |
| 2. Komponenty bazowe | `components.css` (kanon, ujednolicone) | Kanon musi objąć też klasy render-JS |
| 3. Styleguide | `/styleguide` żywa galeria | Mały; utrzymanie trasy/template |
| 4. Migracja + JS | Wszystkie widoki + markup `app.js` na kanon | **Najwyższe ryzyko regresji** (JS hooks, 5 widoków) |
| 5. Przegląd regresji | Sweep wizualny + smoke JS | Wyłapanie cichych regresji CSS/JS |

**Prerequisites:** brak (F-01 jest niezależnym fundamentem). **Estimated effort:** ~3–5 sesji, 5 faz.

## Open Risks & Assumptions

- **Stretch zakresu vs roadmapa:** wybrano pełniejszy redesign niż „świadomie lekki" framing FR-013 —
  większy zakres i ryzyko; świadoma decyzja, regresja domknięta Fazą 5.
- **Markup JS:** zmiana nazw klas w `app.js` musi iść w parze z CSS; id/`data-*` nietknięte.
- **Brak testów frontu:** weryfikacja manualna — dyscyplina przeglądu wg kontraktu DOM jest krytyczna.
- `/frontend-design` może zaproponować look rozjeżdżający się z obecnym — trzymać dark-theme jako bazę.

## Success Criteria (Summary)

- Spójny świeży wygląd na wszystkich widokach + `/styleguide` jako referencja.
- Wszystkie przepływy JS działają (analiza, watchlist, autocomplete, wykresy) — zero błędów konsoli.
- Disclaimer wszędzie; `phpunit`/`phpstan` zielone (brak zmian logiki PHP).
