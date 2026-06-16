---
change-id: sector-benchmark-history-charts
title: "Historia median sektorowych + wykresy w /sectors i /admin/sectors"
status: implemented
created: 2026-06-16
updated: 2026-06-16
roadmap_ref: null
prd_refs: []
---

# Historia median sektorowych + wykresy w /sectors i /admin/sectors

## Co zostało zbudowane

### Faza 1 — Tabela historii i write-path (commit 595d9f6)

Nowa tabela append-only `peer_medians_history` (migracja 020) gromadzi
historię median sektorowych. Każde wywołanie `PeerMedianRepository::upsertMedian()`
(cron dzienny lub ręczny refresh) dopisuje nowy wiersz — bez wpływu na `peer_medians`
używaną przez scoring.

### Faza 2 — Endpoint AJAX + modal z wykresem (commit fbc56cb)

- `GET /admin/sectors/history` — JSON z historią dla danego sektora/branży
- `PeerMedianRepository::findHistory()` — pivot po dacie, 3 metryki (ev_fcf, ev_sales, gm)
- Przycisk 📊 przy każdym wierszu sektora i branży w tabeli
- Shared modal `#sector-history-modal` z Chart.js 4.4.2:
  - Multiline dual-axis: EV/FCF i EV/Sales na lewej osi (mnożnik ×),
    GM% na prawej osi (0–100%)
  - Empty state gdy brak historii
  - Destroy poprzedniego wykresu przed nowym renderem

### Rozszerzenie — Publiczny widok /sectors (commit 7c4fefb)

- Nowy widok `/sectors` dla wszystkich zalogowanych użytkowników (bez admin)
- Identyczny wygląd jak `/admin/sectors` — ale bez przycisku "Odśwież"
- Endpoint `/sectors/history` (auth-only) obsługuje 📊 dla widoku publicznego
- Zakładka "Sektory" dodana do głównej nawigacji (widoczna dla non-admin)
- Admini nadal widzą pełny widok przez `/admin/sectors` z "Odśwież" w dropdown

## Commits

| SHA | Opis |
|-----|------|
| 595d9f6 | Faza 1: migracja 020 + insertHistory() w PeerMedianRepository |
| fbc56cb | Faza 2: endpoint AJAX + modal + Chart.js dual-axis |
| 7c4fefb | Rozszerzenie: publiczny /sectors dla zalogowanych użytkowników |
