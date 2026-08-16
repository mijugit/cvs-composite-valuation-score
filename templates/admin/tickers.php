<?php declare(strict_types=1);

use CVS\Screener\MarketResolver;

/**
 * Admin: add tickers to the screener universe (public/data/tickers.json).
 * Variables injected by TickersController::index():
 *   $tickers       — array<int, array{symbol: string, name: string}>
 *   $flash         — string|null
 *   $marketsConfig — array{default_label?: string, labels?: array<string, string>} (config/cvs-weights.php -> markets)
 *   $overrides     — array<int, array<string, mixed>> admin-defined peer groups (migration 037)
 *   $dueForReview  — array<int, array<string, mixed>> overrides whose review_date has passed
 *   $classification — array<string, array{sector: ?string, industry: ?string, score_date: string}>
 *   $bucketOptions  — array<int, array{key: string, count: int, custom: bool}> selectable peer groups
 *   $minSampleCount — int, peer_group.min_sample_count
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
<!-- Grupy porównawcze (nadpisanie klasyfikacji Yahoo)      -->
<!-- ====================================================== -->

<div class="card" style="margin-bottom:2rem;">
    <h2 style="margin-bottom:.5rem;font-size:var(--text-lg);">Grupy porównawcze</h2>
    <p style="color:var(--c-muted);font-size:.875rem;margin-bottom:1rem;max-width:70ch;">
        Yahoo klasyfikuje po formie korporacyjnej, nie po tym, z kim spółka realnie konkuruje.
        Tu przypiszesz ją do własnej grupy. Wpisanie <strong>istniejącej nazwy branży</strong>
        przeklasyfikowuje spółkę; wpisanie <strong>nowej</strong> tworzy własną grupę.
        Klasyfikacja Yahoo pozostaje nietknięta — zmienia się wyłącznie mediana, do której
        porównywany jest filar Wyceny, a każdy snapshot zapisuje użyty kubełek.
    </p>
    <p style="color:var(--c-warn,#f59e0b);font-size:.8rem;margin-bottom:1rem;max-width:70ch;">
        ⚠ Grupa musi mieścić się w <strong>jednym sektorze Yahoo</strong>. Crawl median liczy
        kubełki sektor po sektorze i nadpisuje je po każdym przebiegu, więc grupa rozpięta na
        dwa sektory byłaby naprzemiennie kasowana. Potrzebne jest też min. 5 spółek — poniżej
        progu resolver i tak wróci do mediany sektorowej.
    </p>

    <?php if (!empty($dueForReview)): ?>
    <div class="alert" style="background:rgba(245,158,11,.15);border:1px solid rgba(245,158,11,.3);margin-bottom:1rem;">
        <strong>Do przeglądu:</strong>
        <?= htmlspecialchars(implode(', ', array_map(static fn(array $o): string => (string) $o['ticker'] . ' (' . (string) $o['review_date'] . ')', $dueForReview))) ?>
        — grupowanie oparte na dominacji segmentu jest zależne od cyklu i minął jego termin ważności.
    </div>
    <?php endif; ?>

    <form method="POST" action="/admin/tickers/peer-group" class="form" style="max-width:560px;margin-bottom:1.5rem;">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <div class="form-group">
            <label for="pg-ticker">Ticker <span style="color:var(--c-danger)">*</span></label>
            <input id="pg-ticker" type="text" name="ticker" placeholder="MU" required>
        </div>
        <div class="form-group">
            <label for="pg-bucket">Grupa porównawcza <span style="color:var(--c-danger)">*</span></label>
            <select id="pg-bucket" name="bucket_key" required>
                <option value="">— wybierz grupę —</option>
                <?php foreach ($bucketOptions as $b): ?>
                <option value="<?= htmlspecialchars($b['key']) ?>">
                    <?= htmlspecialchars($b['key']) ?>
                    (n=<?= $b['count'] ?><?= $b['count'] < $minSampleCount ? ' — poniżej progu' : '' ?><?= $b['custom'] ? ', własna' : '' ?>)
                </option>
                <?php endforeach; ?>
                <option value="__new__">➕ nowa grupa…</option>
            </select>
            <p class="hint" style="margin-top:.35rem;">
                Wybór z listy zamiast wpisywania — literówka stworzyłaby po cichu nową grupę
                z jedną spółką, która i tak wróciłaby do mediany sektorowej.
                <strong>n</strong> to liczba spółek w kubełku; poniżej <?= (int) $minSampleCount ?>
                resolver użyje sektora niezależnie od przypisania.
            </p>
        </div>

        <div class="form-group" id="pg-new-wrap" hidden>
            <label for="pg-bucket-new">Nazwa nowej grupy</label>
            <input id="pg-bucket-new" type="text" name="bucket_key_new" placeholder="Memory &amp; Storage">
        </div>
        <div class="form-group">
            <label for="pg-reason">Uzasadnienie <span style="color:var(--c-danger)">*</span></label>
            <input id="pg-reason" type="text" name="reason" placeholder="Konkuruje w DRAM/NAND — dział pamięci dominuje przychody w tym cyklu" required>
        </div>
        <div class="form-group">
            <label for="pg-review">Data przeglądu</label>
            <input id="pg-review" type="date" name="review_date">
            <p class="hint" style="margin-top:.35rem;">
                Zostaw puste dla grupowania <strong>strukturalnego</strong> (region, regulator — nie wygasa).
                Ustaw datę dla grupowania <strong>zależnego od cyklu</strong> (dominacja segmentu), żeby
                nie zestarzało się po cichu.
            </p>
        </div>
        <button type="submit" class="btn btn--primary btn--sm">Przypisz</button>
    </form>

    <script>
    (function () {
        var sel  = document.getElementById('pg-bucket');
        var wrap = document.getElementById('pg-new-wrap');
        var txt  = document.getElementById('pg-bucket-new');
        if (!sel || !wrap || !txt) return;
        sel.addEventListener('change', function () {
            var isNew = sel.value === '__new__';
            wrap.hidden   = !isNew;
            txt.required  = isNew;
            if (isNew) txt.focus();
        });
    }());
    </script>

    <?php if (empty($overrides)): ?>
    <p style="color:var(--c-muted);font-size:.875rem;">Brak nadpisań — wszystkie spółki używają klasyfikacji Yahoo.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="pillar-table" style="width:100%;">
            <thead>
                <tr>
                    <th>Ticker</th><th>Grupa</th><th>Uzasadnienie</th><th>Przegląd</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($overrides as $o): ?>
                <?php $overdue = !empty($o['review_date']) && (string) $o['review_date'] <= date('Y-m-d'); ?>
                <tr>
                    <td><strong><?= htmlspecialchars((string) $o['ticker']) ?></strong></td>
                    <td><?= htmlspecialchars((string) $o['bucket_key']) ?></td>
                    <td style="font-size:var(--text-xs);color:var(--c-muted);max-width:340px;white-space:normal;">
                        <?= htmlspecialchars((string) ($o['reason'] ?? '')) ?>
                    </td>
                    <td style="font-size:var(--text-xs);<?= $overdue ? 'color:var(--c-warn,#f59e0b);font-weight:600;' : 'color:var(--c-muted);' ?>">
                        <?= $o['review_date'] !== null ? htmlspecialchars((string) $o['review_date']) : 'strukturalne' ?>
                    </td>
                    <td>
                        <form method="POST" action="/admin/tickers/peer-group/delete" style="margin:0;">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            <input type="hidden" name="ticker" value="<?= htmlspecialchars((string) $o['ticker']) ?>">
                            <button type="submit" class="btn btn--ghost btn--sm">Usuń</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ====================================================== -->
<!-- Lista tickerów                                         -->
<!-- ====================================================== -->

<?php
    // ticker => custom bucket, for the "Branża" column below.
    $overrideMap = [];
    foreach ($overrides as $o) {
        $overrideMap[strtoupper((string) $o['ticker'])] = (string) $o['bucket_key'];
    }
?>
<div class="card">
    <h2 style="margin-bottom:1rem;font-size:var(--text-lg);">
        Lista tickerów (<?= count($tickers) ?>)
    </h2>

    <table class="pillar-table" style="width:100%;">
        <thead>
            <tr>
                <th style="width:12%;">Ticker</th>
                <th>Nazwa</th>
                <th style="width:16%;">Sektor</th>
                <th style="width:20%;">Branża (Yahoo)</th>
                <th style="width:14%;">Rynek</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tickers as $t): ?>
            <?php
                $sym  = strtoupper((string) $t['symbol']);
                $cls  = $classification[$sym] ?? null;
                // An override changes which bucket this company is benchmarked
                // against; the Yahoo column keeps showing the untouched source.
                $ovrB = $overrideMap[$sym] ?? null;
            ?>
            <tr>
                <td><code><?= htmlspecialchars((string) $t['symbol']) ?></code></td>
                <td><?= htmlspecialchars((string) $t['name']) ?></td>
                <td style="color:var(--c-muted);font-size:var(--text-sm);">
                    <?= $cls !== null && $cls['sector'] !== null ? htmlspecialchars($cls['sector']) : '<span style="opacity:.5;">—</span>' ?>
                </td>
                <td style="font-size:var(--text-sm);">
                    <?php if ($ovrB !== null): ?>
                        <span style="color:var(--c-muted);text-decoration:line-through;">
                            <?= $cls !== null && $cls['industry'] !== null ? htmlspecialchars($cls['industry']) : '—' ?>
                        </span><br>
                        <span class="peer-badge" style="margin-left:0;">◍ <?= htmlspecialchars($ovrB) ?></span>
                    <?php else: ?>
                        <span style="color:var(--c-muted);">
                            <?= $cls !== null && $cls['industry'] !== null ? htmlspecialchars($cls['industry']) : '<span style="opacity:.5;">—</span>' ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--c-muted);font-size:var(--text-sm);">
                    <?= htmlspecialchars(MarketResolver::labelForTicker((string) $t['symbol'], $marketsConfig)) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
