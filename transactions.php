<?php
require_once __DIR__ . '/config.php';
require_login();
$user = current_user();
$role = $user['role'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'request_borrow') {
        $maxLoans = library_setting('max_active_loans', MAX_ACTIVE_LOANS);
        if ($role !== 'student') {
            http_response_code(403);
            exit('Only students can request books from the catalogue.');
        }
        $bookId = (int)($_POST['book_id'] ?? 0);
        $uid = (int)$user['user_id'];
        try {
            db()->beginTransaction();
            // Lock the student row so simultaneous requests cannot bypass the loan limit.
            $userLock = db()->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'student' AND status = 'active' FOR UPDATE");
            $userLock->execute([$uid]);
            if (!$userLock->fetch()) throw new RuntimeException('Your account is not eligible to request books.');

            $activeStmt = db()->prepare("SELECT COUNT(*) FROM borrowings WHERE user_id = ? AND status IN ('pending','borrowed','return_requested')");
            $activeStmt->execute([$uid]);
            if ((int)$activeStmt->fetchColumn() >= $maxLoans) {
                throw new RuntimeException('You have reached the current library limit of ' . $maxLoans . ' active or pending loans.');
            }
            $bookStmt = db()->prepare('SELECT title, available_copies FROM books WHERE book_id = ? FOR UPDATE');
            $bookStmt->execute([$bookId]);
            $book = $bookStmt->fetch();
            if (!$book || (int)$book['available_copies'] < 1) {
                throw new RuntimeException('This book is currently unavailable.');
            }
            $duplicate = db()->prepare("SELECT COUNT(*) FROM borrowings WHERE user_id = ? AND book_id = ? AND status IN ('pending','borrowed','return_requested')");
            $duplicate->execute([$uid, $bookId]);
            if ((int)$duplicate->fetchColumn() > 0) {
                throw new RuntimeException('You already have an active request or loan for this book.');
            }
            $insert = db()->prepare("INSERT INTO borrowings (user_id, book_id, status) VALUES (?, ?, 'pending')");
            $insert->execute([$uid, $bookId]);
            db()->commit();
            audit_log('BORROW_REQUESTED', 'Student requested book: ' . $book['title'] . '.');
            flash('success', 'Your request has been sent to the librarian for approval.');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            flash('warning', $exception->getMessage());
        }
        redirect('transactions.php');
    }

    if ($action === 'request_return') {
        if ($role !== 'student') {
            http_response_code(403);
            exit('Only the borrowing student can request a return through this page.');
        }
        $borrowingId = (int)($_POST['borrowing_id'] ?? 0);
        $stmt = db()->prepare("UPDATE borrowings SET status = 'return_requested' WHERE borrowing_id = ? AND user_id = ? AND status = 'borrowed'");
        $stmt->execute([$borrowingId, (int)$user['user_id']]);
        if ($stmt->rowCount() > 0) {
            audit_log('RETURN_REQUESTED', 'Student requested to return borrowing ID ' . $borrowingId . '.');
            flash('success', 'Return request sent. Please hand the physical book to the librarian.');
        } else {
            flash('warning', 'This loan could not be updated. It may already have been returned or is not yours.');
        }
        redirect('transactions.php');
    }

    if ($role !== 'librarian') {
        http_response_code(403);
        exit('Only a librarian can process borrowing and return transactions.');
    }

    if ($action === 'approve_request') {
        $maxLoans = library_setting('max_active_loans', MAX_ACTIVE_LOANS);
        $loanDays = library_setting('loan_period_days', LOAN_PERIOD_DAYS);
        $borrowingId = (int)($_POST['borrowing_id'] ?? 0);
        try {
            db()->beginTransaction();
            $loanStmt = db()->prepare("SELECT borrowing_id, user_id, book_id, status FROM borrowings WHERE borrowing_id = ? FOR UPDATE");
            $loanStmt->execute([$borrowingId]);
            $loan = $loanStmt->fetch();
            if (!$loan || $loan['status'] !== 'pending') {
                throw new RuntimeException('This request is no longer pending. Refresh the page and review its current status.');
            }
            $bookStmt = db()->prepare('SELECT title, available_copies FROM books WHERE book_id = ? FOR UPDATE');
            $bookStmt->execute([$loan['book_id']]);
            $book = $bookStmt->fetch();
            if (!$book || (int)$book['available_copies'] < 1) {
                throw new RuntimeException('No copies of this book are currently available. Reject the request or wait for a return.');
            }
            $studentLock = db()->prepare('SELECT user_id FROM users WHERE user_id = ? FOR UPDATE');
            $studentLock->execute([$loan['user_id']]);
            $limitStmt = db()->prepare("SELECT COUNT(*) FROM borrowings WHERE user_id = ? AND status IN ('borrowed','return_requested')");
            $limitStmt->execute([$loan['user_id']]);
            if ((int)$limitStmt->fetchColumn() >= $maxLoans) {
                throw new RuntimeException('The student has reached the active loan limit.');
            }
            db()->prepare('UPDATE books SET available_copies = available_copies - 1 WHERE book_id = ?')->execute([$loan['book_id']]);
            $update = db()->prepare("UPDATE borrowings SET status = 'borrowed', issue_date = CURDATE(), due_date = DATE_ADD(CURDATE(), INTERVAL " . $loanDays . " DAY), processed_by = ? WHERE borrowing_id = ?");
            $update->execute([(int)$user['user_id'], $borrowingId]);
            db()->commit();
            audit_log('BORROW_APPROVED', 'Approved borrowing ID ' . $borrowingId . ' for ' . $book['title'] . '.');
            flash('success', 'Book request approved and issued.');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            flash('danger', $exception->getMessage());
        }
        redirect('transactions.php');
    }

    if ($action === 'reject_request') {
        $borrowingId = (int)($_POST['borrowing_id'] ?? 0);
        $stmt = db()->prepare("UPDATE borrowings SET status = 'rejected', processed_by = ? WHERE borrowing_id = ? AND status = 'pending'");
        $stmt->execute([(int)$user['user_id'], $borrowingId]);
        if ($stmt->rowCount() > 0) {
            audit_log('BORROW_REJECTED', 'Rejected borrowing request ID ' . $borrowingId . '.');
            flash('info', 'Borrowing request rejected.');
        } else {
            flash('warning', 'The request was not pending or could not be found.');
        }
        redirect('transactions.php');
    }

    if ($action === 'record_return') {
        $borrowingId = (int)($_POST['borrowing_id'] ?? 0);
        try {
            db()->beginTransaction();
            $loanStmt = db()->prepare("SELECT borrowing_id, book_id, status FROM borrowings WHERE borrowing_id = ? FOR UPDATE");
            $loanStmt->execute([$borrowingId]);
            $loan = $loanStmt->fetch();
            if (!$loan || !in_array($loan['status'], ['borrowed', 'return_requested'], true)) {
                throw new RuntimeException('This loan is not waiting for a return to be recorded.');
            }
            $bookStmt = db()->prepare('SELECT title, total_copies, available_copies FROM books WHERE book_id = ? FOR UPDATE');
            $bookStmt->execute([$loan['book_id']]);
            $book = $bookStmt->fetch();
            if (!$book || (int)$book['available_copies'] >= (int)$book['total_copies']) {
                throw new RuntimeException('Inventory counts do not allow this return. Ask the administrator to review the record.');
            }
            db()->prepare('UPDATE books SET available_copies = available_copies + 1 WHERE book_id = ?')->execute([$loan['book_id']]);
            db()->prepare("UPDATE borrowings SET status = 'returned', return_date = CURDATE(), processed_by = ? WHERE borrowing_id = ?")->execute([(int)$user['user_id'], $borrowingId]);
            db()->commit();
            audit_log('BOOK_RETURNED', 'Recorded return for borrowing ID ' . $borrowingId . ': ' . $book['title'] . '.');
            flash('success', 'Book return recorded and availability updated.');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            flash('danger', $exception->getMessage());
        }
        redirect('transactions.php');
    }

    if ($action === 'direct_issue') {
        $maxLoans = library_setting('max_active_loans', MAX_ACTIVE_LOANS);
        $loanDays = library_setting('loan_period_days', LOAN_PERIOD_DAYS);
        $studentId = (int)($_POST['student_id'] ?? 0);
        $bookId = (int)($_POST['book_id'] ?? 0);
        try {
            db()->beginTransaction();
            $studentStmt = db()->prepare("SELECT user_id, name FROM users WHERE user_id = ? AND role = 'student' AND status = 'active' FOR UPDATE");
            $studentStmt->execute([$studentId]);
            $student = $studentStmt->fetch();
            if (!$student) throw new RuntimeException('Choose an active student account.');
            $bookStmt = db()->prepare('SELECT title, available_copies FROM books WHERE book_id = ? FOR UPDATE');
            $bookStmt->execute([$bookId]);
            $book = $bookStmt->fetch();
            if (!$book || (int)$book['available_copies'] < 1) throw new RuntimeException('No copies of this book are available.');
            $limitStmt = db()->prepare("SELECT COUNT(*) FROM borrowings WHERE user_id = ? AND status IN ('pending','borrowed','return_requested')");
            $limitStmt->execute([$studentId]);
            if ((int)$limitStmt->fetchColumn() >= $maxLoans) throw new RuntimeException('This student has reached the current loan limit.');
            $insert = db()->prepare("INSERT INTO borrowings (user_id, book_id, issue_date, due_date, status, processed_by) VALUES (?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL " . $loanDays . " DAY), 'borrowed', ?)");
            $insert->execute([$studentId, $bookId, (int)$user['user_id']]);
            db()->prepare('UPDATE books SET available_copies = available_copies - 1 WHERE book_id = ?')->execute([$bookId]);
            db()->commit();
            audit_log('DIRECT_ISSUE', 'Issued ' . $book['title'] . ' to ' . $student['name'] . '.');
            flash('success', 'Book issued successfully.');
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            flash('danger', $exception->getMessage());
        }
        redirect('transactions.php');
    }
}

