<?php
require_once __DIR__ . '/config.php';
require_role('admin');

if (($_GET['export'] ?? '') === 'csv') {
    $rows = db()->query('SELECT br.borrowing_id, u.name AS student, u.email, b.title AS book, b.author, b.ISBN, br.issue_date, br.due_date, br.return_date, br.status, p.name AS processed_by FROM borrowings br JOIN users u ON u.user_id = br.user_id JOIN books b ON b.book_id = br.book_id LEFT JOIN users p ON p.user_id = br.processed_by ORDER BY br.borrowing_id DESC')->fetchAll();
    $safeCsv = static function ($value): string {
        $value = (string)($value ?? '');
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value)) $value = "'" . $value;
        return $value;
    };
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="secure-library-borrowing-report-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Transaction ID','Student','Student Email','Book','Author','ISBN','Issue Date','Due Date','Return Date','Status','Processed By']);
    foreach ($rows as $row) fputcsv($out, array_map($safeCsv, array_values($row)));
    fclose($out);
    exit;
}

$totalBooks = (int)db()->query('SELECT COUNT(*) FROM books')->fetchColumn();
$totalCopies = (int)db()->query('SELECT COALESCE(SUM(total_copies),0) FROM books')->fetchColumn();
$availableCopies = (int)db()->query('SELECT COALESCE(SUM(available_copies),0) FROM books')->fetchColumn();
$borrowedCopies = $totalCopies - $availableCopies;
$totalUsers = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
$activeLoans = (int)db()->query("SELECT COUNT(*) FROM borrowings WHERE status IN ('borrowed','return_requested')")->fetchColumn();
$returnedLoans = (int)db()->query("SELECT COUNT(*) FROM borrowings WHERE status = 'returned'")->fetchColumn();
$pendingLoans = (int)db()->query("SELECT COUNT(*) FROM borrowings WHERE status = 'pending'")->fetchColumn();
$rejectedLoans = (int)db()->query("SELECT COUNT(*) FROM borrowings WHERE status = 'rejected'")->fetchColumn();
$categoryStats = db()->query('SELECT c.category_name, COUNT(b.book_id) AS title_count, COALESCE(SUM(b.total_copies),0) AS copy_count FROM categories c LEFT JOIN books b ON b.category_id = c.category_id GROUP BY c.category_id, c.category_name ORDER BY copy_count DESC, c.category_name')->fetchAll();
$monthlyStats = db()->query("SELECT DATE_FORMAT(created_at, '%b %Y') AS month_label, COUNT(*) AS total FROM borrowings WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH) GROUP BY YEAR(created_at), MONTH(created_at), DATE_FORMAT(created_at, '%b %Y') ORDER BY YEAR(created_at), MONTH(created_at)")->fetchAll();
$recentLoans = db()->query('SELECT br.*, u.name AS student_name, b.title FROM borrowings br JOIN users u ON u.user_id = br.user_id JOIN books b ON b.book_id = br.book_id ORDER BY br.borrowing_id DESC LIMIT 8')->fetchAll();
$auditLogs = db()->query('SELECT al.*, u.name AS user_name FROM activity_logs al LEFT JOIN users u ON u.user_id = al.user_id ORDER BY al.log_id DESC LIMIT 12')->fetchAll();
$pageTitle = 'Reports & audit';
$activePage = 'reports';
require __DIR__ . '/includes/header.php';
?>
<div class="catalogue-intro"><div><span class="eyebrow">VISIBILITY & ACCOUNTABILITY</span><h2>Library at a glance.</h2><p>Review inventory, borrowing activity and the audit trail for important system actions.</p></div><a href="reports.php?export=csv" class="btn btn-primary rounded-pill px-3"><i class="bi bi-download me-1"></i>Export transactions CSV</a></div>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon icon-blue"><i class="bi bi-book"></i></div><div><span>Book titles</span><strong><?= $totalBooks ?></strong><small><?= $totalCopies ?> copies in inventory</small></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon icon-green"><i class="bi bi-box-seam"></i></div><div><span>Available copies</span><strong><?= $availableCopies ?></strong><small><?= $borrowedCopies ?> copies currently out</small></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon icon-purple"><i class="bi bi-people"></i></div><div><span>Registered users</span><strong><?= $totalUsers ?></strong><small>Students and library staff</small></div></div></div>
    <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon icon-amber"><i class="bi bi-arrow-left-right"></i></div><div><span>Active loans</span><strong><?= $activeLoans ?></strong><small><?= $pendingLoans ?> requests awaiting approval</small></div></div></div>
