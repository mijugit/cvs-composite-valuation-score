/**
 * CVS front-end — dashboard analysis form + result rendering.
 *
 * No framework; vanilla ES2020+.
 * Communicates with POST /analysis (JSON response).
 */

(function () {
    'use strict';

    const form        = document.getElementById('analysis-form');
    const spinner     = document.getElementById('spinner');
    const errorMsg    = document.getElementById('error-msg');
    const resultsSection = document.getElementById('results-section');
    const resultsGrid = document.getElementById('results-grid');
    const analyseBtn  = document.getElementById('analyse-btn');

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
    }

    function buildCard(r) {
        const el = document.createElement('div');
        el.className = 'result-card ' + cardClass(r);

        if (r.error) {
            el.innerHTML = `
                <div class="result-card__ticker">${esc(r.ticker)}</div>
                <p class="alert alert--error">${esc(r.error)}</p>`;
            return el;
        }

        if (!r.quality_gate) {
            el.innerHTML = `
                <div class="result-card__ticker">${esc(r.ticker)}</div>
                <div class="result-card__reco">Quality Gate: ODRZUCONO</div>
                <ul class="failure-list">
                    ${(r.gate_failures ?? []).map(f => `<li>${esc(f)}</li>`).join('')}
                </ul>`;
            return el;
        }

        el.innerHTML = `
            <div class="result-card__ticker">${esc(r.ticker)}</div>
            <div class="result-card__reco">${esc(r.recommendation)}</div>
            <div class="result-card__cvs">${Number(r.cvs).toFixed(1)}</div>
            <a class="result-card__link" href="/analysis/${esc(r.ticker)}">Szczeg&oacute;&lstrok;y &rarr;</a>`;

        return el;
    }

    function cardClass(r) {
        if (r.error || !r.quality_gate) return 'result-card--fail';
        const cvs = r.cvs ?? 0;
        if (cvs >= 72) return 'result-card--buy';
        if (cvs >= 42) return 'result-card--warn';
        return 'result-card--sell';
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
        spinner.hidden    = !on;
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
