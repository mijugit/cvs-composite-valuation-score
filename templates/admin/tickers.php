<?php declare(strict_types=1);

use CVS\Screener\MarketResolver;

/**
 * Admin: add tickers to the screener universe (public/data/tickers.json).
 * Variables injected by TickersController::index():
 *   $tickers       — array<int, array{symbol: string, name: string}>
 *   $flash         — string|null
 *   $marketsConfig — array{default_label?: string, labels?: array<string, string>} (config/cvs-weights.php -> markets)
 */
?>

<h1 style="margin-bottom:1rem;">Tickery — uniwersum screenera</h1>

<?php if (!empty($flash)): ?>
    <div class="alert alert--success" style="margin-bottom:1rem;">
        <?= htmlspecialchars((string) $flash) ?>
    </div>
<?php endif; ?>

<!-- ====================================================== -->
<!-- Dodaj ticker                                           -->
<!-- ====================================================== -->

<div class="card" style="margin-bottom:2rem;max-width:560px;">
    <h2 style="margin-bottom:1rem;font-size:var(--text-lg);">Dodaj spółkę</h2>
    <p style="color:var(--c-muted);font-size:.875rem;margin-bottom:1rem;">
        Wklej adres notowania z Yahoo Finance (np. <code>https://finance.yahoo.com/quote/PKN.WA/</code>)
        albo sam ticker (np. <code>PKN.WA</code>).
    </p>
    <form method="POST" action="/admin/tickers/add" class="form">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="form-group">
            <label for="url">URL Yahoo Finance lub ticker <span style="color:var(--c-danger)">*</span></label>
            <input id="url" type="text" name="url" placeholder="https://finance.yahoo.com/quote/SPCX/" required>
        </div>

        <button type="submit" class="btn btn--primary btn--sm">Dodaj</button>
    </form>
</div>

<!-- ====================================================== -->
<!-- Lista tickerów                                         -->
<!-- ====================================================== -->

<div class="card">
    <h2 style="margin-bottom:1rem;font-size:var(--text-lg);">
        Lista tickerów (<?= count($tickers) ?>)
    </h2>

    <table class="pillar-table" style="width:100%;">
        <thead>
            <tr>
                <th style="width:15%;">Ticker</th>
                <th>Nazwa</th>
                <th style="width:20%;">Rynek</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tickers as $t): ?>
            <tr>
                <td><code><?= htmlspecialchars((string) $t['symbol']) ?></code></td>
                <td><?= htmlspecialchars((string) $t['name']) ?></td>
                <td style="color:var(--c-muted);font-size:var(--text-sm);">
                    <?= htmlspecialchars(MarketResolver::labelForTicker((string) $t['symbol'], $marketsConfig)) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
