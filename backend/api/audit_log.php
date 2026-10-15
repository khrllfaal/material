<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

/**
 * Log Aktivitas: who changed what and when (read-only). Admin Pusat and Owner.
 * ?entity=&q=&tgl_from=&tgl_to=&limit=&offset=
 */
send_cors_headers();
require_role(['admin', 'owner']);
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed', 405);

$conds = []; $params = [];
if (!empty($_GET['entity'])) {
    $groups = [
        'absen' => ['attendance'], 'gaji' => ['payroll_periods', 'payroll_payments', 'payroll_adjustments'],
        'kasbon' => ['kasbon', 'kasbon_requests'], 'tarif' => ['labor_rates'], 'pekerja' => ['workers'],
        'user' => ['users'], 'master' => ['projects', 'materials', 'pekerjaan', 'rap_bahan_proyek', 'rap_material'],
        'transaksi' => ['material_receipts', 'material_usage', 'stock_counts'],
    ];
    $e = (string)$_GET['entity'];
    $list = $groups[$e] ?? [$e];
    $conds[] = 'entity IN (' . implode(',', array_fill(0, count($list), '?')) . ')';
    $params = array_merge($params, $list);
}
if (!empty($_GET['q'])) {
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string)$_GET['q']) . '%';
    $conds[] = '(user_username LIKE ? OR detail LIKE ? OR entity_id LIKE ?)';
    array_push($params, $like, $like, $like);
}
if (!empty($_GET['tgl_from'])) { $conds[] = 'created_at >= ?'; $params[] = require_valid_date($_GET['tgl_from']) . ' 00:00:00'; }
if (!empty($_GET['tgl_to']))   { $conds[] = 'created_at <= ?'; $params[] = (string)$_GET['tgl_to'] . ' 23:59:59'; }
$where = $conds ? ' WHERE ' . implode(' AND ', $conds) : '';
$limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

$c = db()->prepare("SELECT COUNT(*) FROM audit_log$where"); $c->execute($params);
$total = (int)$c->fetchColumn();
$st = db()->prepare("SELECT id, user_username, action, entity, entity_id, detail, ip_address, created_at FROM audit_log$where ORDER BY id DESC LIMIT $limit OFFSET $offset");
$st->execute($params);
json_response(['total' => $total, 'rows' => $st->fetchAll()]);
