<?php
declare(strict_types=1);
require_once __DIR__ . '/payroll_lib.php';

/**
 * Tambahan / potongan pada gaji satu periode (bonus, uang makan, denda,
 * potongan kasbon, air galon, ...). Admin Pusat and Akuntan only. A paid
 * period is locked: corrections go into the next period instead of rewriting
 * what was already paid.
 */
send_cors_headers();
$user = require_role(['admin', 'akuntan']);
$method = $_SERVER['REQUEST_METHOD'];

function adj_period_or_fail(string $projectId, string $tgl): array {
    payroll_period($projectId, $tgl);
    db()->beginTransaction(); // released by commit() after the insert; locks the week against a concurrent payment
    $st = db()->prepare('SELECT * FROM payroll_periods WHERE project_id = ? AND tgl_mulai = ? FOR UPDATE');
    $st->execute([$projectId, payroll_week($tgl)[0]]);
    $p = $st->fetch();
    if ($p['status'] === 'dibayar') json_error('Periode ini sudah dibayar dan terkunci. Buat penyesuaian di periode berikutnya.', 409);
    return $p;
}

if ($method === 'POST') {
    $b = read_json_body();
    $projectId = (string)($b['project_id'] ?? '');
    $tgl = require_valid_date($b['tgl'] ?? '', 'Tanggal periode');
    $jenis = (string)($b['jenis'] ?? '');
    $kategori = (string)($b['kategori'] ?? 'lainnya');
    $label = trim((string)($b['label'] ?? ''));
    if ($projectId === '' || !in_array($jenis, ['tambah', 'potong'], true)) json_error('Data penyesuaian tidak lengkap.', 422);
    if (!in_array($kategori, ['kasbon', 'potongan', 'bonus', 'tambahan', 'lainnya'], true)) json_error('Jenis penyesuaian tidak valid.', 422);
    if ($kategori === 'kasbon' && $jenis !== 'potong') json_error('Kasbon hanya berupa potongan.', 422);
    if ($label === '' || mb_strlen($label) > 160) json_error('Keterangan wajib diisi.', 422);
    $workerId = !empty($b['worker_id']) ? (string)$b['worker_id'] : null;
    $qty = (isset($b['qty']) && $b['qty'] !== '') ? (float)$b['qty'] : null;
    $harga = (isset($b['harga']) && $b['harga'] !== '') ? money_in($b['harga'], 'Harga') : null;
    $jumlah = ($qty !== null && $harga !== null) ? round($qty * $harga, 2) : money_in($b['jumlah'] ?? null, 'Jumlah');
    if ($jumlah <= 0) json_error('Jumlah harus lebih dari 0.', 422);
    if ($workerId) {
        $w = db()->prepare('SELECT project_id FROM workers WHERE id = ?'); $w->execute([$workerId]);
        $wp = $w->fetchColumn();
        if ($wp === false) json_error('Pekerja tidak ditemukan.', 422);
    }
    $p = adj_period_or_fail($projectId, $tgl);
    if ($kategori === 'kasbon') {
        $saldo = kasbon_saldo($projectId, $workerId);
        if ($jumlah > $saldo + 0.005) json_error('Potongan kasbon melebihi saldo kasbon (' . number_format($saldo, 0, ',', '.') . ').', 422);
    }
    $id = new_id('adj_');
    db()->prepare('INSERT INTO payroll_adjustments (id, period_id, worker_id, jenis, kategori, label, qty, harga, jumlah, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id, $p['id'], $workerId, $jenis, $kategori, $label, $qty, $harga, $jumlah, $user['id']]);
    db()->commit();
    audit('create', 'payroll_adjustments', $id, "$jenis $kategori $jumlah — $label");
    json_response(['ok' => true, 'id' => $id, 'jumlah' => $jumlah]);
}

if ($method === 'DELETE') {
    $id = (string)($_GET['id'] ?? '');
    db()->beginTransaction();
    $st = db()->prepare('SELECT x.*, p.status FROM payroll_adjustments x JOIN payroll_periods p ON p.id = x.period_id WHERE x.id = ? FOR UPDATE');
    $st->execute([$id]);
    $x = $st->fetch();
    if (!$x) json_error('Data tidak ditemukan.', 404);
    if ($x['status'] === 'dibayar') json_error('Periode ini sudah dibayar dan terkunci.', 409);
    db()->prepare('DELETE FROM payroll_adjustments WHERE id = ?')->execute([$id]);
    db()->commit();
    audit('delete', 'payroll_adjustments', $id, "{$x['jenis']} {$x['kategori']} {$x['jumlah']} — {$x['label']}");
    json_response(['ok' => true]);
}
json_error('Method not allowed', 405);
