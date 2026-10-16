<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

// Never let an uncaught exception (bad DB creds, an invalid ENUM value,
// a duplicate-key race, etc.) reach the client as a raw PHP error page —
// on a misconfigured host that could leak the DSN, table names, or
// filesystem paths. Log the real error server-side and hand back a
// generic JSON 500 instead, so every response stays JSON and every
// endpoint's error handling in the frontend keeps working.
// Indonesian business dates ("today", period edges, week boundaries) must
// follow local time, not UTC, or entries made before 07.00 WIB land on
// yesterday. Hosts that run elsewhere can override with 'timezone' in config.php.
date_default_timezone_set('Asia/Jakarta');
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
    error_log('[material] Unhandled ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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

/**
 * Sessions live in the database, not in PHP's session files. Files are what
 * get a user "thrown out" on shared hosting: the temp folder is purged by
 * whichever site's (short) garbage-collection setting runs first, restarts
 * wipe it, and several PHP workers do not always see the same folder. A row in
 * MySQL survives all of that, and its lifetime is ours to decide: a year from
 * the last request (sliding). If the database cannot be used for sessions the
 * code quietly falls back to files.
 */
class DbSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface {
    private int $lifetime;
    public function __construct(int $lifetime) { $this->lifetime = $lifetime; }
    public static function ensureTable(): void {
        db()->exec('CREATE TABLE IF NOT EXISTS php_sessions (
            id VARCHAR(128) NOT NULL PRIMARY KEY, data MEDIUMBLOB NOT NULL, last_activity INT UNSIGNED NOT NULL,
            INDEX idx_sess_activity (last_activity)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }
    /** Runs a statement; if the sessions table does not exist yet, creates it once and retries. */
    private function run(string $sql, array $params): PDOStatement {
        for ($try = 0; ; $try++) {
            try { $st = db()->prepare($sql); $st->execute($params); return $st; }
            catch (PDOException $e) {
                if ($try === 0 && ($e->getCode() === '42S02' || stripos($e->getMessage(), "doesn't exist") !== false)) { self::ensureTable(); continue; }
                throw $e;
            }
        }
    }
    /** One SELECT serves validateId() and read(); the sliding timestamp is only written every few minutes. */
    private ?array $row = null;
    private function fetchRow(string $id): ?array {
        if ($this->row !== null && $this->row['id'] === $id) return $this->row;
        $st = $this->run('SELECT data, last_activity FROM php_sessions WHERE id = ?', [$id]);
        $r = $st->fetch();
        $this->row = $r && (int)$r['last_activity'] > time() - $this->lifetime ? ['id' => $id, 'data' => (string)$r['data'], 'last' => (int)$r['last_activity']] : ['id' => $id, 'data' => null, 'last' => 0];
        return $this->row;
    }
    public function read(string $id): string|false {
        $r = $this->fetchRow($id);
        return $r['data'] ?? '';
    }
    public function write(string $id, string $data): bool {
        $this->run('INSERT INTO php_sessions (id, data, last_activity) VALUES (?,?,?) ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = VALUES(last_activity)', [$id, $data, time()]);
        return true;
    }
    public function updateTimestamp(string $id, string $data): bool {
        $r = $this->fetchRow($id);
        if ($r['data'] !== null && time() - $r['last'] < 300) return true; // touched within 5 min: skip the write
        $this->run('UPDATE php_sessions SET last_activity = ? WHERE id = ?', [time(), $id]);
        return true;
    }
    public function validateId(string $id): bool {
        return $this->fetchRow($id)['data'] !== null;
    }
    public function destroy(string $id): bool { $this->run('DELETE FROM php_sessions WHERE id = ?', [$id]); return true; }
    public function gc(int $max_lifetime): int|false {
        return $this->run('DELETE FROM php_sessions WHERE last_activity < ?', [time() - $this->lifetime])->rowCount();
    }
}

function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $lifetime = 60 * 60 * 24 * 365; // signed in for a year after the last use, unless the user logs out
    $secure = !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => $lifetime, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        // Plain $_SERVER['HTTPS'] isn't set when TLS is terminated by a reverse proxy in
        // front of PHP (common on shared hosting) — also honour the forwarded-proto header.
        'secure' => $secure,
    ]);
    session_name('material_session');
    $usedDb = false;
    try {
        db(); // fail here (and fall back to files) if the database is unreachable
        session_set_save_handler(new DbSessionHandler($lifetime), true);
        $usedDb = true;
    } catch (Throwable $e) {
        error_log('[material] DB sessions unavailable, using files: ' . $e->getMessage());
        $dir = __DIR__ . '/../sessions';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        if (is_dir($dir) && is_writable($dir)) session_save_path($dir);
    }
    session_start();
    // Sliding window: every request pushes the cookie's expiry out again, so an active
    // phone never reaches the end of its 30 days.
    if (PHP_SAPI !== 'cli' && !headers_sent() && session_id() !== '') {
        setcookie(session_name(), session_id(), ['expires' => time() + $lifetime, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    }
}

function send_security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    if (!empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
        header('Strict-Transport-Security: max-age=31536000');
    }
    header('Cache-Control: no-store');
    header('X-LiteSpeed-Cache-Control: no-cache'); // hosting-level page caches must never keep API answers
}

