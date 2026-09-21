<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

/**
 * Read-only "minimarket inventory" view: current stock per project +
 * material (masuk - keluar) against each material's reorder point, so
 * pusat can see at a glance which projects need a resupply without
 * opening every project one by one.
 */
send_cors_headers();
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') json_error('Method not allowed', 405);

$scope = user_project_ids($user);
$scopeSql = '';
$params = [];
if ($scope !== null) {
    if (!$scope) { json_response([]); exit; }
    $placeholders = implode(',', array_fill(0, count($scope), '?'));
    $scopeSql = "WHERE pm.project_id IN ($placeholders)";
    $params = $scope;
}

$stmt = db()->prepare(
    "SELECT
        p.id AS project_id, p.nama AS project_nama,
        m.id AS material_id, m.nama AS material_nama, m.satuan, m.stok_minimum,
        COALESCE(masuk.total, 0) AS total_masuk,
        COALESCE(keluar.total, 0) AS total_keluar,
        COALESCE(masuk.total, 0) - COALESCE(keluar.total, 0) AS stok
     FROM (
        SELECT project_id, material_id FROM material_receipts
        UNION
        SELECT project_id, material_id FROM material_usage
     ) pm
     JOIN projects p ON p.id = pm.project_id
     JOIN materials m ON m.id = pm.material_id
     LEFT JOIN (SELECT project_id, material_id, SUM(qty) AS total FROM material_receipts GROUP BY project_id, material_id) masuk
        ON masuk.project_id = pm.project_id AND masuk.material_id = pm.material_id
     LEFT JOIN (SELECT project_id, material_id, SUM(qty) AS total FROM material_usage GROUP BY project_id, material_id) keluar
        ON keluar.project_id = pm.project_id AND keluar.material_id = pm.material_id
     $scopeSql
     ORDER BY p.nama, m.nama"
);
$stmt->execute($params);
$out = array_map(function ($r) {
    $stok = (float)$r['stok'];
    $min = (float)$r['stok_minimum'];
    if ($stok <= 0) $status = 'HABIS';
    elseif ($stok <= $min) $status = 'MENIPIS';
    else $status = 'AMAN';
    $r['status'] = $status;
    return $r;
}, $stmt->fetchAll());

json_response($out);
