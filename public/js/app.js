/**
 * CVS front-end — dashboard analysis form + result rendering.
 *
 * S-05 dual-mode: each card shows Swing CVS and Fundamental CVS simultaneously.
 * Golden signals (⭐/⭐⭐) mark the best setups.
 *
 * No framework; vanilla ES2020+.
 * Communicates with POST /analysis (JSON response).
 */

/**
 * Mobile navigation toggle (hamburger). Runs on every page — independent of
 * the dashboard logic below, which early-returns when there is no analysis form.
 */
(function () {
    'use strict';

    const toggle = document.querySelector('.nav-toggle');
    const nav    = document.getElementById('site-nav');
    if (!toggle || !nav) return;

    function close() {
        nav.classList.remove('site-nav--open');
        toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        const open = nav.classList.toggle('site-nav--open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    // Tapping a real navigation link closes the panel (but not the admin sub-trigger).
    nav.addEventListener('click', function (e) {
        if (e.target.closest('a')) close();
    });

    // Tapping outside the open panel closes it.
    document.addEventListener('click', function (e) {
        if (!nav.classList.contains('site-nav--open')) return;
        if (!nav.contains(e.target) && !toggle.contains(e.target)) close();
    });
})();

(function () {
    'use strict';

    const form           = document.getElementById('analysis-form');
    const spinner        = document.getElementById('spinner');
    const errorMsg       = document.getElementById('error-msg');
    const resultsSection = document.getElementById('results-section');
    const resultsGrid    = document.getElementById('results-grid');
    const analyseBtn     = document.getElementById('analyse-btn');

    if (!form) return; // Not on dashboard — nothing to do.

    // ----------------------------------------------------------------
    // Watchlist state — S-06
    // ----------------------------------------------------------------

    /** Set of tickers currently on the user's watchlist (client-side mirror). */
    let watchedSet = new Set();

    function getCsrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content
            ?? document.getElementById('csrf-token')?.value
            ?? '';
    }

    async function watchlistToggle(ticker) {
        const csrf = getCsrf();
        try {
            const resp = await fetch('/watchlist/toggle', {
                method:  'POST',
                headers: {
                    'Content-Type':     'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token':     csrf,
                },
                body: new URLSearchParams({ ticker, _csrf: csrf }),
            });
            if (!resp.ok) return null;
            return resp.json();
        } catch (e) {
            return null;
        }
    }

    // ------ Chip helpers -------------------------------------------

    function addWatchlistChip(ticker) {
        const section = document.querySelector('.watchlist-section');
        const chips   = document.querySelector('.watchlist-chips');
        if (!section || !chips) return;

        // Avoid duplicates
        if (chips.querySelector(`[data-ticker="${CSS.escape(ticker)}"]`)) return;

        const span = document.createElement('span');
        span.className       = 'watchlist-chip';
        span.dataset.ticker  = ticker;
        span.innerHTML = esc(ticker) +
            `<button class="watchlist-chip__remove" data-ticker="${esc(ticker)}"` +
            ` aria-label="Usuń ${esc(ticker)}">&times;</button>`;
        chips.appendChild(span);
        section.hidden = false;
    }

    function removeWatchlistChip(ticker) {
        const chip  = document.querySelector(`.watchlist-chips .watchlist-chip[data-ticker="${CSS.escape(ticker)}"]`);
        if (chip) chip.remove();

        const chips = document.querySelector('.watchlist-chips');
        if (chips && chips.children.length === 0) {
            const section = document.querySelector('.watchlist-section');
            if (section) section.hidden = true;
        }
    }

    // ------ Card toggle buttons ------------------------------------

    function updateCardToggleBtns(ticker, isWatched) {
        document.querySelectorAll(`.watchlist-toggle-btn[data-ticker="${CSS.escape(ticker)}"]`)
            .forEach(btn => {
                btn.classList.toggle('is-watched', isWatched);
                btn.textContent = isWatched ? '×' : '⭐';
            });
    }

    // Delegated listener on results grid
    if (resultsGrid) {
        resultsGrid.addEventListener('click', async (e) => {
            const btn = e.target.closest('.watchlist-toggle-btn');
            if (!btn) return;

            const ticker = btn.dataset.ticker;
            if (!ticker) return;

            const data = await watchlistToggle(ticker);
            if (!data?.ok) return;

            if (data.action === 'added') {
                watchedSet.add(ticker);
                updateCardToggleBtns(ticker, true);
                addWatchlistChip(ticker);
            } else {
                watchedSet.delete(ticker);
                updateCardToggleBtns(ticker, false);
                removeWatchlistChip(ticker);
            }
        });
    }

    // ------ Init watchlist section on page load --------------------

    function initWatchlistSection() {
        const section = document.querySelector('.watchlist-section');
        if (!section) return;

        try {
            const list = JSON.parse(section.dataset.watchlist ?? '[]');
            watchedSet = new Set(list);
        } catch (e) {}

        const chips = section.querySelector('.watchlist-chips');
        if (!chips) return;

        // Chip body click → append to textarea; × click → confirm modal → AJAX remove
        chips.addEventListener('click', async (e) => {
            const chip = e.target.closest('.watchlist-chip');
            if (!chip) return;

            const ticker = chip.dataset.ticker;

            if (e.target.closest('.watchlist-chip__remove')) {
                openRemoveModal(ticker, chip, chips, section);
                return;
            }

            // Chip body → append ticker to textarea
            appendTickerToTextarea(ticker);
        });

        // Double-click on a chip → open the latest analysis for that ticker.
        chips.addEventListener('dblclick', (e) => {
            const chip = e.target.closest('.watchlist-chip');
            if (!chip || e.target.closest('.watchlist-chip__remove')) return;

            const ticker = chip.dataset.ticker;
            if (ticker) window.location.href = `/analysis/${encodeURIComponent(ticker)}`;
        });
    }

    // ------ Removal confirmation modal ------------------------------

    function openRemoveModal(ticker, chip, chips, section) {
        const modal   = document.getElementById('watchlist-remove-modal');
        const label   = document.getElementById('watchlist-remove-ticker');
        const confirm = document.getElementById('watchlist-remove-confirm');
        const cancel  = document.getElementById('watchlist-remove-cancel');
        if (!modal || !label || !confirm || !cancel) return;

        label.textContent = ticker;
        modal.hidden = false;

        const close = () => { modal.hidden = true; cleanup(); };

        const onConfirm = async () => {
            const data = await watchlistToggle(ticker);
            if (data?.ok && data.action === 'removed') {
                chip.remove();
                watchedSet.delete(ticker);
                if (chips.children.length === 0) section.hidden = true;
                updateCardToggleBtns(ticker, false);
            }
            close();
        };

        const onCancel = () => close();

        const onBackdrop = (e) => { if (e.target === modal) close(); };

        function cleanup() {
            confirm.removeEventListener('click', onConfirm);
            cancel.removeEventListener('click', onCancel);
            modal.removeEventListener('click', onBackdrop);
        }

        confirm.addEventListener('click', onConfirm);
        cancel.addEventListener('click', onCancel);
        modal.addEventListener('click', onBackdrop);
    }

    function appendTickerToTextarea(ticker) {
        const ta = document.getElementById('tickers');
        if (!ta) return;
        const tokens = ta.value.split(/[,\s]+/).filter(t => t.length > 0);
        if (!tokens.includes(ticker)) tokens.push(ticker);
        ta.value = tokens.join(', ');
        ta.focus();
    }

    initWatchlistSection();

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
            const isWatchedFail = watchedSet.has(r.ticker);
            el.innerHTML = `
                <div class="result-card__header">
                    <div class="result-card__ticker">${esc(r.ticker)}</div>
                    <button class="watchlist-toggle-btn${isWatchedFail ? ' is-watched' : ''}"
                            data-ticker="${esc(r.ticker)}">${isWatchedFail ? '×' : '⭐'}</button>
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
        const isWatched  = watchedSet.has(r.ticker);

        el.innerHTML = `
            <div class="result-card__header">
                <div class="result-card__ticker">${esc(r.ticker)}</div>
                ${signal ? `<span class="signal-pill signal-pill--${esc(r.golden_signal)}">
                    ${signal.stars ? signal.stars + ' ' : ''}${esc(signal.label)}
                </span>` : ''}
                <button class="watchlist-toggle-btn${isWatched ? ' is-watched' : ''}"
                        data-ticker="${esc(r.ticker)}">${isWatched ? '×' : '⭐'}</button>
            </div>
            <div class="result-card__scores">
                <div class="score-tile score-tile--swing ${swingClass}">
                    <span class="score-tile__mode">Swing</span>
                    <span class="score-tile__value">${Number(r.swing?.cvs ?? 0).toFixed(1)}</span>
                    <span class="score-tile__reco">${esc(r.swing?.recommendation ?? '')}</span>
                </div>
                <div class="score-tile score-tile--fund ${fundClass}">
                    <span class="score-tile__mode">Fund</span>
                    <span class="score-tile__value">${Number(r.fundamental?.cvs ?? 0).toFixed(1)}</span>
                    <span class="score-tile__reco">${esc(r.fundamental?.recommendation ?? '')}</span>
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
                            borderColor:     'rgba(250, 204, 21, 0.9)',
                            backgroundColor: 'rgba(250, 204, 21, 0.08)',
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
        if (cvs >= 72) return 'score-tile--strong';
        if (cvs >= 42) return 'score-tile--neutral';
        return 'score-tile--weak';
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

// ============================================================
// Detail page watchlist toggle — S-06
// Handles .watchlist-detail-btn on /analysis/{ticker}.
// ============================================================

(function () {
    'use strict';

    const btn = document.querySelector('.watchlist-detail-btn');
    if (!btn) return; // Not on detail page.

    btn.addEventListener('click', async () => {
        const ticker = btn.dataset.ticker;
        if (!ticker) return;

        const csrf = document.querySelector('meta[name="csrf-token"]')?.content
                  ?? document.getElementById('csrf-token')?.value
                  ?? '';
        try {
            const resp = await fetch('/watchlist/toggle', {
                method:  'POST',
                headers: {
                    'Content-Type':     'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token':     csrf,
                },
                body: new URLSearchParams({ ticker, _csrf: csrf }),
            });

            if (!resp.ok) return;
            const data = await resp.json();
            if (!data.ok) return;

            const isWatched = data.action === 'added';
            btn.dataset.watched = isWatched ? '1' : '0';
            btn.classList.toggle('is-watched', isWatched);
            btn.textContent = isWatched ? '× Usuń z obserwowanych' : '⭐ Obserwuj';
        } catch (e) { /* network error — silent */ }
    });
})();

// ============================================================
// Autocomplete — S-06
// Targets: #tickers textarea on the dashboard.
// Loads public/data/tickers.json once, filters in memory.
// ============================================================

(function () {
    'use strict';

    const TICKERS_URL = '/data/tickers.json';
    const MAX_SUGGESTIONS = 8;

    let tickerList  = []; // [{symbol, name}, ...]
    let activeIndex = -1; // keyboard selection index

    // ------------------------------------------------------------------
    // Boot — fetch dict once, then attach to textarea
    // ------------------------------------------------------------------

    document.addEventListener('DOMContentLoaded', () => {
        const textarea = document.getElementById('tickers');
        if (!textarea) return;

        // Wrap textarea in a relative-positioned container so the dropdown
        // can be positioned absolutely without disrupting layout.
        const wrapper = document.createElement('div');
        wrapper.className = 'ac-wrapper';
        textarea.parentNode.insertBefore(wrapper, textarea);
        wrapper.appendChild(textarea);

        const dropdown = document.createElement('div');
        dropdown.className = 'ac-dropdown';
        dropdown.hidden = true;
        wrapper.appendChild(dropdown);

        // Cache-bust with the server-stamped file version (set by dashboard.php)
        // so a ticker added via /admin/tickers shows up without a hard refresh —
        // same problem class as the CSS/JS cache-busting in layout.php's $asset().
        const version = textarea.dataset.tickersVersion;
        const url = version ? TICKERS_URL + '?v=' + version : TICKERS_URL;

        fetch(url)
            .then(r => r.json())
            .then(data => {
                tickerList = data;
                attachListeners(textarea, dropdown);
            })
            .catch(() => { /* autocomplete unavailable — degrade silently */ });
    });

    // ------------------------------------------------------------------
    // Event listeners
    // ------------------------------------------------------------------

    function attachListeners(textarea, dropdown) {

        textarea.addEventListener('input', () => {
            const token = lastToken(textarea.value);
            if (token.length === 0) { hideDropdown(dropdown); return; }

            const matches = filterTickers(token);
            if (matches.length === 0) { hideDropdown(dropdown); return; }

            renderDropdown(dropdown, matches, textarea);
        });

        // Keyboard navigation (ArrowDown/Up/Enter/Escape)
        textarea.addEventListener('keydown', (e) => {
            if (dropdown.hidden) return;

            const items = dropdown.querySelectorAll('.ac-item');
            if (items.length === 0) return;

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = Math.min(activeIndex + 1, items.length - 1);
                items[activeIndex].focus();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                if (activeIndex <= 0) {
                    activeIndex = -1;
                    textarea.focus();
                } else {
                    activeIndex = activeIndex - 1;
                    items[activeIndex].focus();
                }
            } else if (e.key === 'Escape') {
                hideDropdown(dropdown);
                textarea.focus();
            }
        });

        // Arrow-up on first item returns focus to textarea
        dropdown.addEventListener('keydown', (e) => {
            const items = dropdown.querySelectorAll('.ac-item');
            if (e.key === 'Escape') {
                hideDropdown(dropdown);
                textarea.focus();
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIndex = Math.min(activeIndex + 1, items.length - 1);
                items[activeIndex].focus();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIndex = Math.max(0, activeIndex - 1);
                if (activeIndex === 0 && document.activeElement === items[0]) {
                    activeIndex = -1;
                    textarea.focus();
                } else {
                    items[activeIndex].focus();
                }
            }
        });

        // Hide when focus leaves the wrapper entirely
        document.addEventListener('click', (e) => {
            if (!dropdown.closest('.ac-wrapper')?.contains(e.target)) {
                hideDropdown(dropdown);
            }
        });
    }

    // ------------------------------------------------------------------
    // Render
    // ------------------------------------------------------------------

    function renderDropdown(dropdown, matches, textarea) {
        activeIndex = -1;
        dropdown.innerHTML = '';

        matches.forEach(m => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ac-item';
            btn.dataset.symbol = m.symbol;
            btn.textContent = m.symbol + ' — ' + m.name;

            btn.addEventListener('mousedown', (e) => {
                // mousedown fires before blur; prevent textarea losing focus
                e.preventDefault();
                selectSuggestion(textarea, dropdown, m.symbol);
            });

            btn.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    selectSuggestion(textarea, dropdown, m.symbol);
                }
            });

            dropdown.appendChild(btn);
        });

        dropdown.hidden = false;
    }

    function hideDropdown(dropdown) {
        dropdown.hidden = true;
        dropdown.innerHTML = '';
        activeIndex = -1;
    }

    // ------------------------------------------------------------------
    // Selection — replace last token, append separator
    // ------------------------------------------------------------------

    function selectSuggestion(textarea, dropdown, symbol) {
        const val   = textarea.value;
        const parts = splitTokens(val);

        // Replace last non-empty token or append
        if (parts.length > 0) {
            parts[parts.length - 1] = symbol;
        } else {
            parts.push(symbol);
        }

        textarea.value = parts.join(', ') + ', ';
        // Move cursor to end
        textarea.selectionStart = textarea.selectionEnd = textarea.value.length;
        textarea.focus();

        hideDropdown(dropdown);

        // Trigger input for any listeners that depend on textarea value
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    }

    // ------------------------------------------------------------------
    // Token helpers
    // ------------------------------------------------------------------

    /**
     * Return the last token the user is currently typing.
     * Split on comma or whitespace sequences.
     */
    function lastToken(val) {
        // If val ends with a separator, user started a new (empty) token
        if (/[,\s]$/.test(val)) return '';
        const parts = val.split(/[,\s]+/);
        return parts[parts.length - 1] ?? '';
    }

    /**
     * Split value into non-empty token list (for rebuilding after selection).
     */
    function splitTokens(val) {
        return val.split(/[,\s]+/).filter(t => t.length > 0);
    }

    // ------------------------------------------------------------------
    // Filtering — prefix on symbol (priority) + substring on name
    // ------------------------------------------------------------------

    function filterTickers(token) {
        const q = token.toUpperCase();
        const prefix = [];
        const substr = [];

        for (const t of tickerList) {
            if (t.symbol.startsWith(q)) {
                prefix.push(t);
            } else if (t.name.toUpperCase().includes(q)) {
                substr.push(t);
            }
            if (prefix.length + substr.length >= MAX_SUGGESTIONS * 2) break;
        }

        return [...prefix, ...substr].slice(0, MAX_SUGGESTIONS);
    }

