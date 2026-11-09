<?php
require_once __DIR__ . '/config.php';
require_login();
$canManage = in_array(current_user()['role'], ['librarian', 'admin'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        http_response_code(403);
        exit('Only librarians and administrators can change book records.');
    }
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save_book') {
        $bookId = (int)($_POST['book_id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $author = trim((string)($_POST['author'] ?? ''));
        $isbn = trim((string)($_POST['isbn'] ?? ''));
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $totalCopies = filter_var($_POST['total_copies'] ?? null, FILTER_VALIDATE_INT);

        if ($title === '' || $author === '' || $isbn === '' || $categoryId <= 0 || $totalCopies === false || $totalCopies < 1) {
            flash('danger', 'Complete all book fields. Total copies must be at least 1.');
            redirect($bookId > 0 ? 'books.php?edit=' . $bookId : 'books.php');
        }
        if (mb_strlen($title) > 180 || mb_strlen($author) > 140 || mb_strlen($isbn) > 30) {
            flash('danger', 'One or more book fields exceed the allowed length.');
            redirect('books.php');
        }
        $categoryCheck = db()->prepare('SELECT category_id FROM categories WHERE category_id = ?');
        $categoryCheck->execute([$categoryId]);
        if (!$categoryCheck->fetch()) {
            flash('danger', 'Please choose a valid category.');
            redirect('books.php');
        }

        try {
            if ($bookId > 0) {
                db()->beginTransaction();
                $oldStmt = db()->prepare('SELECT total_copies, available_copies FROM books WHERE book_id = ? FOR UPDATE');
                $oldStmt->execute([$bookId]);
                $old = $oldStmt->fetch();
                if (!$old) {
                    throw new RuntimeException('The selected book could not be found.');
                }
                $checkedOut = (int)$old['total_copies'] - (int)$old['available_copies'];
                if ($totalCopies < $checkedOut) {
                    throw new RuntimeException('Total copies cannot be lower than the number currently borrowed (' . $checkedOut . ').');
                }
                $available = $totalCopies - $checkedOut;
                $update = db()->prepare('UPDATE books SET title = ?, author = ?, ISBN = ?, category_id = ?, total_copies = ?, available_copies = ? WHERE book_id = ?');
                $update->execute([$title, $author, $isbn, $categoryId, $totalCopies, $available, $bookId]);
                db()->commit();
                audit_log('BOOK_UPDATED', 'Updated book: ' . $title . ' (ID ' . $bookId . ').');
                flash('success', 'Book information updated successfully.');
            } else {
                $insert = db()->prepare('INSERT INTO books (title, author, ISBN, category_id, total_copies, available_copies) VALUES (?, ?, ?, ?, ?, ?)');
                $insert->execute([$title, $author, $isbn, $categoryId, $totalCopies, $totalCopies]);
                audit_log('BOOK_CREATED', 'Added book: ' . $title . '.');
                flash('success', 'New book added to the catalogue.');
            }
        } catch (PDOException $exception) {
            if (db()->inTransaction()) db()->rollBack();
            if ($exception->getCode() === '23000') {
                flash('danger', 'That ISBN already exists. Each book record must have a unique ISBN.');
            } else {
                error_log($exception->getMessage());
                flash('danger', 'The book could not be saved. Please try again.');
            }
        } catch (Throwable $exception) {
            if (db()->inTransaction()) db()->rollBack();
            flash('danger', $exception->getMessage());
        }
        redirect('books.php');
    }

    if ($action === 'delete_book') {
        $bookId = (int)($_POST['book_id'] ?? 0);
        try {
            $stmt = db()->prepare('DELETE FROM books WHERE book_id = ?');
            $stmt->execute([$bookId]);
            if ($stmt->rowCount() > 0) {
                audit_log('BOOK_DELETED', 'Deleted book ID ' . $bookId . '.');
                flash('success', 'Book record deleted.');
            } else {
                flash('warning', 'The book record was not found.');
            }
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                flash('danger', 'This book has borrowing history and cannot be deleted. Keep the record to preserve transaction integrity.');
            } else {
                error_log($exception->getMessage());
                flash('danger', 'The book could not be deleted.');
            }
        }
        redirect('books.php');
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$categoryFilter = (int)($_GET['category'] ?? 0);
$availability = (string)($_GET['availability'] ?? '');
$sql = 'SELECT b.*, c.category_name FROM books b JOIN categories c ON c.category_id = b.category_id WHERE 1=1';
$params = [];
if ($search !== '') {
    $sql .= ' AND (b.title LIKE ? OR b.author LIKE ? OR b.ISBN LIKE ? OR c.category_name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($categoryFilter > 0) {
    $sql .= ' AND b.category_id = ?';
    $params[] = $categoryFilter;
}
if ($availability === 'available') $sql .= ' AND b.available_copies > 0';
if ($availability === 'unavailable') $sql .= ' AND b.available_copies = 0';
$sql .= ' ORDER BY b.title ASC LIMIT 200';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll();
$categories = db()->query('SELECT category_id, category_name FROM categories ORDER BY category_name')->fetchAll();
$editBook = null;
if ($canManage && isset($_GET['edit'])) {
    $editStmt = db()->prepare('SELECT * FROM books WHERE book_id = ?');
    $editStmt->execute([(int)$_GET['edit']]);
    $editBook = $editStmt->fetch() ?: null;
}
$pageTitle = 'Book catalogue';
$activePage = 'books';
require __DIR__ . '/includes/header.php';
?>
<div class="catalogue-intro"><div><span class="eyebrow">THE COLLECTION</span><h2>Discover your next read.</h2><p>Search by title, author, ISBN or category and check availability across the library.</p></div><div class="catalogue-count"><strong><?= count($books) ?></strong><span>matching books</span></div></div>
<?php if ($canManage): ?>
<div class="panel-card management-panel mb-4" id="book-form">
    <div class="panel-heading"><div><span class="eyebrow">INVENTORY CONTROL</span><h3><?= $editBook ? 'Update book details' : 'Add a new book' ?></h3></div><?php if ($editBook): ?><a class="btn btn-light btn-sm" href="books.php"><i class="bi bi-x-lg me-1"></i>Cancel edit</a><?php else: ?><span class="small muted-copy">Librarian / Admin only</span><?php endif; ?></div>
    <form method="post" action="books.php" class="row g-3">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_book"><input type="hidden" name="book_id" value="<?= (int)($editBook['book_id'] ?? 0) ?>">
        <div class="col-md-6"><label class="form-label" for="title">Book title</label><input class="form-control" id="title" name="title" maxlength="180" required value="<?= e($editBook['title'] ?? '') ?>" placeholder="e.g. The Alchemist"></div>
        <div class="col-md-6"><label class="form-label" for="author">Author</label><input class="form-control" id="author" name="author" maxlength="140" required value="<?= e($editBook['author'] ?? '') ?>" placeholder="Author name"></div>
        <div class="col-md-4"><label class="form-label" for="isbn">ISBN</label><input class="form-control" id="isbn" name="isbn" maxlength="30" required value="<?= e($editBook['ISBN'] ?? '') ?>" placeholder="Unique ISBN"></div>
        <div class="col-md-4"><label class="form-label" for="category_id">Category</label><select class="form-select" id="category_id" name="category_id" required><option value="">Choose category</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>" <?= (int)($editBook['category_id'] ?? 0) === (int)$category['category_id'] ? 'selected' : '' ?>><?= e($category['category_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="total_copies">Total copies</label><input class="form-control" type="number" min="1" max="10000" id="total_copies" name="total_copies" required value="<?= e($editBook['total_copies'] ?? 1) ?>"></div>
        <div class="col-12 d-flex justify-content-end"><button class="btn btn-primary px-4" type="submit"><i class="bi bi-<?= $editBook ? 'check2' : 'plus-lg' ?> me-1"></i><?= $editBook ? 'Save changes' : 'Add book' ?></button></div>
    </form>
</div>
<?php endif; ?>
<div class="panel-card">
    <form method="get" action="books.php" class="catalogue-filter row g-2 align-items-end">
        <div class="col-lg-5"><label class="form-label" for="q">Search catalogue</label><div class="search-field"><i class="bi bi-search"></i><input class="form-control" id="q" name="q" value="<?= e($search) ?>" placeholder="Search title, author, ISBN..."></div></div>
        <div class="col-sm-6 col-lg-3"><label class="form-label" for="category">Category</label><select class="form-select" id="category" name="category"><option value="0">All categories</option><?php foreach ($categories as $category): ?><option value="<?= (int)$category['category_id'] ?>" <?= $categoryFilter === (int)$category['category_id'] ? 'selected' : '' ?>><?= e($category['category_name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-sm-6 col-lg-2"><label class="form-label" for="availability">Availability</label><select class="form-select" id="availability" name="availability"><option value="">Any status</option><option value="available" <?= $availability === 'available' ? 'selected' : '' ?>>Available</option><option value="unavailable" <?= $availability === 'unavailable' ? 'selected' : '' ?>>Unavailable</option></select></div>
        <div class="col-lg-2 d-flex gap-2"><button class="btn btn-primary flex-grow-1" type="submit">Search</button><a class="btn btn-light" href="books.php" title="Clear filters"><i class="bi bi-arrow-counterclockwise"></i></a></div>
    </form>
    <div class="d-flex justify-content-between align-items-center mt-4 mb-2"><div><h3 class="table-title">Library books</h3><p class="small muted-copy mb-0">Showing up to 200 matching records.</p></div><span class="small muted-copy"><i class="bi bi-info-circle me-1"></i>Availability updates automatically</span></div>
    <div class="table-responsive"><table class="table align-middle app-table mb-0"><thead><tr><th>Book details</th><th>Category</th><th>ISBN</th><th>Copies</th><th>Availability</th><?php if ($canManage): ?><th class="text-end">Actions</th><?php else: ?><th class="text-end">Request</th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($books as $book): ?><tr>
        <td><div class="book-cell"><span class="book-mini"><i class="bi bi-book-half"></i></span><div><strong><?= e($book['title']) ?></strong><small><?= e($book['author']) ?></small></div></div></td>
        <td><span class="category-pill"><?= e($book['category_name']) ?></span></td><td><code><?= e($book['ISBN']) ?></code></td><td><?= (int)$book['available_copies'] ?> / <?= (int)$book['total_copies'] ?></td>
        <td><?php if ((int)$book['available_copies'] > 0): ?><span class="availability available"><i class="bi bi-check-circle-fill"></i> Available</span><?php else: ?><span class="availability unavailable"><i class="bi bi-x-circle-fill"></i> Out of stock</span><?php endif; ?></td>
        <?php if ($canManage): ?><td class="text-end"><div class="d-inline-flex gap-1"><a class="btn btn-sm btn-light action-btn" href="books.php?edit=<?= (int)$book['book_id'] ?>#book-form" title="Edit book"><i class="bi bi-pencil"></i></a><form method="post" action="books.php" class="d-inline" data-confirm="Delete this book record? This is only possible when it has no borrowing history."><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_book"><input type="hidden" name="book_id" value="<?= (int)$book['book_id'] ?>"><button class="btn btn-sm btn-light action-btn text-danger" type="submit" title="Delete book"><i class="bi bi-trash3"></i></button></form></div></td>
        <?php else: ?><td class="text-end"><?php if ((int)$book['available_copies'] > 0): ?><form method="post" action="transactions.php" class="d-inline"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="request_borrow"><input type="hidden" name="book_id" value="<?= (int)$book['book_id'] ?>"><button class="btn btn-sm btn-primary" type="submit">Request <i class="bi bi-arrow-right-short"></i></button></form><?php else: ?><button class="btn btn-sm btn-light" disabled>Unavailable</button><?php endif; ?></td><?php endif; ?>
    </tr><?php endforeach; ?>
    <?php if (!$books): ?><tr><td colspan="6"><div class="empty-state compact"><div class="empty-icon"><i class="bi bi-search"></i></div><h5>No books found</h5><p>Try another keyword or clear the selected filters.</p></div></td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
