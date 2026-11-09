<?php
require_once __DIR__ . '/config.php';
if (is_logged_in()) {
    redirect('dashboard.php');
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
        $error = 'Enter your full name (2–120 characters).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (!password_is_strong($password)) {
        $error = 'Your password must contain at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $stmt = db()->prepare('INSERT INTO users (name, email, password, role, status) VALUES (?, ?, ?, \'student\', \'active\')');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $newId = (int)db()->lastInsertId();
            audit_log('STUDENT_REGISTERED', 'New student account created: ' . $email);
            flash('success', 'Your student account has been created. You can now sign in.');
            redirect('login.php');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $error = 'An account with this email address already exists.';
            } else {
                error_log($exception->getMessage());
                $error = 'The account could not be created. Please try again.';
            }
        }
    }
}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create account | Secure Library</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"><link href="assets/css/style.css" rel="stylesheet"></head>
<body class="auth-body"><div class="auth-layout">
<section class="auth-showcase"><a class="brand brand-light" href="login.php"><span class="brand-mark"><i class="bi bi-shield-lock-fill"></i></span><span><strong>Secure Library</strong><small>Knowledge, protected.</small></span></a><div class="showcase-copy"><span class="showcase-tag">START YOUR READING JOURNEY</span><h1>Make room for <em>new worlds.</em></h1><p>Create your student account to explore the catalogue, request books and keep track of your reading.</p><div class="showcase-features"><span><i class="bi bi-book"></i> Explore books</span><span><i class="bi bi-journal-check"></i> Track loans</span><span><i class="bi bi-shield-check"></i> Private account</span></div></div><div class="showcase-bottom"><span>CONFIDENTIALITY</span><span>INTEGRITY</span><span>ACCESS CONTROL</span></div><div class="decor-circle decor-one"></div><div class="decor-circle decor-two"></div></section>
<section class="auth-panel"><div class="auth-form-wrap"><div class="mobile-brand brand"><span class="brand-mark"><i class="bi bi-shield-lock-fill"></i></span><span><strong>Secure Library</strong><small>Knowledge, protected.</small></span></div><span class="eyebrow">STUDENT REGISTRATION</span><h2>Create your account</h2><p class="muted-copy mb-4">Register with your name and email address.</p>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" action="register.php" class="auth-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<label class="form-label" for="name">Full name</label><div class="input-icon-wrap"><i class="bi bi-person"></i><input class="form-control" type="text" id="name" name="name" maxlength="120" placeholder="Your full name" value="<?= e($_POST['name'] ?? '') ?>" required autocomplete="name"></div>
<label class="form-label mt-3" for="email">Email address</label><div class="input-icon-wrap"><i class="bi bi-envelope"></i><input class="form-control" type="email" id="email" name="email" placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>" required autocomplete="email"></div>
<label class="form-label mt-3" for="password">Password</label><div class="input-icon-wrap"><i class="bi bi-lock"></i><input class="form-control" type="password" id="password" name="password" minlength="8" placeholder="At least 8 characters" required autocomplete="new-password"><button class="password-toggle" type="button" data-toggle-password="password" aria-label="Show password"><i class="bi bi-eye"></i></button></div>
<label class="form-label mt-3" for="confirm_password">Confirm password</label><div class="input-icon-wrap"><i class="bi bi-lock-fill"></i><input class="form-control" type="password" id="confirm_password" name="confirm_password" minlength="8" placeholder="Repeat your password" required autocomplete="new-password"></div>
<button class="btn btn-primary w-100 auth-submit mt-4" type="submit">Create student account <i class="bi bi-arrow-right ms-2"></i></button></form>
<p class="auth-switch">Already registered? <a href="login.php">Sign in</a></p><div class="auth-security"><i class="bi bi-shield-lock"></i><span>Public registration creates student accounts only. Librarian and admin accounts are controlled by the administrator.</span></div>
</div></section></div><script src="assets/js/app.js"></script></body></html>