// ------------------------------------------------------------------
// Admin Sectors — accordion + refresh AJAX + toast
// ------------------------------------------------------------------

(function () {
    'use strict';

    const sectorRows = document.querySelectorAll('.sector-row');
    if (!sectorRows.length) return;

    function getCsrfSectors() {
        return document.querySelector('meta[name="csrf-token"]')?.content
            ?? document.getElementById('csrf-token')?.value
            ?? '';
    }

    let toastTimer = null;
    function showToast(msg) {
        const el = document.getElementById('sectors-toast');
        if (!el) return;
        el.textContent = msg;
        el.hidden = false;
        el.classList.add('sectors-toast--visible');
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(() => {
            el.classList.remove('sectors-toast--visible');
            setTimeout(() => { el.hidden = true; }, 200);
        }, 4000);
    }

    // Accordion
    sectorRows.forEach(row => {
        row.addEventListener('click', e => {
            if (e.target.closest('.js-refresh-sector')) return;
            if (e.target.closest('.js-sector-chart')) return;
            const slug = row.dataset.sector;
            if (!slug) return;
            const children = document.querySelectorAll('.industry-row--' + slug);
            if (!children.length) return;
            children.forEach(r => { r.hidden = !r.hidden; });
            row.classList.toggle('sector-row--expanded');
        });
    });

    // Refresh AJAX
    document.querySelectorAll('.js-refresh-sector').forEach(btn => {
        btn.addEventListener('click', async e => {
            e.stopPropagation();
            const sector = btn.dataset.sector;
            btn.disabled = true;
            btn.textContent = '…';
            const csrf = getCsrfSectors();
            try {
                const resp = await fetch('/admin/sectors/refresh', {
                    method: 'POST',
                    headers: {
                        'Content-Type':     'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token':     csrf,
                    },
                    body: new URLSearchParams({ sector, _csrf: csrf }),
                });
                const data = await resp.json();
                showToast(data.ok
                    ? 'Odświeżanie ' + sector + ' uruchomiono'
                    : 'Błąd: ' + (data.error ?? 'nieznany'));
            } catch {
                showToast('Błąd połączenia');
            } finally {
                btn.textContent = 'Odśwież';
                btn.disabled = false;
            }
        });
    });
}());

