---
change_id: ci-cd-pipeline
title: GitHub Actions CI pipeline (CI bez CD)
status: implemented
created: 2026-06-09
implemented_at: 2026-06-09
commits:
  - sha: "3732814"
    desc: "docs(context): consolidate all project docs + add GitHub Actions CI/CD"
  - sha: "042657f"
    desc: "ci: add non-blocking composer audit step for CVE reporting"
---

## Kontekst

Projekt CVS działał bez żadnego CI/CD — deploy ręczny SSH+git, testy uruchamiane
tylko lokalnie. Wymaganie certyfikacji 10xDev 3.0 wskazuje na konieczność pipeline'u.

Jednocześnie Cyber_Folks (hosting) nie udostępnia deploy API ani webhooków,
więc automatyczny CD wymagałby przechowywania klucza SSH jako GitHub Secret
i hand-rolowanego skryptu — nadmierne ryzyko przy braku środowiska staging.

**Decyzja:** CI (każdy push testowany automatycznie) + ręczny CD.

## Co zostało zaimplementowane

### `.github/workflows/ci.yml`

```yaml
name: CI

on:
  push:
    branches: [ main ]
  pull_request:
    branches: [ main ]

jobs:
  test:
    name: PHP 8.2 — Tests & Static Analysis
    runs-on: ubuntu-latest

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP 8.2
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: pdo, json, curl, pdo_sqlite
          coverage: none

      - name: Install dependencies
        run: composer install --no-interaction --prefer-dist --optimize-autoloader

      - name: Run PHPUnit
        run: vendor/bin/phpunit --testdox

      - name: Run PHPStan (level 6)
        run: composer stan

      - name: Audit dependencies for known CVEs
        run: composer audit
        continue-on-error: true
```

## Kroki pipeline'u

| Krok | Narzędzie | Blokuje build? | Co sprawdza |
|------|-----------|---------------|-------------|
| checkout | actions/checkout@v4 | tak | pobranie kodu |
| setup-php | shivammathur/setup-php@v2 | tak | PHP 8.2 + ext: pdo, json, curl, pdo_sqlite |
| composer install | Composer | tak | reprodukowalna instalacja z composer.lock |
| phpunit | PHPUnit 11 | tak | 340 testów jednostkowych (offline, SQLite in-memory) |
| phpstan | PHPStan level 6 | tak | statyczna analiza typów w `src/` |
| composer audit | Composer | **nie** (`continue-on-error: true`) | CVE w zależnościach — sygnalizuje, nie blokuje |

## Decyzje projektowe

**Dlaczego `pdo_sqlite`?**
Testy CVS używają SQLite in-memory jako backend zamiast MySQL (izolacja, brak
konfiguracji bazy w CI). Bez tego rozszerzenia suite padłaby na GitHub Actions
mimo że lokalnie działa.

**Dlaczego `composer audit` nie blokuje?**
Podatność w zależności nie oznacza że jest exploitowalna w tym projekcie
(np. CVE w funkcji której nie używamy). Blokowanie buildu przy każdym nowym CVE
prowadziłoby do paraliżu deploymentu. Właściwa reakcja: świadoma decyzja
właściciela projektu — zaktualizuj lub zaakceptuj ryzyko.

**Dlaczego brak automatycznego CD?**
- Cyber_Folks nie ma deploy API/webhooków
- Brak środowiska staging → rollback przez SSH ręcznie
- Projekt solo, ~10 userów → koszt złego automatycznego deployu > koszt ręcznego

Deploy pozostaje przez `/MiJu-CF-Deploy` (skill).

## Weryfikacja

- ✅ Pipeline uruchamia się automatycznie na każdy push do `main`
- ✅ Pipeline uruchamia się na każdy PR do `main`
- ✅ PHPUnit: 340 testów, 0 failures
- ✅ PHPStan: 0 errors (level 6)
- ✅ `composer audit`: 0 advisories (stan 2026-06-09)
- ✅ Wynik widoczny w zakładce GitHub Actions repozytorium

## Progress

### Phase 1: Implementacja

#### Automated
- [x] 1.1 Utworzono `.github/workflows/ci.yml` — 3732814
- [x] 1.2 Dodano krok `composer audit` z `continue-on-error: true` — 042657f
- [x] 1.3 Pipeline uruchamia się i przechodzi na `main` — 042657f