</div>
<div class="row g-4 mb-4">
    <div class="col-lg-7"><div class="panel-card h-100"><div class="panel-heading"><div><span class="eyebrow">BORROWING TREND</span><h3>Recent requests</h3></div><span class="panel-icon"><i class="bi bi-graph-up"></i></span></div><div class="chart-wrap"><canvas id="monthlyChart" height="135"></canvas></div></div></div>
    <div class="col-lg-5"><div class="panel-card h-100"><div class="panel-heading"><div><span class="eyebrow">INVENTORY MIX</span><h3>Books by category</h3></div></div><div class="category-report-list"><?php foreach ($categoryStats as $cat): $percent = $totalCopies > 0 ? round(((int)$cat['copy_count'] / $totalCopies) * 100) : 0; ?><div class="category-report-item"><div class="d-flex justify-content-between"><strong><?= e($cat['category_name']) ?></strong><span><?= (int)$cat['copy_count'] ?> copies</span></div><div class="progress report-progress"><div class="progress-bar" role="progressbar" style="width: <?= $percent ?>%" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100"></div></div><small><?= (int)$cat['title_count'] ?> titles · <?= $percent ?>% of collection</small></div><?php endforeach; ?></div></div></div>
</div>
<div class="row g-4 mb-4">
    <div class="col-lg-5"><div class="panel-card h-100"><div class="panel-heading"><div><span class="eyebrow">TRANSACTION STATUS</span><h3>Borrowing summary</h3></div></div><div class="status-summary-list"><div><span class="status-summary-dot dot-pending"></span><span>Pending requests</span><strong><?= $pendingLoans ?></strong></div><div><span class="status-summary-dot dot-borrowed"></span><span>Borrowed / return requested</span><strong><?= $activeLoans ?></strong></div><div><span class="status-summary-dot dot-returned"></span><span>Returned</span><strong><?= $returnedLoans ?></strong></div><div><span class="status-summary-dot dot-rejected"></span><span>Rejected</span><strong><?= $rejectedLoans ?></strong></div></div><div class="report-total"><span>Total recorded transactions</span><strong><?= $pendingLoans + $activeLoans + $returnedLoans + $rejectedLoans ?></strong></div></div></div>
    <div class="col-lg-7"><div class="panel-card h-100"><div class="panel-heading"><div><span class="eyebrow">RECENT RECORDS</span><h3>Latest borrowings</h3></div><a class="text-link" href="transactions.php">Open transactions <i class="bi bi-arrow-right"></i></a></div><div class="table-responsive"><table class="table align-middle app-table mb-0"><thead><tr><th>Student</th><th>Book</th><th>Due date</th><th>Status</th></tr></thead><tbody><?php foreach ($recentLoans as $loan): ?><tr><td class="fw-semibold"><?= e($loan['student_name']) ?></td><td><?= e($loan['title']) ?></td><td><?= $loan['due_date'] ? e(date('d M Y', strtotime($loan['due_date']))) : '—' ?></td><td><?= status_badge($loan['status']) ?></td></tr><?php endforeach; ?><?php if (!$recentLoans): ?><tr><td colspan="4" class="text-center text-muted py-3">No transactions recorded.</td></tr><?php endif; ?></tbody></table></div></div></div>
</div>
<div class="panel-card"><div class="panel-heading"><div><span class="eyebrow">SECURITY & MONITORING</span><h3>Audit activity log</h3><p class="small muted-copy mb-0">Recent logins, account changes and library operations.</p></div><span class="panel-icon"><i class="bi bi-shield-check"></i></span></div><div class="table-responsive"><table class="table align-middle app-table mb-0"><thead><tr><th>Action</th><th>User</th><th>Details</th><th>IP address</th><th>Timestamp</th></tr></thead><tbody><?php foreach ($auditLogs as $log): ?><tr><td><span class="activity-dot"></span><strong><?= e(str_replace('_', ' ', $log['action'])) ?></strong></td><td><?= e($log['user_name'] ?? 'System / removed user') ?></td><td class="small text-muted"><?= e($log['details']) ?></td><td><code><?= e($log['ip_address']) ?></code></td><td><?= e(date('d M Y, H:i', strtotime($log['created_at']))) ?></td></tr><?php endforeach; ?><?php if (!$auditLogs): ?><tr><td colspan="5" class="text-center text-muted py-3">No audit events recorded yet.</td></tr><?php endif; ?></tbody></table></div></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
const chartLabels = <?= json_encode(array_column($monthlyStats, 'month_label'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const chartValues = <?= json_encode(array_map('intval', array_column($monthlyStats, 'total'))) ?>;
const chartCanvas = document.getElementById('monthlyChart');
if (chartCanvas && window.Chart) {
    new Chart(chartCanvas, {
        type: 'bar',
        data: { labels: chartLabels, datasets: [{ label: 'Borrowing requests', data: chartValues, backgroundColor: '#6477e8', borderRadius: 7, maxBarThickness: 38 }] },
        options: { responsive: true, plugins: { legend: { display: false } }, scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#edf0f5' } } } }
    });
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