// ------------------------------------------------------------------
// Sector/industry peer-median history modal + Chart.js. Page-agnostic —
// triggered by any .js-sector-chart element (data-level, data-bucket);
// used on /admin/sectors and /analysis/{ticker}, both sharing
// templates/partials/sector-history-modal.php.
// ------------------------------------------------------------------

(function () {
    'use strict';

    const chartBtns = document.querySelectorAll('.js-sector-chart');
    if (!chartBtns.length) return;

    const modal        = document.getElementById('sector-history-modal');
    const titleEl      = document.getElementById('sector-history-title');
    const emptyEl      = document.getElementById('sector-history-empty');
    const canvas       = document.getElementById('sector-history-chart');
    const closeBtn     = document.getElementById('sector-history-close');
    const companyLegend = document.getElementById('sector-history-legend-company');

    if (!modal || !canvas) return;

    const historyBase = modal.dataset.historyBase || '/admin/sectors/history';
    let activeChart = null;

    function destroyChart() {
        if (activeChart) { activeChart.destroy(); activeChart = null; }
        const existing = Chart.getChart(canvas);
        if (existing) existing.destroy();
    }

    // companyValue/companyVariant/companyLabel are optional — set only by the
    // valuation badge on /analysis/{ticker} (templates/analysis.php), never
    // by the plain sector/industry rows on /admin/sectors or /sectors. When
    // present, overlay the analysed company's own multiple as a flat dashed
    // reference line so its position against the sector's historical median
    // is visible at a glance ("this company through the lens of its sector").
    function openModal(level, bucket, companyValue, companyVariant, companyLabel) {
        titleEl.textContent = 'Historia: ' + bucket;
        emptyEl.hidden = true;
        canvas.style.display = 'none';
        if (companyLegend) {
            companyLegend.hidden = true;
            companyLegend.style.display = 'none';
        }
        modal.hidden = false;
        destroyChart();

        const companyNum = parseFloat(companyValue);
        const hasCompany = Number.isFinite(companyNum) && (companyVariant === 'A' || companyVariant === 'B');

        fetch(historyBase + '?level=' + encodeURIComponent(level) + '&bucket_key=' + encodeURIComponent(bucket), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(r => r.json())
            .then(json => {
                if (!json.ok || !json.data || !json.data.labels || json.data.labels.length === 0) {
                    emptyEl.hidden = false;
                    return;
                }
                const d = json.data;
                canvas.style.display = 'block';

                const datasets = [
                    {
                        label: 'EV/FCF',
                        data: d.ev_fcf,
                        yAxisID: 'y',
                        borderColor: 'rgba(64, 144, 224, 0.9)',
                        backgroundColor: 'rgba(64, 144, 224, 0.1)',
                        tension: 0.3,
                        spanGaps: true,
                    },
                    {
                        label: 'EV/Sales',
                        data: d.ev_sales,
                        yAxisID: 'y2',
                        borderColor: 'rgba(250, 204, 21, 0.9)',
                        backgroundColor: 'rgba(250, 204, 21, 0.1)',
                        tension: 0.3,
                        spanGaps: true,
                    },
                    {
                        label: 'GM%',
                        data: d.gm,
                        yAxisID: 'y1',
                        borderColor: 'rgba(52, 211, 153, 0.9)',
                        backgroundColor: 'rgba(52, 211, 153, 0.1)',
                        tension: 0.3,
                        spanGaps: true,
                    },
                ];

                if (hasCompany) {
                    // Flat line at the company's own multiple across the same date
                    // axis as the sector history — we only have ONE current value
                    // for the company (not a historical series), so a constant
                    // dashed reference line is the correct comparison, not a curve.
                    datasets.push({
                        label: (companyLabel || 'Spółka') + (companyVariant === 'A' ? ' — EV/FCF' : ' — EV/Sales'),
                        data: d.labels.map(() => companyNum),
                        yAxisID: companyVariant === 'A' ? 'y' : 'y2',
                        borderColor: 'rgba(239, 68, 68, 0.95)',
                        backgroundColor: 'transparent',
                        borderDash: [6, 4],
                        borderWidth: 2,
                        pointRadius: 0,
                        tension: 0,
                        spanGaps: true,
                    });
                    if (companyLegend) {
                        companyLegend.hidden = false;
                        companyLegend.style.display = 'inline-flex';
                        const labelSpan = companyLegend.querySelector('[data-company-legend-text]');
                        if (labelSpan) {
                            labelSpan.textContent = (companyLabel || 'Spółka')
                                + (companyVariant === 'A' ? ' (EV/FCF, aktualnie)' : ' (EV/Sales, aktualnie)');
                        }
                    }
                }

                activeChart = new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: d.labels,
                        datasets: datasets,
                    },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
                        // Native canvas legend replaced by the HTML legend row in the
                        // modal (templates/admin/sectors.php #sector-history-legend) —
                        // lets each series carry a .chart-hint tooltip, which a
                        // Chart.js-drawn canvas legend can't host.
                        plugins: { legend: { display: false } },
                        scales: {
                            y: {
                                position: 'left',
                                title: { display: true, text: 'EV/FCF (×)' },
                            },
                            y1: {
                                position: 'right',
                                title: { display: true, text: 'GM%' },
                                min: 0,
                                max: 100,
                                grid: { drawOnChartArea: false },
                            },
                            y2: {
                                position: 'right',
                                title: { display: true, text: 'EV/Sales (×)' },
                                grid: { drawOnChartArea: false },
                                // Separate scale from EV/FCF (y): sector medians for
                                // EV/Sales run much smaller (e.g. ~8x vs ~32x for
                                // Technology) — sharing one axis flattened the
                                // EV/Sales line near zero and hid its own movement.
                            },
                        },
                    },
                });
            })
            .catch(() => { emptyEl.hidden = false; });
    }

    function closeModal() {
        modal.hidden = true;
        destroyChart();
    }

    chartBtns.forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const level  = btn.dataset.level;
            const bucket = btn.dataset.bucket;
            if (level && bucket) {
                openModal(level, bucket, btn.dataset.companyValue, btn.dataset.companyVariant, btn.dataset.companyLabel);
            }
        });
    });

    closeBtn?.addEventListener('click', closeModal);

    modal.addEventListener('click', e => {
        if (e.target === modal) closeModal();
    });
}());

