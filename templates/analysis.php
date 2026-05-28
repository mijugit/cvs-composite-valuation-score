<section class="analysis-detail">
    <h1>Analiza: <?= htmlspecialchars($ticker) ?></h1>

    <?php if (!empty($error)): ?>
        <p class="alert alert--error"><?= htmlspecialchars($error) ?></p>
    <?php elseif ($result !== null): ?>

        <?php if (!$result['quality_gate']): ?>
            <div class="card card--fail">
                <h2>Odrzucono przez Quality Gate</h2>
                <p>Spółka nie spełnia minimalnych wymagań jakościowych. CVS nie zostało wyliczone.</p>
                <ul class="failure-list">
                    <?php foreach ($result['gate_failures'] as $fail): ?>
                        <li><?= htmlspecialchars($fail) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php else: ?>

            <?php
            // Helper: format raw financial values (B/M/K + ratio %)
            $ratioKeys = ['gross_margins', 'revenue_growth', 'return_on_equity'];
            $fmtRaw = static function (string $key, $val) use ($ratioKeys): string {
                if (is_bool($val)) return $val ? 'tak' : 'nie';
                if (!is_numeric($val)) return htmlspecialchars((string)$val);
                $f = (float) $val;
                if (in_array($key, $ratioKeys, true)) {
                    return number_format($f * 100, 1) . '%';
                }
                $abs = abs($f);
                if ($abs >= 1_000_000_000) return number_format($f / 1_000_000_000, 2) . ' B';
                if ($abs >= 1_000_000)     return number_format($f / 1_000_000, 1) . ' M';
                if ($abs >= 1_000)         return number_format($f / 1_000, 1) . ' K';
                if ($abs < 100)            return number_format($f, 2);
                return number_format($f, 0);
            };

            // Helper: recommendation → CSS class
            $swing = $result['swing'] ?? [];
            $fund  = $result['fundamental'] ?? [];
            $ps    = $result['pillar_scores'] ?? [];
            $gs    = $result['golden_signal'] ?? null;

            $gsLabels = [
                'strong'    => ['stars' => '⭐⭐', 'label' => 'Silny sygnał — wartość i momentum'],
                'watchlist' => ['stars' => '⭐',   'label' => 'Setup — czekaj na momentum'],
                'momentum'  => ['stars' => '',     'label' => 'Momentum — nie value'],
            ];

            $swingWeights = [
                'Wycena'   => '40%',
                'Momentum' => '45%',
                'Jakość'   => '15%',
            ];
            $fundWeights = [
                'Wycena'   => '65%',
                'Momentum' => '15%',
                'Jakość'   => '20%',
            ];

            $cfgFile   = require dirname(__DIR__) . '/config/cvs-weights.php';
            $modeSwing = $cfgFile['modes']['swing']       ?? [];
            $modeFund  = $cfgFile['modes']['fundamental'] ?? [];
            ?>

            <!-- Dual CVS score header -->
            <div class="card card--result">
                <?php if ($gs && isset($gsLabels[$gs])): ?>
                    <div class="golden-signal golden-signal--<?= htmlspecialchars($gs) ?>">
                        <?= $gsLabels[$gs]['stars'] ? htmlspecialchars($gsLabels[$gs]['stars']) . ' ' : '' ?>
                        <?= htmlspecialchars($gsLabels[$gs]['label']) ?>
                    </div>
                <?php endif; ?>

                <div class="dual-cvs-header">
                    <!-- Swing score -->
                    <div class="cvs-mode-tile cvs-mode-tile--swing">
                        <div class="cvs-mode-tile__label"><?= htmlspecialchars($modeSwing['label'] ?? 'Swing') ?></div>
                        <div class="cvs-mode-tile__score"><?= number_format((float)($swing['cvs'] ?? 0), 1) ?></div>
                        <div class="cvs-mode-tile__reco">
                            <span class="cvs-badge"><?= htmlspecialchars($swing['recommendation'] ?? '') ?></span>
                        </div>
                    </div>

                    <!-- Fundamental score -->
                    <div class="cvs-mode-tile cvs-mode-tile--fund">
                        <div class="cvs-mode-tile__label"><?= htmlspecialchars($modeFund['label'] ?? 'Fundamentalny') ?></div>
                        <div class="cvs-mode-tile__score"><?= number_format((float)($fund['cvs'] ?? 0), 1) ?></div>
                        <div class="cvs-mode-tile__reco">
                            <span class="cvs-badge cvs-badge--fund"><?= htmlspecialchars($fund['recommendation'] ?? '') ?></span>
                        </div>
                    </div>
                </div>

                <!-- Radar chart with two lines -->
                <div class="detail-radar-wrapper">
                    <canvas id="detail-radar" width="300" height="300"></canvas>
                    <div class="detail-radar-legend">
                        <span class="legend-dot legend-dot--swing"></span> Swing &nbsp;
                        <span class="legend-dot legend-dot--fund"></span> Fundamentalny
                    </div>
                </div>

                <!-- Pillar table with weights -->
                <h3>Składowe filary</h3>
                <table class="pillar-table">
                    <thead>
                        <tr>
                            <th>Filar</th>
                            <th>Wynik (0–100)</th>
                            <th>Waga Swing</th>
                            <th>Waga Fund</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $pillarRows = [
                            ['key' => 'valuation',      'label' => 'Wycena (EV/FCF)',     'sw' => '40%', 'fn' => '65%'],
                            ['key' => 'momentum_swing', 'label' => 'Momentum (Swing)',     'sw' => '45%', 'fn' => '—'],
                            ['key' => 'momentum_fund',  'label' => 'Momentum (Fund)',      'sw' => '—',   'fn' => '15%'],
                            ['key' => 'quality',        'label' => 'Jakość fundamentalna', 'sw' => '15%', 'fn' => '20%'],
                        ];
                        foreach ($pillarRows as $row):
                            $score = $ps[$row['key']] ?? null;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($row['label']) ?></td>
                            <td>
                                <?php if ($score !== null): ?>
                                <div class="pillar-bar">
                                    <div class="pillar-bar__fill" style="width:<?= min(100, round((float)$score)) ?>%"></div>
                                    <span><?= number_format((float)$score, 1) ?></span>
                                </div>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($row['sw']) ?></td>
                            <td><?= htmlspecialchars($row['fn']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th colspan="1">CVS Swing</th>
                            <td colspan="3">
                                <strong><?= number_format((float)($swing['cvs'] ?? 0), 1) ?></strong>
                                — <?= htmlspecialchars($swing['recommendation'] ?? '') ?>
                            </td>
                        </tr>
                        <tr>
                            <th colspan="1">CVS Fund</th>
                            <td colspan="3">
                                <strong><?= number_format((float)($fund['cvs'] ?? 0), 1) ?></strong>
                                — <?= htmlspecialchars($fund['recommendation'] ?? '') ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>

                <!-- Raw financial data -->
                <?php if (!empty($financials)): ?>
                <details class="raw-data">
                    <summary>Dane źródłowe (surowe)</summary>
                    <table class="pillar-table raw-table">
                        <tbody>
                            <?php
                            $rawFields = [
                                'current_price'       => 'Cena bieżąca ($)',
                                'shares_outstanding'  => 'Liczba akcji',
                                'revenue'             => 'Przychody ($)',
                                'ebitda'              => 'EBITDA ($)',
                                'free_cash_flow'      => 'FCF efektywny ($)',
                                'free_cash_flow_raw'  => 'FCF raportowany ($)',
                                'operating_cash_flow' => 'OpCF ($)',
                                'free_cash_flow_adjusted' => 'FCF adjusted (OpCF fallback)',
                                'total_debt'          => 'Dług całkowity ($)',
                                'cash'                => 'Gotówka ($)',
                                'gross_margins'       => 'Marża brutto',
                                'revenue_growth'      => 'Wzrost przychodów',
                                'return_on_equity'    => 'ROE',
                                'forward_eps'         => 'EPS forward',
                                'trailing_eps'        => 'EPS trailing',
                                'sector'              => 'Sektor',
                            ];
                            foreach ($rawFields as $key => $label):
                                $val = $financials[$key] ?? null;
                                if ($val === null) continue;
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($label) ?></td>
                                <td>
                                    <?= $fmtRaw($key, $val) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
                <?php endif; ?>
            </div>

            <p class="disclaimer-inline"><?= htmlspecialchars($result['disclaimer']) ?></p>

            <!-- Radar chart initialisation -->
            <script>
            window.addEventListener('load', function () {
                if (typeof Chart === 'undefined') return;
                const ctx = document.getElementById('detail-radar');
                if (!ctx) return;
                const ps = <?= json_encode($ps) ?>;
                new Chart(ctx.getContext('2d'), {
                    type: 'radar',
                    data: {
                        labels: ['Wycena', 'Momentum', 'Jakość'],
                        datasets: [
                            {
                                label: 'Swing',
                                data: [ps.valuation ?? 0, ps.momentum_swing ?? 0, ps.quality ?? 0],
                                borderColor:     'rgba(79, 142, 247, 0.9)',
                                backgroundColor: 'rgba(79, 142, 247, 0.08)',
                                pointRadius: 3,
                                borderWidth: 2,
                            },
                            {
                                label: 'Fundamentalny',
                                data: [ps.valuation ?? 0, ps.momentum_fund ?? 0, ps.quality ?? 0],
                                borderColor:     'rgba(234, 179, 8, 0.9)',
                                backgroundColor: 'rgba(234, 179, 8, 0.08)',
                                pointRadius: 3,
                                borderWidth: 2,
                            },
                        ],
                    },
                    options: {
                        animation: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            r: {
                                min: 0,
                                max: 100,
                                ticks: { display: false, stepSize: 25 },
                                pointLabels: {
                                    font: { size: 11 },
                                    color: 'rgba(255,255,255,0.75)',
                                },
                                grid: { color: 'rgba(128,128,128,.15)' },
                            },
                        },
                    },
                });
            });
            </script>

        <?php endif; ?>

    <?php endif; ?>

    <p><a href="/dashboard">&larr; Powrót do panelu</a></p>
