<?php
require_once __DIR__ . '/config.php';
if (is_logged_in()) {
    redirect('dashboard.php');
}

$error = '';
$locked = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Enter a valid email address and password.';
    } else {
        $attemptStmt = db()->prepare('SELECT attempts, last_attempt FROM login_attempts WHERE email = ? AND ip_address = ?');
        $attemptStmt->execute([$email, $ip]);
        $attempt = $attemptStmt->fetch();
        $withinWindow = $attempt && strtotime($attempt['last_attempt']) > (time() - 900);
        if ($withinWindow && (int)$attempt['attempts'] >= 5) {
            $locked = true;
            $error = 'Too many unsuccessful attempts. Please wait 15 minutes before trying again.';
        } else {
            $stmt = db()->prepare('SELECT user_id, name, email, password, role, status FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$email]);
            $account = $stmt->fetch();

            if ($account && $account['status'] === 'active' && password_verify($password, $account['password'])) {
                db()->prepare('DELETE FROM login_attempts WHERE email = ? AND ip_address = ?')->execute([$email, $ip]);
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'user_id' => (int)$account['user_id'],
                    'name' => $account['name'],
                    'email' => $account['email'],
                    'role' => $account['role'],
                    'status' => $account['status'],
                ];
                audit_log('LOGIN_SUCCESS', 'User signed in.');
                redirect('dashboard.php');
            }

            $nextAttempts = ($withinWindow ? (int)$attempt['attempts'] : 0) + 1;
            $upsert = db()->prepare('INSERT INTO login_attempts (email, ip_address, attempts, last_attempt) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), last_attempt = NOW()');
            $upsert->execute([$email, $ip, $nextAttempts]);
            $error = 'The email or password is incorrect, or the account is inactive.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in | Secure Library</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="auth-body">
<div class="auth-layout">
    <section class="auth-showcase">
        <a class="brand brand-light" href="login.php"><span class="brand-mark"><i class="bi bi-shield-lock-fill"></i></span><span><strong>Secure Library</strong><small>Knowledge, protected.</small></span></a>
        <div class="showcase-copy">
            <span class="showcase-tag"><i class="bi bi-stars me-2"></i>SMARTER LIBRARY. STRONGER SECURITY.</span>
            <h1>Your next great idea is <em>on the shelf.</em></h1>
            <p>A calmer, faster way to discover books and manage every library transaction — with security built in.</p>
            <div class="showcase-features"><span><i class="bi bi-search"></i> Easy discovery</span><span><i class="bi bi-shield-check"></i> Protected records</span><span><i class="bi bi-arrow-repeat"></i> Simple borrowing</span></div>
        </div>
        <div class="showcase-bottom"><span>CONFIDENTIALITY</span><span>INTEGRITY</span><span>ACCESS CONTROL</span></div>
        <div class="decor-circle decor-one"></div><div class="decor-circle decor-two"></div>
    </section>
    <section class="auth-panel">
        <div class="auth-form-wrap">
            <div class="mobile-brand brand"><span class="brand-mark"><i class="bi bi-shield-lock-fill"></i></span><span><strong>Secure Library</strong><small>Knowledge, protected.</small></span></div>
            <span class="eyebrow">WELCOME BACK</span>
            <h2>Sign in to your account</h2>
            <p class="muted-copy mb-4">Enter your details to access your library workspace.</p>
            <?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-circle me-2"></i><?= e($error) ?></div><?php endif; ?>
            <?php if ($message = get_flash()): ?><div class="alert alert-<?= e($message['type']) ?>"><?= e($message['message']) ?></div><?php endif; ?>
            <form method="post" action="login.php" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <label class="form-label" for="email">Email address</label>
                <div class="input-icon-wrap"><i class="bi bi-envelope"></i><input class="form-control" type="email" id="email" name="email" placeholder="you@example.com" value="<?= e($_POST['email'] ?? '') ?>" required autocomplete="username"></div>
                <label class="form-label mt-3" for="password">Password</label>
                <div class="input-icon-wrap"><i class="bi bi-lock"></i><input class="form-control" type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password"><button class="password-toggle" type="button" data-toggle-password="password" aria-label="Show password"><i class="bi bi-eye"></i></button></div>
                <button class="btn btn-primary w-100 auth-submit mt-4" type="submit">Sign in <i class="bi bi-arrow-right ms-2"></i></button>
            </form>
            <p class="auth-switch">New to the library? <a href="register.php">Create a student account</a></p>
            <div class="auth-security"><i class="bi bi-shield-lock"></i><span>Your connection is protected by secure sessions and role-based permissions.</span></div>
        </div>
    </section>
</div>
<script src="assets/js/app.js"></script>
</body></html>