// ------------------------------------------------------------------
// Track Record — per-ticker accordion
// ------------------------------------------------------------------

(function () {
    'use strict';

    const summaryRows = document.querySelectorAll('.tr-summary-row');
    if (!summaryRows.length) return;

    summaryRows.forEach(row => {
        row.addEventListener('click', e => {
            if (e.target.closest('a')) return; // let the ticker link navigate normally
            const ticker = row.dataset.ticker;
            if (!ticker) return;
            // Attribute match, not a class-name suffix — tickers can contain a dot
            // (e.g. "XTB.WA"), which would otherwise split a compound class selector.
            const detailRows = document.querySelectorAll('.tr-detail-row[data-ticker="' + ticker + '"]');
            if (!detailRows.length) return;
            detailRows.forEach(r => { r.hidden = !r.hidden; });
            row.classList.toggle('tr-summary-row--expanded');
        });
    });
}());

// ------------------------------------------------------------------
// Ticker hover hint — portal
// ------------------------------------------------------------------
// .ticker-hint__tooltip (screener, track-record) lives inside a card with
// overflow-x:auto, which per the CSS overflow spec computes overflow-y to
// auto too — any position:absolute (or position:fixed, since the card's
// backdrop-filter makes it the containing block for fixed descendants as
// well) tooltip nested inside gets clipped by that card's edge. Fix: a
// single tooltip element living directly under <body> (never a descendant
// of the clipping card), repositioned via getBoundingClientRect() on each
// hover instead of pure CSS :hover.

