<section class="auth-box">
    <h1>Rejestracja</h1>

    <?php if (!empty($error)): ?>
        <p class="alert alert--error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST" action="/register" class="form">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="form-group">
            <label for="email">E-mail</label>
            <input id="email" type="email" name="email" required autocomplete="email">
        </div>

        <div class="form-group">
            <label for="password">Hasło <small>(min. 8 znaków)</small></label>
            <input id="password" type="password" name="password" required autocomplete="new-password" minlength="8">
        </div>

        <div class="form-group">
            <label for="password_confirm">Powtórz hasło</label>
            <input id="password_confirm" type="password" name="password_confirm" required autocomplete="new-password" minlength="8">
        </div>

        <button type="submit" class="btn btn--primary">Utwórz konto</button>
    </form>

    <p class="auth-switch">Masz już konto? <a href="/login">Zaloguj się</a></p>
    <p class="auth-switch">Ciekawi Cię metodologia? <a href="/model">Jak działa model CVS →</a></p>
</section>
