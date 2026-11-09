<?php
require_once __DIR__ . '/config.php';
require_role('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'create_user') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $role = (string)($_POST['role'] ?? 'student');
        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !password_is_strong($password) || !in_array($role, ['student','librarian','admin'], true)) {
            flash('danger', 'Enter a valid name, email, role and password of at least 8 characters.');
            redirect('users.php');
        }
        try {
            $stmt = db()->prepare("INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, ?, 'active')");
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
            audit_log('USER_CREATED', 'Created ' . $role . ' account: ' . $email . '.');
            flash('success', 'User account created successfully.');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') flash('danger', 'An account with that email address already exists.');
            else { error_log($exception->getMessage()); flash('danger', 'Could not create the user account.'); }
        }
        redirect('users.php');
    }

    if ($action === 'update_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $role = (string)($_POST['role'] ?? 'student');
        $status = (string)($_POST['status'] ?? 'active');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $selfId = (int)current_user()['user_id'];
        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['student','librarian','admin'], true) || !in_array($status, ['active','inactive'], true)) {
            flash('danger', 'Check the user details and selected permissions.');
            redirect('users.php');
        }
        if ($userId === $selfId && ($role !== 'admin' || $status !== 'active')) {
            flash('danger', 'You cannot remove your own administrator access or deactivate your own account.');
            redirect('users.php');
        }
        if ($newPassword !== '' && !password_is_strong($newPassword)) {
            flash('danger', 'A new password must contain at least 8 characters.');
            redirect('users.php');
        }
        $targetStmt = db()->prepare('SELECT user_id, role, status FROM users WHERE user_id = ?');
        $targetStmt->execute([$userId]);
        $target = $targetStmt->fetch();
        if (!$target) {
            flash('warning', 'The selected user was not found.');
            redirect('users.php');
        }
        if ($target['role'] === 'admin' && $target['status'] === 'active' && ($role !== 'admin' || $status !== 'active')) {
            $activeAdmins = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
            if ($activeAdmins <= 1) {
                flash('danger', 'The last active administrator cannot be demoted or deactivated.');
                redirect('users.php');
            }
        }
        try {
            if ($newPassword !== '') {
                $stmt = db()->prepare('UPDATE users SET name = ?, email = ?, role = ?, status = ?, password = ? WHERE user_id = ?');
                $stmt->execute([$name, $email, $role, $status, password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
            } else {
                $stmt = db()->prepare('UPDATE users SET name = ?, email = ?, role = ?, status = ? WHERE user_id = ?');
                $stmt->execute([$name, $email, $role, $status, $userId]);
            }
            audit_log('USER_UPDATED', 'Updated account ID ' . $userId . ' (' . $email . '), role ' . $role . ', status ' . $status . '.');
            flash('success', 'User account and permissions updated.');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') flash('danger', 'That email address is already being used by another account.');
            else { error_log($exception->getMessage()); flash('danger', 'Could not update the account.'); }
        }
        redirect('users.php');
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$roleFilter = (string)($_GET['role'] ?? 'all');
$statusFilter = (string)($_GET['status'] ?? 'all');
$sql = 'SELECT user_id, name, email, role, status, created_at FROM users WHERE 1=1';
$params = [];
if ($search !== '') { $sql .= ' AND (name LIKE ? OR email LIKE ?)'; $like = '%' . $search . '%'; $params[] = $like; $params[] = $like; }
if (in_array($roleFilter, ['student','librarian','admin'], true)) { $sql .= ' AND role = ?'; $params[] = $roleFilter; }
if (in_array($statusFilter, ['active','inactive'], true)) { $sql .= ' AND status = ?'; $params[] = $statusFilter; }
$sql .= ' ORDER BY created_at DESC LIMIT 300';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();
$pageTitle = 'User management';
$activePage = 'users';
require __DIR__ . '/includes/header.php';
?>
<div class="catalogue-intro"><div><span class="eyebrow">ACCESS CONTROL</span><h2>People behind the pages.</h2><p>Create accounts, assign roles and keep access limited to the right people.</p></div><div class="catalogue-count"><strong><?= count($users) ?></strong><span>accounts shown</span></div></div>
<div class="row g-4">
    <div class="col-xl-4"><div class="panel-card h-100"><div class="panel-heading"><div><span class="eyebrow">NEW ACCOUNT</span><h3>Create a user</h3></div><span class="panel-icon"><i class="bi bi-person-plus"></i></span></div>
        <form method="post" class="d-grid gap-3"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create_user">
            <div><label class="form-label" for="new_name">Full name</label><input class="form-control" id="new_name" name="name" maxlength="120" required placeholder="Full name"></div>
            <div><label class="form-label" for="new_email">Email address</label><input class="form-control" id="new_email" name="email" type="email" required placeholder="name@institution.edu"></div>
            <div><label class="form-label" for="new_password">Initial password</label><input class="form-control" id="new_password" name="password" type="password" minlength="8" required placeholder="At least 8 characters"><div class="form-hint">Passwords are stored as secure hashes.</div></div>
            <div><label class="form-label" for="new_role">Role</label><select class="form-select" id="new_role" name="role"><option value="student">Student</option><option value="librarian">Librarian</option><option value="admin">Administrator</option></select></div>
            <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>Create account</button>
        </form>
        <div class="permission-note mt-4"><i class="bi bi-shield-check"></i><div><strong>Role protection</strong><p>Students can only request and view their own books. Librarians manage inventory and transactions. Admins manage accounts and reports.</p></div></div>
    </div>
    <div class="col-xl-8"><div class="panel-card"><div class="panel-heading"><div><span class="eyebrow">REGISTERED ACCOUNTS</span><h3>User directory</h3></div></div>
        <form method="get" class="row g-2 mb-3"><div class="col-md-5"><div class="search-field"><i class="bi bi-search"></i><input class="form-control" name="q" value="<?= e($search) ?>" placeholder="Search name or email"></div></div><div class="col-md-3"><select class="form-select" name="role"><option value="all">All roles</option><option value="student" <?= $roleFilter === 'student' ? 'selected' : '' ?>>Student</option><option value="librarian" <?= $roleFilter === 'librarian' ? 'selected' : '' ?>>Librarian</option><option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Admin</option></select></div><div class="col-md-2"><select class="form-select" name="status"><option value="all">All status</option><option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div><div class="col-md-2"><button class="btn btn-light w-100" type="submit">Filter</button></div></form>
        <div class="accordion user-accordion" id="usersAccordion">
            <?php foreach ($users as $index => $account): ?><div class="accordion-item"><h2 class="accordion-header" id="heading<?= (int)$account['user_id'] ?>"><button class="accordion-button <?= $index > 0 ? 'collapsed' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#user<?= (int)$account['user_id'] ?>"><span class="user-row-avatar"><?= e(strtoupper(substr($account['name'], 0, 1))) ?></span><span class="flex-grow-1"><strong class="d-block"><?= e($account['name']) ?></strong><small class="text-muted"><?= e($account['email']) ?></small></span><span class="me-3 d-none d-md-inline"><?= status_badge($account['status']) ?></span><span class="role-chip role-<?= e($account['role']) ?>"><?= e(role_label($account['role'])) ?></span></button></h2><div id="user<?= (int)$account['user_id'] ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>" data-bs-parent="#usersAccordion"><div class="accordion-body"><form method="post" class="row g-2"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update_user"><input type="hidden" name="user_id" value="<?= (int)$account['user_id'] ?>"><div class="col-md-6"><label class="form-label small" for="name<?= (int)$account['user_id'] ?>">Name</label><input class="form-control form-control-sm" id="name<?= (int)$account['user_id'] ?>" name="name" value="<?= e($account['name']) ?>" required></div><div class="col-md-6"><label class="form-label small" for="email<?= (int)$account['user_id'] ?>">Email</label><input class="form-control form-control-sm" type="email" id="email<?= (int)$account['user_id'] ?>" name="email" value="<?= e($account['email']) ?>" required></div><div class="col-md-4"><label class="form-label small" for="role<?= (int)$account['user_id'] ?>">Role</label><select class="form-select form-select-sm" id="role<?= (int)$account['user_id'] ?>" name="role"><option value="student" <?= $account['role'] === 'student' ? 'selected' : '' ?>>Student</option><option value="librarian" <?= $account['role'] === 'librarian' ? 'selected' : '' ?>>Librarian</option><option value="admin" <?= $account['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option></select></div><div class="col-md-4"><label class="form-label small" for="status<?= (int)$account['user_id'] ?>">Account status</label><select class="form-select form-select-sm" id="status<?= (int)$account['user_id'] ?>" name="status"><option value="active" <?= $account['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $account['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></div><div class="col-md-4"><label class="form-label small" for="pass<?= (int)$account['user_id'] ?>">Reset password (optional)</label><input class="form-control form-control-sm" type="password" id="pass<?= (int)$account['user_id'] ?>" name="new_password" minlength="8" placeholder="Leave blank"></div><div class="col-12 d-flex justify-content-between align-items-center mt-3"><small class="text-muted">Joined <?= e(date('d M Y', strtotime($account['created_at']))) ?> · Account #<?= (int)$account['user_id'] ?></small><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-save me-1"></i>Save changes</button></div></form></div></div></div><?php endforeach; ?>
            <?php if (!$users): ?><div class="empty-state compact"><div class="empty-icon"><i class="bi bi-people"></i></div><h5>No accounts found</h5><p>Change the search or filter values and try again.</p></div><?php endif; ?>
        </div>
    </div></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
