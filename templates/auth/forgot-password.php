<?php declare(strict_types=1);
/** @var string|null $error */
?>
<section class="auth-box">
    <h1>Reset hasła</h1>

    <?php if (!empty($error)): ?>
        <p class="alert alert--error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <p style="color:var(--c-text-muted);margin-bottom:1rem;">
        Podaj adres e-mail konta — wyślemy link do ustawienia nowego hasła.
    </p>

    <form method="POST" action="/auth/forgot-password" class="form">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="form-group">
            <label for="email">E-mail</label>
            <input id="email" type="email" name="email" required autocomplete="email">
        </div>

        <button type="submit" class="btn btn--primary">Wyślij link do resetu hasła</button>
    </form>

    <p class="auth-switch"><a href="/login">← Wróć do logowania</a></p>
</section>
