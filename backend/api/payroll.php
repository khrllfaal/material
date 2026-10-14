<?php
declare(strict_types=1);
require_once __DIR__ . '/payroll_lib.php';

/**
 * Rekap gaji (read-only). Admin Pusat, Akuntan and Owner only — Admin
 * Lapangan never sees money. ?project_id=&tgl_from=&tgl_to=
 * Default range: the current Friday–Thursday week.
 */
send_cors_headers();
require_role(['admin', 'akuntan', 'owner']);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed', 405);

[$wFrom, $wTo] = payroll_week(date('Y-m-d'));
$from = !empty($_GET['tgl_from']) ? require_valid_date($_GET['tgl_from'], 'Tanggal dari') : $wFrom;
$to = !empty($_GET['tgl_to']) ? $_GET['tgl_to'] : $wTo;
if (!DateTime::createFromFormat('Y-m-d', $to)) json_error('Tanggal sampai tidak valid.', 422);
if ($to < $from) json_error('Tanggal sampai harus setelah tanggal dari.', 422);
if ((strtotime($to) - strtotime($from)) / 86400 > 400) json_error('Rentang tanggal maksimal 400 hari.', 422);

$pids = !empty($_GET['project_id']) ? [(string)$_GET['project_id']] : null;
$res = payroll_compute($pids, $from, $to);

// Kasbon saldo per project/worker for rows shown (and group-level per project).
$saldo = [];
$seen = [];
foreach ($res['rows'] as $r) {
    $k = $r['project_id'] . '|' . $r['worker_id'];
    if (!isset($seen[$k])) { $seen[$k] = 1; $s = kasbon_saldo($r['project_id'], $r['worker_id']); if (abs($s) > 0.005) $saldo[$k] = $s; }
}
foreach ($res['periods'] as $p) {
    $k = $p['project_id'] . '|';
    if (!isset($seen[$k])) { $seen[$k] = 1; $s = kasbon_saldo($p['project_id'], null); if (abs($s) > 0.005) $saldo[$k] = $s; }
}
$res['kasbon_saldo'] = (object)$saldo;
$res['range'] = ['from' => $from, 'to' => $to];
$res['grades'] = WORKER_GRADES;
json_response($res);
