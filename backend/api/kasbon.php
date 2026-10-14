<?php
declare(strict_types=1);
require_once __DIR__ . '/payroll_lib.php';

/**
 * Buku kasbon. Admin Pusat / Akuntan record kasbon given; the repayments are
 * the "kasbon" deductions on payroll periods. Saldo = given - repaid, never
 * negative. Owner may read.
 */
send_cors_headers();
$method = $_SERVER['REQUEST_METHOD'];
$user = $method === 'GET' ? require_role(['admin', 'akuntan', 'owner']) : require_role(['admin', 'akuntan']);

if ($method === 'GET') {
    $rows = db()->query(
        "SELECT k.*, w.nama AS worker_nama, p.nama AS project_nama FROM kasbon k
           JOIN projects p ON p.id = k.project_id LEFT JOIN workers w ON w.id = k.worker_id ORDER BY k.tgl DESC, k.created_at DESC")->fetchAll();
    $out = [];
    foreach ($rows as $r) { $r['jumlah'] = (float)$r['jumlah']; $out[] = $r; }
    $saldo = [];
    foreach ($out as $r) {
        $k = $r['project_id'] . '|' . ($r['worker_id'] ?? '');
        if (!isset($saldo[$k])) $saldo[$k] = ['project_id' => $r['project_id'], 'project_nama' => $r['project_nama'], 'worker_id' => $r['worker_id'],
            'nama' => $r['worker_nama'] ?? 'Grup / penanggung jawab', 'saldo' => kasbon_saldo($r['project_id'], $r['worker_id'])];
    }
    json_response(['kasbon' => $out, 'saldo' => array_values($saldo)]);
}

if ($method === 'POST') {
    $b = read_json_body();
    $projectId = (string)($b['project_id'] ?? '');
    $tgl = require_valid_date($b['tgl'] ?? '', 'Tanggal kasbon');
    $jumlah = money_in($b['jumlah'] ?? null, 'Jumlah');
    if ($projectId === '' || $jumlah <= 0) json_error('Proyek dan jumlah wajib diisi.', 422);
    $workerId = !empty($b['worker_id']) ? (string)$b['worker_id'] : null;
    if ($workerId) { $w = db()->prepare('SELECT 1 FROM workers WHERE id = ?'); $w->execute([$workerId]); if (!$w->fetchColumn()) json_error('Pekerja tidak ditemukan.', 422); }
    $id = new_id('ksb_');
    db()->prepare('INSERT INTO kasbon (id, project_id, worker_id, tgl, jumlah, ket, created_by) VALUES (?,?,?,?,?,?,?)')
        ->execute([$id, $projectId, $workerId, $tgl, $jumlah, mb_substr(trim((string)($b['ket'] ?? '')), 0, 255), $user['id']]);
    audit('create', 'kasbon', $id, (string)$jumlah);
    json_response(['ok' => true, 'id' => $id]);
}

if ($method === 'DELETE') {
    $id = (string)($_GET['id'] ?? '');
    $st = db()->prepare('SELECT * FROM kasbon WHERE id = ?'); $st->execute([$id]);
    $k = $st->fetch();
    if (!$k) json_error('Data tidak ditemukan.', 404);
    if (kasbon_saldo($k['project_id'], $k['worker_id']) - (float)$k['jumlah'] < -0.005) {
        json_error('Kasbon ini sudah dipotong dari gaji, jadi tidak bisa dihapus.', 409);
    }
    db()->prepare('DELETE FROM kasbon WHERE id = ?')->execute([$id]);
    audit('delete', 'kasbon', $id, (string)$k['jumlah']);
    json_response(['ok' => true]);
}
json_error('Method not allowed', 405);
