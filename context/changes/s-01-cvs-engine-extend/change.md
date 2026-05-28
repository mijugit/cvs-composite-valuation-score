---
id: s-01-cvs-engine-extend
title: "CVS Engine — napraw Sektor (EV/FCF) + dodaj Momentum (ROC vs SPY)"
status: done
roadmap_ref: S-01
created: 2026-05-27
updated: 2026-05-28
implementing_started: 2026-05-27
done_at: 2026-05-28
---

## Summary

Rozszerzenie istniejącego 4-filarowego modelu PHP CVS:
1. **SectorBenchmarkPillar** — przepisanie z P/E/P/S/EV/EBITDA (zawsze null → score=50)
   na EV/FCF lub EV/Sales vs hardkodowane benchmarki sektorowe (z Python cvs_analyze.py)
2. **MomentumPillar** — zastąpienie PriceHistoryPillar (52W/200MA) Momentum
   (ROC 6M+3M vs SPY excess return) z tego samego modelu Python

Wagi pozostają 30/25/25/20. Quality Gate pozostaje bez zmian.

## Scope

- `src/Api/FinancialDataFetcher.php` — dodaj assetProfile + chart endpoint
- `src/CVS/Pillars/SectorBenchmarkPillar.php` — przepisanie kompletne
- `src/CVS/Pillars/PriceHistoryPillar.php` → DELETE
- `src/CVS/Pillars/MomentumPillar.php` → NEW
- `src/CVS/CVSModel.php` — swap pillar + weight key
- `config/cvs-weights.php` — dodaj benchmarks + momentum config
- `tests/CVS/CVSModelTest.php` — aktualizacja fixtures i testów
- `templates/analysis.php` — etykieta pillar "Momentum"
- `CLAUDE.md` — usuń przestarzałą notatkę o null medianach
