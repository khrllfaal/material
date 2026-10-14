<?php
declare(strict_types=1);
require_once __DIR__ . '/payroll_lib.php';

/**
 * Pengajuan kasbon.
 *   Admin Lapangan  POST {id, project_id, worker_id?, jumlah, ket}   -> status "diajukan"
 *                   GET                                              -> pengajuan proyeknya sendiri
 *   Akuntan / Admin POST {action:'setujui', id, jumlah?, tgl?, catatan?} -> dicatat ke buku kasbon
 *                   POST {action:'tolak',   id, catatan}             -> ditolak dengan alasan
 *   Owner           GET only.
 * Lapangan can only create requests; it can never approve or touch the kasbon book.
 */
send_cors_headers();
$method = $_SERVER['REQUEST_METHOD'];
$user = $method === 'GET' ? require_login() : require_role(['admin', 'akuntan', 'lapangan']);
$role = $user['role'];
$scope = user_project_ids($user);

if ($method === 'GET') {
    $conds = []; $params = [];
    if ($scope !== null) {
        if (!$scope) json_response([]);
        $conds[] = 'r.project_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
        $params = $scope;
    }
    if (!empty($_GET['status'])) { $conds[] = 'r.status = ?'; $params[] = (string)$_GET['status']; }
    $st = db()->prepare(
        "SELECT r.id, r.project_id, p.nama AS project_nama, r.worker_id, w.nama AS worker_nama, r.jumlah, r.ket, r.status, r.catatan, r.created_at, r.diproses_at
           FROM kasbon_requests r JOIN projects p ON p.id = r.project_id LEFT JOIN workers w ON w.id = r.worker_id"
        . ($conds ? ' WHERE ' . implode(' AND ', $conds) : '') . ' ORDER BY FIELD(r.status, \'diajukan\', \'disetujui\', \'ditolak\'), r.created_at DESC LIMIT 200');
    $st->execute($params);
    json_response(array_map(function ($r) { $r['jumlah'] = (float)$r['jumlah']; return $r; }, $st->fetchAll()));
}

$b = read_json_body();
$action = (string)($b['action'] ?? '');

if ($role === 'lapangan' || $action === '') {
    // create a request (field admin; also allowed for admin/akuntan on someone's behalf)
    $projectId = (string)($b['project_id'] ?? '');
    if ($projectId === '') json_error('Proyek wajib dipilih.', 422);
    require_project_access($user, $projectId);
    $id = (string)($b['id'] ?? '');
    if ($id === '') $id = new_id('ksr_');
    if (!preg_match('/^ksr_[a-z0-9]{6,30}$/', $id)) json_error('id tidak valid.', 422);
    $jumlah = money_in($b['jumlah'] ?? null, 'Jumlah');
    if ($jumlah <= 0) json_error('Jumlah kasbon harus lebih dari 0.', 422);
    if ($jumlah > 1e9) json_error('Jumlah terlalu besar.', 422);
    $workerId = !empty($b['worker_id']) ? (string)$b['worker_id'] : null;
    if ($workerId) {
        $w = db()->prepare('SELECT project_id FROM workers WHERE id = ?'); $w->execute([$workerId]);
        if ($w->fetchColumn() !== $projectId) json_error('Pekerja bukan bagian dari proyek ini.', 422);
    }
    $ex = db()->prepare('SELECT 1 FROM kasbon_requests WHERE id = ?'); $ex->execute([$id]);
    if ($ex->fetchColumn()) json_error('Pengajuan sudah tersimpan.', 403); // offline resend: treated as done
    $open = db()->prepare("SELECT COUNT(*) FROM kasbon_requests WHERE project_id = ? AND status = 'diajukan'"); $open->execute([$projectId]);
    if ((int)$open->fetchColumn() >= 30) json_error('Terlalu banyak pengajuan yang belum diperiksa. Hubungi Akuntan.', 429);
    db()->prepare('INSERT INTO kasbon_requests (id, project_id, worker_id, jumlah, ket, created_by) VALUES (?,?,?,?,?,?)')
        ->execute([$id, $projectId, $workerId, $jumlah, mb_substr(trim((string)($b['ket'] ?? '')), 0, 255), $user['id']]);
    audit('create', 'kasbon_requests', $id, (string)$jumlah);
    json_response(['ok' => true, 'id' => $id]);
}

// review: akuntan / admin only
if (!in_array($role, ['admin', 'akuntan'], true)) json_error('Anda tidak punya akses untuk aksi ini.', 403);
$id = (string)($b['id'] ?? '');
$st = db()->prepare('SELECT * FROM kasbon_requests WHERE id = ?'); $st->execute([$id]);
$r = $st->fetch();
if (!$r) json_error('Pengajuan tidak ditemukan.', 404);
if ($r['status'] !== 'diajukan') json_error('Pengajuan ini sudah diproses.', 409);
$catatan = mb_substr(trim((string)($b['catatan'] ?? '')), 0, 255);

if ($action === 'tolak') {
    if ($catatan === '') json_error('Alasan penolakan wajib diisi.', 422);
    db()->prepare("UPDATE kasbon_requests SET status='ditolak', catatan=?, diproses_by=?, diproses_at=NOW() WHERE id=?")->execute([$catatan, $user['id'], $id]);
    audit('update', 'kasbon_requests', $id, 'ditolak: ' . $catatan);
    json_response(['ok' => true, 'status' => 'ditolak']);
}
if ($action === 'setujui') {
    $jumlah = isset($b['jumlah']) && $b['jumlah'] !== '' ? money_in($b['jumlah'], 'Jumlah') : (float)$r['jumlah'];
    if ($jumlah <= 0) json_error('Jumlah harus lebih dari 0.', 422);
    $tgl = require_valid_date($b['tgl'] ?? date('Y-m-d'), 'Tanggal kasbon');
    $kid = new_id('ksb_');
    db()->beginTransaction();
    db()->prepare('INSERT INTO kasbon (id, project_id, worker_id, tgl, jumlah, ket, created_by) VALUES (?,?,?,?,?,?,?)')
        ->execute([$kid, $r['project_id'], $r['worker_id'], $tgl, $jumlah, mb_substr('Pengajuan lapangan' . ($r['ket'] !== '' ? ': ' . $r['ket'] : ''), 0, 255), $user['id']]);
    db()->prepare("UPDATE kasbon_requests SET status='disetujui', catatan=?, kasbon_id=?, diproses_by=?, diproses_at=NOW() WHERE id=?")
        ->execute([$catatan, $kid, $user['id'], $id]);
    db()->commit();
    audit('update', 'kasbon_requests', $id, "disetujui $jumlah -> $kid");
    json_response(['ok' => true, 'status' => 'disetujui', 'kasbon_id' => $kid]);
}
json_error('Aksi tidak dikenal.', 422);
