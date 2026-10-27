<?php
declare(strict_types=1);
require_once __DIR__ . '/payroll_lib.php';

/**
 * Buku kasbon. Admin Pusat / Akuntan record kasbon given; the repayments are
 * the "kasbon" deductions on payroll periods. Saldo = given - repaid, never
 * negative. Owner may read.
 */
send_cors_headers();
$method = $_SERVER['REQUEST_METHOD'];
$user = $method === 'GET' ? require_role(['admin', 'akuntan', 'owner']) : require_role(['admin', 'akuntan']);

$svc = kasbon_buku_service();
if ($method === 'GET') json_response($svc->daftar());
if ($method === 'POST') json_response($svc->catat($user, read_json_body()));
if ($method === 'DELETE') json_response($svc->hapus((string)($_GET['id'] ?? '')));
json_error('Method not allowed', 405);
