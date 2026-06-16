<?php declare(strict_types=1); ?>
<div style="max-width:480px;margin:4rem auto;padding:2rem;background:rgba(14,27,47,.55);
            backdrop-filter:blur(4px);border-radius:var(--radius);text-align:center;">
    <div style="font-size:3rem;margin-bottom:1rem;">📬</div>
    <h1 style="font-size:var(--text-xl);margin-bottom:.5rem;">Sprawdź skrzynkę e-mail</h1>
    <?php if (!empty($email)): ?>
    <p style="color:var(--c-text-muted);margin-bottom:1.5rem;">
        Wysłaliśmy link aktywacyjny na adres<br>
        <strong style="color:var(--c-text);"><?= htmlspecialchars($email) ?></strong>
    </p>
    <?php endif; ?>
    <p style="color:var(--c-text-muted);font-size:var(--text-sm);margin-bottom:1.5rem;">
        Link jest ważny przez 48 godzin. Sprawdź też folder spam.
    </p>
    <form method="POST" action="/auth/resend-verification">
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <button type="submit" class="btn btn--ghost btn--sm">
            Wyślij link ponownie
        </button>
    </form>
    <p style="margin-top:2rem;font-size:var(--text-sm);">
        <a href="/login" style="color:var(--c-text-muted);">← Wróć do logowania</a>
    </p>
</div>
