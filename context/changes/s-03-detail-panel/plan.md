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
- [x] Link powrótu do panelu
- [ ] Deploy na cvs.timeflow.fun (git pull)
- [ ] Weryfikacja: AAPL — tabela danych widoczna na `/analysis/AAPL`
- [ ] Weryfikacja: Dane odpowiadają wyjściu cvs_analyze.py
- [ ] Weryfikacja: XYZNOTEXIST — graceful error bez raw data