</section>

<style>
/* Dual CVS tiles */
.dual-cvs-header {
    display: flex;
    gap: 1rem;
    margin-bottom: 1.5rem;
    flex-wrap: wrap;
}
.cvs-mode-tile {
    flex: 1;
    min-width: 140px;
    background: var(--c-bg);
    border: 1px solid var(--c-border);
    border-radius: 8px;
    padding: 1rem;
    text-align: center;
}
.cvs-mode-tile--swing  { border-color: rgba(79, 142, 247, .4); }
.cvs-mode-tile--fund   { border-color: rgba(234, 179, 8, .4); }
.cvs-mode-tile__label  { font-size: .8rem; color: var(--c-muted); margin-bottom: .35rem; }
.cvs-mode-tile__score  { font-size: 2.2rem; font-weight: 700; }
.cvs-mode-tile--swing .cvs-mode-tile__score { color: var(--c-primary); }
.cvs-mode-tile--fund  .cvs-mode-tile__score { color: #eab308; }
.cvs-mode-tile__reco   { margin-top: .35rem; }

.cvs-badge--fund { background: rgba(234, 179, 8, .2); color: #eab308; }

/* Golden signal banner */
.golden-signal {
    font-size: .9rem;
    padding: .4rem 1rem;
    border-radius: 99px;
    display: inline-block;
    margin-bottom: 1rem;
}
.golden-signal--strong    { background: rgba(34,197,94,.12);  color: #22c55e; }
.golden-signal--watchlist { background: rgba(234,179,8,.12);  color: #eab308; }
.golden-signal--momentum  { background: rgba(79,142,247,.12); color: var(--c-primary); }

/* Radar */
.detail-radar-wrapper { display: flex; flex-direction: column; align-items: center; margin: 1.5rem 0; }
.detail-radar-legend  { font-size: .8rem; color: var(--c-muted); margin-top: .5rem; }
.legend-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; }
.legend-dot--swing { background: #4f8ef7; }
.legend-dot--fund  { background: #eab308; }

/* Raw data */
.raw-data { margin-top: 1.5rem; }
.raw-data summary { cursor: pointer; font-size: .85rem; color: var(--c-muted); margin-bottom: .5rem; }
.raw-table td:first-child { color: var(--c-muted); font-size: .82rem; width: 55%; }
</style>
