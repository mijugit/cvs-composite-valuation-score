<?php declare(strict_types=1); ?>

<h1 style="margin-bottom:1.5rem;">Panel PRO — Kody dostępu</h1>

<?php if (!empty($flash)): ?>
    <div class="alert alert--success" style="margin-bottom:1rem;">
        <?= htmlspecialchars((string) $flash) ?>
    </div>
<?php endif; ?>

<!-- ====================================================== -->
<!-- Dodaj nowy kod                                         -->
<!-- ====================================================== -->
<div class="card" style="margin-bottom:2rem;max-width:560px;">
    <h2 style="margin-bottom:1rem;font-size:var(--text-lg);">Dodaj kod PRO</h2>
    <form method="POST" action="/admin/pro" class="form">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="form-group">
            <label for="code">Kod <span style="color:var(--c-danger)">*</span></label>
            <input id="code" type="text" name="code" placeholder="np. CVS-BETA-2026" required
                   style="font-family:monospace;">
        </div>

        <div class="form-group">
            <label for="user_id">Przypisz do użytkownika (opcjonalne — puste = globalny)</label>
            <select id="user_id" name="user_id">
                <option value="">— globalny (dla wszystkich) —</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= (int) $u['id'] ?>"><?= htmlspecialchars((string) $u['email']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label for="description">Opis (opcjonalny)</label>
            <input id="description" type="text" name="description" placeholder="np. Znajomy Marek">
        </div>

        <button type="submit" class="btn btn--primary btn--sm">Dodaj kod</button>
    </form>
</div>

<!-- ====================================================== -->
<!-- Lista kodów                                            -->
<!-- ====================================================== -->
<div class="card">
    <h2 style="margin-bottom:1rem;font-size:var(--text-lg);">Lista kodów PRO</h2>

    <?php if (empty($codes)): ?>
        <p style="color:var(--c-muted);">Brak kodów PRO. Dodaj pierwszy kod powyżej.</p>
    <?php else: ?>
    <table class="pillar-table" style="width:100%;">
        <thead>
            <tr>
                <th>Kod</th>
                <th>Użytkownik</th>
                <th>Opis</th>
                <th>Status</th>
                <th>Dodano</th>
                <th>Akcja</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($codes as $c): ?>
            <tr>
                <td><code style="font-size:var(--text-sm);"><?= htmlspecialchars((string) $c['code']) ?></code></td>
                <td style="color:var(--c-muted);font-size:var(--text-sm);">
                    <?= $c['user_email'] ? htmlspecialchars((string) $c['user_email']) : '— globalny —' ?>
                </td>
                <td style="color:var(--c-muted);font-size:var(--text-sm);">
                    <?= htmlspecialchars((string) ($c['description'] ?? '')) ?>
                </td>
                <td>
                    <?php if ((int) $c['is_active'] === 1): ?>
                        <span class="signal-pill signal-pill--strong">Aktywny</span>
                    <?php else: ?>
                        <span class="signal-pill signal-pill--watchlist" style="opacity:.6;">Unieważniony</span>
                    <?php endif; ?>
                </td>
                <td style="color:var(--c-muted);font-size:var(--text-sm);">
                    <?= htmlspecialchars(substr((string) $c['created_at'], 0, 10)) ?>
                </td>
                <td>
                    <?php if ((int) $c['is_active'] === 1): ?>
                    <form method="POST" action="/admin/pro/revoke" style="display:inline;">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="btn btn--danger btn--sm"
                                onclick="return confirm('Unieważnić kod <?= htmlspecialchars((string) $c['code']) ?>?')">
                            Unieważnij
                        </button>
                    </form>
                    <?php else: ?>
                    <form method="POST" action="/admin/pro/activate-code" style="display:inline;">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <button type="submit" class="btn btn--ghost btn--sm">Przywróć</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<p style="margin-top:1.5rem;"><a href="/dashboard">&larr; Powrót do panelu</a></p>

<p class="disclaimer-inline">
    Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna. Inwestuj świadomie.
</p>
