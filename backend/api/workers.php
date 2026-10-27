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

$svc = worker_service();

if ($method === 'GET') json_response($svc->daftar($user, $scope, $_GET));

if ($method === 'POST') {
    $b = read_json_body();
    switch ((string)($b['action'] ?? '')) {
        case 'verifikasi': json_response($svc->verifikasi($user, $b));
        case 'set_aktif':  json_response($svc->setAktif($user, $b));
        case 'rename_tim': json_response($svc->gantiNamaTim($user, $b));
    }
    json_response($svc->simpan($user, $b));
}

if ($method === 'DELETE') json_response($svc->hapus($user, (string)($_GET['id'] ?? '')));
json_error('Method not allowed', 405);