$pageTitle = $role === 'student' ? 'My borrowings' : 'Library transactions';
$activePage = 'transactions';
if ($role === 'student') {
    $stmt = db()->prepare('SELECT br.*, b.title, b.author, b.ISBN FROM borrowings br JOIN books b ON b.book_id = br.book_id WHERE br.user_id = ? ORDER BY br.borrowing_id DESC');
    $stmt->execute([(int)$user['user_id']]);
    $loans = $stmt->fetchAll();
} else {
    $filter = (string)($_GET['status'] ?? 'all');
    $allowedFilters = ['all','pending','borrowed','return_requested','returned','rejected'];
    if (!in_array($filter, $allowedFilters, true)) $filter = 'all';
    $sql = 'SELECT br.*, u.name AS student_name, u.email AS student_email, b.title, b.author, p.name AS processed_name FROM borrowings br JOIN users u ON u.user_id = br.user_id JOIN books b ON b.book_id = br.book_id LEFT JOIN users p ON p.user_id = br.processed_by';
    $params = [];
    if ($filter !== 'all') { $sql .= ' WHERE br.status = ?'; $params[] = $filter; }
    $sql .= ' ORDER BY br.borrowing_id DESC LIMIT 300';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $loans = $stmt->fetchAll();
    $activeStudents = db()->query("SELECT user_id, name, email FROM users WHERE role = 'student' AND status = 'active' ORDER BY name")->fetchAll();
    $availableBooks = db()->query('SELECT book_id, title, author, available_copies FROM books WHERE available_copies > 0 ORDER BY title')->fetchAll();
}
require __DIR__ . '/includes/header.php';
?>
<?php if ($role === 'student'): ?>
    <div class="catalogue-intro"><div><span class="eyebrow">YOUR READING RECORD</span><h2>My borrowings</h2><p>Track requests, keep an eye on due dates, and submit a return request when you are ready to hand back a book.</p></div><a class="btn btn-primary rounded-pill px-3" href="books.php"><i class="bi bi-search me-1"></i> Find a book</a></div>
    <div class="panel-card"><div class="table-responsive"><table class="table align-middle app-table mb-0"><thead><tr><th>Book</th><th>Requested / issue date</th><th>Due date</th><th>Returned</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>
    <?php foreach ($loans as $loan): ?><tr><td><div class="book-cell"><span class="book-mini"><i class="bi bi-book"></i></span><div><strong><?= e($loan['title']) ?></strong><small><?= e($loan['author']) ?> · ISBN <?= e($loan['ISBN']) ?></small></div></div></td><td><?= $loan['issue_date'] ? e(date('d M Y', strtotime($loan['issue_date']))) : e(date('d M Y', strtotime($loan['created_at']))) ?></td><td><?= $loan['due_date'] ? e(date('d M Y', strtotime($loan['due_date']))) : 'After approval' ?><?php if ($loan['status'] === 'borrowed' && $loan['due_date'] < date('Y-m-d')): ?><span class="d-block small text-danger">Overdue</span><?php endif; ?></td><td><?= $loan['return_date'] ? e(date('d M Y', strtotime($loan['return_date']))) : '—' ?></td><td><?= status_badge($loan['status']) ?></td><td class="text-end"><?php if ($loan['status'] === 'borrowed'): ?><form method="post" action="transactions.php" class="d-inline" data-confirm="Submit a return request? Please hand the physical book to the librarian."><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="request_return"><input type="hidden" name="borrowing_id" value="<?= (int)$loan['borrowing_id'] ?>"><button class="btn btn-sm btn-outline-primary" type="submit">Request return</button></form><?php else: ?><span class="small muted-copy">—</span><?php endif; ?></td></tr><?php endforeach; ?>
    <?php if (!$loans): ?><tr><td colspan="6"><div class="empty-state"><div class="empty-icon"><i class="bi bi-journal-bookmark"></i></div><h5>No borrowing records yet</h5><p>Search the catalogue and request an available book to start your reading journey.</p><a class="btn btn-primary btn-sm" href="books.php">Browse catalogue</a></div></td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php else: ?>
    <div class="catalogue-intro"><div><span class="eyebrow">LIBRARY DESK</span><h2>Issue, approve and return.</h2><p>Review student requests, keep inventory accurate, and preserve a clear transaction history.</p></div><div class="catalogue-count"><strong><?= count($loans) ?></strong><span>records shown</span></div></div>
    <?php if ($role === 'librarian'): ?>
        <div class="panel-card mb-4"><div class="panel-heading"><div><span class="eyebrow">COUNTER SERVICE</span><h3>Issue a book directly</h3></div><span class="small muted-copy">For walk-in students</span></div>
        <form method="post" class="row g-3 align-items-end"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="direct_issue"><div class="col-md-5"><label class="form-label" for="student_id">Student</label><select class="form-select" name="student_id" id="student_id" required><option value="">Choose a student</option><?php foreach ($activeStudents as $student): ?><option value="<?= (int)$student['user_id'] ?>"><?= e($student['name']) ?> — <?= e($student['email']) ?></option><?php endforeach; ?></select></div><div class="col-md-5"><label class="form-label" for="book_id">Available book</label><select class="form-select" name="book_id" id="book_id" required><option value="">Choose a book</option><?php foreach ($availableBooks as $book): ?><option value="<?= (int)$book['book_id'] ?>"><?= e($book['title']) ?> (<?= (int)$book['available_copies'] ?> available)</option><?php endforeach; ?></select></div><div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-up-right me-1"></i> Issue book</button></div></form></div>
    <?php endif; ?>
    <div class="panel-card"><form method="get" class="filter-toolbar"><div><span class="eyebrow">TRANSACTION REGISTER</span><h3 class="mb-0">Borrowing records</h3></div><div class="d-flex align-items-center gap-2"><label class="small muted-copy" for="status">Filter</label><select class="form-select form-select-sm filter-select" name="status" id="status" onchange="this.form.submit()"><option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All statuses</option><option value="pending" <?= $filter === 'pending' ? 'selected' : '' ?>>Pending</option><option value="borrowed" <?= $filter === 'borrowed' ? 'selected' : '' ?>>Borrowed</option><option value="return_requested" <?= $filter === 'return_requested' ? 'selected' : '' ?>>Return requested</option><option value="returned" <?= $filter === 'returned' ? 'selected' : '' ?>>Returned</option><option value="rejected" <?= $filter === 'rejected' ? 'selected' : '' ?>>Rejected</option></select></div></form>
    <div class="table-responsive"><table class="table align-middle app-table mb-0"><thead><tr><th>Student</th><th>Book</th><th>Issue date</th><th>Due date</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>
    <?php foreach ($loans as $loan): ?><tr><td><strong><?= e($loan['student_name']) ?></strong><small class="d-block text-muted"><?= e($loan['student_email']) ?></small></td><td><strong><?= e($loan['title']) ?></strong><small class="d-block text-muted"><?= e($loan['author']) ?></small></td><td><?= $loan['issue_date'] ? e(date('d M Y', strtotime($loan['issue_date']))) : '—' ?></td><td><?= $loan['due_date'] ? e(date('d M Y', strtotime($loan['due_date']))) : '—' ?></td><td><?= status_badge($loan['status']) ?></td><td class="text-end"><div class="d-inline-flex flex-wrap justify-content-end gap-1">
    <?php if ($role === 'librarian' && $loan['status'] === 'pending'): ?><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="approve_request"><input type="hidden" name="borrowing_id" value="<?= (int)$loan['borrowing_id'] ?>"><button class="btn btn-sm btn-success" type="submit"><i class="bi bi-check2 me-1"></i>Approve</button></form><form method="post" class="d-inline" data-confirm="Reject this borrowing request?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="reject_request"><input type="hidden" name="borrowing_id" value="<?= (int)$loan['borrowing_id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Reject</button></form>
    <?php elseif ($role === 'librarian' && in_array($loan['status'], ['borrowed','return_requested'], true)): ?><form method="post" class="d-inline" data-confirm="Confirm that the physical book has been returned?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="record_return"><input type="hidden" name="borrowing_id" value="<?= (int)$loan['borrowing_id'] ?>"><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-check2-circle me-1"></i>Record return</button></form>
    <?php else: ?><span class="small muted-copy"><?= e($loan['processed_name'] ?? '—') ?></span><?php endif; ?></div></td></tr><?php endforeach; ?>
    <?php if (!$loans): ?><tr><td colspan="6" class="text-center text-muted py-4">No transactions match this filter.</td></tr><?php endif; ?>
    </tbody></table></div></div>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
