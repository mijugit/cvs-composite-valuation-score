---
id: s-02-radar-chart
title: "S-02 — Radar chart: wizualizacja filarów CVS"
status: done
roadmap_ref: S-02
created: 2026-05-28
updated: 2026-05-28
implementing_started: 2026-05-28
done_at: 2026-05-28
---

## Summary

Dodanie wykresu radarowego Chart.js 4.x dla każdej spółki z wynikiem CVS:
1. Pełny radar (380px, responsive) na stronie szczegółów `/analysis/{ticker}`
2. Mini radar (200×200 px) na każdej karcie wyników na dashboardzie

4 osie odpowiadające aktualnym 4 filarom PHP:
**Wzrost / Benchmark sektorowy / Momentum / Jakość**

## Scope

- `templates/layout.php` — Chart.js CDN (4.4.9) przed app.js
- `templates/analysis.php` — `<canvas id="pillarRadar">` + inline script
- `public/js/app.js` — `buildCard()` dodaje canvas; `initMiniRadars()` inicjuje Chart.js
- `public/css/app.css` — `.radar-wrapper`, `.result-card__radar`
