<section class="dashboard">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1rem;">
        <h1 style="margin:0;">Panel analizy CVS</h1>
        <div style="display:flex;align-items:center;gap:.5rem;font-size:var(--text-sm);">
            <span style="color:var(--c-muted);">Alerty:</span>
            <button id="btn-alerts-global"
                    class="btn btn--sm <?= ($alertsEnabled ?? false) ? 'btn--primary' : 'btn--ghost' ?>"
                    data-enabled="<?= ($alertsEnabled ?? false) ? '1' : '0' ?>"
                    title="<?= ($alertsEnabled ?? false) ? 'Wyłącz alerty' : 'Włącz alerty email przy zmianie stanu spółki' ?>">
                <?= ($alertsEnabled ?? false) ? '🔔 ON' : '🔕 OFF' ?>
            </button>
        </div>
    </div>
    <script>
    document.getElementById('btn-alerts-global')?.addEventListener('click', function () {
        var btn  = this;
        var csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
        fetch('/alerts/global', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf},
            body: new URLSearchParams({_csrf: csrf}),
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) return;
            btn.dataset.enabled = d.enabled ? '1' : '0';
            btn.textContent = d.enabled ? '🔔 ON' : '🔕 OFF';
            btn.className   = 'btn btn--sm ' + (d.enabled ? 'btn--primary' : 'btn--ghost');
            btn.title = d.enabled ? 'Wyłącz alerty' : 'Włącz alerty email przy zmianie stanu spółki';
        });
    });
    </script>

    <?php /* Watchlist section — always rendered; hidden when empty so JS can reveal it */ ?>
    <div class="watchlist-section card"
         data-watchlist='<?= json_encode($watchlist ?? []) ?>'
         <?= empty($watchlist) ? 'hidden' : '' ?>>
        <h3>Obserwowane</h3>
        <div class="watchlist-chips">
            <?php foreach ($watchlist ?? [] as $t): ?>
            <span class="watchlist-chip" data-ticker="<?= htmlspecialchars($t) ?>">
                <?= htmlspecialchars($t) ?>
                <button class="watchlist-chip__remove"
                        data-ticker="<?= htmlspecialchars($t) ?>"
                        aria-label="Usuń <?= htmlspecialchars($t) ?>">&times;</button>
            </span>
            <?php endforeach; ?>
        </div>
    </div>

    <?php /* Analysis history (S-08) — last N analyses, newest first; hidden when empty */ ?>
    <?php if (!empty($history)): ?>
    <div class="history-section card">
        <h3>Ostatnie analizy</h3>
        <table class="history-table">
            <thead>
                <tr>
                    <th>Ticker</th>
                    <th>CVS Swing</th>
                    <th>Rekomendacja</th>
                    <th>Data</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($history as $row): ?>
                <?php $passed = (int) ($row['quality_gate'] ?? 0) === 1; ?>
                <tr<?= $passed ? '' : ' class="gate-fail"' ?>>
                    <td><a href="/analysis/<?= urlencode((string) $row['ticker']) ?>"><?= htmlspecialchars((string) $row['ticker']) ?></a></td>
                    <td><?= $row['cvs_swing'] !== null ? number_format((float) $row['cvs_swing'], 1) : '—' ?></td>
                    <td><?= $passed ? htmlspecialchars((string) ($row['reco_swing'] ?? '—')) : 'Odrzucono' ?></td>
                    <td><?= htmlspecialchars(date('d.m', strtotime((string) $row['analysed_at']))) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

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
