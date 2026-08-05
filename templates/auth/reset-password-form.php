<?php declare(strict_types=1);
/** @var string $token */
/** @var string|null $error */
?>
<section class="auth-box">
    <h1>Ustaw nowe hasło</h1>

    <?php if (!empty($error)): ?>
        <p class="alert alert--error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST" action="/auth/reset-password" class="form">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

        <div class="form-group">
            <label for="password">Nowe hasło <small>(min. 8 znaków)</small></label>
            <input id="password" type="password" name="password" required autocomplete="new-password" minlength="8">
        </div>

        <div class="form-group">
            <label for="password_confirm">Powtórz nowe hasło</label>
            <input id="password_confirm" type="password" name="password_confirm" required autocomplete="new-password" minlength="8">
        </div>

        <button type="submit" class="btn btn--primary">Zmień hasło</button>
    </form>

    <p class="auth-switch"><a href="/login">← Wróć do logowania</a></p>
</section>
