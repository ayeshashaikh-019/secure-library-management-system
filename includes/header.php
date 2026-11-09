<?php
$pageTitle = $pageTitle ?? 'Dashboard';
$activePage = $activePage ?? '';
$user = current_user();
$flashMessage = get_flash();
$role = $user['role'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#14213d">
    <title><?= e($pageTitle) ?> | <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="dashboard.php">
            <span class="brand-mark"><i class="bi bi-shield-lock-fill"></i></span>
            <span><strong>Secure Library</strong><small>Knowledge, protected.</small></span>
        </a>
        <div class="sidebar-label">WORKSPACE</div>
        <nav class="sidebar-nav">
            <a class="nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>" href="dashboard.php"><i class="bi bi-grid-1x2"></i><span>Dashboard</span></a>
            <a class="nav-link <?= $activePage === 'books' ? 'active' : '' ?>" href="books.php"><i class="bi bi-book"></i><span>Book catalogue</span></a>
            <?php if (in_array($role, ['student', 'librarian', 'admin'], true)): ?>
                <a class="nav-link <?= $activePage === 'transactions' ? 'active' : '' ?>" href="transactions.php"><i class="bi bi-arrow-left-right"></i><span><?= $role === 'student' ? 'My borrowings' : 'Transactions' ?></span></a>
            <?php endif; ?>
            <?php if ($role === 'admin'): ?>
                <div class="sidebar-label mt-4">ADMINISTRATION</div>
                <a class="nav-link <?= $activePage === 'users' ? 'active' : '' ?>" href="users.php"><i class="bi bi-people"></i><span>User management</span></a>
                <a class="nav-link <?= $activePage === 'reports' ? 'active' : '' ?>" href="reports.php"><i class="bi bi-bar-chart-line"></i><span>Reports & audit</span></a>
                <a class="nav-link <?= $activePage === 'settings' ? 'active' : '' ?>" href="settings.php"><i class="bi bi-sliders"></i><span>System settings</span></a>
            <?php elseif ($role === 'librarian'): ?>
                <div class="sidebar-label mt-4">LIBRARY DESK</div>
                <a class="nav-link <?= $activePage === 'transactions' ? 'active' : '' ?>" href="transactions.php"><i class="bi bi-clipboard-check"></i><span>Issue & returns</span></a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-note mt-auto">
            <div class="note-icon"><i class="bi bi-shield-check"></i></div>
            <strong>Security first</strong>
            <p>Role-based access, protected sessions and secure records.</p>
        </div>
        <div class="sidebar-bottom"><span class="online-dot"></span> Library system online</div>
    </aside>

    <main class="main-area">
        <header class="topbar">
            <div class="d-flex align-items-center gap-3">
                <button class="btn btn-light sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle navigation"><i class="bi bi-list"></i></button>
                <div><div class="eyebrow">LIBRARY MANAGEMENT SYSTEM</div><h1 class="topbar-title"><?= e($pageTitle) ?></h1></div>
            </div>
            <div class="topbar-user">
                <div class="topbar-avatar"><?= e(strtoupper(substr($user['name'] ?? 'U', 0, 1))) ?></div>
                <div class="user-meta d-none d-sm-block"><strong><?= e($user['name'] ?? 'User') ?></strong><small><?= e(role_label($role)) ?></small></div>
                <a class="btn btn-outline-secondary btn-sm logout-btn" href="logout.php" title="Sign out"><i class="bi bi-box-arrow-right"></i><span class="d-none d-md-inline ms-1">Sign out</span></a>
            </div>
        </header>

        <section class="page-content">
            <?php if ($flashMessage): ?>
                <div class="alert alert-<?= e($flashMessage['type']) ?> alert-dismissible fade show shadow-sm" role="alert">
                    <?= e($flashMessage['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
