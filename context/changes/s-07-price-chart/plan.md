---
id: s-07-price-chart
title: "Price chart na detail page"
status: implementing
created: 2026-05-28
updated: 2026-05-28
---

## Overview

Dodanie wykresu liniowego Chart.js do strony `/analysis/{ticker}`.
Wykres pokazuje znormalizowaną cenę spółki vs SPY (indeks 100) za ostatnie 12 miesięcy.
Wszystkie dane są już dostępne w `$financials` — **brak zmian backendu, brak migracji, brak nowych testów**.

### Scope

- **IN:** `templates/analysis.php` — HTML canvas + inline script + inline CSS
- **OUT:** backend, routes, DB, testy jednostkowe

### Data available in `$financials`

| Klucz | Typ | Opis |
|---|---|---|
| `monthly_closes` | `float[]` | ~36 punktów (3y), oldest→newest |
| `spy_closes` | `float[]` | ~12 punktów (1y), oldest→newest |

### Chart logic

1. Weź ostatnie 12 wartości z `monthly_closes` i `spy_closes`
2. Normalizuj obie serie do bazy 100 (pierwszy punkt = 100)
3. Wygeneruj etykiety miesięcy client-side (N miesięcy wstecz od dziś)
4. Jeśli SPY ma mniej punktów — renderuj tylko tyle ile jest (bez padding)

---

## Phase 1: Price chart w templates/analysis.php

### Overview

Jedyna faza. Wszystkie zmiany w jednym pliku: PHP guard, canvas, script, CSS.

### Changes Required

- `templates/analysis.php`
  - Dodaj sekcję `.price-chart-section` (PHP guard `!empty($financials['monthly_closes'])`)
  - Umieść bezpośrednio przed `<!-- Dual CVS score header -->` (wewnątrz bloku `<?php else: ?>` po quality gate check)
  - Dodaj inline `<script>` z inicjalizacją Chart.js Line po bloku radar script
  - Dodaj inline CSS w bloku `<style>` na dole pliku

### Success Criteria

#### Automated
- [ ] `vendor/bin/phpunit` — 0 failed (brak zmian backendu)

#### Manual
- [ ] Otwórz `/analysis/AAPL` — sekcja wykresu widoczna nad dual-CVS tiles
- [ ] Dwie linie: ticker (niebieski) + SPY (szara przerywana)
- [ ] Oś X: etykiety miesięcy (12 sztuk)
- [ ] Oś Y: wartości ~100 (znormalizowane)
- [ ] `/analysis/NONEXISTENT` (błąd) — brak crash, brak wykresu
- [ ] Spółka bez danych cenowych — sekcja wykresu ukryta

---

## Progress

### Phase 1: Price chart w templates/analysis.php

#### Automated
- [x] 1.1 Dodaj PHP guard + canvas HTML w analysis.php
- [x] 1.2 Dodaj inline script Chart.js Line chart
- [x] 1.3 Dodaj CSS dla .price-chart-section

#### Manual
- [x] 1.M1 Weryfikacja manualna (opis w Success Criteria powyżej) — 63da2e0
