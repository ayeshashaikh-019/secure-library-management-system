<?php
declare(strict_types=1);

/**
 * Secure Library Management System
 * Main application configuration and shared security helpers.
 */

date_default_timezone_set('Asia/Karachi');

const APP_NAME = 'Secure Library';
const APP_TIMEZONE = 'Asia/Karachi';
const MAX_ACTIVE_LOANS = 3;       // Demo library rule; adjust to your institution's policy.
const LOAN_PERIOD_DAYS = 14;      // Demo library rule; adjust to your institution's policy.

$dbHost = '127.0.0.1';
$dbName = 'secure_library';
$dbUser = 'root';
$dbPass = ''; // Default XAMPP MySQL password is blank. Change this if you configured one.

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('secure_library_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

try {
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $exception) {
    error_log('Secure Library database connection failed: ' . $exception->getMessage());
    http_response_code(500);
    exit('Database connection failed. Start MySQL in XAMPP and import database.sql.');
}

function db(): PDO
{
    global $pdo;
    return $pdo;
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    $known = $_SESSION['csrf_token'] ?? '';
    if (!is_string($sent) || !is_string($known) || $known === '' || !hash_equals($known, $sent)) {
        http_response_code(419);
        exit('Your session token has expired or is invalid. Go back, refresh the page, and try again.');
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('warning', 'Please log in to continue.');
        redirect('login.php');
    }

    // Refresh the user's status and role from the database on every protected page.
    $stmt = db()->prepare('SELECT user_id, name, email, role, status FROM users WHERE user_id = ? LIMIT 1');
    $stmt->execute([current_user()['user_id']]);
    $freshUser = $stmt->fetch();

    if (!$freshUser || $freshUser['status'] !== 'active') {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('danger', 'Your account is inactive or no longer available. Contact the administrator.');
        redirect('login.php');
    }

    $_SESSION['user'] = $freshUser;
}

function require_role(string ...$roles): void
{
    require_login();
    if (!in_array(current_user()['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied. Your account does not have permission to open this page.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $message = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $message;
}

function audit_log(string $action, string $details = ''): void
{
    try {
        $userId = current_user()['user_id'] ?? null;
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);
        $stmt = db()->prepare('INSERT INTO activity_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, substr($action, 0, 100), $details, $ip]);
    } catch (Throwable $exception) {
        // Logging must not break a normal library operation. Details go to the PHP error log.
        error_log('Secure Library audit log failed: ' . $exception->getMessage());
    }
}

function library_setting(string $key, int $default): int
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = db()->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        $cache[$key] = ($value !== false && filter_var($value, FILTER_VALIDATE_INT) !== false)
            ? (int)$value
            : $default;
    } catch (Throwable $exception) {
        // Defaults are used until the schema has been imported or when settings are unavailable.
        $cache[$key] = $default;
    }
    return $cache[$key];
}

function password_is_strong(string $password): bool
{
    return strlen($password) >= 8;
}

function status_badge(string $status): string
{
    $map = [
        'active' => 'success', 'inactive' => 'secondary',
        'pending' => 'warning', 'borrowed' => 'primary',
        'return_requested' => 'info', 'returned' => 'success', 'rejected' => 'danger',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge text-bg-' . $class . '">' . e(ucwords(str_replace('_', ' ', $status))) . '</span>';
}

function role_label(string $role): string
{
    return match ($role) {
        'admin' => 'Administrator',
        'librarian' => 'Librarian',
        default => 'Student',
    };
}
