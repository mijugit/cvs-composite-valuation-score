---
project: CVS — Composite Valuation Score
checked_at: 2026-05-29T13:28:19Z
health_status: healthy
context_type: brownfield
language_family: php
stack_assessment_available: true
checks_run:
  - lockfile
  - dependency_audit
  - outdated_deps
  - test_runner
  - ci_cd
  - configuration
audit_findings:
  critical: 0
  high: 0
  moderate: 0
  low: 0
test_runner_detected: true
ci_provider: null
recommended_fixes: 5
---

## Dependency Health

### Lockfile

```
Status: present (composer.lock)
Package manager: composer (Composer 2.9.8; composer.phar bundled in repo)
```

`composer.lock` jest commitowany, `vendor/` w `.gitignore` — deploye reprodukowalne.

### Security Audit

```
Tool: php composer.phar audit --format=plain (Composer 2.9.8 — spełnia wymóg 2.4+)
Summary: 0 CRITICAL, 0 HIGH, 0 MODERATE, 0 LOW
Direct vs transitive: brak rozróżnienia — brak jakichkolwiek advisory
```

Brak podatności. Drzewo zależności jest minimalne (tylko PHPUnit jako dev-dependency,
runtime opiera się na rozszerzeniach PHP: pdo/json/curl).

### Outdated Dependencies

```
Packages with major version gaps: 0
```

`composer outdated --direct` zwraca pustą listę — jedyna bezpośrednia zależność (PHPUnit ^11)
jest aktualna.

## Test Suite

```
Test runner: PHPUnit 11
Tests found: 72 tests (151 assertions)
Test execution: passing (OK — 72 tests, 151 assertions)
```

```
Configuration: phpunit.xml
Framework: PHPUnit ^11 (require-dev)
Katalogi testów: tests/CVS, tests/Forecast, tests/History, tests/Watchlist
```

Runner działa, cały zestaw przechodzi. Testy są offline (bez sieci) — `FinancialDataFetcher`
nie jest ćwiczony, co jest świadomą decyzją. Agent ma czym weryfikować własne zmiany.

## CI/CD

```
Provider: not detected
Configuration: not found (.github/workflows brak)
```

| Stage      | Status | Notes                                      |
|------------|--------|--------------------------------------------|
| Lint       | ✗      | not configured                              |
| Test       | ✗      | not configured (PHPUnit istnieje lokalnie)  |
| Build      | ✗      | not applicable (brak kroku build)           |
| Type check | ✗      | not configured (brak PHPStan/Psalm)         |
| Security   | ✗      | not configured (composer audit tylko ręcznie) |

ℹ Brak CI/CD. Deploy jest ręczny (SSH+git). Dla projektu solo na shared hostingu to
akceptowalny stan — lokalny runner (72 testy) wystarcza do współpracy z agentem. CI to
ulepszenie do rozważenia (patrz Recommended Fixes), nie blokada.

## Configuration

### Medium severity

- **Brak statycznej analizy (PHPStan/Psalm)** — kod używa `declare(strict_types=1)` i type
  hintów (konwencja w CLAUDE.md), ale nic tego nie egzekwuje; przy nowym kodzie fazy 2
  (AI/mail/scheduler) łatwo o błąd typu w runtime. Fix: `php composer.phar require --dev phpstan/phpstan`,
  `vendor/bin/phpstan analyse src --level=6`.
- **Brak formattera/lintera** (php-cs-fixer / PHP_CodeSniffer) — styl kodu utrzymywany ręcznie
  wg konwencji; agent może produkować niespójny styl. Fix: dodać php-cs-fixer z konfiguracją PSR-12.

### Low severity

- **`.editorconfig`** — brak; drobna niespójność formatowania między edytorami. Fix: dodać
  `.editorconfig` (PSR-12: 4 spacje, LF, UTF-8).

Obecne i poprawne: `.gitignore` ✓, `.env.example` ✓, `phpunit.xml` ✓, `CLAUDE.md` ✓,
`AGENTS.md` ✓.

## Stack Assessment Cross-Reference

```
Stack assessment: context/foundation/stack-assessment.md
Agent readiness (from stack-assess): ready-with-compensation
```

| Quality Gate Gap        | Health-Check Finding                                          | Status      |
|-------------------------|---------------------------------------------------------------|-------------|
| typed: partial          | Brak PHPStan/Psalm + brak type-check w CI                     | Reinforced  |
| convention_based: ~     | CLAUDE.md + AGENTS.md obecne, z układem i konwencjami         | Mitigated   |
| training data: pass     | (potwierdzone — mainstreamowy PHP/PSR-4/PDO/PHPUnit)          | —           |
| documented: pass        | (potwierdzone — php.net, PHPUnit)                             | —           |

