<?php
declare(strict_types=1);
require_once __DIR__ . '/payroll_lib.php';

/**
 * Menu Borongan: kontrak per tim, addendum, termin, buku besar, jurnal. Uang, jadi hanya peran pusat.
 *   GET  ?project_id=            daftar kontrak + ringkasan per tim
 *   GET  ?tim=<worker_id>        buku besar satu tim (kontrak, kejadian berurut, kasbon)
 *   GET  ?id=<kontrak_id>        satu kontrak lengkap
 *   GET  ?action=bukti&bukti_no= data Bukti Pembayaran termin
 *   GET  ?action=jurnal&from=&to=&project_id=   peristiwa jurnal borongan
 *   POST {action: buat|ubah|ajukan|setujui|kembalikan|selesai|batal | addendum_buat|addendum_setujui|addendum_tolak | termin_buat|termin_setujui|termin_tolak|termin_bayar, ...}
 * Admin Pusat membuat dan mengajukan; Akuntan menyetujui, membayar, menyelesaikan; Owner membaca.
 */
send_cors_headers();
$method = $_SERVER['REQUEST_METHOD'];
$user = $method === 'GET' ? require_role(['admin', 'akuntan', 'owner']) : require_role(['admin', 'akuntan']);
$svc = borongan_service();

if ($method === 'GET') {
    $action = (string)($_GET['action'] ?? '');
    if ($action === 'bukti') json_response($svc->bukti($user, (string)($_GET['bukti_no'] ?? '')));
    if ($action === 'jurnal') {
        $dari = require_valid_date($_GET['from'] ?? '2000-01-01', 'Dari tanggal'); $sampai = require_valid_date($_GET['to'] ?? date('Y-m-d'), 'Sampai tanggal');
        json_response($svc->jurnal(user_project_ids($user), !empty($_GET['project_id']) ? (string)$_GET['project_id'] : null, $dari, $sampai));
    }
    if (!empty($_GET['tim'])) json_response($svc->tim($user, (string)$_GET['tim']));
    if (!empty($_GET['id'])) json_response($svc->kontrak($user, (string)$_GET['id']));
    json_response($svc->daftar(user_project_ids($user), !empty($_GET['project_id']) ? (string)$_GET['project_id'] : null));
}

if ($method === 'POST') {
    $b = read_json_body();
    $id = (string)($b['id'] ?? '');
    switch ((string)($b['action'] ?? '')) {
        case 'buat':             json_response($svc->buat($user, $b));
        case 'ubah':             json_response($svc->ubah($user, $b));
        case 'ajukan':           json_response($svc->ajukan($user, $id));
        case 'setujui':          json_response($svc->setujui($user, $id));
        case 'kembalikan':       json_response($svc->kembalikan($user, $id, (string)($b['alasan'] ?? '')));
        case 'selesai':          json_response($svc->selesai($user, $id, !empty($b['kurangi_nilai'])));
        case 'batal':            json_response($svc->batal($user, $id, (string)($b['alasan'] ?? '')));
        case 'addendum_buat':    json_response($svc->addendumBuat($user, $b));
        case 'addendum_setujui': json_response($svc->addendumProses($user, $id, true));
        case 'addendum_tolak':   json_response($svc->addendumProses($user, $id, false));
        case 'termin_buat':      json_response($svc->terminBuat($user, $b));
        case 'termin_setujui':   json_response($svc->terminSetujui($user, $id));
        case 'termin_tolak':     json_response($svc->terminTolak($user, $id, (string)($b['alasan'] ?? '')));
        case 'termin_bayar':     json_response($svc->terminBayar($user, $id, $b));
    }
    json_error('Aksi tidak dikenal.', 422);
}
json_error('Method not allowed', 405);
