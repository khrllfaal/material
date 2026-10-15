<?php
declare(strict_types=1);
require_once __DIR__ . '/payroll_lib.php';

/**
 * Absensi harian. One POST saves one project + one date for many workers
 * (idempotent upsert, so an offline resend never duplicates).
 *
 * Admin Lapangan: own projects only, today and the previous 7 days, only
 * while that week's period is still "draft" (not yet diajukan), and never
 * sees rates. Admin Pusat: any date up to tomorrow, until the period is paid.
 * Akuntan and Owner: read only.
 */
send_cors_headers();
$method = $_SERVER['REQUEST_METHOD'];
$user = $method === 'GET' ? require_login() : require_role(['admin', 'lapangan']);
$isField = $user['role'] === 'lapangan';
$scope = user_project_ids($user);

if ($method === 'GET') {
    $conds = []; $params = [];
    if ($scope !== null) {
        if (!$scope) json_response([]);
        $conds[] = 'a.project_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
        $params = array_merge($params, $scope);
    }
    if (!empty($_GET['project_id'])) { $conds[] = 'a.project_id = ?'; $params[] = $_GET['project_id']; }
    if (!empty($_GET['tgl_from'])) { $conds[] = 'a.tgl >= ?'; $params[] = $_GET['tgl_from']; }
    if (!empty($_GET['tgl_to']))   { $conds[] = 'a.tgl <= ?'; $params[] = $_GET['tgl_to']; }
    $cols = $isField ? 'a.worker_id, a.project_id, a.tgl, a.hari, a.lembur_jam, a.ket, a.rev'
                     : 'a.worker_id, a.project_id, a.tgl, a.hari, a.lembur_jam, a.ket, a.rev, a.grade, a.tim, a.tarif_harian, a.tarif_lembur';
    $st = db()->prepare("SELECT $cols FROM attendance a" . ($conds ? ' WHERE ' . implode(' AND ', $conds) : '') . ' ORDER BY a.tgl, a.worker_id');
    $st->execute($params);
    json_response(array_map(function ($r) {
        $r['hari'] = (float)$r['hari']; $r['lembur_jam'] = (float)$r['lembur_jam']; $r['rev'] = (int)$r['rev'];
        if (isset($r['tarif_harian'])) { $r['tarif_harian'] = (float)$r['tarif_harian']; $r['tarif_lembur'] = (float)$r['tarif_lembur']; }
        return $r;
    }, $st->fetchAll()));
}

if ($method !== 'POST') json_error('Method not allowed', 405);

$b = read_json_body();
$projectId = (string)($b['project_id'] ?? '');
if ($projectId === '') json_error('Proyek wajib dipilih.', 422);
require_project_access($user, $projectId);
$tgl = require_valid_date($b['tgl'] ?? '', 'Tanggal absen');
$entries = $b['entries'] ?? null;
if (!is_array($entries) || !$entries) json_error('Tidak ada data absen untuk disimpan.', 422);
if (count($entries) > 400) json_error('Terlalu banyak pekerja dalam satu kiriman.', 422);

$today = date('Y-m-d');
if ($isField) {
    if ($tgl > $today) json_error('Absen tidak bisa diisi untuk tanggal yang belum tiba.', 422);
    if ($tgl < date('Y-m-d', strtotime('-7 days'))) json_error('Absen lebih dari 7 hari lalu hanya bisa diubah Admin Pusat.', 403);
}
payroll_period($projectId, $tgl); // make sure the week's period row exists

