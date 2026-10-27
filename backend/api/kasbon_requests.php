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

$svc = kasbon_request_service();
if ($method === 'GET') json_response($svc->daftar($scope, !empty($_GET['status']) ? (string)$_GET['status'] : null));

$b = read_json_body();
if ($role === 'lapangan' || (string)($b['action'] ?? '') === '') json_response($svc->ajukan($user, $b)); // membuat pengajuan (juga boleh oleh admin/akuntan atas nama lapangan)
json_response($svc->tinjau($user, $b)); // memeriksa: hanya Akuntan (Admin Pusat mengoreksi/menghapus, tidak menyetujui)
