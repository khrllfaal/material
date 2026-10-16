<?php
declare(strict_types=1);
require_once __DIR__ . '/payroll_lib.php';

/**
 * Master pekerja per proyek. Admin Lapangan can list the roster of their own
 * projects (name, status, team only — never rates or bank details) and add a
 * new worker on the spot. Admin Pusat and Akuntan manage everything,
 * including rates; Owner is read-only.
 */
send_cors_headers();
$method = $_SERVER['REQUEST_METHOD'];
$user = $method === 'GET' ? require_login() : require_role(['admin', 'akuntan', 'lapangan']);
$isField = $user['role'] === 'lapangan';
$scope = user_project_ids($user);

function norm_name(string $s): string { return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s))); }

if ($method === 'GET') {
    $conds = []; $params = [];
    if ($scope !== null) {
        if (!$scope) json_response([]);
        $conds[] = 'project_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
        $params = array_merge($params, $scope);
    }
    if (!empty($_GET['project_id'])) { $conds[] = 'project_id = ?'; $params[] = $_GET['project_id']; }
    if (empty($_GET['all'])) $conds[] = 'aktif = 1';
    $cols = $isField ? 'id, project_id, nama, grade, tim, aktif, diverifikasi'
                     : 'id, project_id, nama, grade, tim, tarif_harian, tarif_lembur, no_rek, aktif, diverifikasi, created_at';
    $st = db()->prepare("SELECT $cols FROM workers" . ($conds ? ' WHERE ' . implode(' AND ', $conds) : '') . ' ORDER BY tim, FIELD(grade,\'KTK\',\'TK\',\'LD\',\'OBK\',\'SPR\',\'OMX\',\'LAIN\'), nama');
    $st->execute($params);
    $rows = array_map(function ($r) use ($isField) {
        $r['aktif'] = (int)$r['aktif'];
        $r['diverifikasi'] = 1; // verifikasi pekerja baru dinonaktifkan
        if (!$isField) {
            $r['tarif_harian'] = $r['tarif_harian'] === null ? null : (float)$r['tarif_harian'];
            $r['tarif_lembur'] = $r['tarif_lembur'] === null ? null : (float)$r['tarif_lembur'];
        }
        return $r;
    }, $st->fetchAll());
    json_response($rows);
}

