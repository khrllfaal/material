<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

/**
 * Admin-only user management, including which projects a 'lapangan'
 * (field admin) account can see/write to. Exists so onboarding new
 * field admins across dozens of projects doesn't require SSH/CLI
 * access to the server (bin/create_user.php still works too, for
 * hosts where SSH is available).
 *
 * Login is by plain username, not email — faster to type on a phone
 * in the field and no @domain to get wrong.
 */
send_cors_headers();
$admin = require_role(['admin']);
$method = $_SERVER['REQUEST_METHOD'];

function user_with_scope(array $u): array {
    $out = ['id' => $u['id'], 'username' => $u['username'], 'nama' => $u['nama'], 'role' => $u['role']];
    if ($u['role'] === 'lapangan') $out['projectIds'] = user_project_ids($u);
    return $out;
}

if ($method === 'GET') {
    $rows = db()->query('SELECT id, username, nama, role FROM users ORDER BY nama')->fetchAll();
    json_response(array_map('user_with_scope', $rows));
    exit;
}

if ($method === 'POST') {
    $body = read_json_body();
    $username = trim(strtolower((string)($body['username'] ?? '')));
    $nama = trim((string)($body['nama'] ?? ''));
    $role = (string)($body['role'] ?? '');
    $password = (string)($body['password'] ?? '');
    $projectIds = is_array($body['projectIds'] ?? null) ? $body['projectIds'] : [];
    $id = (string)($body['id'] ?? '');

    if (!in_array($role, ['admin', 'owner', 'lapangan'], true)) json_error('Role tidak valid.', 422);
    if ($username === '' || $nama === '') json_error('Username dan nama wajib diisi.', 422);
    if (!preg_match('/^[a-z0-9._-]{3,40}$/', $username)) {
        json_error('Username 3-40 karakter, hanya huruf kecil/angka/titik/garis bawah/strip, tanpa spasi.', 422);
    }

    $db = db();
    if ($id === '') {
        // create
        if (strlen($password) < 10) json_error('Password minimal 10 karakter.', 422);
        $exists = $db->prepare('SELECT id FROM users WHERE username = ?');
        $exists->execute([$username]);
        if ($exists->fetch()) json_error('Username sudah dipakai.', 422);
        $id = 'u' . bin2hex(random_bytes(6));
        $stmt = $db->prepare('INSERT INTO users (id, username, password_hash, nama, role) VALUES (?,?,?,?,?)');
        $stmt->execute([$id, $username, password_hash($password, PASSWORD_BCRYPT), $nama, $role]);
    } else {
        $stmt = $db->prepare('UPDATE users SET username = ?, nama = ?, role = ? WHERE id = ?');
        $stmt->execute([$username, $nama, $role, $id]);
        if ($password !== '') {
            if (strlen($password) < 10) json_error('Password minimal 10 karakter.', 422);
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
               ->execute([password_hash($password, PASSWORD_BCRYPT), $id]);
        }
    }

    $db->prepare('DELETE FROM user_projects WHERE user_id = ?')->execute([$id]);
    if ($role === 'lapangan' && $projectIds) {
        $ins = $db->prepare('INSERT IGNORE INTO user_projects (user_id, project_id) VALUES (?, ?)');
        foreach ($projectIds as $pid) $ins->execute([$id, (string)$pid]);
    }

    audit('update', 'users', $id);
    $row = $db->prepare('SELECT id, username, nama, role FROM users WHERE id = ?');
    $row->execute([$id]);
    json_response(user_with_scope($row->fetch()));
    exit;
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if ($id === '') json_error('id wajib diisi', 422);
    if ($id === $admin['id']) json_error('Tidak bisa menghapus akun sendiri.', 400);
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    audit('delete', 'users', (string)$id);
    json_response(['ok' => true]);
    exit;
}

json_error('Method not allowed', 405);
