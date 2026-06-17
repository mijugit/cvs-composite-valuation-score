---
change_id: multi-currency-fx
title: Konwersja danych finansowych do USD + dual-currency display
status: implemented
created: 2026-06-16
updated: 2026-06-17
---

## Summary

Spółki notowane w obcych walutach (np. 000660.KS w KRW) mają dane finansowe i cenę w walucie macierzystej. Wszystkie pola monetarne są konwertowane do USD w jednym seamie (`FinancialDataFetcher::normalise()`) przy użyciu kursu FX z Yahoo `{CCY}=X`. CVS, peer medians, fair value i snapshoty działają odtąd spójnie w USD. UI pokazuje cenę i Fair Value dwuwalutowo (USD wiodące, natywna w nawiasie).

## Motivation

Obecnie cena KRW jest pokazywana z zahardkodowanym znakiem `$` (mylące), a dla ADR-ów (cena ≠ waluta finansów, np. TSM USD/TWD) Enterprise Value miesza waluty w jednym równaniu → zatruty CVS score. Istniejące guardy tylko ukrywają fair value, nie naprawiają wyniku. Użytkownik ma i będzie miał spółki z całego świata — potrzebny jest spójny model w USD oraz czytelna prezentacja w obu walutach.

## Decisions

- Seam konwersji: `FinancialDataFetcher::normalise()` — jeden punkt prawdy; cały downstream w USD
- Źródło FX: Yahoo chart `{CCY}=X` istniejącym kanałem (zero nowych zależności)
- Determinizm: kurs pobierany raz w `fetch()`, wstrzykiwany do `normalise()` jak `referenceDate` (FR-015)
- Brak kursu dla nie-USD → pomiń spółkę (brak wyniku + komunikat), nigdy nie pokazuj/nie zapisuj błędnych liczb
- Snapshoty: migracja dodaje `fx_rate`, `native_currency`, `native_price`; `price_at_snapshot` = USD
- Istniejące snapshoty: grandfathering — naprawi je następny rescore (bez backfillu z historycznym kursem)
- Zakres pól: wszystkie monetarne (price, 52w low/high, MA200, revenue, gross_profit, ebitda, debt, equity, cash, current_assets/liabilities, fcf+raw, opCF, forward_fcf_est, EPS)
- ADR: konwertuj finanse wg `financial_currency`, cena już USD; sanity-bounds chronią przed niedopasowaniem ADR-ratio
- Dual-display: USD wiodące, natywna w nawiasie — dla ceny i Fair Value
- `model_version` bump (3.0 → 4.0) — czysty rozdział semantyki snapshotów; wymaga rebuildu peer_medians
