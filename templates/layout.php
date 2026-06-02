<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CVS — Composite Valuation Score</title>
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
    <link rel="stylesheet" href="/css/tokens.css">
    <link rel="stylesheet" href="/css/components.css">
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
<header class="site-header">
    <div class="container">
        <a class="site-logo" href="/dashboard">CVS</a>
        <span class="site-tagline">Composite Valuation Score</span>
        <?php if (!empty($_SESSION['user_id'])): ?>
        <nav class="site-nav">
            <a href="/dashboard">Panel</a>
            <a href="/track-record">Track Record</a>
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
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="/js/app.js"></script>
</body>
</html>
