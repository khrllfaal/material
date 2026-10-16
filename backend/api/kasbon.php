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
    $pn = []; foreach (db()->query('SELECT id, nama FROM projects')->fetchAll() as $r) $pn[$r['id']] = $r['nama'];
    $wn = []; foreach (db()->query('SELECT id, nama FROM workers')->fetchAll() as $r) $wn[$r['id']] = $r['nama'];
    $saldo = [];
    foreach (kasbon_saldo_map(null) as $key => $v) {
        [$pid, $who] = explode('|', $key, 2);
        $isTim = strncmp($who, 't:', 2) === 0;
        $saldo[] = ['project_id' => $pid, 'project_nama' => $pn[$pid] ?? $pid, 'worker_id' => ($who !== '' && !$isTim) ? $who : null,
                    'tim' => $isTim ? substr($who, 2) : '', 'nama' => $isTim ? tim_label(substr($who, 2)) : ($who !== '' ? ($wn[$who] ?? $who) : 'Grup / penanggung jawab'), 'saldo' => round($v, 2)];
    }
    usort($saldo, fn($a, $b) => [$a['project_nama'], $a['nama']] <=> [$b['project_nama'], $b['nama']]);
    json_response(['kasbon' => $out, 'saldo' => $saldo]);
}

if ($method === 'POST') {
    $b = read_json_body();
    $projectId = (string)($b['project_id'] ?? '');
    $tgl = require_valid_date($b['tgl'] ?? '', 'Tanggal kasbon');
    $jumlah = money_in($b['jumlah'] ?? null, 'Jumlah');
    $pelunasan = (string)($b['jenis'] ?? '') === 'pelunasan'; // the worker / tim hands cash back (e.g. the project ended with a balance)
    if ($projectId === '' || $jumlah <= 0) json_error('Proyek dan jumlah wajib diisi.', 422);
    $workerId = !empty($b['worker_id']) ? (string)$b['worker_id'] : null;
    $tim = $workerId === null ? mb_substr(trim((string)($b['tim'] ?? '')), 0, 80) : '';
    if ($workerId) { $w = db()->prepare('SELECT project_id FROM workers WHERE id = ?'); $w->execute([$workerId]); $wp = $w->fetchColumn(); if ($wp === false) json_error('Pekerja tidak ditemukan.', 422); if ($wp !== $projectId) json_error('Pekerja bukan bagian dari proyek ini.', 422); }
    if ($tim !== '') { $tq = db()->prepare('SELECT 1 FROM workers WHERE project_id = ? AND tim = ? LIMIT 1'); $tq->execute([$projectId, $tim]); if (!$tq->fetchColumn()) json_error('Tim tidak ditemukan di proyek ini.', 422); }
    if ($pelunasan) {
        $saldo = kasbon_saldo($projectId, $workerId, $tim);
        if ($jumlah > $saldo + 0.005) json_error('Pelunasan melebihi saldo kasbon (' . number_format(max(0, $saldo), 0, ',', '.') . ').', 422);
    }
    $id = new_id('ksb_');
    $ket = mb_substr(trim((string)($b['ket'] ?? '')), 0, 240);
    if ($pelunasan) $ket = 'Pelunasan' . ($ket !== '' ? ': ' . $ket : '');
    db()->prepare('INSERT INTO kasbon (id, project_id, worker_id, tim, tgl, jumlah, ket, created_by) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id, $projectId, $workerId, $tim, $tgl, $pelunasan ? -$jumlah : $jumlah, $ket, $user['id']]);
    audit('create', 'kasbon', $id, ($pelunasan ? 'pelunasan ' : '') . $jumlah);
    json_response(['ok' => true, 'id' => $id]);
}

if ($method === 'DELETE') {
    $id = (string)($_GET['id'] ?? '');
    $st = db()->prepare('SELECT * FROM kasbon WHERE id = ?'); $st->execute([$id]);
    $k = $st->fetch();
    if (!$k) json_error('Data tidak ditemukan.', 404);
    if ((float)$k['jumlah'] > 0 && kasbon_saldo($k['project_id'], $k['worker_id'], (string)$k['tim']) - (float)$k['jumlah'] < -0.005) {
        json_error('Kasbon ini sudah dipotong dari gaji, jadi tidak bisa dihapus.', 409);
    }
    db()->prepare('DELETE FROM kasbon WHERE id = ?')->execute([$id]);
    audit('delete', 'kasbon', $id, (string)$k['jumlah']);
    json_response(['ok' => true]);
}
json_error('Method not allowed', 405);
