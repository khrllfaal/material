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
    json_response(attendance_service()->daftar($scope, $_GET, $isField));
}

if ($method !== 'POST') json_error('Method not allowed', 405);

$b = read_json_body();
$projectId = (string)($b['project_id'] ?? '');
if ($projectId === '') json_error('Proyek wajib dipilih.', 422);
require_project_access($user, $projectId);
$tgl = require_valid_date($b['tgl'] ?? '', 'Tanggal absen');
$entries = $b['entries'] ?? null;
json_response(attendance_service()->simpan($user, $projectId, $tgl, is_array($entries) ? $entries : []));
