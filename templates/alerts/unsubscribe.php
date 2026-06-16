<?php declare(strict_types=1); ?>

<section class="auth-box" style="text-align:center;max-width:520px;">
    <?php if (!empty($success)): ?>
        <h1>Wypisano z alertów</h1>
        <p style="color:var(--c-text-muted);margin:1rem 0 2rem;">
            Pomyślnie wyłączono powiadomienia e-mail CVS dla tego konta.<br>
            Możesz je ponownie włączyć z poziomu Aplikacji.
        </p>
        <a href="/login" class="btn btn--primary">Zaloguj się</a>
    <?php else: ?>
        <h1>Nieprawidłowy link</h1>
        <p style="color:var(--c-text-muted);margin:1rem 0 2rem;">
            Link do wypisania jest nieprawidłowy lub wygasł.<br>
            Zaloguj się i wyłącz alerty ręcznie w panelu watchlist.
        </p>
        <a href="/login" class="btn btn--ghost">Zaloguj się</a>
    <?php endif; ?>
</section>
