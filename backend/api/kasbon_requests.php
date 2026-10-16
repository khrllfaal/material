<?php
declare(strict_types=1);
require_once __DIR__ . '/payroll_lib.php';

/**
 * Pengajuan kasbon.
 *   Admin Lapangan  POST {id, project_id, worker_id?|tim?, jumlah, ket}   -> status "diajukan"
 *                   POST {project_id, ket, items:[{id, worker_id?|tim?, jumlah}, ...]} -> satu pengajuan berisi beberapa tim/pekerja
 *                   GET                                              -> pengajuan proyeknya sendiri
 *   Akuntan         POST {action:'setujui', id, jumlah?, tgl?, catatan?} -> dicatat ke buku kasbon
 *                   POST {action:'setujui_batch'|'tolak_batch', batch_id, tgl?, catatan?}
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
        "SELECT r.id, r.project_id, p.nama AS project_nama, r.worker_id, w.nama AS worker_nama, r.tim, r.batch_id, r.jumlah, r.ket, r.status, r.catatan, r.created_at, r.diproses_at,
               COALESCE(DATE(CONVERT_TZ(r.created_at, @@session.time_zone, '+07:00')), DATE(r.created_at)) AS tgl_wib
           FROM kasbon_requests r JOIN projects p ON p.id = r.project_id LEFT JOIN workers w ON w.id = r.worker_id"
        . ($conds ? ' WHERE ' . implode(' AND ', $conds) : '') . ' ORDER BY FIELD(r.status, \'diajukan\', \'disetujui\', \'ditolak\'), r.created_at DESC LIMIT 200');
    $st->execute($params);
    json_response(array_map(function ($r) { $r['jumlah'] = (float)$r['jumlah']; return $r; }, $st->fetchAll()));
}

$b = read_json_body();
$action = (string)($b['action'] ?? '');

if ($role === 'lapangan' || $action === '') {
    // create a request (field admin; also allowed for admin/akuntan on someone's behalf). One request may carry several lines
    // (e.g. Tim A 500.000 + Tim B 300.000): every line is a row of its own that shares a batch_id and is decided together.
    $projectId = (string)($b['project_id'] ?? '');
    if ($projectId === '') json_error('Proyek wajib dipilih.', 422);
    require_project_access($user, $projectId);
    $items = (isset($b['items']) && is_array($b['items'])) ? $b['items'] : [['id' => $b['id'] ?? '', 'worker_id' => $b['worker_id'] ?? null, 'tim' => $b['tim'] ?? '', 'jumlah' => $b['jumlah'] ?? null]];
    if (!$items || count($items) > 20) json_error('Isi 1 sampai 20 baris kasbon.', 422);
    $ket = mb_substr(trim((string)($b['ket'] ?? '')), 0, 255);
    $lines = []; $seen = [];
    foreach ($items as $it) {
        $id = (string)($it['id'] ?? '');
        if ($id === '') $id = new_id('ksr_');
        if (!preg_match('/^ksr_[a-z0-9]{6,30}$/', $id)) json_error('id tidak valid.', 422);
        $jumlah = money_in($it['jumlah'] ?? null, 'Jumlah');
        if ($jumlah <= 0) json_error('Jumlah kasbon harus lebih dari 0.', 422);
        if ($jumlah > 1e9) json_error('Jumlah terlalu besar.', 422);
        $workerId = !empty($it['worker_id']) ? (string)$it['worker_id'] : null;
        if ($workerId) {
            $w = db()->prepare('SELECT project_id FROM workers WHERE id = ?'); $w->execute([$workerId]);
            if ($w->fetchColumn() !== $projectId) json_error('Pekerja bukan bagian dari proyek ini.', 422);
        }
        $tim = $workerId ? '' : mb_substr(trim((string)($it['tim'] ?? '')), 0, 80);
        if ($tim !== '') {
            $tq = db()->prepare('SELECT 1 FROM workers WHERE project_id = ? AND tim = ? LIMIT 1'); $tq->execute([$projectId, $tim]);
            if (!$tq->fetchColumn()) json_error('Tim tidak ditemukan di proyek ini.', 422);
        }
        $nameKey = $workerId ?: ($tim !== '' ? 't:' . $tim : 'g');
        if (isset($seen[$nameKey])) json_error('Satu nama hanya boleh satu baris dalam satu pengajuan.', 422);
        $seen[$nameKey] = true;
        $lines[] = ['id' => $id, 'worker_id' => $workerId, 'tim' => $tim, 'jumlah' => $jumlah, 'key' => $nameKey];
    }
    $ex = db()->prepare('SELECT 1 FROM kasbon_requests WHERE id = ?'); $ex->execute([$lines[0]['id']]);
    if ($ex->fetchColumn()) json_error('Pengajuan sudah tersimpan.', 403); // offline resend: treated as done
    $open = db()->prepare("SELECT COUNT(*) FROM kasbon_requests WHERE project_id = ? AND status = 'diajukan'"); $open->execute([$projectId]);
    if ((int)$open->fetchColumn() + count($lines) > 60) json_error('Terlalu banyak pengajuan yang belum diperiksa. Hubungi Akuntan.', 429);
    // Rule: one request per name (worker / tim / grup) per payroll week. Rejected ones do not count. The week is Jakarta time.
    if ($role === 'lapangan') {
        [$wFrom, $wTo] = payroll_week(date('Y-m-d'));
        foreach ($lines as $ln) {
            $dup = db()->prepare("SELECT COUNT(*) FROM kasbon_requests WHERE project_id = ? AND status <> 'ditolak'
                                    AND COALESCE(DATE(CONVERT_TZ(created_at, @@session.time_zone, '+07:00')), DATE(created_at)) BETWEEN ? AND ?
                                    AND " . ($ln['worker_id'] ? 'worker_id = ?' : 'worker_id IS NULL AND tim = ?'));
            $dup->execute([$projectId, $wFrom, $wTo, $ln['worker_id'] ?: $ln['tim']]);
            if ((int)$dup->fetchColumn() > 0) json_error('Kasbon untuk ' . ($ln['worker_id'] ? 'pekerja ini' : ($ln['tim'] !== '' ? 'Tim ' . $ln['tim'] : 'grup')) . ' sudah diajukan minggu ini. Satu kali per minggu — hubungi Akuntan bila perlu tambahan.', 409);
        }
    }
    $batch = count($lines) > 1 ? ((string)($b['batch_id'] ?? '') !== '' && preg_match('/^bat_[a-z0-9]{6,30}$/', (string)$b['batch_id']) ? (string)$b['batch_id'] : new_id('bat_')) : null;
    db()->beginTransaction();
    $ins = db()->prepare('INSERT INTO kasbon_requests (id, project_id, worker_id, tim, batch_id, jumlah, ket, created_by) VALUES (?,?,?,?,?,?,?,?)');
    foreach ($lines as $ln) $ins->execute([$ln['id'], $projectId, $ln['worker_id'], $ln['tim'], $batch, $ln['jumlah'], $ket, $user['id']]);
    db()->commit();
    audit('create', 'kasbon_requests', $lines[0]['id'], count($lines) . ' baris, total ' . array_sum(array_column($lines, 'jumlah')));
    json_response(['ok' => true, 'id' => $lines[0]['id'], 'ids' => array_column($lines, 'id'), 'batch_id' => $batch, 'status' => 'diajukan']);
}

// review: Akuntan only (Admin Pusat edits/deletes, it does not approve)
if ($role !== 'akuntan') json_error('Hanya Akuntan yang memeriksa pengajuan kasbon.', 403);
$catatan = mb_substr(trim((string)($b['catatan'] ?? '')), 0, 255);

/** Writes the approved kasbon into the buku kasbon and closes the request. */
function approve_kasbon_row(array $r, float $jumlah, string $tgl, string $catatan, string $userId): string {
    $kid = new_id('ksb_');
    db()->prepare('INSERT INTO kasbon (id, project_id, worker_id, tim, tgl, jumlah, ket, created_by) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$kid, $r['project_id'], $r['worker_id'], $r['tim'], $tgl, $jumlah, mb_substr('Pengajuan lapangan' . ($r['tim'] !== '' ? ' (' . $r['tim'] . ')' : '') . ($r['ket'] !== '' ? ': ' . $r['ket'] : ''), 0, 255), $userId]);
    db()->prepare("UPDATE kasbon_requests SET status='disetujui', catatan=?, kasbon_id=?, diproses_by=?, diproses_at=NOW() WHERE id=?")
        ->execute([$catatan, $kid, $userId, $r['id']]);
    return $kid;
}

if ($action === 'setujui_batch' || $action === 'tolak_batch') {
    $batch = (string)($b['batch_id'] ?? '');
    db()->beginTransaction();
    $st = db()->prepare("SELECT * FROM kasbon_requests WHERE batch_id = ? AND status = 'diajukan' FOR UPDATE"); $st->execute([$batch]);
    $rows = $st->fetchAll();
    if (!$rows) json_error('Pengajuan ini sudah diproses atau tidak ditemukan.', 404);
    if ($action === 'tolak_batch') {
        if ($catatan === '') json_error('Alasan penolakan wajib diisi.', 422);
        db()->prepare("UPDATE kasbon_requests SET status='ditolak', catatan=?, diproses_by=?, diproses_at=NOW() WHERE batch_id = ? AND status = 'diajukan'")->execute([$catatan, $user['id'], $batch]);
        db()->commit();
        audit('update', 'kasbon_requests', $batch, 'ditolak (' . count($rows) . ' baris): ' . $catatan);
        json_response(['ok' => true, 'status' => 'ditolak', 'baris' => count($rows)]);
    }
    $tgl = require_valid_date($b['tgl'] ?? date('Y-m-d'), 'Tanggal kasbon');
    $total = 0.0;
    foreach ($rows as $r) { approve_kasbon_row($r, (float)$r['jumlah'], $tgl, $catatan, $user['id']); $total += (float)$r['jumlah']; }
    db()->commit();
    audit('update', 'kasbon_requests', $batch, 'disetujui ' . count($rows) . ' baris, total ' . $total);
    json_response(['ok' => true, 'status' => 'disetujui', 'baris' => count($rows), 'total' => $total]);
}

$id = (string)($b['id'] ?? '');
db()->beginTransaction();
$st = db()->prepare('SELECT * FROM kasbon_requests WHERE id = ? FOR UPDATE'); $st->execute([$id]);
$r = $st->fetch();
if (!$r) json_error('Pengajuan tidak ditemukan.', 404);
if ($r['status'] !== 'diajukan') json_error('Pengajuan ini sudah diproses.', 409);

if ($action === 'tolak') {
    if ($catatan === '') json_error('Alasan penolakan wajib diisi.', 422);
    db()->prepare("UPDATE kasbon_requests SET status='ditolak', catatan=?, diproses_by=?, diproses_at=NOW() WHERE id=?")->execute([$catatan, $user['id'], $id]);
    db()->commit();
    audit('update', 'kasbon_requests', $id, 'ditolak: ' . $catatan);
    json_response(['ok' => true, 'status' => 'ditolak']);
}
if ($action === 'setujui') {
    $jumlah = isset($b['jumlah']) && $b['jumlah'] !== '' ? money_in($b['jumlah'], 'Jumlah') : (float)$r['jumlah'];
    if ($jumlah <= 0) json_error('Jumlah harus lebih dari 0.', 422);
    $tgl = require_valid_date($b['tgl'] ?? date('Y-m-d'), 'Tanggal kasbon');
    $kid = approve_kasbon_row($r, $jumlah, $tgl, $catatan, $user['id']);
    db()->commit();
    audit('update', 'kasbon_requests', $id, "disetujui $jumlah -> $kid");
    json_response(['ok' => true, 'status' => 'disetujui', 'kasbon_id' => $kid]);
}
json_error('Aksi tidak dikenal.', 422);
