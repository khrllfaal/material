<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

/**
 * Read-only "minimarket inventory" view: current stock per project +
 * material (masuk - keluar) against a reorder point, so pusat can see
 * at a glance which projects need a resupply without opening every
 * project one by one.
 *
 * The reorder point is derived, not typed in: when the project has a
 * RAP Bahan budget for this material, the threshold is "remaining need"
 * (rap_qty - total pemakaian so far) — stock below that means there
 * isn't enough on site to finish the budgeted work. Only when no RAP
 * entry exists at all does it fall back to the material's manual
 * stok_minimum, so the warning still works before RAP is filled in.
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
        rap.rap_qty AS rap_qty,
        COALESCE(masuk.total, 0) AS total_masuk,
        COALESCE(keluar.total, 0) AS total_keluar,
        COALESCE(masuk.total, 0) - COALESCE(keluar.total, 0) AS stok
     FROM (
        SELECT project_id, material_id FROM material_receipts
        UNION
        SELECT project_id, material_id FROM material_usage
        UNION
        SELECT project_id, material_id FROM rap_bahan_proyek
     ) pm
     JOIN projects p ON p.id = pm.project_id
     JOIN materials m ON m.id = pm.material_id
     LEFT JOIN (SELECT project_id, material_id, SUM(qty) AS total FROM material_receipts GROUP BY project_id, material_id) masuk
        ON masuk.project_id = pm.project_id AND masuk.material_id = pm.material_id
     LEFT JOIN (SELECT project_id, material_id, SUM(qty) AS total FROM material_usage GROUP BY project_id, material_id) keluar
        ON keluar.project_id = pm.project_id AND keluar.material_id = pm.material_id
     LEFT JOIN rap_bahan_proyek rap
        ON rap.project_id = pm.project_id AND rap.material_id = pm.material_id
     $scopeSql
     ORDER BY p.nama, m.nama"
);
$stmt->execute($params);
$out = array_map(function ($r) {
    $stok = (float)$r['stok'];
    $min = (float)$r['stok_minimum'];
    $pemakaian = (float)$r['total_keluar'];
    $hasRap = $r['rap_qty'] !== null;
    $rapQty = $hasRap ? (float)$r['rap_qty'] : null;
    $sisaKebutuhan = $hasRap ? max(0, $rapQty - $pemakaian) : null;
    // Reorder threshold: remaining budgeted need when RAP exists,
    // otherwise the material's manual stok_minimum as a fallback.
    $threshold = $hasRap ? $sisaKebutuhan : $min;
    // PDO returns DECIMAL columns as strings — cast back to real JSON
    // numbers so the frontend's typeof-based number formatting
    // (exports especially) doesn't treat them as pre-formatted text.
    $r['stok'] = $stok;
    $r['stok_minimum'] = $min;
    $r['total_masuk'] = (float)$r['total_masuk'];
    $r['total_keluar'] = (float)$r['total_keluar'];
    $r['rap_qty'] = $rapQty;
    $r['sisa_kebutuhan'] = $sisaKebutuhan;
    $r['threshold_basis'] = $hasRap ? 'rap' : 'manual';
    if ($stok <= 0) $status = 'HABIS';
    elseif ($stok <= $threshold) $status = 'MENIPIS';
    else $status = 'AMAN';
    $r['status'] = $status;
    return $r;
}, $stmt->fetchAll());

json_response($out);
