<?php
/**
 * Shared layout (top). Expects: $base ('' or '../'), $active ('dashboard'|'upload'), $title.
 */
$base   = $base   ?? '';
$active = $active ?? 'dashboard';
$title  = $title  ?? 'Dashboard';
$name   = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
$initial = strtoupper(substr((string) $name, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= $base ?>assets/css/style.css">
</head>
<body>
<div class="app" id="app">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="brand-mark sm"><?= icon('logo') ?></span>
            <span>Analytics</span>
        </div>

        <nav class="nav">
            <a href="<?= $base ?>dashboard.php" class="nav-link <?= $active === 'dashboard' ? 'active' : '' ?>">
                <?= icon('grid') ?> Dashboard
            </a>
            <a href="<?= $base ?>upload/" class="nav-link <?= $active === 'upload' ? 'active' : '' ?>">
                <?= icon('upload') ?> Upload Data
            </a>
        </nav>

        <div class="sidebar-foot">
            <div class="user-chip">
                <span class="avatar"><?= e($initial) ?></span>
                <span class="user-name"><?= e($name) ?></span>
            </div>
            <form method="post" action="<?= $base ?>logout.php">
                <?= csrf_field() ?>
                <button type="submit" class="nav-link logout-btn"><?= icon('logout') ?> Sign out</button>
            </form>
        </div>
    </aside>
    <div class="scrim" id="scrim"></div>

    <div class="main">
        <header class="topbar">
            <button class="icon-btn menu-btn" id="menuBtn" aria-label="Open menu"><?= icon('menu') ?></button>
            <h1 class="page-title"><?= e($title) ?></h1>
        </header>
        <main class="content">