/** 422s unless $v is a real calendar date (YYYY-MM-DD) that is not in
 *  the future — one day of slack covers a phone clock a little ahead. */
function require_valid_date($v, string $label = 'Tanggal'): string {
    $v = (string)$v;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) json_error("$label tidak valid.", 422);
    if ($v > date('Y-m-d', time() + 86400)) json_error("$label tidak boleh di masa depan.", 422);
    if ($v < '2000-01-01') json_error("$label tidak valid.", 422);
    return $v;
}

/** Cross-site request forgery defence on top of SameSite cookies: a browser can
 *  only send a *cross-site* form/beacon as a "simple" request, never as JSON,
 *  and a foreign page's Origin never matches ours. Both are rejected here. */
function guard_cross_site(): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) return;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && $origin !== 'null') {
        // Compare host names only, ignoring port and a leading "www.", and accept the
        // host the proxy forwarded — a real user behind a CDN/redirect must never be refused.
        $norm = fn($h) => preg_replace('/^www\./', '', strtolower(preg_replace('/:\d+$/', '', trim((string)$h))));
        $oh = $norm(parse_url($origin, PHP_URL_HOST));
        $hosts = [$norm($_SERVER['HTTP_HOST'] ?? ''), $norm(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''))[0]), $norm($_SERVER['SERVER_NAME'] ?? '')];
        $allowed = cfg()['cors_origins'] ?? [];
        if (!in_array($oh, array_filter($hosts), true) && !in_array($origin, $allowed, true)) json_error('Permintaan ditolak.', 403);
    }
    $ct = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($ct, 'application/json') !== 0) json_error('Permintaan ditolak.', 415);
}

function send_cors_headers(): void {
    send_security_headers();
    guard_cross_site();
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

/** Endpoints an 'akuntan' (accountant) account may call. Everything else —
 *  stock, materials, RAP, users — answers 403 for that role, enforced here
 *  once so no individual endpoint can forget it. */
const AKUNTAN_ENDPOINTS = [
    'auth_me.php', 'auth_logout.php', 'projects.php', 'workers.php', 'labor_rates.php',
    'attendance.php', 'payroll.php', 'payroll_periods.php', 'payroll_adjustments.php', 'kasbon.php', 'kasbon_requests.php',
];

function require_login(): array {
    $u = current_user();
    if (!$u) json_error('Belum login.', 401);
    ensure_payroll_controls();
    if ($u['role'] === 'akuntan' && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), AKUNTAN_ENDPOINTS, true)) {
        json_error('Anda tidak punya akses untuk aksi ini.', 403);
    }
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
        'INSERT INTO audit_log (user_id, user_username, action, entity, entity_id, detail, ip_address, user_agent) VALUES (?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([
        $u['id'] ?? null, $u['username'] ?? null, $action, substr($entity, 0, 40), substr($entityId, 0, 40), $detail,
        $_SERVER['REMOTE_ADDR'] ?? null,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255) ?: null,
    ]);
}

/** Creates stock_counts if a deployment never ran the migration, so a
 *  forgotten SQL step can't take Monitor Stok / Dashboard down. Returns
 *  false if the DB user may not create tables (caller then degrades). */
