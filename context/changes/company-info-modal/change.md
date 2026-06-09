---
change-id: company-info-modal
title: "X-02: Informacje o spółce — modal z opisem Yahoo Finance"
status: implemented
created: 2026-06-01
updated: 2026-06-01
roadmap_ref: X-02
prd_refs: []
---

# X-02 — Company Info Modal

Przycisk „Informacje o spółce" w nagłówku strony `/analysis/{ticker}`.
Otwiera modal z opisem biznesowym spółki, sektorem, branżą, krajem,
liczbą pracowników i linkiem do strony — dane z Yahoo Finance `assetProfile`.

**Zero dodatkowych API callów** — `assetProfile` był już pobierany przez
`FinancialDataFetcher` (moduł w stałej `MODULES`), tylko nie wyciągany.

**Pola dodane do normalise():**
- `long_name` (longName)
- `industry` (industry)
- `country` (country)
- `website` (website)
- `employees` (fullTimeEmployees)
- `long_description` (longBusinessSummary)

**Lesson:** Przycisk pokazywany zawsze (nie warunkowo na `!empty(long_description)`),
bo session cache może mieć stare dane bez nowych pól — warunkowy warunek
powodował niewidoczność przycisku po aktualizacji kodu.

**Commits:** `9a06cda` (initial), `8100652` (unconditional button fix), `574d06d` (stray endif fix)
