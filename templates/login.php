<section class="auth-box">
    <h1>Logowanie</h1>

    <?php if (!empty($error)): ?>
        <p class="alert alert--error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST" action="/login" class="form">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

        <div class="form-group">
            <label for="email">E-mail</label>
            <input id="email" type="email" name="email" required autocomplete="email">
        </div>

        <div class="form-group">
            <label for="password">Hasło</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">
        </div>

        <button type="submit" class="btn btn--primary">Zaloguj</button>
    </form>

    <p class="auth-switch">Nie masz konta? <a href="/register">Zarejestruj się</a></p>
</section>
