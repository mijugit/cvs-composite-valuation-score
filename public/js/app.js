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

        fetch(TICKERS_URL)
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
// Admin Sectors — history modal + Chart.js
// ------------------------------------------------------------------

(function () {
    'use strict';

    const chartBtns = document.querySelectorAll('.js-sector-chart');
    if (!chartBtns.length) return;

    const modal    = document.getElementById('sector-history-modal');
    const titleEl  = document.getElementById('sector-history-title');
    const emptyEl  = document.getElementById('sector-history-empty');
    const canvas   = document.getElementById('sector-history-chart');
    const closeBtn = document.getElementById('sector-history-close');

    if (!modal || !canvas) return;

    const historyBase = modal.dataset.historyBase || '/admin/sectors/history';
    let activeChart = null;

    function destroyChart() {
        if (activeChart) { activeChart.destroy(); activeChart = null; }
        const existing = Chart.getChart(canvas);
        if (existing) existing.destroy();
    }

    function openModal(level, bucket) {
        titleEl.textContent = 'Historia: ' + bucket;
        emptyEl.hidden = true;
        canvas.style.display = 'none';
        modal.hidden = false;
        destroyChart();

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
                activeChart = new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: d.labels,
                        datasets: [
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
                                yAxisID: 'y',
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
                        ],
                    },
                    options: {
                        responsive: true,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend: { display: true } },
                        scales: {
                            y: {
                                position: 'left',
                                title: { display: true, text: 'Mnożnik (×)' },
                            },
                            y1: {
                                position: 'right',
                                title: { display: true, text: 'GM%' },
                                min: 0,
                                max: 100,
                                grid: { drawOnChartArea: false },
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
            if (level && bucket) openModal(level, bucket);
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

})();
