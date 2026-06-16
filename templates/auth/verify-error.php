<?php declare(strict_types=1); ?>
<div style="max-width:480px;margin:4rem auto;padding:2rem;background:rgba(14,27,47,.55);
            backdrop-filter:blur(4px);border-radius:var(--radius);text-align:center;">
    <div style="font-size:3rem;margin-bottom:1rem;">⏱</div>
    <h1 style="font-size:var(--text-xl);margin-bottom:.5rem;">Link weryfikacyjny wygasł</h1>
    <p style="color:var(--c-text-muted);margin-bottom:1.5rem;">
        Link weryfikacyjny jest nieprawidłowy lub wygasł (ważność: 48h).<br>
        Wyślij nowy link lub zaloguj się, by kontynuować.
    </p>
    <?php if (!empty($email)): ?>
    <form method="POST" action="/auth/resend-verification" style="margin-bottom:1rem;">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <button type="submit" class="btn btn--primary">
            Wyślij nowy link
        </button>
    </form>
    <?php endif; ?>
    <p style="font-size:var(--text-sm);">
        <a href="/login" style="color:var(--c-text-muted);">← Wróć do logowania</a>
    </p>
</div>
