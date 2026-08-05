<?php declare(strict_types=1); ?>
<div style="max-width:480px;margin:4rem auto;padding:2rem;background:rgba(14,27,47,.55);
            backdrop-filter:blur(4px);border-radius:var(--radius);text-align:center;">
    <div style="font-size:3rem;margin-bottom:1rem;">⏱</div>
    <h1 style="font-size:var(--text-xl);margin-bottom:.5rem;">Link do resetu hasła wygasł</h1>
    <p style="color:var(--c-text-muted);margin-bottom:1.5rem;">
        Link jest nieprawidłowy, wygasł (ważność: 1h) lub został już użyty.<br>
        Poproś o nowy link, aby zresetować hasło.
    </p>
    <p style="margin-bottom:1rem;">
        <a href="/auth/forgot-password" class="btn btn--primary">Wyślij nowy link</a>
    </p>
    <p style="font-size:var(--text-sm);">
        <a href="/login" style="color:var(--c-text-muted);">← Wróć do logowania</a>
    </p>
</div>
