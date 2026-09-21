<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

// Never let an uncaught exception (bad DB creds, an invalid ENUM value,
// a duplicate-key race, etc.) reach the client as a raw PHP error page —
// on a misconfigured host that could leak the DSN, table names, or
// filesystem paths. Log the real error server-side and hand back a
// generic JSON 500 instead, so every response stays JSON and every
// endpoint's error handling in the frontend keeps working.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Belt-and-suspenders alongside .htaccess's mod_deflate: some shared
// hosting plans restrict which Apache modules a .htaccess can enable,
// so compress at the PHP layer too. ob_gzhandler is a no-op if the
// client didn't send Accept-Encoding: gzip or zlib isn't available —
// safe to call unconditionally. The full transactions list is the
// biggest response here (over 1MB once a few months of real data is
// loaded) and compresses to well under a tenth of that.
if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
    ob_start('ob_gzhandler');
}
set_exception_handler(function (Throwable $e): void {
    error_log('[accv2] Unhandled ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => 'Terjadi kesalahan pada server.']);
    exit;
});

function cfg(): array {
    static $cfg = null;
    if ($cfg === null) $cfg = require __DIR__ . '/config.php';
    return $cfg;
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        // Plain $_SERVER['HTTPS'] isn't set when TLS is terminated by a
        // reverse proxy/load balancer in front of PHP (common on shared
        // hosting) — fall back to the standard forwarded-proto header so
        // the cookie still gets marked secure on a real HTTPS deploy.
        'secure' => !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'),
    ]);
    session_name('accv2_session');
    session_start();
}

function send_cors_headers(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin && in_array($origin, cfg()['cors_origins'], true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void {
    json_response(['error' => $message], $status);
}

/** php://input can only be read once, so the parsed body is cached.
 *  Pass $override to permanently replace the cache — used to inject
 *  server-trusted fields (e.g. created_by from the session) that the
 *  client must never be able to spoof via the request body itself. */
function read_json_body(?array $override = null): array {
    static $cached = null;
    if ($override !== null) return $cached = $override;
    if ($cached !== null) return $cached;
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return $cached = [];
    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Invalid JSON body', 400);
    return $cached = $data;
}

/** Returns the logged-in user's session row, or null. Never trusts the
 *  client for identity — always re-derived from the server-side session. */
function current_user(): ?array {
    start_session();
    return $_SESSION['user'] ?? null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) json_error('Belum login.', 401);
    return $u;
}

/** Like require_login(), but 403s unless the user's role is in $roles. */
function require_role(array $roles): array {
    $u = require_login();
    if (!in_array($u['role'], $roles, true)) json_error('Anda tidak punya akses untuk aksi ini.', 403);
    return $u;
}

/** Project IDs a 'lapangan' user is allowed to touch. admin/owner get
 *  null, meaning "no restriction" — callers must check for that. */
function user_project_ids(array $user): ?array {
    if ($user['role'] !== 'lapangan') return null;
    $stmt = db()->prepare('SELECT project_id FROM user_projects WHERE user_id = ?');
    $stmt->execute([$user['id']]);
    return array_column($stmt->fetchAll(), 'project_id');
}

/** 403s if $user (a 'lapangan' user) isn't assigned to $projectId.
 *  No-op for admin/owner. */
function require_project_access(array $user, string $projectId): void {
    $scope = user_project_ids($user);
    if ($scope !== null && !in_array($projectId, $scope, true)) {
        json_error('Anda tidak punya akses ke proyek ini.', 403);
    }
}

function audit(string $action, string $entity, string $entityId, string $detail = ''): void {
    $u = current_user();
    $stmt = db()->prepare(
        'INSERT INTO audit_log (user_id, user_email, action, entity, entity_id, detail, ip_address, user_agent) VALUES (?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $u['id'] ?? null, $u['email'] ?? null, $action, $entity, $entityId, $detail,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255) ?: null,
    ]);
}
