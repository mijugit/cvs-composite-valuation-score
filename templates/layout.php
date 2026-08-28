<?php
/**
 * Cache-busting helper: appends ?v=<filemtime> to a public asset path so browsers
 * pick up CSS/JS changes immediately after a deploy instead of serving a stale copy.
 */
$asset = static function (string $path): string {
    $full = dirname(__DIR__) . '/public' . $path;
    return is_file($full) ? $path . '?v=' . filemtime($full) : $path;
};
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVS — Composite Valuation Score</title>
    <link rel="icon" type="image/x-icon" href="<?= $asset('/images/favicon.ico') ?>">
    <link rel="shortcut icon" href="<?= $asset('/images/favicon.ico') ?>">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <link rel="stylesheet" href="<?= $asset('/css/tokens.css') ?>">
    <link rel="stylesheet" href="<?= $asset('/css/components.css') ?>">
    <link rel="stylesheet" href="<?= $asset('/css/app.css') ?>">
</head>
<body>
<video class="bg-video" autoplay muted loop playsinline>
    <source src="<?= $asset('/images/CVS.webm') ?>" type="video/webm">
    <source src="<?= $asset('/images/CVS.mp4') ?>" type="video/mp4">
</video>
<header class="site-header">
    <div class="container">
        <a class="site-logo" href="/dashboard">
            <img src="<?= $asset('/images/ikona.png') ?>" alt="" class="site-logo__icon" width="28" height="28">
            CVS
        </a>
        <span class="site-tagline">Composite Valuation Score</span>
        <?php if (!empty($_SESSION['user_id'])): ?>
        <button class="nav-toggle" type="button" aria-label="Menu" aria-expanded="false" aria-controls="site-nav">
            <span class="nav-toggle__bar"></span>
            <span class="nav-toggle__bar"></span>
            <span class="nav-toggle__bar"></span>
        </button>
        <nav class="site-nav" id="site-nav">
            <a href="/screener">Screener</a>
            <a href="/dashboard">Analizy</a>
            <div class="admin-menu">
                <button class="admin-menu__trigger" type="button" aria-haspopup="true">
                    Portfele <span class="admin-menu__caret">▾</span>
                </button>
                <ul class="admin-menu__dropdown" role="menu">
                    <li><a href="/portfolio" role="menuitem"<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/portfolio') ? ' aria-current="page"' : '' ?>>Portfel Bazowy Claude</a></li>
                    <li><a href="/llm-free" role="menuitem"<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/llm-free') ? ' aria-current="page"' : '' ?>>Portfel Free Claude</a></li>
                    <li><a href="/llm-gemini" role="menuitem"<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/llm-gemini') ? ' aria-current="page"' : '' ?>>Portfel Free Gemini</a></li>
                    <li><a href="/llm-gpt-luna" role="menuitem"<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/llm-gpt-luna') ? ' aria-current="page"' : '' ?>>Portfel Free GPT Luna</a></li>
                </ul>
            </div>
            <a href="/lab"<?= str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/lab') ? ' aria-current="page"' : '' ?>>Lab</a>
            <a href="/track-record">Track Record</a>
            <a href="/model">Model</a>
            <?php if (empty($_SESSION['is_admin'])): ?>
            <a href="/sectors">Sektory</a>
            <?php endif; ?>
            <?php if (!empty($_SESSION['is_admin'])): ?>
            <div class="admin-menu">
                <button class="admin-menu__trigger" type="button" aria-haspopup="true">
                    Admin <span class="admin-menu__caret">▾</span>
                </button>
                <ul class="admin-menu__dropdown" role="menu">
                    <li><a href="/admin/pro" role="menuitem">Panel PRO</a></li>
                    <li><a href="/admin/sectors" role="menuitem">Sektory</a></li>
                    <li><a href="/admin/tickers" role="menuitem">Tickery</a></li>
                </ul>
            </div>
            <?php endif; ?>
            <a href="/logout">Wyloguj</a>
        </nav>
        <?php endif; ?>
    </div>
</header>

<main class="site-main">
    <?php if (!empty($_SESSION['_flash'])): ?>
    <div class="container" style="padding-top:.75rem;padding-bottom:0;">
        <div class="alert alert--success"><?= htmlspecialchars((string) $_SESSION['_flash']) ?></div>
    </div>
    <?php unset($_SESSION['_flash']); endif; ?>
    <div class="container">
        <?php echo $content ?? ''; ?>
    </div>
</main>

<footer class="site-footer">
    <div class="container">
        <p class="disclaimer">
            Wyniki CVS to hipoteza modelu analitycznego, nie rekomendacja inwestycyjna.
            Inwestuj świadomie.
        </p>
        <p style="margin-top:.5rem;font-size:.75rem;color:var(--c-text-muted);">
            <a href="/terms-of-service" style="color:var(--c-text-muted);">Regulamin</a>
            &nbsp;·&nbsp;
            <a href="/privacy-policy" style="color:var(--c-text-muted);">Polityka Prywatności</a>
        </p>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="<?= $asset('/js/app.js') ?>"></script>
</body>
</html>
