/**
 * CVS front-end — dashboard analysis form + result rendering.
 *
 * S-05 dual-mode: each card shows Swing CVS and Fundamental CVS simultaneously.
 * Golden signals (⭐/⭐⭐) mark the best setups.
 *
 * No framework; vanilla ES2020+.
 * Communicates with POST /analysis (JSON response).
 */

(function () {
    'use strict';

    const form           = document.getElementById('analysis-form');
    const spinner        = document.getElementById('spinner');
    const errorMsg       = document.getElementById('error-msg');
    const resultsSection = document.getElementById('results-section');
    const resultsGrid    = document.getElementById('results-grid');
    const analyseBtn     = document.getElementById('analyse-btn');

    if (!form) return; // Not on dashboard — nothing to do.

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearUI();

        const tickers = document.getElementById('tickers').value.trim();
        if (!tickers) {
            showError('Wpisz co najmniej jeden ticker.');
            return;
        }

        const csrf = document.getElementById('csrf-token')?.value ?? '';

        setLoading(true);

        try {
            const response = await fetch('/analysis', {
                method:  'POST',
                headers: {
                    'Content-Type':     'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token':     csrf,
                },
                body: new URLSearchParams({ tickers, _csrf: csrf }),
            });

            const data = await response.json();

            if (!response.ok || data.error) {
                showError(data.error ?? 'Nieznany błąd serwera.');
                return;
            }

            renderResults(data.results ?? []);
        } catch (err) {
            showError('Błąd sieci. Sprawdź połączenie i spróbuj ponownie.');
        } finally {
            setLoading(false);
        }
    });

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    function renderResults(results) {
        if (results.length === 0) {
            showError('Brak wyników.');
            return;
        }

        resultsGrid.innerHTML = '';

        results.forEach(r => {
            const card = buildCard(r);
            resultsGrid.appendChild(card);
        });

        resultsSection.hidden = false;

        // Initialise mini radar charts after DOM is populated.
        initMiniRadars(results);
    }

    function buildCard(r) {
        const el = document.createElement('div');
        el.className = 'result-card ' + cardClass(r);

        if (r.error) {
            el.innerHTML = `
                <div class="result-card__header">
                    <div class="result-card__ticker">${esc(r.ticker)}</div>
                </div>
                <p class="alert alert--error">${esc(r.error)}</p>`;
            return el;
        }

        if (!r.quality_gate) {
            el.innerHTML = `
                <div class="result-card__header">
                    <div class="result-card__ticker">${esc(r.ticker)}</div>
                </div>
                <div class="result-card__fail-label">Quality Gate: ODRZUCONO</div>
                <ul class="failure-list">
                    ${(r.gate_failures ?? []).map(f => `<li>${esc(f)}</li>`).join('')}
                </ul>`;
            return el;
        }

        const signal     = goldenSignal(r);
        const swingClass = scoreClass(r.swing?.cvs ?? 0);
        const fundClass  = scoreClass(r.fundamental?.cvs ?? 0);

        el.innerHTML = `
            <div class="result-card__header">
                <div class="result-card__ticker">${esc(r.ticker)}</div>
                ${signal ? `<div class="result-card__signal result-card__signal--${esc(r.golden_signal)}">
                    ${signal.stars ? signal.stars + ' ' : ''}${esc(signal.label)}
                </div>` : ''}
            </div>
            <div class="result-card__scores">
                <div class="score-badge score-badge--swing ${swingClass}">
                    <span class="score-badge__mode">Swing</span>
                    <span class="score-badge__value">${Number(r.swing?.cvs ?? 0).toFixed(1)}</span>
                    <span class="score-badge__reco">${esc(r.swing?.recommendation ?? '')}</span>
                </div>
                <div class="score-badge score-badge--fund ${fundClass}">
                    <span class="score-badge__mode">Fund</span>
                    <span class="score-badge__value">${Number(r.fundamental?.cvs ?? 0).toFixed(1)}</span>
                    <span class="score-badge__reco">${esc(r.fundamental?.recommendation ?? '')}</span>
                </div>
            </div>
            <div class="result-card__radar">
                <canvas id="radar-${esc(r.ticker)}" width="180" height="180"></canvas>
            </div>
            <a class="result-card__link" href="/analysis/${esc(r.ticker)}">Szczeg&oacute;&lstrok;y &rarr;</a>`;

        return el;
    }

    // ------------------------------------------------------------------
    // Mini Radars — two datasets (Swing + Fund) on one chart
    // ------------------------------------------------------------------

    function initMiniRadars(results) {
        if (typeof Chart === 'undefined') return; // Chart.js not loaded

        results.forEach(r => {
            if (!r.quality_gate || r.error) return;

            const canvas = document.getElementById('radar-' + r.ticker);
            if (!canvas) return;

            const ps = r.pillar_scores ?? {};

            new Chart(canvas.getContext('2d'), {
                type: 'radar',
                data: {
                    labels: ['Wycena', 'Momentum', 'Jakość'],
                    datasets: [
                        {
                            label: 'Swing',
                            data: [
                                ps.valuation      ?? 0,
                                ps.momentum_swing ?? 0,
                                ps.quality        ?? 0,
                            ],
                            borderColor:     'rgba(79, 142, 247, 0.9)',
                            backgroundColor: 'rgba(79, 142, 247, 0.08)',
                            pointRadius: 2,
                            borderWidth: 1.5,
                        },
                        {
                            label: 'Fund',
                            data: [
                                ps.valuation     ?? 0,
                                ps.momentum_fund ?? 0,
                                ps.quality       ?? 0,
                            ],
                            borderColor:     'rgba(234, 179, 8, 0.9)',
                            backgroundColor: 'rgba(234, 179, 8, 0.08)',
                            pointRadius: 2,
                            borderWidth: 1.5,
                        },
                    ],
                },
                options: {
                    animation: false,
                    plugins: {
                        legend: {
                            display: false,
                        },
                    },
                    scales: {
                        r: {
                            min: 0,
                            max: 100,
                            ticks: {
                                display: false,
                                stepSize: 25,
                            },
                            pointLabels: {
                                font: { size: 9 },
                                color: 'rgba(255,255,255,0.65)',
                            },
                            grid: {
                                color: 'rgba(128, 128, 128, 0.15)',
                            },
                        },
                    },
                },
            });
        });
    }

    // ------------------------------------------------------------------
    // Golden signal helper
    // ------------------------------------------------------------------

    function goldenSignal(r) {
        if (!r.golden_signal) return null;
        const map = {
            strong:    { stars: '⭐⭐', label: 'Silny sygnał' },
            watchlist: { stars: '⭐',   label: 'Setup — czekaj' },
            momentum:  { stars: null,   label: 'Momentum' },
        };
        return map[r.golden_signal] ?? null;
    }

    // ------------------------------------------------------------------
    // Card colour class
    // ------------------------------------------------------------------

    function cardClass(r) {
        if (r.error || !r.quality_gate) return 'result-card--fail';
        const cvs = r.swing?.cvs ?? 0;
        if (cvs >= 72) return 'result-card--buy';
        if (cvs >= 42) return 'result-card--warn';
        return 'result-card--sell';
    }

    function scoreClass(cvs) {
        if (cvs >= 72) return 'score-badge--strong';
        if (cvs >= 42) return 'score-badge--neutral';
        return 'score-badge--weak';
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    function esc(str) {
        const d = document.createElement('div');
        d.textContent = String(str ?? '');
        return d.innerHTML;
    }

    function setLoading(on) {
        spinner.hidden      = !on;
        analyseBtn.disabled = on;
    }

    function showError(msg) {
        errorMsg.textContent = msg;
        errorMsg.hidden = false;
    }

    function clearUI() {
        errorMsg.hidden = true;
        errorMsg.textContent = '';
        resultsSection.hidden = true;
        resultsGrid.innerHTML = '';
    }
})();