**Dodatkowy gap:** stack-assess wskazał 5 bloków instrukcji do wklejenia w CLAUDE.md/AGENTS.md
(PHPStan, Claude API, mailer, scheduler, nowe namespace'y/migracje) — **jeszcze nie wklejone**.
To kompensacja, na której opiera się werdykt „ready-with-compensation"; warto ją wprowadzić
PRZED implementacją fazy 2, by guardraile (awaria AI nie psuje strony, kontrola kosztów,
determinizm) były wpisane w instrukcji od początku.

## Recommended Fixes

> Projekt jest zdrowy operacyjnie. Poniższe to ulepszenia podnoszące niezawodność współpracy
> z agentem w fazie 2 — żadne nie jest blokadą. Kolejność wg wpływu na pracę agenta.

### 1. Wklej kompensacje ze stack-assess do CLAUDE.md / AGENTS.md

**Impact**: konwencje dla nowych komponentów fazy 2 (Claude API, mailer, scheduler, namespace'y)
chronią guardraile; bez nich agent przy pierwszym podejściu może je złamać.
**Severity**: medium
**Effort**: moderate (15–30 min)
**Fix**: skopiuj 5 bloków z `context/foundation/stack-assessment.md → Recommended Instruction
File Additions` do sekcji „Project-specific rules" w `CLAUDE.md` (i skrót do `AGENTS.md`).

### 2. Dodaj statyczną analizę (PHPStan)

**Impact**: siatka bezpieczeństwa dla nowego kodu (AI/mail/scheduler); domyka lukę „typed:
partial" ze stack-assess; vanilla PHP łatwo dopuszcza `null`/typ-mismatch w runtime.
**Severity**: medium
**Effort**: moderate (15–30 min)
**Fix**: `php composer.phar require --dev phpstan/phpstan`, następnie `vendor/bin/phpstan analyse src --level=6`
(podnoś level stopniowo). Dopisz komendę do `composer.json → scripts`.

### 3. Dodaj formatter/linter (php-cs-fixer)

**Impact**: spójny styl outputu agenta bez ręcznego pilnowania.
**Severity**: medium
**Effort**: moderate (15–30 min)
**Fix**: `php composer.phar require --dev friendsofphp/php-cs-fixer`, konfiguracja PSR-12,
skrypt `composer fix`.

### 4. Dodaj `.editorconfig`

**Impact**: spójne formatowanie między edytorami (drobne, ale tanie).
**Severity**: low
**Effort**: quick (< 5 min)
**Fix**: utwórz `.editorconfig` (PSR-12: `indent_size = 4`, `end_of_line = lf`, `charset = utf-8`).

### 5. (Później / opcjonalnie) CI pipeline

**Impact**: automatyczne lint+test+PHPStan na push — wcześniejsze łapanie regresji niż przy
ręcznym deployu. Dla projektu solo to ulepszenie, nie konieczność.
**Severity**: low
**Effort**: moderate (15–30 min)
**Fix**: GitHub Actions (repo jest na GitHubie) — workflow uruchamiający `composer install`,
`vendor/bin/phpunit` i `vendor/bin/phpstan`. Deploy może pozostać ręczny (SSH+git).

## Summary

```
Health status: healthy
```

Projekt jest w bardzo dobrej kondycji operacyjnej: zero podatności, zero przeterminowanych
zależności, lockfile commitowany, działający zestaw 72 testów (wszystkie przechodzą) i obecne
pliki instrukcji (CLAUDE.md, AGENTS.md). Główne luki to brak statycznej analizy (PHPStan) i
formattera oraz brak CI — wszystkie to ulepszenia, nie blokady; brak CI jest oczekiwany dla
projektu solo na shared hostingu z ręcznym deployem. Jedyna rzecz warta zrobienia przed
implementacją fazy 2 to wprowadzenie kompensacji ze stack-assess do instruction files (Fix #1)
i dodanie PHPStan (Fix #2), bo nowy kod (Claude API, mailer, scheduler) skorzysta z obu.

Next step: zaadresuj Fix #1 i #2 przed startem fazy 2, resztę traktuj jako ulepszenia w tle;
fundament (shape → PRD → stack-assess → health-check) jest kompletny — można przejść do
roadmapy fazy 2 (/10x-roadmap) i planowania pierwszego slice'a (/10x-plan).