(function () {
    'use strict';

    const hints = document.querySelectorAll('.ticker-hint');
    if (!hints.length) return;

    const portal = document.createElement('div');
    portal.className = 'ticker-hint-portal';
    document.body.appendChild(portal);

    function show(hint, tooltip) {
        portal.innerHTML = tooltip.innerHTML;
        const r = hint.getBoundingClientRect();
        portal.style.left = (r.left + r.width / 2) + 'px';
        portal.style.top = (r.top - 6) + 'px';
        portal.classList.add('ticker-hint-portal--visible');
    }

    function hide() {
        portal.classList.remove('ticker-hint-portal--visible');
    }

    hints.forEach(hint => {
        const tooltip = hint.querySelector('.ticker-hint__tooltip');
        if (!tooltip || tooltip.innerHTML.trim() === '') return;

        hint.addEventListener('mouseenter', () => show(hint, tooltip));
        hint.addEventListener('mouseleave', hide);
        // Keyboard/touch users: focus on the ticker link also reveals it.
        hint.addEventListener('focusin', () => show(hint, tooltip));
        hint.addEventListener('focusout', hide);
    });

    // A hidden portal card would otherwise trail the page during horizontal
    // table scroll; hide on scroll of the nearest scrolling ancestor too.
    window.addEventListener('scroll', hide, true);
}());

// ------------------------------------------------------------------
// Column header hint (ⓘ) — portal
// ------------------------------------------------------------------
// Same clipping bug as the ticker hover hint above: .chart-hint__tooltip
// used to be position:absolute inside a <th>, which a card's
// overflow-x:auto ancestor (screener, track-record tables) clips. Same
// fix — single body-level portal, repositioned via getBoundingClientRect().

(function () {
    'use strict';

    const hints = document.querySelectorAll('.chart-hint');
    if (!hints.length) return;

    const portal = document.createElement('div');
    portal.className = 'chart-hint-portal';
    document.body.appendChild(portal);

    function show(hint, tooltip) {
        portal.innerHTML = tooltip.innerHTML;
        const r = hint.getBoundingClientRect();
        portal.style.left = (r.left + r.width / 2) + 'px';
        portal.style.top = (r.top - 8) + 'px';
        portal.classList.add('chart-hint-portal--visible');
    }

    function hide() {
        portal.classList.remove('chart-hint-portal--visible');
    }

    hints.forEach(hint => {
        const tooltip = hint.querySelector('.chart-hint__tooltip');
        if (!tooltip || tooltip.innerHTML.trim() === '') return;

        hint.addEventListener('mouseenter', () => show(hint, tooltip));
        hint.addEventListener('mouseleave', hide);
        hint.addEventListener('focusin', () => show(hint, tooltip));
        hint.addEventListener('focusout', hide);
    });

    window.addEventListener('scroll', hide, true);
}());

// ------------------------------------------------------------------
// Analysis page — chart zoom modal (desktop only)
// ------------------------------------------------------------------
// Radar / price / trajectory charts render small on the analysis page.
// Clicking one re-renders the SAME Chart.js instance's data/options at a
// much larger size in a modal — an accessibility nicety, not a new chart.
// Desktop only, on purpose: reliably closing a full-screen modal on small
// viewports is its own unsolved problem elsewhere in this app, so the
// interaction is simply never offered below the 768px breakpoint (matches
// .chart-zoom-target's cursor/hint-icon media query in app.css).