$db = db();
$getW = $db->prepare('SELECT * FROM workers WHERE id = ?');
$up = $db->prepare(
    'INSERT INTO attendance (worker_id, project_id, tgl, hari, lembur_jam, grade, tim, tarif_harian, tarif_lembur, ket, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE hari=VALUES(hari), lembur_jam=VALUES(lembur_jam), ket=VALUES(ket), rev = rev + 1,
       tarif_harian = IF(tarif_harian = 0, VALUES(tarif_harian), tarif_harian),
       tarif_lembur = IF(tarif_lembur = 0, VALUES(tarif_lembur), tarif_lembur)');
$del = $db->prepare('DELETE FROM attendance WHERE worker_id=? AND project_id=? AND tgl=?');
$fmt = fn($v) => $v === null ? 'kosong' : (rtrim(rtrim(number_format($v[0], 1, ',', ''), '0'), ',') . 'h+' . rtrim(rtrim(number_format($v[1], 1, ',', ''), '0'), ',') . 'j');

$db->beginTransaction();
$saved = 0; $changes = []; $conflicts = [];
// The period row is locked for the whole save: nobody can approve/pay the week
// half-way through, and two saves for the same project+week run one after the other.
$lk = $db->prepare('SELECT * FROM payroll_periods WHERE project_id = ? AND tgl_mulai = ? FOR UPDATE');
$lk->execute([$projectId, payroll_week($tgl)[0]]);
$period = $lk->fetch();
if ($period['status'] === 'dibayar') json_error('Periode ini sudah dibayar dan terkunci. Koreksi dibuat sebagai penyesuaian di periode berikutnya.', 409);
if ($isField && $period['status'] !== 'draft') json_error('Absen minggu ini sudah diajukan. Hubungi Admin Pusat untuk koreksi.', 409);

// Before-state of this day (locked), for the audit trail and for conflict detection.
$before = [];
$bq = $db->prepare('SELECT worker_id, hari, lembur_jam, rev FROM attendance WHERE project_id = ? AND tgl = ? FOR UPDATE');
$bq->execute([$projectId, $tgl]);
foreach ($bq->fetchAll() as $o) $before[$o['worker_id']] = ['v' => [(float)$o['hari'], (float)$o['lembur_jam']], 'rev' => (int)$o['rev']];

foreach ($entries as $e) {
    $wid = (string)($e['worker_id'] ?? '');
    $getW->execute([$wid]);
    $w = $getW->fetch();
    if (!$w) json_error('Pekerja tidak ditemukan. Muat ulang halaman.', 422);
    // Field admins: the worker must belong to this project. Admin Pusat may also
    // record someone who moved from another project.
    if ($w['project_id'] !== $projectId && $isField) json_error('Pekerja bukan bagian dari proyek ini.', 403);
    $cur = $before[$wid] ?? null;
    $hasHari = array_key_exists('hari', $e) && $e['hari'] !== null;

    // Optimistic check: the client says which version it was looking at (rev, or
    // null = "no row yet"). If someone else saved in between, refuse — unless the
    // stored value is already exactly what is being sent (a harmless re-send).
    if (array_key_exists('ver', $e)) {
        $ver = $e['ver'] === null ? null : (int)$e['ver'];
        $same = $cur !== null && $hasHari && $cur['v'] === [(float)$e['hari'], (float)($e['lembur_jam'] ?? 0)];
        $gone = $cur === null && !$hasHari;
        if (($cur === null ? null : $cur['rev']) !== $ver && !$same && !$gone) { $conflicts[] = $w['nama']; continue; }
        if ($same || $gone) { $saved++; continue; }
    }

    if (!$hasHari) { // clear an entry
        if ($cur) $changes[] = $w['nama'] . ': ' . $fmt($cur['v']) . ' -> kosong';
        $del->execute([$wid, $projectId, $tgl]);
        $saved++;
        continue;
    }
    $hari = (float)$e['hari'];
    if (!in_array($hari, [0.0, 0.5, 1.0], true)) json_error('Kehadiran harus 0, ½, atau 1 hari.', 422);
    $lembur = (float)($e['lembur_jam'] ?? 0);
    if ($lembur < 0 || $lembur > 16 || fmod($lembur * 2, 1.0) != 0.0) json_error('Jam lembur 0–16 dalam kelipatan 0,5 jam.', 422);
    if ($hari == 0.0 && $lembur > 0) json_error('Lembur hanya untuk pekerja yang hadir.', 422);
    $ket = mb_substr(trim((string)($e['ket'] ?? '')), 0, 255);
    $now = [$hari, $lembur];
    if ($cur === null || $cur['v'] !== $now) $changes[] = $w['nama'] . ': ' . ($cur ? $fmt($cur['v']) : 'baru') . ' -> ' . $fmt($now);
    [$harian, $lemburRate] = worker_rates($w);
    $up->execute([$wid, $projectId, $tgl, $hari, $lembur, $w['grade'], $w['tim'], $harian, $lemburRate, $ket, $user['id']]);
    $saved++;
}
if ($conflicts) {
    $db->rollBack();
    json_error('Absen ini baru saja diubah orang lain (' . implode(', ', array_slice($conflicts, 0, 5)) . (count($conflicts) > 5 ? ' dll' : '') . '). Layar dimuat ulang supaya tidak saling menimpa.', 409);
}
// An approved week that is edited loses its approval: it must be checked again.
if ($period['status'] === 'disetujui' && $changes) {
    $db->prepare("UPDATE payroll_periods SET status='diajukan', disetujui_at=NULL, disetujui_by=NULL WHERE id=?")->execute([$period['id']]);
}
$db->commit();

$detail = "$tgl: $saved pekerja";
if ($changes) {
    $detail .= ' | ' . count($changes) . ' perubahan: ' . implode('; ', array_slice($changes, 0, 8)) . (count($changes) > 8 ? '; +' . (count($changes) - 8) . ' lainnya' : '');
    if ($period['status'] !== 'draft') $detail .= ' | SETELAH DIAJUKAN (' . $period['status'] . ')';
}
audit('update', 'attendance', $projectId . '/' . $tgl, $detail);
json_response(['ok' => true, 'saved' => $saved, 'periode_status' => ($period['status'] === 'disetujui' && $changes) ? 'diajukan' : $period['status']]);
