<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

/* Lets Admin Lapangan add a bahan that is missing from Master Bahan while
   recording a delivery or usage. Only creates: no edit, no delete, and the
   kode is generated here so field phones cannot choose or collide on it. */
send_cors_headers();
$user = require_role(['admin', 'lapangan']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

$body = read_json_body();
$nama = trim((string)($body['nama'] ?? ''));
$satuan = trim((string)($body['satuan'] ?? ''));
if ($nama === '' || $satuan === '') json_error('Nama dan satuan wajib diisi.', 422);
if (strlen($nama) > 255 || strlen($satuan) > 40) json_error('Nama atau satuan terlalu panjang.', 422);

// Duplicate check ignores case and repeated/edge spaces, so "Semen  Tiga Roda"
// and "semen tiga roda" count as the same bahan. Master Bahan is small enough
// that comparing in PHP is simpler than normalising inside SQL.
$norm = function (string $x): string { return strtolower(preg_replace('/\s+/', ' ', trim($x))); };
foreach (db()->query('SELECT nama, satuan FROM materials')->fetchAll() as $row) {
    if ($norm($row['nama']) === $norm($nama) && $norm($row['satuan']) === $norm($satuan)) {
        json_error('Bahan dengan nama dan satuan yang sama sudah ada. Pilih dari daftar.', 409);
    }
}

$max = (int)db()->query("SELECT COALESCE(MAX(CAST(SUBSTRING(kode, 5) AS UNSIGNED)), 0) FROM materials WHERE kode LIKE 'BHN-%'")->fetchColumn();
$kode = 'BHN-' . str_pad((string)($max + 1), 3, '0', STR_PAD_LEFT);
$id = 'mat_' . bin2hex(random_bytes(6));

db()->prepare('INSERT INTO materials (id, kode, nama, satuan) VALUES (?, ?, ?, ?)')->execute([$id, $kode, $nama, $satuan]);
audit('create', 'materials', $id, 'ditambahkan dari lapangan');
json_response(['id' => $id, 'kode' => $kode, 'nama' => $nama, 'satuan' => $satuan]);
