<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

/**
 * Absensi harian. One POST saves one project + one date for many workers
 * (idempotent upsert, so an offline resend never duplicates).
 *
 * Admin Lapangan: own projects only, today and the previous 3 days, only
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
    $cols = $isField ? 'a.worker_id, a.project_id, a.tgl, a.hari, a.lembur_jam, a.ket'
                     : 'a.worker_id, a.project_id, a.tgl, a.hari, a.lembur_jam, a.ket, a.grade, a.tim, a.tarif_harian, a.tarif_lembur';
    $st = db()->prepare("SELECT $cols FROM attendance a" . ($conds ? ' WHERE ' . implode(' AND ', $conds) : '') . ' ORDER BY a.tgl, a.worker_id');
    $st->execute($params);
    json_response(array_map(function ($r) {
        $r['hari'] = (float)$r['hari']; $r['lembur_jam'] = (float)$r['lembur_jam'];
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
    if ($tgl < date('Y-m-d', strtotime('-3 days'))) json_error('Absen lebih dari 3 hari lalu hanya bisa diubah Admin Pusat.', 403);
}
$period = payroll_period($projectId, $tgl);
if ($period['status'] === 'dibayar') json_error('Periode ini sudah dibayar dan terkunci. Koreksi dibuat sebagai penyesuaian di periode berikutnya.', 409);
if ($isField && $period['status'] !== 'draft') json_error('Absen minggu ini sudah diajukan. Hubungi Admin Pusat untuk koreksi.', 409);

$db = db();
$getW = $db->prepare('SELECT * FROM workers WHERE id = ?');
$getA = $db->prepare('SELECT tarif_harian, tarif_lembur FROM attendance WHERE worker_id=? AND project_id=? AND tgl=?');
$up = $db->prepare(
    'INSERT INTO attendance (worker_id, project_id, tgl, hari, lembur_jam, grade, tim, tarif_harian, tarif_lembur, ket, created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)
     ON DUPLICATE KEY UPDATE hari=VALUES(hari), lembur_jam=VALUES(lembur_jam), ket=VALUES(ket),
       tarif_harian = IF(tarif_harian = 0, VALUES(tarif_harian), tarif_harian),
       tarif_lembur = IF(tarif_lembur = 0, VALUES(tarif_lembur), tarif_lembur)');
$del = $db->prepare('DELETE FROM attendance WHERE worker_id=? AND project_id=? AND tgl=?');

$db->beginTransaction();
$saved = 0;
try {
    foreach ($entries as $e) {
        $wid = (string)($e['worker_id'] ?? '');
        $getW->execute([$wid]);
        $w = $getW->fetch();
        if (!$w) json_error('Pekerja tidak ditemukan. Muat ulang halaman.', 422);
        // Field admins and normal flow: the worker must belong to this project.
        // Admin Pusat may also record someone who moved from another project.
        if ($w['project_id'] !== $projectId && $isField) json_error('Pekerja bukan bagian dari proyek ini.', 403);
        if ($e['hari'] === null) { // clear an entry
            $del->execute([$wid, $projectId, $tgl]);
            $saved++;
            continue;
        }
        $hari = (float)($e['hari'] ?? 0);
        if (!in_array($hari, [0.0, 0.5, 1.0], true)) json_error('Kehadiran harus 0, ½, atau 1 hari.', 422);
        $lembur = (float)($e['lembur_jam'] ?? 0);
        if ($lembur < 0 || $lembur > 16 || fmod($lembur * 2, 1.0) != 0.0) json_error('Jam lembur 0–16 dalam kelipatan 0,5 jam.', 422);
        if ($hari == 0.0 && $lembur > 0) json_error('Lembur hanya untuk pekerja yang hadir.', 422);
        $ket = mb_substr(trim((string)($e['ket'] ?? '')), 0, 255);
        [$harian, $lemburRate] = worker_rates($w);
        $up->execute([$wid, $projectId, $tgl, $hari, $lembur, $w['grade'], $w['tim'], $harian, $lemburRate, $ket, $user['id']]);
        $saved++;
    }
    $db->commit();
} catch (Throwable $ex) {
    if ($db->inTransaction()) $db->rollBack();
    throw $ex;
}
audit('update', 'attendance', $projectId . '/' . $tgl, "$saved pekerja");
json_response(['ok' => true, 'saved' => $saved, 'periode_status' => $period['status']]);
