# CVS — Composite Valuation Score

A web app that scores US-listed stocks (NYSE / NASDAQ) with a single composite
valuation number (0–100) and a clear recommendation — answering not *"how much
am I paying?"* but *"how much am I paying relative to what I get?"*.

🔗 **Live:** https://cvs.timeflow.fun/

> ⚠️ **Disclaimer:** *Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja
> inwestycyjna. Inwestuj świadomie.*
> CVS results are an analytical-model hypothesis, **not investment advice**. The
> tool is for educational and screening purposes only. Do your own research.

---

## Why CVS

Traditional valuation multiples (P/E, P/S, EV/EBITDA, PEG) tell you the price tag
but not whether it's a good deal. A company at P/E 40 can be cheap if it grows
60% a year; one at P/E 12 can be expensive if it's shrinking. CVS answers a
relative question across several independent dimensions:

> *Is this company cheap or expensive relative to (a) its own growth, (b) its
> sector benchmark, (c) its own price history, and (d) the quality of the
> business?*

The model is deliberately **value-oriented and contrarian** — it will call a
hot momentum name "expensive" when the multiples say so. That divergence from
the analyst consensus is a feature, not a bug.

## The model

CVS combines **three pillars**, computed in **two modes** simultaneously (the raw
pillar scores are identical; only the weights differ):

| Pillar              | Swing (1–4M) | Fundamental (6–12M) | What it measures                              |
|---------------------|:------------:|:-------------------:|-----------------------------------------------|
| **Valuation**       | 40%          | 65%                 | EV/FCF vs sector medians (relative valuation) |
| **Momentum**        | 45%          | 15%                 | Price ROC vs SPY (excess return)              |
| **Quality**         | 15%          | 20%                 | Gross margin, leverage, forward growth        |

**Recommendation scale:**

| Score   | Label            |
|---------|------------------|
| ≥ 72    | ⬆⬆ SILNE KUPUJ   |
| 58–71   | ⬆ AKUMULUJ       |
| 42–57   | → NEUTRALNIE     |
| 28–41   | ⬇ REDUKUJ        |
| < 28    | ⬇⬇ UNIKAJ        |

**Golden signals** flag the most interesting setups: ⭐⭐ when both modes score
≥ 58 (value *and* momentum), ⭐ when only the fundamental mode does (a setup —
wait for momentum).

All model parameters — weights, thresholds, sector benchmarks — live in a single
config file and are never hard-coded into the scoring logic. The core score is
**deterministic**: the same inputs always produce the same result.

## Features

- 🔐 Account-based access (email + password), per-account data isolation
- 📊 Analyse up to 10 tickers at once, ranked by CVS with both modes shown
- 🕸️ Radar chart of the three pillars per company
- 🔎 Detail panel with the raw financial data feeding the model
- 📈 12-month price chart vs SPY
- ⭐ Watchlist (with ~600-ticker autocomplete across S&P 500 + NASDAQ 100)
- 🕘 Analysis history
- 🎯 Analyst forecast card — price targets and recommendation consensus

### On the roadmap

- 🤖 AI-powered interpretation explaining *why* CVS and analysts disagree
- 🧭 CVS screener across watched tickers, with sector / threshold / signal filters
- 🔔 Watchlist alerts on state changes (threshold crossing, golden signal)
- 📚 Model track record — how past CVS calls fared against the market
- 🎨 Visual redesign

## Tech stack

- **PHP 8.2** (vanilla, no framework), PSR-4 (`CVS\` namespace)
- **MySQL** via PDO
- **Apache** front controller (`public/index.php`)
- Market data from **Yahoo Finance** (cURL)
- **Composer** for dependencies, **PHPUnit** for tests

## Getting started

```bash
# 1. Install dependencies
composer install

# 2. Configure environment
cp .env.example .env        # then fill in DB credentials etc.

# 3. Run database migrations
#    apply database/migrations/*.sql to your MySQL database

# 4. Start a local dev server (document root is public/)
php -S localhost:8000 -t public
```

Run the test suite (fully offline — no network calls):

```bash
vendor/bin/phpunit
```

## Project structure

```
src/Core/        Router, Request, Response, Database (PDO singleton)
src/Auth/        Authentication + user repository
src/CVS/         CVSModel, CVSResult, AnalysisController, Pillars/
src/Api/         FinancialDataFetcher (Yahoo Finance)
config/          app.php, cvs-weights.php (model parameters)
templates/       Plain-PHP views wrapped by layout.php
public/          Front controller + static assets
database/        SQL migrations
```

## Conventions

- `declare(strict_types=1)` at the top of every PHP file
- UI strings → **Polish**; code identifiers, comments, docblocks → **English**
- New routes go in `src/Core/routes.php`; CSRF is verified on every POST

---

*This is a personal analytical project. Not affiliated with any broker or data
provider. Market data is sourced from public endpoints.*