(function () {
    'use strict';

    const targets = document.querySelectorAll('.chart-zoom-target');
    if (!targets.length) return;

    const modal    = document.getElementById('chart-zoom-modal');
    const titleEl  = document.getElementById('chart-zoom-title');
    const canvas   = document.getElementById('chart-zoom-canvas');
    const closeBtn = document.getElementById('chart-zoom-close');
    if (!modal || !canvas) return;

    // The modal is templated inside .card--result, which has backdrop-filter —
    // that establishes a containing block for position:fixed descendants (CSS
    // spec), so "fixed" would resolve against the card's box instead of the
    // viewport. Same fix as the .chart-hint/.ticker-hint tooltip portals: move
    // it to a direct child of <body> before anything else can position it.
    document.body.appendChild(modal);

    const isMobile = () => window.matchMedia('(max-width: 768px)').matches;

    function destroyZoomChart() {
        const existing = typeof Chart !== 'undefined' ? Chart.getChart(canvas) : null;
        if (existing) existing.destroy();
    }

    // Chart.js v4 instruments nested option/data objects with per-instance
    // scriptable-option resolution state (its internal `_scriptable` cache).
    // Reusing the SAME nested objects across two live Chart instances throws
    // "Recursion detected: _scriptable->_scriptable" the moment it tries to
    // resolve them (confirmed while building this feature — the first cut
    // shared srcChart.options/.data by reference and silently rendered an
    // empty canvas, only throwing once something forced a re-resolve).
    // Deep-clone plain objects/arrays; pass functions through by reference —
    // tooltip callbacks etc. are stateless, safe to share.
    function cloneForChart(value) {
        if (Array.isArray(value)) return value.map(cloneForChart);
        if (value !== null && typeof value === 'object' && value.constructor === Object) {
            const out = {};
            for (const k in value) {
                // Chart.js stamps internal per-instance bookkeeping onto dataset/
                // option objects after construction (e.g. `_meta`, keyed by chart
                // id) — cloning that alongside the real config carried over stale
                // per-instance state and broke the new chart ("t.startsWith is
                // not a function" deep in Chart.js internals). Only clone the
                // config a caller actually wrote.
                if (k.charAt(0) === '_') continue;
                out[k] = cloneForChart(value[k]);
            }
            return out;
        }
        return value;
    }

    function openZoom(sourceId, title) {
        if (typeof Chart === 'undefined') return;
        const srcCanvas = document.getElementById(sourceId);
        if (!srcCanvas) return;
        const srcChart = Chart.getChart(srcCanvas);
        if (!srcChart) return; // chart hasn't rendered yet (or failed) — nothing to zoom

        titleEl.textContent = title || '—';
        modal.hidden = false;
        destroyZoomChart();

        // srcChart.data/.options are Chart.js's LIVE resolved view for this
        // specific instance (options in particular is backed by an internal
        // scriptable-option resolver) — merely reading through them again for
        // a second instance is what threw "t.startsWith is not a function"
        // deep in Chart.js internals. srcChart.config.data/.options is the
        // original plain config object passed into `new Chart()`, unresolved
        // and safe to clone.
        const zoomData    = cloneForChart(srcChart.config.data);
        const zoomOptions = cloneForChart(srcChart.config.options);
        zoomOptions.responsive = true;
        // Small charts disable animation (several render at once on page
        // load — no flourish worth the flicker). The zoom modal only ever
        // builds one chart at a time, on a deliberate user click — a nice
        // spot for Chart.js's default "grow in from zero" reveal (radar
        // points expand from centre, lines draw left-to-right). Replays
        // every time the modal opens, since it's a fresh Chart instance
        // each time (see destroyZoomChart() above).
        zoomOptions.animation = { duration: 800, easing: 'easeOutQuart' };
        // Radar keeps its aspect ratio (a stretched triangle looks wrong);
        // line charts (price/trajectory) fill the modal's rectangular canvas.
        zoomOptions.maintainAspectRatio = srcChart.config.type === 'radar';
        // The small radar hides Chart.js's legend and relies on the external
        // .detail-radar-legend HTML instead (not present in the modal) — turn
        // the built-in legend on here so Swing/Fundamentalny are still labelled.
        if (srcChart.config.type === 'radar') {
            zoomOptions.plugins = zoomOptions.plugins || {};
            zoomOptions.plugins.legend = Object.assign({}, zoomOptions.plugins.legend, {
                display: true,
                position: 'top',
                labels: { color: 'rgba(255,255,255,0.75)' },
            });
        }

        new Chart(canvas.getContext('2d'), {
            type: srcChart.config.type,
            data: zoomData,
            options: zoomOptions,
        });
    }

    function closeZoom() {
        modal.hidden = true;
        destroyZoomChart();
    }

    targets.forEach(target => {
        target.addEventListener('click', e => {
            if (isMobile()) return;
            if (e.target.closest('a, .chart-hint')) return; // let links/hints work normally
            const sourceId = target.dataset.zoomCanvas;
            const title    = target.dataset.zoomTitle;
            if (sourceId) openZoom(sourceId, title);
        });
    });

    closeBtn?.addEventListener('click', closeZoom);
    modal.addEventListener('click', e => { if (e.target === modal) closeZoom(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !modal.hidden) closeZoom(); });
}());

})();

// ============================================================
// Screener search — filters the already-rendered /screener table
// client-side, scoped to whatever ticker set the GET filters produced
// (never the full ~500-ticker universe like the dashboard autocomplete
// pulls from public/data/tickers.json). No extra fetch: the ticker +
// company name are already in the DOM via data-ticker/data-company on
// each <tr> (templates/screener.php).
// ============================================================

