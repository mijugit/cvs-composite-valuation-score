---
id: s-08-history
title: "Historia analiz — zapis wyników do DB + widok na dashboardzie"
status: archived
roadmap_ref: S-08
created: 2026-05-28
updated: 2026-05-28
archived_at: 2026-05-28T21:07:14Z
---

## Summary

Po każdym wywołaniu POST /analysis (batch analiza) wyniki zapisywane są
do tabeli `analysis_history`. Dashboard pokazuje ostatnie 20 analiz jako
klikalną listę prowadzącą do strony szczegółów.

## Decisions

| Decyzja | Wybór |
|---|---|
| Trigger zapisu | Tylko `analyse()` POST — nie w GET `show()` |
| Dane | ticker, cvs_swing, cvs_fund, reco_swing, reco_fund, golden_signal, quality_gate, analysed_at |
| Limit display | 20 wpisów (config `data_source.max_history`) |
| Gate failures | Zapisywane (widać historię prób) |
| Click akcja | Link → `/analysis/{ticker}` (live re-run) |
| Dedup | Brak — każde wywołanie to osobny wpis |
