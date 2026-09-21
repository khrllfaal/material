<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
send_cors_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

$body = read_json_body();
$email = trim(strtolower((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');
if ($email === '' || $password === '') json_error('Email dan password wajib diisi.', 422);

$stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
$stmt->execute([$email]);
$user = $stmt->fetch();

$generic_error = 'Email atau password salah.';

if (!$user) {
    // Don't reveal whether the email exists at all.
    json_error($generic_error, 401);
}

if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
    json_error('Akun terkunci sementara karena terlalu banyak percobaan gagal. Coba lagi beberapa menit lagi.', 429);
}

if (!password_verify($password, $user['password_hash'])) {
    $attempts = (int)$user['failed_attempts'] + 1;
    $lockUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 15 * 60) : null;
    $upd = db()->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?');
    $upd->execute([$attempts, $lockUntil, $user['id']]);
    json_error($generic_error, 401);
}

// success — reset lockout state, rotate session id (session fixation defence)
db()->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$user['id']]);
start_session();
session_regenerate_id(true);
$_SESSION['user'] = ['id' => $user['id'], 'email' => $user['email'], 'nama' => $user['nama'], 'role' => $user['role']];

$out = $_SESSION['user'];
if ($user['role'] === 'lapangan') $out['projectIds'] = user_project_ids($_SESSION['user']);
json_response(['user' => $out]);
