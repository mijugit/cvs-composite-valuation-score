---
id: s-03-detail-panel
title: "S-03 — Panel szczegółów spółki"
status: done
roadmap_ref: S-03
created: 2026-05-28
updated: 2026-05-28
implementing_started: 2026-05-28
done_at: 2026-05-28
---

## Summary

Panel surowych danych finansowych na stronie `/analysis/{ticker}` (FR-009 — weryfikowalność).

Dane odpowiadają dokładnie temu co zasila model CVS. Sekcje:
- **Wycena**: EV, FCF, EV/FCF, Revenue, Wariant A/B
- **Jakość fundamentalna**: Gross Margin, Dźwignia (NetDebt/EBITDA), Forward Growth, EPS
- **Momentum ceny**: cena bieżąca, ROC 6M/3M, zakres cen hist., ilość danych
- **Benchmark sektorowy**: sektor, benchmark referencyjny

## Scope

- `src/CVS/AnalysisController.php` — `show()` przekazuje `$financials` do widoku
- `templates/analysis.php` — sekcja `.card--data` z `.data-table`
- `public/css/app.css` — `.data-table`, `.data-table__section`, `.back-link`, `.ticker-meta`