(function () {
    'use strict';

    const input = document.getElementById('screener-search');
    const table = document.getElementById('screener-table');
    if (!input || !table) return;

    const dropdown  = document.getElementById('screener-search-dropdown');
    const emptyRow  = document.getElementById('screener-search-empty');
    const countEl   = document.getElementById('screener-count');
    const countTotal = countEl ? parseInt(countEl.dataset.total || '0', 10) : 0;

    const rows = Array.from(table.querySelectorAll('tbody tr[data-ticker]')).map(row => ({
        row,
        ticker: (row.dataset.ticker || '').toUpperCase(),
        company: (row.dataset.company || '').toUpperCase(),
    }));

    let activeIndex = -1;

    function matches(entry, q) {
        return entry.ticker.startsWith(q) || (entry.company !== '' && entry.company.includes(q));
    }

    // Same priority as the dashboard autocomplete: ticker-prefix hits first,
    // then company-name substring hits.
    function rank(q) {
        const prefix = [];
        const substr = [];
        for (const entry of rows) {
            if (entry.ticker.startsWith(q)) prefix.push(entry);
            else if (entry.company !== '' && entry.company.includes(q)) substr.push(entry);
        }
        return [...prefix, ...substr];
    }

    function applyFilter(q) {
        if (q === '') {
            rows.forEach(entry => { entry.row.hidden = false; });
            if (emptyRow) emptyRow.hidden = true;
            if (countEl) countEl.textContent = countTotal + ' spółek';
            return;
        }

        let visible = 0;
        rows.forEach(entry => {
            const show = matches(entry, q);
            entry.row.hidden = !show;
            if (show) visible++;
        });
        if (emptyRow) emptyRow.hidden = visible > 0;
        if (countEl) countEl.textContent = visible + ' z ' + countTotal + ' spółek';
    }

    function renderDropdown(matched) {
        activeIndex = -1;
        dropdown.innerHTML = '';
        if (matched.length === 0) { hideDropdown(); return; }

        matched.slice(0, 8).forEach(entry => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'ac-item';
            btn.dataset.ticker = entry.ticker;
            btn.textContent = entry.company !== '' ? entry.ticker + ' — ' + entry.company : entry.ticker;

            btn.addEventListener('mousedown', (e) => {
                e.preventDefault(); // fires before blur — keep focus flow predictable
                selectSuggestion(entry.ticker);
            });
            btn.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); selectSuggestion(entry.ticker); }
            });

            dropdown.appendChild(btn);
        });

        dropdown.hidden = false;
    }

    function hideDropdown() {
        dropdown.hidden = true;
        dropdown.innerHTML = '';
        activeIndex = -1;
    }

    function selectSuggestion(ticker) {
        input.value = ticker;
        applyFilter(ticker);
        hideDropdown();
        input.focus();
        const hit = rows.find(entry => entry.ticker === ticker);
        hit?.row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }

    input.addEventListener('input', () => {
        const q = input.value.trim().toUpperCase();
        applyFilter(q);
        if (q === '') { hideDropdown(); return; }
        renderDropdown(rank(q));
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            input.value = '';
            applyFilter('');
            hideDropdown();
        } else if (e.key === 'Enter') {
            // Never submit the surrounding filter form — this field has no
            // `name` and is purely a client-side live filter.
            e.preventDefault();
        } else if (!dropdown.hidden && e.key === 'ArrowDown') {
            e.preventDefault();
            const items = dropdown.querySelectorAll('.ac-item');
            if (items.length) { activeIndex = 0; items[activeIndex].focus(); }
        }
    });

    dropdown.addEventListener('keydown', (e) => {
        const items = dropdown.querySelectorAll('.ac-item');
        if (items.length === 0) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, items.length - 1);
            items[activeIndex].focus();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (activeIndex <= 0) { activeIndex = -1; input.focus(); }
            else { activeIndex -= 1; items[activeIndex].focus(); }
        } else if (e.key === 'Escape') {
            hideDropdown();
            input.focus();
        }
    });

    document.addEventListener('click', (e) => {
        if (!input.closest('.ac-wrapper')?.contains(e.target)) {
            hideDropdown();
        }
    });
})();

// ============================================================
// Screener — "favourite links" right-click menu (change:
// cvs-screener-ticker-links), desktop only
// ============================================================
// Every row's curated links are already embedded via data-links (no extra
// fetch — same "no N+1" principle as the screener search block above).
// Desktop only: same matchMedia(max-width:768px) gate as the analysis
// page's chart-zoom modal — a custom context menu is a poor fit for touch
// (no right-click gesture, and it would fight the browser's own long-press
// menu on the ticker link). Any authenticated user can add a link (up to
// MAX_LINKS/ticker) and remove their own; an admin can remove any link —
// see TickerLinkController::canDelete() for the server-side check this
// mirrors (the ✕ shown here is a UI convenience, not the real gate).

