<?php
declare(strict_types=1);

/**
 * Shared bootstrap: error policy, session, security headers, helpers,
 * database connection, authentication and authorization helpers.
 */

const TASK_STATUSES = ['Pending', 'In Progress', 'Completed'];
const TITLE_MAX = 150;
const DESC_MAX = 2000;

// ---------------------------------------------------------------- mbstring fallbacks
// The mbstring extension is normally present (XAMPP, Laragon, Docker php images).
// These tiny fallbacks keep the app working on minimal PHP builds that lack it.
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s, ?string $encoding = null): int
    {
        return preg_match_all('/./us', $s) ?: 0;
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null, ?string $encoding = null): string
    {
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('', array_slice($chars, $start, $length));
    }
}

// ---------------------------------------------------------------- errors
// Never leak raw PHP / database errors to the browser; log them instead.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $e): void {
    error_log('[task-tracker] ' . get_class($e) . ': ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo '<!doctype html><meta charset="utf-8"><title>Something went wrong</title>'
       . '<body style="font-family:sans-serif;max-width:32rem;margin:4rem auto">'
       . '<h1>Something went wrong</h1><p>The request could not be completed. '
       . 'Please try again later.</p></body>';
    exit;
});

// ---------------------------------------------------------------- session
session_name('tt_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']),
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; form-action 'self'");

// ---------------------------------------------------------------- helpers
function config(string $key, $default = null)
{
    static $cfg = null;
    $cfg ??= require __DIR__ . '/../config.php';
    return $cfg[$key] ?? $default;
}

/** Escape output for HTML (XSS protection). */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function status_class(string $status): string
{
    return match ($status) {
        'In Progress' => 'badge-progress',
        'Completed'   => 'badge-done',
        default       => 'badge-pending',
    };
}

function excerpt(string $text, int $max = 90): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text));
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
}

// ---------------------------------------------------------------- CSRF
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/** Call at the top of every state-changing (POST) handler. */
function csrf_check(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Your session expired or the request was not valid. Go back, refresh the page and try again.');
    }
}

function require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method not allowed.');
    }
}

// ---------------------------------------------------------------- database
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            config('db_host'), config('db_port'), config('db_name')
        );
        $pdo = new PDO($dsn, config('db_user'), config('db_pass'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
        ]);
    }
    return $pdo;
}

// ---------------------------------------------------------------- authentication
/** Returns the logged-in user row (re-read from the DB on every request) or null. */
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $stmt = db()->prepare('SELECT id, name, email, role FROM users WHERE id = :id');
            $stmt->execute([':id' => (int) $_SESSION['user_id']]);
            $user = $stmt->fetch() ?: null;
            if ($user === null) {
                unset($_SESSION['user_id']); // account deleted since login
            }
        }
    }
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        flash('error', 'Please log in to continue.');
        redirect('login.php');
    }
    return $user;
}

function is_admin(array $user): bool
{
    return $user['role'] === 'admin';
}

// ---------------------------------------------------------------- authorization
/**
 * Roles & rules (enforced here, in the backend):
 *  - user  : may view / edit / change status of / delete ONLY tasks where tasks.user_id = their id
 *  - admin : may view / edit / change status of / delete ALL tasks
 * Every task lookup and every write goes through these two helpers.
 */
function ownership_sql(array $user): array
{
    return is_admin($user)
        ? ['', []]
        : [' AND user_id = :owner_id', [':owner_id' => (int) $user['id']]];
}

/** Returns the task if the user may access it, otherwise null (callers answer 404). */
function find_task_for_user(int $taskId, array $user): ?array
{
    [$sql, $params] = ownership_sql($user);
    $stmt = db()->prepare('SELECT * FROM tasks WHERE id = :id' . $sql);
    $stmt->execute([':id' => $taskId] + $params);
    return $stmt->fetch() ?: null;
}

function abort_not_found(): never
{
    http_response_code(404);
    exit('Task not found.');
}

// ---------------------------------------------------------------- validation
/** @return array{0: array<string,string>, 1: array<string,string>} [errors, cleaned values] */
function validate_task(array $input): array
{
    $title  = trim((string) ($input['title'] ?? ''));
    $desc   = trim((string) ($input['description'] ?? ''));
    $status = (string) ($input['status'] ?? 'Pending');
    $errors = [];

    if ($title === '') {
        $errors['title'] = 'Please enter a title for the task.';
    } elseif (mb_strlen($title) > TITLE_MAX) {
        $errors['title'] = 'The title must be ' . TITLE_MAX . ' characters or fewer.';
    }
    if (mb_strlen($desc) > DESC_MAX) {
        $errors['description'] = 'The description must be ' . DESC_MAX . ' characters or fewer.';
    }
    if (!in_array($status, TASK_STATUSES, true)) {
        $errors['status'] = 'Choose Pending, In Progress or Completed.';
    }

    return [$errors, ['title' => $title, 'description' => $desc, 'status' => $status]];
}

/** Whitelisted dashboard query string (q, status, page, scope) from any array. */
function dashboard_url(array $src, array $override = []): string
{
    $p = [];
    foreach (['q', 'status', 'page', 'scope'] as $k) {
        $v = $override[$k] ?? ($src[$k] ?? '');
        if (is_string($v) && $v !== '' && !($k === 'page' && $v === '1')) {
            $p[$k] = $v;
        }
    }
    return 'index.php' . ($p ? '?' . http_build_query($p) : '');
}

/** Rebuilds the dashboard URL (filters/page) a POST came from; hidden fields are prefixed f_. */
function return_url_from_post(): string
{
    $src = [];
    foreach (['q', 'status', 'page', 'scope'] as $k) {
        $src[$k] = $_POST['f_' . $k] ?? '';
    }
    return dashboard_url($src);
}

// ---------------------------------------------------------------- layout
function render_header(string $title, ?array $user = null): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · Task Tracker</title>
<link rel="stylesheet" href="assets/style.css">
<script src="assets/app.js" defer></script>
</head>
<body>
<header class="topbar">
  <a class="brand" href="index.php">Task Tracker</a>
  <?php if ($user): ?>
  <nav class="topbar-right">
    <span class="whoami"><?= e($user['name']) ?><?= is_admin($user) ? ' (admin)' : '' ?></span>
    <form method="post" action="logout.php"><?= csrf_field() ?><button class="btn btn-ghost" type="submit">Log out</button></form>
  </nav>
  <?php endif; ?>
</header>
<main class="container">
<?php foreach (pull_flash() as $f): ?>
  <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['message']) ?></div>
<?php endforeach; ?>
<?php
}

function render_footer(): void
{
    echo "</main>\n</body>\n</html>\n";
}
