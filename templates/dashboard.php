<section class="dashboard">
    <h1>Panel analizy CVS</h1>

    <div class="analysis-form-wrapper card">
        <h2>Wprowadź symbole spółek</h2>
        <p class="hint">Wpisz do 10 tickerów (NYSE / NASDAQ), oddzielonych przecinkami lub spacjami.<br>
           Przykład: <code>AAPL, MSFT, NVDA</code></p>

        <form id="analysis-form" class="form">
            <input type="hidden" id="csrf-token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

            <div class="form-group">
                <label for="tickers">Tickery</label>
                <textarea id="tickers" name="tickers" rows="3" placeholder="AAPL, MSFT, NVDA"></textarea>
            </div>

            <button type="submit" class="btn btn--primary" id="analyse-btn">
                Analizuj
            </button>
        </form>
    </div>

    <div id="results-section" class="results-section" hidden>
        <h2>Wyniki</h2>
        <div id="results-grid" class="results-grid"></div>

        <p class="disclaimer-inline">
            Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.
        </p>
    </div>

    <div id="spinner" class="spinner" hidden>Pobieram dane&hellip;</div>
    <div id="error-msg" class="alert alert--error" hidden></div>
</section>
