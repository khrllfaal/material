<?php
declare(strict_types=1);
require_once __DIR__ . '/payroll_lib.php';

/**
 * Siklus periode gaji mingguan per proyek: draft -> diajukan -> dibayar.
 *   GET  ?project_id=&tgl=      status minggu itu (tanpa nominal, aman untuk lapangan)
 *   POST action=header          isi penanggung jawab / nama grup / catatan
 *   POST action=ajukan          lapangan/admin/akuntan mengajukan minggu ke pusat
 *   POST action=ajukan          lapangan (atau akuntan atas nama lapangan); catatan keterangan opsional
 *   POST action=setujui         Akuntan memeriksa & menyetujui (syarat sebelum dibayar)
 *   POST action=kembalikan      Akuntan mengembalikan ke lapangan dengan alasan (belum ada pembayaran)
 *   POST action=bayar           Akuntan mencatat pembayaran — hanya periode yang sudah disetujui
 * Admin Pusat tidak menyetujui/membayar: perannya mengoreksi absen, tarif, pekerja, dan menghapus data.
 */
send_cors_headers();
$method = $_SERVER['REQUEST_METHOD'];
$user = $method === 'GET' ? require_login() : require_role(['admin', 'akuntan', 'lapangan']); // each action below checks its own role
$role = $user['role'];

if ($method === 'GET') {
    $projectId = (string)($_GET['project_id'] ?? '');
    if (($_GET['action'] ?? '') === 'bukti') { // data Bukti Pembayaran (uang: hanya peran pusat)
        if (!in_array($role, ['admin', 'akuntan', 'owner'], true)) json_error('Anda tidak punya akses untuk aksi ini.', 403);
        json_response(bukti_service()->data((string)($_GET['bukti_no'] ?? '')));
    }
    if ($projectId === '' && in_array($role, ['admin', 'akuntan', 'owner'], true)) {
        // Pusat view: which weeks are waiting (e.g. status=diajukan -> ready to be paid). No money here.
        $status = (string)($_GET['status'] ?? '');
        $st = db()->prepare('SELECT p.id, p.project_id, pr.nama AS project_nama, p.tgl_mulai, p.tgl_akhir, p.status, p.pj_nama, p.grup_nama,
                                    (SELECT COUNT(*) FROM attendance a WHERE a.project_id = p.project_id AND a.tgl BETWEEN p.tgl_mulai AND p.tgl_akhir) AS n_absen
                               FROM payroll_periods p JOIN projects pr ON pr.id = p.project_id'
                           . ($status !== '' ? ' WHERE p.status = ?' : '') . ' ORDER BY p.tgl_mulai DESC, pr.nama LIMIT 200');
        $st->execute($status !== '' ? [$status] : []);
        json_response($st->fetchAll());
    }
    if ($projectId === '') json_error('Proyek wajib dipilih.', 422);
    require_project_access($user, $projectId);
    if (($_GET['action'] ?? '') === 'kasbon_plan') { // preview of the automatic kasbon deduction (money: pusat roles only)
        if (!in_array($role, ['admin', 'akuntan', 'owner'], true)) json_error('Anda tidak punya akses untuk aksi ini.', 403);
        $per = payroll_period($projectId, !empty($_GET['tgl']) ? require_valid_date($_GET['tgl']) : date('Y-m-d'));
        json_response(kasbon_auto_plan($projectId, $per));
    }
    $tgl = !empty($_GET['tgl']) ? require_valid_date($_GET['tgl']) : date('Y-m-d');
    [$s, $e] = period_bounds($projectId, $tgl);
    $st = db()->prepare('SELECT id, project_id, tgl_mulai, tgl_akhir, status, pj_nama, grup_nama, catatan FROM payroll_periods WHERE project_id = ? AND tgl_mulai = ?');
    $st->execute([$projectId, $s]);
    $p = $st->fetch() ?: ['id' => null, 'project_id' => $projectId, 'tgl_mulai' => $s, 'tgl_akhir' => $e, 'status' => 'draft', 'pj_nama' => '', 'grup_nama' => '', 'catatan' => ''];
    if ($p['pj_nama'] === '' && $p['grup_nama'] === '') { // remember the last values used on this project
        $l = db()->prepare("SELECT pj_nama, grup_nama FROM payroll_periods WHERE project_id = ? AND (pj_nama <> '' OR grup_nama <> '') ORDER BY tgl_mulai DESC LIMIT 1");
        $l->execute([$projectId]);
        if ($x = $l->fetch()) { $p['pj_nama'] = $x['pj_nama']; $p['grup_nama'] = $x['grup_nama']; }
    }
    json_response($p);
}

$b = read_json_body();
$action = (string)($b['action'] ?? '');
$projectId = (string)($b['project_id'] ?? '');
if ($projectId === '') json_error('Proyek wajib dipilih.', 422);
require_project_access($user, $projectId);
$tgl = require_valid_date($b['tgl'] ?? date('Y-m-d'), 'Tanggal');
payroll_period($projectId, $tgl); // pastikan barisnya ada — dibaca SEBELUM transaksi dibuka (lihat PeriodRepository::kunci)

// Lapisan HTTP hanya meneruskan ke use case (Application/PeriodService); pelanggaran aturan dilempar sebagai DomainError.
$svc = payroll_services()['period'];
switch ($action) {
    case 'header':     json_response($svc->header($user, $projectId, $tgl, $b));
    case 'ajukan':     json_response($svc->ajukan($user, $projectId, $tgl, $b));
    case 'setujui':    json_response($svc->setujui($user, $projectId, $tgl, $b));
    case 'kembalikan':
    case 'buka':       json_response($svc->kembalikan($user, $projectId, $tgl, $b));
    case 'bayar':      json_response($svc->bayar($user, $projectId, $tgl, $b, require_valid_date($b['tgl_bayar'] ?? date('Y-m-d'), 'Tanggal bayar')));
}
json_error('Aksi tidak dikenal.', 422);
