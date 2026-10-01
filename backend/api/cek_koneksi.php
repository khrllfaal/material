<?php
declare(strict_types=1);

/**
 * Buka file ini langsung di browser (contoh: http://localhost/backend/api/cek_koneksi.php)
 * untuk mendiagnosa kenapa aplikasi tidak bisa konek ke database — tanpa
 * perlu command line. Tidak menampilkan isi config.php (host/user/password)
 * demi keamanan, hanya status tiap langkah pengecekan.
 *
 * KEAMANAN: hapus file ini setelah masalah selesai, atau jangan pernah
 * upload ke server publik (Hostinger) — siapa pun yang tahu URL-nya bisa
 * melihat apakah database/tabel sudah terisi.
 */
header('Content-Type: text/html; charset=utf-8');

function step($label, $ok, $detail = ''){
    $icon = $ok ? '✅' : '❌';
    $color = $ok ? '#15803d' : '#b91c1c';
    echo '<div style="padding:10px 14px;margin-bottom:8px;border-radius:8px;background:'.($ok?'#f0fdf4':'#fef2f2').';border:1px solid '.($ok?'#bbf7d0':'#fecaca').'">';
    echo '<b style="color:'.$color.'">'.$icon.' '.htmlspecialchars($label).'</b>';
    if ($detail) echo '<div style="margin-top:4px;font-size:14px;color:#334155">'.$detail.'</div>';
    echo '</div>';
}

?><!DOCTYPE html>
<html lang="id">
<head><meta charset="UTF-8"><title>Cek Koneksi Database</title>
<style>body{font-family:system-ui,sans-serif;max-width:680px;margin:40px auto;padding:0 20px;background:#f8fafc}
h1{font-size:20px}</style></head>
<body>
<h1>🔍 Cek Koneksi Database — Material & Stok</h1>

<?php

// Langkah 1: config.php ada?
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    step('config.php belum dibuat', false,
        'Copy file <code>backend/api/config.sample.php</code>, lalu rename hasil copy-nya menjadi <code>config.php</code> (di folder yang sama). Setelah itu isi <code>db_user</code> dan <code>db_pass</code> sesuai yang kamu buat di phpMyAdmin.');
    echo '</body></html>';
    exit;
}
step('config.php ditemukan', true);

$cfg = require $configPath;
foreach (['db_host','db_name','db_user','db_pass'] as $key) {
    if (!isset($cfg[$key]) || $cfg[$key] === '') {
        step("Kolom '$key' kosong di config.php", false, 'Isi kolom ini terlebih dahulu.');
        echo '</body></html>';
        exit;
    }
}
if ($cfg['db_pass'] === 'CHANGE-ME') {
    step("db_pass masih 'CHANGE-ME'", false, 'Ganti dengan password MySQL yang sebenarnya untuk user <code>'.htmlspecialchars($cfg['db_user']).'</code>.');
    echo '</body></html>';
    exit;
}
step('Semua kolom config.php sudah terisi', true);

// Langkah 2: bisa konek ke server MySQL-nya (belum tentu database-nya ada)?
try {
    $pdoServer = new PDO(
        sprintf('mysql:host=%s;charset=utf8mb4', $cfg['db_host']),
        $cfg['db_user'], $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
    step('Berhasil konek ke server MySQL di host "'.htmlspecialchars($cfg['db_host']).'"', true);
} catch (PDOException $e) {
    $msg = $e->getMessage();
    $hint = 'Pastikan MySQL di XAMPP Control Panel statusnya "Running" (tombol Start berwarna hijau).';
    if (stripos($msg, 'Access denied') !== false) {
        $hint = 'Username atau password MySQL di config.php salah, atau user <code>'.htmlspecialchars($cfg['db_user']).'</code> belum dibuat di phpMyAdmin (tab "User accounts").';
    } elseif (stripos($msg, 'could not find driver') !== false) {
        $hint = 'Extension PDO MySQL belum aktif di PHP. Biasanya ini bukan masalah dengan XAMPP standar — cek ulang instalasi XAMPP.';
    }
    step('Tidak bisa konek ke server MySQL', false, 'Pesan asli: <code>'.htmlspecialchars($msg).'</code><br>'.$hint);
    echo '</body></html>';
    exit;
}

// Langkah 3: database-nya ada?
$dbExists = $pdoServer->prepare('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
$dbExists->execute([$cfg['db_name']]);
if (!$dbExists->fetch()) {
    step('Database "'.htmlspecialchars($cfg['db_name']).'" belum ada', false,
        'Buka phpMyAdmin → klik "New" di sidebar kiri → buat database bernama <b>'.htmlspecialchars($cfg['db_name']).'</b> (harus sama persis, huruf kecil semua).');
    echo '</body></html>';
    exit;
}
step('Database "'.htmlspecialchars($cfg['db_name']).'" ditemukan', true);

// Langkah 4: user ini punya akses ke database tsb + tabel sudah ada?
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_name']),
        $cfg['db_user'], $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    step('User "'.htmlspecialchars($cfg['db_user']).'" punya akses ke database ini', true);
} catch (PDOException $e) {
    step('User tidak punya akses ke database "'.htmlspecialchars($cfg['db_name']).'"', false,
        'Di phpMyAdmin, buka tab "User accounts" → edit user <code>'.htmlspecialchars($cfg['db_user']).'</code> → di bagian "Database for user account", centang/tambahkan akses penuh (GRANT ALL) ke database <b>'.htmlspecialchars($cfg['db_name']).'</b>.');
    echo '</body></html>';
    exit;
}

// Langkah 5: tabel sudah diimport?
$tableCheck = $pdo->query("SHOW TABLES LIKE 'users'")->fetch();
if (!$tableCheck) {
    step('Tabel belum ada di database ini', false,
        'Buka phpMyAdmin → pilih database <b>'.htmlspecialchars($cfg['db_name']).'</b> → tab "Import" → pilih file <code>backend/mysql/schema.sql</code> → klik "Go".');
    echo '</body></html>';
    exit;
}
step('Tabel sudah ada (struktur database lengkap)', true);

// Langkah 6: ada berapa user login yang terdaftar?
$count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($count === 0) {
    step('Tabel users masih kosong — 0 akun login', false,
        'Belum ada akun untuk login. Buat lewat phpMyAdmin (INSERT manual dengan password ter-hash) atau jalankan <code>backend/api/bin/create_user.php</code>.');
} else {
    step("Database siap — ada $count akun login terdaftar", true,
        '🎉 Koneksi database berhasil sepenuhnya. Kalau aplikasi masih error, masalahnya ada di tempat lain (cek Console browser dengan tombol F12).');
}

?>
<p style="margin-top:24px;font-size:13px;color:#94a3b8">⚠️ Hapus file ini (<code>cek_koneksi.php</code>) setelah selesai, atau jangan upload ke server publik.</p>
</body></html>
