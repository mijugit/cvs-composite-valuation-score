<?php
/** @var array{valuation: int, quality: int, valuation_weight: float, quality_weight: float, honeypot_field: string} $captcha */
$valWeightPct  = number_format($captcha['valuation_weight'] * 100, 0);
$qualWeightPct = number_format($captcha['quality_weight'] * 100, 0);
?>
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

        <!-- CVS Pillar Check — on-brand anti-bot arithmetic, same weights as the
             real Swing pillar (config/cvs-weights.php). Random operands per
             render, so the answer can't be memorised or hardcoded. -->
        <div class="form-group">
            <label for="pillar_answer">
                Szybkie sprawdzenie: Wycena=<?= $captcha['valuation'] ?> × <?= $valWeightPct ?>%
                + Jakość=<?= $captcha['quality'] ?> × <?= $qualWeightPct ?>% = ?
            </label>
            <input id="pillar_answer" type="text" name="pillar_answer" inputmode="decimal"
                   required autocomplete="off" placeholder="np. 42.0">
            <small style="color:var(--c-text-muted);">
                To ten sam wzór, którym CVS liczy wynik Swing — chronimy formularz przed botami.
            </small>
        </div>

        <!-- Honeypot — real users never see this (hidden off-screen, not
             display:none/type=hidden, so it still "looks" fillable to a
             naive autofill bot). Any value here = reject on submit. -->
        <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">
            <label for="<?= htmlspecialchars($captcha['honeypot_field']) ?>">Zostaw to pole puste</label>
            <input type="text" id="<?= htmlspecialchars($captcha['honeypot_field']) ?>"
                   name="<?= htmlspecialchars($captcha['honeypot_field']) ?>" tabindex="-1" autocomplete="off">
        </div>

        <button type="submit" class="btn btn--primary">Utwórz konto</button>

        <p style="font-size:.75rem;color:var(--c-text-muted);margin-top:.75rem;">
            Rejestrując się, akceptujesz <a href="/terms-of-service" target="_blank">Regulamin</a>
            i <a href="/privacy-policy" target="_blank">Politykę Prywatności</a>.
        </p>
    </form>

    <p class="auth-switch">Masz już konto? <a href="/login">Zaloguj się</a></p>
    <p class="auth-switch">Ciekawi Cię metodologia? <a href="/model">Jak działa model CVS →</a></p>
</section>