if ($method === 'POST') {
    $b = read_json_body();
    // Pusat confirms that workers added from the field are real people (ghost-worker control).
    if (($b['action'] ?? '') === 'verifikasi') {
        if ($user['role'] !== 'akuntan') json_error('Hanya Akuntan yang memverifikasi pekerja baru.', 403);
        $ids = array_values(array_filter(array_map('strval', (array)($b['ids'] ?? []))));
        if (!empty($b['project_id']) && !empty($b['semua'])) {
            $st = db()->prepare('SELECT id FROM workers WHERE project_id = ? AND diverifikasi = 0');
            $st->execute([(string)$b['project_id']]);
            $ids = array_merge($ids, array_column($st->fetchAll(), 'id'));
        }
        if (!$ids) json_error('Tidak ada pekerja yang dipilih.', 422);
        $up = db()->prepare('UPDATE workers SET diverifikasi = 1, diverifikasi_by = ?, diverifikasi_at = NOW() WHERE id = ? AND diverifikasi = 0');
        $n = 0;
        foreach (array_unique($ids) as $wid) { $up->execute([$user['id'], $wid]); $n += $up->rowCount(); }
        audit('update', 'workers', 'verifikasi', "$n pekerja diverifikasi");
        json_response(['ok' => true, 'diverifikasi' => $n]);
    }
    // Merge or rename a tim everywhere (workers, tim rates, kasbon, open periods) so nothing is orphaned.
    if (($b['action'] ?? '') === 'rename_tim') {
        if ($isField) json_error('Anda tidak punya akses untuk aksi ini.', 403);
        $pid = (string)($b['project_id'] ?? ''); $dari = trim((string)($b['dari'] ?? '')); $ke = trim((string)($b['ke'] ?? ''));
        if ($pid === '' || $dari === '' || $ke === '' || mb_strlen($ke) > 80) json_error('Isi tim asal dan nama tim baru (maksimal 80 karakter).', 422);
        if ($dari === $ke) json_error('Nama tim baru sama dengan yang lama.', 422);
        require_project_access($user, $pid);
        $db = db(); $db->beginTransaction();
        $n = $db->prepare('UPDATE workers SET tim = ? WHERE project_id = ? AND tim = ?'); $n->execute([$ke, $pid, $dari]); $nw = $n->rowCount();
        $db->prepare('UPDATE IGNORE labor_rates SET tim = ? WHERE project_id = ? AND tim = ?')->execute([$ke, $pid, $dari]);
        $db->prepare('DELETE FROM labor_rates WHERE project_id = ? AND tim = ?')->execute([$pid, $dari]); // the target already had its own rate
        $db->prepare('UPDATE kasbon SET tim = ? WHERE project_id = ? AND worker_id IS NULL AND tim = ?')->execute([$ke, $pid, $dari]);
        $db->prepare('UPDATE kasbon_requests SET tim = ? WHERE project_id = ? AND worker_id IS NULL AND tim = ?')->execute([$ke, $pid, $dari]);
        $db->prepare('UPDATE payroll_adjustments x JOIN payroll_periods p ON p.id = x.period_id SET x.tim = ? WHERE p.project_id = ? AND x.worker_id IS NULL AND x.tim = ?')->execute([$ke, $pid, $dari]);
        $db->prepare("UPDATE attendance a JOIN payroll_periods p ON p.project_id = a.project_id AND a.tgl BETWEEN p.tgl_mulai AND p.tgl_akhir
                         SET a.tim = ? WHERE a.project_id = ? AND a.tim = ? AND p.status <> 'dibayar'")->execute([$ke, $pid, $dari]); // paid weeks keep the name they were paid under
        $db->commit();
        audit('update', 'workers', $pid, "tim '$dari' -> '$ke' ($nw pekerja)");
        json_response(['ok' => true, 'pekerja' => $nw]);
    }
    $id = (string)($b['id'] ?? '');
    $projectId = (string)($b['project_id'] ?? '');
    $nama = trim(preg_replace('/\s+/u', ' ', (string)($b['nama'] ?? '')));
    $grade = (string)($b['grade'] ?? '');
    $tim = trim((string)($b['tim'] ?? ''));
    if ($projectId === '') json_error('Proyek wajib dipilih.', 422);
    if ($nama === '' || mb_strlen($nama) > 120) json_error('Nama pekerja wajib diisi (maksimal 120 karakter).', 422);
    if (!isset(WORKER_GRADES[$grade])) json_error('Status pekerja tidak valid.', 422);
    if (mb_strlen($tim) > 80) json_error('Nama tim terlalu panjang.', 422);
    require_project_access($user, $projectId);
    if ($tim !== '') { // "tim a" / "Tim A " must not become a second team: reuse the spelling already in this project
        $tq = db()->prepare('SELECT tim FROM workers WHERE project_id = ? AND tim <> \'\' GROUP BY tim'); $tq->execute([$projectId]);
        foreach ($tq->fetchAll() as $t0) if (norm_name($t0['tim']) === norm_name($tim)) { $tim = $t0['tim']; break; }
    }

    $exists = null;
    if ($id !== '') {
        if (!preg_match('/^wrk_[a-z0-9]{6,30}$/', $id)) json_error('id tidak valid.', 422);
        $st = db()->prepare('SELECT * FROM workers WHERE id = ?');
        $st->execute([$id]);
        $exists = $st->fetch() ?: null;
    }
    if ($isField && $exists) json_error('Data pekerja sudah tersimpan. Perubahan dilakukan oleh Admin Pusat.', 403);

    // Same name + status + team in one project = almost certainly a duplicate.
    $dup = db()->prepare('SELECT id, nama, tim FROM workers WHERE project_id = ? AND grade = ? AND id <> ?');
    $dup->execute([$projectId, $grade, $id]);
    foreach ($dup->fetchAll() as $d) {
        if (norm_name($d['nama']) === norm_name($nama) && norm_name($d['tim']) === norm_name($tim)) {
            json_error('Pekerja bernama sama (status dan tim sama) sudah ada di proyek ini. Pilih dari daftar, atau beri tambahan nama agar berbeda (mis. "Endang B").', 409);
        }
    }

    $aktif = array_key_exists('aktif', $b) ? ((int)(bool)$b['aktif']) : 1;
    if ($isField) {
        $id = $id !== '' ? $id : new_id('wrk_');
        db()->prepare('INSERT INTO workers (id, project_id, nama, grade, tim, aktif, diverifikasi, created_by) VALUES (?,?,?,?,?,1,1,?)')
            ->execute([$id, $projectId, $nama, $grade, $tim, $user['id']]);
        audit('create', 'workers', $id, "$nama ($grade)");
        json_response(['ok' => true, 'id' => $id]);
    }

    $harian = (isset($b['tarif_harian']) && $b['tarif_harian'] !== '') ? money_in($b['tarif_harian'], 'Tarif harian') : null;
    $lembur = (isset($b['tarif_lembur']) && $b['tarif_lembur'] !== '') ? money_in($b['tarif_lembur'], 'Tarif lembur') : null;
    $rek = mb_substr(trim((string)($b['no_rek'] ?? '')), 0, 80);
    if ($exists) {
        db()->prepare('UPDATE workers SET project_id=?, nama=?, grade=?, tim=?, tarif_harian=?, tarif_lembur=?, no_rek=?, aktif=? WHERE id=?')
            ->execute([$projectId, $nama, $grade, $tim, $harian, $lembur, $rek, $aktif, $id]);
        $detail = '';
        if ((string)$exists['tarif_harian'] !== (string)$harian || (string)$exists['tarif_lembur'] !== (string)$lembur || $exists['grade'] !== $grade) {
            $detail = sprintf('tarif/status: %s %s/%s -> %s %s/%s', $exists['grade'], $exists['tarif_harian'] ?? '-', $exists['tarif_lembur'] ?? '-', $grade, $harian ?? '-', $lembur ?? '-');
        }
        audit('update', 'workers', $id, $detail);
    } else {
        $id = $id !== '' ? $id : new_id('wrk_');
        db()->prepare('INSERT INTO workers (id, project_id, nama, grade, tim, tarif_harian, tarif_lembur, no_rek, aktif, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $projectId, $nama, $grade, $tim, $harian, $lembur, $rek, $aktif, $user['id']]);
        audit('create', 'workers', $id, "$nama ($grade)");
    }
    fill_zero_snapshots($projectId, null, $id);
    json_response(['ok' => true, 'id' => $id]);
}

if ($method === 'DELETE') {
    if ($user['role'] !== 'admin') json_error('Hanya Admin Pusat yang bisa menghapus pekerja. Nonaktifkan bila sudah pernah absen.', 403);
    $id = (string)($_GET['id'] ?? '');
    if ($id === '') json_error('id wajib diisi', 422);
    $c = db()->prepare('SELECT COUNT(*) FROM attendance WHERE worker_id = ?');
    $c->execute([$id]);
    if ((int)$c->fetchColumn() > 0) json_error('Pekerja ini sudah punya riwayat absen, jadi tidak dihapus. Ubah menjadi Nonaktif.', 409);
    db()->prepare('DELETE FROM workers WHERE id = ?')->execute([$id]);
    audit('delete', 'workers', $id);
    json_response(['ok' => true]);
}
json_error('Method not allowed', 405);
