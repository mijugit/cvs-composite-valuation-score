<section class="dashboard">
    <?php if (!($emailVerified ?? true)): ?>
    <div class="alert" style="margin-bottom:1rem;background:rgba(224,82,82,.15);border:1px solid rgba(224,82,82,.3);border-radius:var(--radius);padding:.75rem 1rem;font-size:var(--text-sm);">
        &#9888; <strong>Potwierdź adres e-mail</strong>, by włączyć alerty.
        <a href="/auth/check-email" style="margin-left:.75rem;color:var(--c-primary);">Wyślij link ponownie</a>
    </div>
    <?php endif; ?>
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
            if (!d.ok) {
                if (d.needs_verification) {
                    var errEl = document.getElementById('error-msg');
                    if (errEl) { errEl.textContent = d.message; errEl.hidden = false; }
                }
                return;
            }
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
        <?php
        // Maps a recommendation label to the same border-colour class used on the
        // chip itself, so the tooltip's CVS Swing/Fund numbers get matching colours.
        $recoToClass = static fn(string $reco): string => match (true) {
            str_contains($reco, 'SILNE KUPUJ') => 'reco--strong-buy',
            str_contains($reco, 'AKUMULUJ')    => 'reco--accumulate',
            str_contains($reco, 'REDUKUJ')     => 'reco--reduce',
            str_contains($reco, 'UNIKAJ')      => 'reco--avoid',
            default                            => '',
        };
        ?>
        <div class="watchlist-chips">
            <?php foreach ($watchlist ?? [] as $t):
                $reco      = $watchlistRecos[$t] ?? '';
                $recoClass = $recoToClass($reco);
                $info      = $watchlistInfo[$t] ?? [];
                $name      = $info['companyName'] ?? null;
                $cvsSwing  = $info['cvsSwing'] ?? null;
                $cvsFund   = $info['cvsFund']  ?? null;
                $swingCls  = $recoToClass((string) ($info['recoSwing'] ?? ''));
                $fundCls   = $recoToClass((string) ($info['recoFund']  ?? ''));
            ?>
            <span class="watchlist-chip <?= $recoClass ?>" data-ticker="<?= htmlspecialchars($t) ?>">
                <?= htmlspecialchars($t) ?>
                <button class="watchlist-chip__remove"
                        data-ticker="<?= htmlspecialchars($t) ?>"
                        aria-label="Usuń <?= htmlspecialchars($t) ?>">&times;</button>
                <span class="watchlist-chip__tooltip">
                    <strong><?= htmlspecialchars($name ?? $t) ?></strong>
                    <?php if ($cvsSwing !== null || $cvsFund !== null): ?>
                    <span class="watchlist-chip__tooltip-scores">
                        <?php if ($cvsSwing !== null): ?>
                        <span class="<?= $swingCls ?>">CVS Swing <?= number_format($cvsSwing, 1) ?></span>
                        <?php endif; ?>
                        <?php if ($cvsFund !== null): ?>
                        <span class="<?= $fundCls ?>">CVS Fund <?= number_format($cvsFund, 1) ?></span>
                        <?php endif; ?>
                    </span>
                    <?php endif; ?>
                </span>
            </span>
            <?php endforeach; ?>
        </div>
    </div>

    <?php /* Confirmation modal for removing a ticker from the watchlist */ ?>
    <div class="ai-modal" id="watchlist-remove-modal" hidden>
        <div class="ai-modal__inner">
            <p>Czy na pewno chcesz usunąć <strong id="watchlist-remove-ticker"></strong> z listy obserwowanych?</p>
            <div style="display:flex; gap:.5rem; justify-content:center; margin-top:1rem;">
                <button type="button" class="btn btn--ghost" id="watchlist-remove-cancel">Anuluj</button>
                <button type="button" class="btn btn--primary" id="watchlist-remove-confirm">Usuń</button>
            </div>
        </div>
    </div>

    <div class="analysis-form-wrapper card">
        <h2>Wprowadź symbole spółek</h2>
        <p class="hint">Wpisz do 10 tickerów (NYSE / NASDAQ), oddzielonych przecinkami lub spacjami.<br>
           Przykład: <code>AAPL, MSFT, NVDA</code></p>

        <form id="analysis-form" class="form">
            <input type="hidden" id="csrf-token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

            <?php
            // Cache-busting for the autocomplete data fetch (app.js) — same
            // filemtime pattern as $asset() in layout.php, but tickers.json is
            // fetched dynamically by JS rather than linked in <head>, so it needs
            // its own version stamp passed through a data attribute. Without this,
            // a ticker added via /admin/tickers can sit in a stale browser cache
            // of the JSON response and never appear in the dropdown.
            $tickersJsonPath    = dirname(__DIR__) . '/public/data/tickers.json';
            $tickersJsonVersion = is_file($tickersJsonPath) ? filemtime($tickersJsonPath) : time();
            ?>
            <div class="form-group">
                <label for="tickers">Tickery</label>
                <textarea id="tickers" name="tickers" rows="3" placeholder="AAPL, MSFT, NVDA"
                          data-tickers-version="<?= $tickersJsonVersion ?>"></textarea>
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

    <?php /* Analysis history — collapsible accordion, newest first */ ?>
    <?php if (!empty($history)): ?>
    <div class="history-section card history-accordion">
        <button class="history-accordion__toggle" aria-expanded="false" aria-controls="history-body">
            <span class="history-accordion__title">
                Ostatnie analizy
                <span class="history-accordion__count"><?= count($history) ?></span>
            </span>
            <span class="history-accordion__arrow">▼</span>
        </button>
        <div class="history-accordion__body" id="history-body" hidden>
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
                        <td><?= htmlspecialchars(date('d.m.y', strtotime((string) $row['analysed_at']))) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <script>
    (function () {
        var toggle = document.querySelector('.history-accordion__toggle');
        var body   = document.getElementById('history-body');
        if (!toggle || !body) return;
        toggle.addEventListener('click', function () {
            var open = toggle.getAttribute('aria-expanded') === 'true';
            toggle.setAttribute('aria-expanded', open ? 'false' : 'true');
            body.hidden = open;
            toggle.querySelector('.history-accordion__arrow').textContent = open ? '▼' : '▲';
        });
    })();
    </script>
    <?php endif; ?>
</section>
