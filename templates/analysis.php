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
            <div class="card card--result">
                <div class="cvs-score-header">
                    <span class="cvs-badge"><?= htmlspecialchars($result['recommendation']) ?></span>
                    <span class="cvs-number"><?= number_format((float)$result['cvs'], 1) ?> / 100</span>
                </div>

                <h3>Składowe filary</h3>
                <table class="pillar-table">
                    <thead>
                        <tr>
                            <th>Filar</th>
                            <th>Wynik (0–100)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $pillarLabels = [
                            'growth'  => '(a) Wzrost vs własna trajektoria',
                            'sector'  => '(b) Benchmark sektorowy',
                            'history' => '(c) Percentyl cenowy',
                            'quality' => '(d) Jakość fundamentalna',
                        ];
                        foreach ($result['pillar_scores'] as $key => $score):
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($pillarLabels[$key] ?? $key) ?></td>
                            <td>
                                <div class="pillar-bar">
                                    <div class="pillar-bar__fill" style="width:<?= round((float)$score) ?>%"></div>
                                    <span><?= number_format((float)$score, 1) ?></span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p class="disclaimer-inline"><?= htmlspecialchars($result['disclaimer']) ?></p>
        <?php endif; ?>

    <?php endif; ?>

    <p><a href="/dashboard">&larr; Powrót do panelu</a></p>
</section>
