<?php
require_once __DIR__ . '/../config/database.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Dashboard' ?> — <?= APP_NAME ?></title>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600;700&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>

<!-- TOP NAVIGATION -->
<nav class="top-nav">
    <div class="top-nav-inner">
        <a href="<?= BASE_URL ?>/" class="logo">EDMS <span>// Engineering Drawing Manager</span></a>
        <div class="nav-tabs">
            <a href="<?= BASE_URL ?>/" class="nav-tab <?= $currentPage === 'index' ? 'active' : '' ?>">Dashboard</a>
            <a href="<?= BASE_URL ?>/pages/sales_orders.php" class="nav-tab <?= $currentPage === 'sales_orders' ? 'active' : '' ?>">Sales Orders</a>
            <a href="<?= BASE_URL ?>/pages/product_codes.php" class="nav-tab <?= $currentPage === 'product_codes' ? 'active' : '' ?>">Products</a>
            <a href="<?= BASE_URL ?>/pages/templates.php" class="nav-tab <?= $currentPage === 'templates' ? 'active' : '' ?>">Templates</a>
            <a href="<?= BASE_URL ?>/pages/drawings.php" class="nav-tab <?= $currentPage === 'drawings' ? 'active' : '' ?>">Drawings</a>
            <a href="<?= BASE_URL ?>/pages/certificates.php" class="nav-tab <?= $currentPage === 'certificates' ? 'active' : '' ?>">Certificates</a>
            <a href="<?= BASE_URL ?>/pages/changes.php" class="nav-tab <?= $currentPage === 'changes' ? 'active' : '' ?>">Changes</a>
        </div>
    </div>
</nav>

<!-- FLASH MESSAGE -->
<?php if ($flash): ?>
<div class="flash-message flash-<?= $flash['type'] ?>">
    <?= sanitize($flash['message']) ?>
    <button class="flash-close" onclick="this.parentElement.remove()">&times;</button>
</div>
<?php endif; ?>

<!-- MAIN CONTENT -->
<div class="content">
