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
    session_name('material_session');
    session_start();
}

/** Baseline browser-hardening headers for every API response. */
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

function send_cors_headers(): void {
    send_security_headers();
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
    'attendance.php', 'payroll.php', 'payroll_periods.php', 'payroll_adjustments.php', 'kasbon.php',
];

function require_login(): array {
    $u = current_user();
    if (!$u) json_error('Belum login.', 401);
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
        $u['id'] ?? null, $u['username'] ?? null, $action, $entity, $entityId, $detail,
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

/** Jam lembur yang dicatat adalah lama kerja setelah jam normal (mis. 16.00–18.00 = 2).
 *  Lembar pengajuan asli membayar jam itu dikurangi 0,5 jam istirahat per hari lembur
 *  (2 -> 1,5; 6 -> 5,5; 1 -> 0,5). */
const LEMBUR_ISTIRAHAT_JAM = 0.5;
function lembur_paid_hours(float $jam): float {
    return $jam > 0 ? max(0.0, $jam - LEMBUR_ISTIRAHAT_JAM) : 0.0;
}

/** Lembur per jam when none is configured: gaji harian / 8, rounded down to Rp500. */
function default_lembur_rate(float $harian): float {
    return floor($harian / 8 / 500) * 500;
}

/** Effective rates for a worker: personal override, else the project's status rate. */
function worker_rates(array $worker): array {
    $st = db()->prepare('SELECT tarif_harian, tarif_lembur FROM labor_rates WHERE project_id = ? AND grade = ?');
    $st->execute([$worker['project_id'], $worker['grade']]);
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
