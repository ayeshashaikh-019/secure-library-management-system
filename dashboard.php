<?php
require_once __DIR__ . '/config.php';
require_login();
$user = current_user();
$role = $user['role'];
$pageTitle = 'Dashboard';
$activePage = 'dashboard';

if ($role === 'student') {
    $uid = (int)$user['user_id'];
    $stmt = db()->prepare("SELECT COUNT(*) FROM borrowings WHERE user_id = ? AND status IN ('borrowed','return_requested')");
    $stmt->execute([$uid]);
    $activeLoans = (int)$stmt->fetchColumn();
    $stmt = db()->prepare("SELECT COUNT(*) FROM borrowings WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$uid]);
    $pendingRequests = (int)$stmt->fetchColumn();
    $stmt = db()->prepare("SELECT COUNT(*) FROM borrowings WHERE user_id = ? AND status = 'borrowed' AND due_date < CURDATE()");
    $stmt->execute([$uid]);
    $overdue = (int)$stmt->fetchColumn();
    $catalogueCount = (int)db()->query('SELECT COUNT(*) FROM books')->fetchColumn();
    $recentStmt = db()->prepare('SELECT br.*, b.title, b.author FROM borrowings br JOIN books b ON b.book_id = br.book_id WHERE br.user_id = ? ORDER BY br.borrowing_id DESC LIMIT 5');
    $recentStmt->execute([$uid]);
    $recent = $recentStmt->fetchAll();
    $suggested = db()->query('SELECT b.book_id, b.title, b.author, b.available_copies, c.category_name FROM books b JOIN categories c ON c.category_id = b.category_id WHERE b.available_copies > 0 ORDER BY b.book_id DESC LIMIT 4')->fetchAll();
} elseif ($role === 'librarian') {
    $bookCount = (int)db()->query('SELECT COUNT(*) FROM books')->fetchColumn();
    $availableCopies = (int)db()->query('SELECT COALESCE(SUM(available_copies),0) FROM books')->fetchColumn();
    $borrowedCount = (int)db()->query("SELECT COUNT(*) FROM borrowings WHERE status IN ('borrowed','return_requested')")->fetchColumn();
    $pendingCount = (int)db()->query("SELECT COUNT(*) FROM borrowings WHERE status = 'pending'")->fetchColumn();
    $returnCount = (int)db()->query("SELECT COUNT(*) FROM borrowings WHERE status = 'return_requested'")->fetchColumn();
    $recent = db()->query('SELECT br.*, u.name AS student_name, b.title FROM borrowings br JOIN users u ON u.user_id = br.user_id JOIN books b ON b.book_id = br.book_id ORDER BY br.borrowing_id DESC LIMIT 7')->fetchAll();
} else {
    $userCount = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $studentCount = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'")->fetchColumn();
    $staffCount = (int)db()->query("SELECT COUNT(*) FROM users WHERE role IN ('librarian','admin') AND status = 'active'")->fetchColumn();
    $bookCount = (int)db()->query('SELECT COUNT(*) FROM books')->fetchColumn();
    $copyCount = (int)db()->query('SELECT COALESCE(SUM(total_copies),0) FROM books')->fetchColumn();
    $borrowedCount = (int)db()->query("SELECT COUNT(*) FROM borrowings WHERE status IN ('borrowed','return_requested')")->fetchColumn();
    $pendingCount = (int)db()->query("SELECT COUNT(*) FROM borrowings WHERE status = 'pending'")->fetchColumn();
    $recentLogs = db()->query('SELECT al.*, u.name AS user_name FROM activity_logs al LEFT JOIN users u ON u.user_id = al.user_id ORDER BY al.log_id DESC LIMIT 8')->fetchAll();
}
require __DIR__ . '/includes/header.php';
?>
<?php if ($role === 'student'): ?>
    <div class="welcome-banner student-banner">
        <div><span class="banner-kicker">YOUR LIBRARY, YOUR SPACE</span><h2>Good to see you, <?= e(explode(' ', $user['name'])[0]) ?> <span>✦</span></h2><p>Find your next read, keep track of your loans, and make every visit count.</p><a class="btn btn-light btn-sm rounded-pill px-3" href="books.php">Explore catalogue <i class="bi bi-arrow-up-right ms-1"></i></a></div>
        <div class="banner-art"><i class="bi bi-book-half"></i><i class="bi bi-stars"></i></div>
    </div>
    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="stat-card"><div class="stat-icon icon-blue"><i class="bi bi-bookmark-check"></i></div><div><span>Current loans</span><strong><?= $activeLoans ?></strong><small>Books in your account</small></div></div></div>
        <div class="col-md-4"><div class="stat-card"><div class="stat-icon icon-amber"><i class="bi bi-hourglass-split"></i></div><div><span>Pending requests</span><strong><?= $pendingRequests ?></strong><small>Waiting for librarian approval</small></div></div></div>
        <div class="col-md-4"><div class="stat-card"><div class="stat-icon icon-rose"><i class="bi bi-calendar-x"></i></div><div><span>Overdue loans</span><strong><?= $overdue ?></strong><small>Check your due dates</small></div></div></div>
    </div>
    <div class="section-heading"><div><span class="eyebrow">PICK UP WHERE YOU LEFT OFF</span><h3>Recent activity</h3></div><a class="text-link" href="transactions.php">View all <i class="bi bi-arrow-right"></i></a></div>
    <div class="panel-card mb-4">
        <?php if (!$recent): ?><div class="empty-state"><div class="empty-icon"><i class="bi bi-journal-bookmark"></i></div><h5>Your reading list starts here</h5><p>You have no borrowing activity yet. Browse the catalogue and find a book to request.</p><a href="books.php" class="btn btn-primary btn-sm">Browse books</a></div>
        <?php else: ?><div class="table-responsive"><table class="table align-middle app-table mb-0"><thead><tr><th>Book</th><th>Issue date</th><th>Due date</th><th>Status</th></tr></thead><tbody><?php foreach ($recent as $row): ?><tr><td><div class="book-cell"><span class="book-mini"><i class="bi bi-book"></i></span><div><strong><?= e($row['title']) ?></strong><small><?= e($row['author']) ?></small></div></div></td><td><?= $row['issue_date'] ? e(date('d M Y', strtotime($row['issue_date']))) : '—' ?></td><td><?= $row['due_date'] ? e(date('d M Y', strtotime($row['due_date']))) : 'Pending' ?></td><td><?= status_badge($row['status']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>
    <div class="section-heading"><div><span class="eyebrow">FRESH FROM THE SHELVES</span><h3>Available to explore</h3></div><span class="muted-copy small"><?= $catalogueCount ?> titles in catalogue</span></div>
    <div class="row g-3"><?php foreach ($suggested as $book): ?><div class="col-sm-6 col-xl-3"><div class="book-feature-card"><div class="book-cover"><i class="bi bi-book-half"></i><span><?= e($book['category_name']) ?></span></div><div class="book-feature-body"><h5><?= e($book['title']) ?></h5><p><?= e($book['author']) ?></p><div class="d-flex justify-content-between align-items-center"><small class="text-success fw-semibold"><?= (int)$book['available_copies'] ?> available</small><a href="books.php" class="small fw-semibold">View <i class="bi bi-arrow-up-right"></i></a></div></div></div></div><?php endforeach; ?></div>
<?php elseif ($role === 'librarian'): ?>
    <div class="welcome-banner librarian-banner"><div><span class="banner-kicker">LIBRARY OPERATIONS</span><h2>Welcome back, <?= e(explode(' ', $user['name'])[0]) ?></h2><p>Keep the shelves organized and every book moving to the right reader.</p><a class="btn btn-light btn-sm rounded-pill px-3" href="transactions.php">Open transactions <i class="bi bi-arrow-up-right ms-1"></i></a></div><div class="banner-art"><i class="bi bi-journal-bookmark-fill"></i><i class="bi bi-check2-circle"></i></div></div>
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon icon-blue"><i class="bi bi-bookshelf"></i></div><div><span>Book titles</span><strong><?= $bookCount ?></strong><small>In the catalogue</small></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon icon-green"><i class="bi bi-box-seam"></i></div><div><span>Available copies</span><strong><?= $availableCopies ?></strong><small>Ready to issue</small></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon icon-amber"><i class="bi bi-hourglass-split"></i></div><div><span>Pending requests</span><strong><?= $pendingCount ?></strong><small>Need your review</small></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon icon-purple"><i class="bi bi-arrow-left-right"></i></div><div><span>Active loans</span><strong><?= $borrowedCount ?></strong><small><?= $returnCount ?> return requests</small></div></div></div>
    </div>
    <div class="section-heading"><div><span class="eyebrow">LATEST LIBRARY ACTIVITY</span><h3>Recent transactions</h3></div><a class="text-link" href="transactions.php">Manage transactions <i class="bi bi-arrow-right"></i></a></div>
    <div class="panel-card"><div class="table-responsive"><table class="table align-middle app-table mb-0"><thead><tr><th>Student</th><th>Book</th><th>Issue date</th><th>Due date</th><th>Status</th></tr></thead><tbody><?php foreach ($recent as $row): ?><tr><td class="fw-semibold"><?= e($row['student_name']) ?></td><td><?= e($row['title']) ?></td><td><?= $row['issue_date'] ? e(date('d M Y', strtotime($row['issue_date']))) : '—' ?></td><td><?= $row['due_date'] ? e(date('d M Y', strtotime($row['due_date']))) : '—' ?></td><td><?= status_badge($row['status']) ?></td></tr><?php endforeach; ?><?php if (!$recent): ?><tr><td colspan="5" class="text-center text-muted py-4">No transactions have been recorded yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php else: ?>
    <div class="welcome-banner admin-banner"><div><span class="banner-kicker">SYSTEM OVERVIEW · ADMINISTRATOR</span><h2>Everything in one place.</h2><p>Monitor users, library inventory, transactions and system activity from your control centre.</p><a class="btn btn-light btn-sm rounded-pill px-3" href="reports.php">View reports <i class="bi bi-arrow-up-right ms-1"></i></a></div><div class="banner-art"><i class="bi bi-shield-lock-fill"></i><i class="bi bi-graph-up-arrow"></i></div></div>
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon icon-blue"><i class="bi bi-people"></i></div><div><span>Total users</span><strong><?= $userCount ?></strong><small><?= $studentCount ?> active students</small></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon icon-green"><i class="bi bi-book"></i></div><div><span>Book titles</span><strong><?= $bookCount ?></strong><small><?= $copyCount ?> total copies</small></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon icon-purple"><i class="bi bi-arrow-left-right"></i></div><div><span>Active loans</span><strong><?= $borrowedCount ?></strong><small>Borrowed or return requested</small></div></div></div>
        <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon icon-amber"><i class="bi bi-hourglass-split"></i></div><div><span>Pending requests</span><strong><?= $pendingCount ?></strong><small><?= $staffCount ?> active staff accounts</small></div></div></div>
    </div>
    <div class="section-heading"><div><span class="eyebrow">ACCOUNTABILITY</span><h3>Recent system activity</h3></div><a class="text-link" href="reports.php">Full audit log <i class="bi bi-arrow-right"></i></a></div>
    <div class="panel-card"><div class="table-responsive"><table class="table align-middle app-table mb-0"><thead><tr><th>Activity</th><th>User</th><th>Details</th><th>Time</th><th>IP address</th></tr></thead><tbody><?php foreach ($recentLogs as $log): ?><tr><td><span class="activity-dot"></span><strong><?= e(str_replace('_', ' ', $log['action'])) ?></strong></td><td><?= e($log['user_name'] ?? 'System / removed user') ?></td><td class="text-muted small"><?= e($log['details']) ?></td><td><?= e(date('d M, H:i', strtotime($log['created_at']))) ?></td><td><code><?= e($log['ip_address']) ?></code></td></tr><?php endforeach; ?><?php if (!$recentLogs): ?><tr><td colspan="5" class="text-center text-muted py-4">No activity has been recorded yet.</td></tr><?php endif; ?></tbody></table></div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