function ensure_stock_counts_table(): bool {
    try {
        db()->exec('CREATE TABLE IF NOT EXISTS stock_counts (
          id VARCHAR(120) PRIMARY KEY,
          project_id VARCHAR(40) NOT NULL,
          material_id VARCHAR(40) NOT NULL,
          tgl DATE NOT NULL,
          qty DECIMAL(18,3) NOT NULL DEFAULT 0,
          ket TEXT NULL,
          created_by VARCHAR(40) NULL,
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          UNIQUE KEY uniq_count_day (project_id, material_id, tgl),
          FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
          FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
          FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        return true;
    } catch (Throwable $e) {
        error_log('[material] ensure_stock_counts_table: ' . $e->getMessage());
        return false;
    }
}


/* ---------------- Absensi & gaji ---------------- */

/** Status pekerja. Kode dipakai di database, nama untuk tampilan. */
const WORKER_GRADES = [
    'KTK' => 'Kepala Tukang', 'TK' => 'Tukang', 'LD' => 'Laden',
    'OBK' => 'Operator Beko', 'SPR' => 'Supir', 'OMX' => 'Operator Mixer', 'LAIN' => 'Lainnya',
];

/** Gaji dibayar mingguan, Jumat–Kamis (sesuai lembar pengajuan absen). */
function payroll_week(string $tgl): array {
    $t = strtotime($tgl . ' 12:00:00');
    $daysSinceFriday = ((int)date('N', $t) + 2) % 7; // N: Mon=1..Sun=7; Fri=5 -> 0
    $start = strtotime("-$daysSinceFriday days", $t);
    return [date('Y-m-d', $start), date('Y-m-d', strtotime('+6 days', $start))];
}

/** Returns the (project, week) period row, creating a draft if needed. */
function payroll_period(string $projectId, string $tgl): array {
    [$start, $end] = payroll_week($tgl);
    $id = 'per_' . $projectId . '_' . $start;
    db()->prepare('INSERT IGNORE INTO payroll_periods (id, project_id, tgl_mulai, tgl_akhir) VALUES (?,?,?,?)')
        ->execute([$id, $projectId, $start, $end]);
    $st = db()->prepare('SELECT * FROM payroll_periods WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}

/** Jam lembur yang diinput = jam yang dibayar (tidak ada pengurangan tersembunyi).
 *  Lembar kertas lama menulis "2" untuk lembur yang dibayar 1,5 jam; di sistem
 *  cukup ketik 1,5. Jika suatu saat perlu potongan istirahat otomatis, ubah
 *  konstanta ini (mis. 0.5) — seluruh rekap, Excel, dan pembayaran ikut. */
const LEMBUR_ISTIRAHAT_JAM = 0.0;
function lembur_paid_hours(float $jam): float {
    return $jam > 0 ? max(0.0, $jam - LEMBUR_ISTIRAHAT_JAM) : 0.0;
}

/** Lembur per jam when none is configured: gaji harian / 8, rounded down to Rp500. */
function default_lembur_rate(float $harian): float {
    return floor($harian / 8 / 500) * 500;
}

/** Effective rates for a worker: personal override, else the project's status rate. */
function worker_rates(array $worker): array {
    // Rate order: the worker's own override, then the rate of the worker's tim (if the tim has one), then the project's standard rate.
    $st = db()->prepare("SELECT tarif_harian, tarif_lembur FROM labor_rates WHERE project_id = ? AND grade = ? AND tim IN (?, '') ORDER BY tim DESC LIMIT 1");
    $st->execute([$worker['project_id'], $worker['grade'], (string)($worker['tim'] ?? '')]);
    $r = $st->fetch() ?: ['tarif_harian' => 0, 'tarif_lembur' => 0];
    $harian = $worker['tarif_harian'] !== null ? (float)$worker['tarif_harian'] : (float)$r['tarif_harian'];
    if ($worker['tarif_lembur'] !== null) $lembur = (float)$worker['tarif_lembur'];
    elseif ($worker['tarif_harian'] === null && (float)$r['tarif_lembur'] > 0) $lembur = (float)$r['tarif_lembur'];
    else $lembur = default_lembur_rate($harian);
    return [$harian, $lembur];
}

function new_id(string $prefix): string { return $prefix . bin2hex(random_bytes(6)); }

function money_in($v, string $label): float {
    if (!is_numeric($v) || (float)$v < 0 || (float)$v > 1e12) json_error("$label harus berupa angka nol atau lebih.", 422);
    return round((float)$v, 2);
}

/** Attendance saved before any rate existed carries a 0 snapshot. When a rate
 *  is later set, fill only those zeros (never touching a non-zero snapshot)
 *  in periods that are not yet paid, so entering rates after the fact still
 *  produces the right pay. */
function fill_zero_snapshots(string $projectId, ?string $grade, ?string $workerId): int {
    $n = 0;
    $st = db()->prepare("SELECT w.* FROM workers w WHERE w.project_id = ?" . ($grade !== null ? ' AND w.grade = ?' : '') . ($workerId !== null ? ' AND w.id = ?' : ''));
    $st->execute(array_values(array_filter([$projectId, $grade, $workerId], fn($v) => $v !== null)));
    $up = db()->prepare(
        "UPDATE attendance a JOIN payroll_periods p ON p.project_id = a.project_id AND a.tgl BETWEEN p.tgl_mulai AND p.tgl_akhir
            SET a.tarif_harian = IF(a.tarif_harian = 0, ?, a.tarif_harian), a.tarif_lembur = IF(a.tarif_lembur = 0, ?, a.tarif_lembur)
          WHERE a.worker_id = ? AND a.project_id = ? AND p.status <> 'dibayar' AND (a.tarif_harian = 0 OR a.tarif_lembur = 0)");
    foreach ($st->fetchAll() as $w) {
        [$h, $l] = worker_rates($w);
        if ($h <= 0) continue;
        $up->execute([$h, $l, $w['id'], $projectId]);
        $n += $up->rowCount();
    }
    return $n;
}


/** Adds the columns/tables introduced by the payroll controls (approval step,
 *  worker verification, login throttling) when a deployment has not run the
 *  SQL migration yet. Checked once per session; needs ALTER/CREATE rights and
 *  fails quietly otherwise. */
function ensure_payroll_controls(): void {
    start_session();
    if ((int)($_SESSION['controls_ok'] ?? 0) >= 3) return; // bump the number whenever a new migration is added
    try {
        $db = db();
        $has = function (string $table, string $col) use ($db): bool {
            $q = $db->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $q->execute([$table, $col]);
            return (bool)$q->fetchColumn();
        };
        $tbl = $db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workers'")->fetchColumn();
        if (!$tbl) { // database lama belum punya tabel Tenaga Kerja: buat otomatis dari file SQL
            $sql = (string)@file_get_contents(__DIR__ . '/../mysql/tenaga_kerja_tabel.sql');
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            foreach (preg_split('/;\s*(\r?\n|$)/', $sql) as $stmt) { if (trim($stmt) !== '') $db->exec($stmt); }
        }
        if (!$has('workers', 'diverifikasi')) {
            $db->exec("ALTER TABLE workers ADD COLUMN diverifikasi TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN diverifikasi_by VARCHAR(40) NULL, ADD COLUMN diverifikasi_at DATETIME NULL");
        }
        if (!$has('payroll_periods', 'disetujui_at')) {
            $db->exec("ALTER TABLE payroll_periods MODIFY status ENUM('draft','diajukan','disetujui','dibayar') NOT NULL DEFAULT 'draft', ADD COLUMN disetujui_at DATETIME NULL, ADD COLUMN disetujui_by VARCHAR(40) NULL");
        }
        if (!$has('attendance', 'rev')) {
            $db->exec('ALTER TABLE attendance ADD COLUMN rev INT NOT NULL DEFAULT 0');
        }
        $roleType = (string)$db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'")->fetchColumn();
        if ($roleType !== '' && stripos($roleType, 'akuntan') === false) { // older databases: the Akuntan role did not exist yet
            $db->exec("ALTER TABLE users MODIFY role ENUM('admin','owner','lapangan','akuntan') NOT NULL");
        }
        if (!$has('labor_rates', 'tim')) {
            $db->exec("ALTER TABLE labor_rates ADD COLUMN tim VARCHAR(80) NOT NULL DEFAULT '' AFTER project_id, ADD INDEX idx_lr_project (project_id), DROP PRIMARY KEY, ADD PRIMARY KEY (project_id, tim, grade)");
        }
        if (!$has('kasbon_requests', 'tim')) {
            $db->exec("ALTER TABLE kasbon_requests ADD COLUMN tim VARCHAR(80) NOT NULL DEFAULT '' AFTER worker_id");
        }
        $_SESSION['controls_ok'] = 3;
    } catch (Throwable $e) {
        error_log('[material] ensure_payroll_controls: ' . $e->getMessage());
    }
}
