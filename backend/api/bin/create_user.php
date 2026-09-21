<?php
declare(strict_types=1);
/**
 * One-time helper to create an admin/owner login. There is no public
 * sign-up form on purpose — accounts are created by whoever controls
 * the server.
 *
 * Usage (CLI only — refuses to run over HTTP):
 *   php create_user.php owner@example.com "S3curePassword!" "Nama Pemilik" owner
 *   php create_user.php admin@example.com "S3curePassword!" "Nama Admin" admin
 *
 * On Hostinger without SSH access: use hPanel's phpMyAdmin instead —
 * run this script locally to print the password hash, then INSERT the
 * row yourself via phpMyAdmin's SQL tab (see docs/DEPLOY_HOSTINGER.md).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script only runs from the command line.');
}

require_once __DIR__ . '/../db.php';

[$script, $email, $password, $nama, $role] = array_pad($argv, 5, null);
if (!$email || !$password || !$nama || !in_array($role, ['admin', 'owner', 'lapangan'], true)) {
    fwrite(STDERR, "Usage: php create_user.php <email> <password> <nama> <admin|owner|lapangan>\n");
    fwrite(STDERR, "For 'lapangan' (field admin), assign project access afterwards via the Kelola User Lapangan screen or the users.php API.\n");
    exit(1);
}
if (strlen($password) < 10) {
    fwrite(STDERR, "Password terlalu pendek — minimal 10 karakter.\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$id = 'u' . bin2hex(random_bytes(6));

$stmt = db()->prepare(
    'INSERT INTO users (id, email, password_hash, nama, role) VALUES (?,?,?,?,?)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), nama = VALUES(nama), role = VALUES(role),
       failed_attempts = 0, locked_until = NULL'
);
$stmt->execute([$id, strtolower(trim($email)), $hash, $nama, $role]);

echo "OK — user '$email' ($role) is ready to log in.\n";
