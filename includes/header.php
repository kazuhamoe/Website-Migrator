<?php
$page = $page ?? 'home';
$current = isset($_GET['p']) ? $_GET['p'] : $page;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="initial-scale=1, width=device-width">
    <title>Website Migrator — Universal 1-Click Site Cloner</title>
    <link rel="icon" type="image/x-icon" href="assets/icons/favicon.ico">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= time(); ?>">
</head>
<body>

<header class="header">
    <div class="container">
        <div class="header-inner">
            <div class="logo">
                <div class="logo-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                    </svg>
                </div>
                <span class="logo-text">Website Migrator</span>
                <span class="logo-badge">v1.1.0</span>
            </div>

            <nav class="nav">
                <a href="index.php" class="nav-item <?= $current === 'home' ? 'active' : '' ?>">Export</a>
                <a href="import.php" class="nav-item <?= $current === 'import' ? 'active' : '' ?>">Import</a>
                <a href="system-check.php" class="nav-item <?= $current === 'system' ? 'active' : '' ?>">System Check</a>
            </nav>

            <div class="status-indicator">
                <div class="dot"></div>
                <span>System Ready</span>
            </div>

            <button class="mobile-menu-btn" id="mobileMenuBtn" aria-label="Toggle menu">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>
        </div>
    </div>
    <div class="container">
        <div class="mobile-nav" id="mobileNav">
            <a href="index.php" class="nav-item <?= $current === 'home' ? 'active' : '' ?>">Export</a>
            <a href="import.php" class="nav-item <?= $current === 'import' ? 'active' : '' ?>">Import</a>
            <a href="system-check.php" class="nav-item <?= $current === 'system' ? 'active' : '' ?>">System Check</a>
        </div>
    </div>
</header>

<main>
