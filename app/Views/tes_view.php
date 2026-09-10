<?php
defined('BASEPATH') || exit('No direct script access allowed');
$title = $title ?? 'Testing';
$pageTitle = $page_title ?? 'Program testing';
?>
<!doctype html>
<html lang="id" data-theme="dark" data-radius="flat">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · Codekop MVC</title>
    <link rel="icon" href="<?= e(base_url('assets/img/codekop-logo.png')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/retro-term/retro-term.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/retro-term/retro-term-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(base_url('assets/main.css')) ?>">
</head>
<body class="test-page">
<header class="rt-container test-nav">
    <a class="test-brand" href="<?= e(base_url()) ?>">
        <img src="<?= e(base_url('assets/img/codekop-logo.png')) ?>" alt="Codekop MVC">
        <span>Codekop MVC</span>
    </a>
    <button class="rt-btn rt-btn-secondary" id="testThemeToggle" type="button" aria-label="Ganti tema">
        <i class="rt rt-moon" aria-hidden="true"></i>
        <span>Dark / Light</span>
    </button>
</header>

<main class="test-main">
    <section class="test-card">
        <div class="test-icon">
            <i class="rt rt-terminal" aria-hidden="true"></i>
        </div>
        <div class="rt-badge rt-badge--success">
            <i class="rt rt-check-circle" aria-hidden="true"></i>
            Route berhasil dijalankan
        </div>
        <h1><?= e($pageTitle) ?></h1>
        <p class="test-copy">
            Halaman ini membuktikan controller, routing manual, view loader,
            templating PHP-native, asset lokal, dan security policy berjalan.
        </p>
        <div class="test-terminal" aria-label="Informasi route">
            <div>$ route GET /home/test</div>
            <div>→ Home::test</div>
            <div>$ template</div>
            <div>→ app/Views/tes_view.php · Retro-term</div>
            <div>$ status</div>
            <div>→ OK</div>
        </div>
        <div class="test-actions">
            <a class="rt-btn rt-btn-primary" href="<?= e(base_url()) ?>">
                <i class="rt rt-home" aria-hidden="true"></i> Beranda
            </a>
            <a class="rt-btn rt-btn-secondary" href="<?= e(base_url('api/health')) ?>">
                <i class="rt rt-braces" aria-hidden="true"></i> Coba API
            </a>
        </div>
    </section>
</main>

<footer class="test-footer">
    Support by
    <a href="https://aiti-solutions.com/" target="_blank" rel="noopener noreferrer">AITI Solutions</a>
    · <a href="https://www.codekop.com/" target="_blank" rel="noopener noreferrer">Codekop</a>
    · <a href="https://github.com/fauzan1892/php-mvc-codekop" target="_blank" rel="noopener noreferrer">GitHub</a>
</footer>

<script src="<?= e(base_url('assets/retro-term/retro-term.min.js')) ?>" defer></script>
<script nonce="<?= e(CSP_NONCE) ?>">
    (() => {
        const root = document.documentElement;
        const saved = localStorage.getItem('codekop-theme');
        if (saved === 'light' || saved === 'dark') root.dataset.theme = saved;
        document.getElementById('testThemeToggle')?.addEventListener('click', () => {
            const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
            root.dataset.theme = next;
            localStorage.setItem('codekop-theme', next);
        });
    })();
</script>
</body>
</html>