(function () {
    'use strict';

    const table = document.getElementById('screener-table');
    const tbody = table?.querySelector('tbody');
    if (!table || !tbody) return;

    const MAX_LINKS     = 10;
    const isAdmin        = table.dataset.isAdmin === '1';
    const currentUserId  = parseInt(table.dataset.userId, 10) || 0;
    const isDesktop      = () => !window.matchMedia('(max-width: 768px)').matches;

    function getCsrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = String(s ?? '');
        return d.innerHTML;
    }

    function readLinks(row) {
        try {
            const parsed = JSON.parse(row.dataset.links || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    function writeLinks(row, links) {
        row.dataset.links = JSON.stringify(links);
    }

    // Portal element (direct <body> child) — the screener table lives inside
    // a card with overflow-x:auto, which clips position:fixed descendants
    // the same way it clips the ticker-hint tooltip (see that portal's
    // comment above); moving the menu itself to <body> sidesteps it too.
    const menu = document.getElementById('ticker-link-menu');
    if (menu) document.body.appendChild(menu);

    const addModal      = document.getElementById('ticker-link-add-modal');
    const addTickerEl   = document.getElementById('ticker-link-add-ticker');
    const addLabelInput = document.getElementById('ticker-link-label-input');
    const addUrlInput   = document.getElementById('ticker-link-url-input');
    const addErrorEl    = document.getElementById('ticker-link-add-error');
    const addSubmitBtn  = document.getElementById('ticker-link-add-submit');
    const addCancelBtn  = document.getElementById('ticker-link-add-cancel');

    let currentTicker = null;

    function hideMenu() {
        if (menu) menu.classList.remove('ticker-link-menu--visible');
    }

    function renderMenu(row) {
        if (!menu) return;
        currentTicker = row.dataset.ticker || '';
        const links = readLinks(row);

        let html = '';
        if (links.length === 0) {
            html += '<div class="ticker-link-menu__empty">Brak zapisanych linków</div>';
        } else {
            links.forEach(link => {
                const canDelete = isAdmin || (parseInt(link.created_by, 10) === currentUserId);
                html += '<div class="ticker-link-menu__item">'
                    + '<a class="ticker-link-menu__link" href="' + esc(link.url) + '" target="_blank" rel="noopener noreferrer" title="' + esc(link.url) + '">' + esc(link.label) + '</a>'
                    + (canDelete ? '<button type="button" class="ticker-link-menu__remove" data-id="' + esc(link.id) + '" data-label="' + esc(link.label) + '" aria-label="Usuń ' + esc(link.label) + '">&times;</button>' : '')
                    + '</div>';
            });
        }

        // Adding is open to every authenticated user (not just admins) — the
        // 10-link cap is shared across everyone who adds to this ticker.
        html += links.length >= MAX_LINKS
            ? '<div class="ticker-link-menu__empty">Limit ' + MAX_LINKS + ' linków osiągnięty</div>'
            : '<button type="button" class="ticker-link-menu__add">+ Dodaj link…</button>';

        menu.innerHTML = html;
    }

    function positionMenu(x, y) {
        if (!menu) return;
        menu.style.left = x + 'px';
        menu.style.top  = y + 'px';
        menu.classList.add('ticker-link-menu--visible');

        // Clamp to viewport — a menu opened near the right/bottom edge must
        // not render partially off-screen.
        const rect = menu.getBoundingClientRect();
        const overflowX = rect.right  - window.innerWidth;
        const overflowY = rect.bottom - window.innerHeight;
        if (overflowX > 0) menu.style.left = Math.max(0, x - overflowX) + 'px';
        if (overflowY > 0) menu.style.top  = Math.max(0, y - overflowY) + 'px';
    }

    tbody.addEventListener('contextmenu', (e) => {
        if (!isDesktop()) return;
        const row = e.target.closest('tr[data-ticker]');
        if (!row) return;

        // Every authenticated user can add a link, so the menu is always
        // useful (even with zero existing links, it offers "+ Dodaj link…")
        // — unlike a strictly read-only viewer, there's no case left where
        // letting the native browser menu through is the better choice.
        e.preventDefault();
        renderMenu(row);
        positionMenu(e.clientX, e.clientY);
    });

    menu?.addEventListener('click', (e) => {
        const removeBtn = e.target.closest('.ticker-link-menu__remove');
        if (removeBtn) {
            e.preventDefault();
            const id    = parseInt(removeBtn.dataset.id, 10);
            const label = removeBtn.dataset.label || '';
            if (!Number.isFinite(id) || !confirm('Usunąć link "' + label + '"?')) return;
            deleteLink(id);
            return;
        }
        if (e.target.closest('.ticker-link-menu__add')) {
            openAddModal();
        }
    });

    document.addEventListener('click', (e) => {
        if (menu && !menu.contains(e.target)) hideMenu();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') hideMenu();
    });
    window.addEventListener('scroll', hideMenu, true);

    function openAddModal() {
        if (!addModal) return;
        hideMenu();
        if (addTickerEl)   addTickerEl.textContent = currentTicker || '';
        if (addLabelInput) addLabelInput.value = '';
        if (addUrlInput)   addUrlInput.value = '';
        if (addErrorEl)    { addErrorEl.style.display = 'none'; addErrorEl.textContent = ''; }
        addModal.hidden = false;
        setTimeout(() => addLabelInput?.focus(), 50);
    }

    function closeAddModal() {
        if (addModal) addModal.hidden = true;
    }

    function showAddError(msg) {
        if (!addErrorEl) return;
        addErrorEl.textContent = msg;
        addErrorEl.style.display = 'block';
    }

    addCancelBtn?.addEventListener('click', closeAddModal);
    addModal?.addEventListener('click', (e) => {
        if (e.target === addModal) closeAddModal();
    });

    addSubmitBtn?.addEventListener('click', async () => {
        const ticker = currentTicker;
        const label  = (addLabelInput?.value ?? '').trim();
        const url    = (addUrlInput?.value ?? '').trim();
        if (!ticker) return;
        if (!label) { showAddError('Podaj etykietę.'); return; }
        if (!/^https?:\/\//i.test(url)) { showAddError('Adres musi zaczynać się od http:// lub https://.'); return; }

        const csrf = getCsrf();
        try {
            const resp = await fetch('/screener/links/add', {
                method:  'POST',
                headers: {
                    'Content-Type':     'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token':     csrf,
                },
                body: new URLSearchParams({ ticker, label, url, _csrf: csrf }),
            });
            const data = await resp.json();
            if (!data.ok) { showAddError(data.error || 'Nie udało się dodać linku.'); return; }

            const row = tbody.querySelector('tr[data-ticker="' + CSS.escape(ticker) + '"]');
            if (row) {
                const links = readLinks(row);
                links.push(data.link);
                writeLinks(row, links);
            }
            closeAddModal();
        } catch (e) {
            showAddError('Błąd połączenia.');
        }
    });

    [addLabelInput, addUrlInput].forEach(input => {
        input?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') addSubmitBtn?.click();
        });
    });

    async function deleteLink(id) {
        const csrf   = getCsrf();
        const ticker = currentTicker;
        try {
            const resp = await fetch('/screener/links/delete', {
                method:  'POST',
                headers: {
                    'Content-Type':     'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token':     csrf,
                },
                body: new URLSearchParams({ id: String(id), _csrf: csrf }),
            });
            const data = await resp.json();
            if (!data.ok) return;

            const row = tbody.querySelector('tr[data-ticker="' + CSS.escape(ticker || '') + '"]');
            if (row) writeLinks(row, readLinks(row).filter(l => l.id !== id));
            hideMenu();
        } catch (e) {
            // Silent — data-links stays as-is; the next successful call reconciles it.
        }
    }
}());
