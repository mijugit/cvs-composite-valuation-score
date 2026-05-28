---
change_id: s-03-detail-panel
status: done
phases: 1
---

## Faza 1 — Implementacja i deploy

- [x] `AnalysisController::show()` przekazuje `$financials` do widoku
- [x] Sekcja "Dane wejściowe modelu" w `templates/analysis.php`
- [x] Derived metrics: EV (price*shares+debt-cash), EV/FCF, NetDebt/EBITDA, ROC 6M/3M
- [x] Graceful N/A dla wszystkich nullów
- [x] Disclaimer widoczny przy danych surowych
- [x] Link powrótu do panelu (góra i dół)
- [x] Deploy na cvs.timeflow.fun (git pull, SHA f960cae)
- [x] Weryfikacja MELI: EV 92.70B, FCF -4.10B, Wariant B (EV/Sales), Gross Margin 49.5%
- [x] Weryfikacja MELI: ROC 6M -15.8% (tłumaczy Momentum=15.7), ROC 3M -1.9%
- [x] Weryfikacja: XYZNOTEXIST — graceful error bez sekcji raw data
