<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

/**
 * The simple RAP view: total budgeted qty per project+material vs
 * total actual usage so far — no pekerjaan/BOQ breakdown required.
 * This is meant to be the one pusat fills in for every project; see
 * material_rap_report.php for the optional per-pekerjaan deep-dive.
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
    $scopeSql = "WHERE rbp.project_id IN ($placeholders)";
    $params = $scope;
}

$stmt = db()->prepare(
    "SELECT
        rbp.project_id, p.nama AS project_nama,
        rbp.material_id, m.nama AS material_nama, m.satuan,
        rbp.rap_qty,
        COALESCE(usage_total.total, 0) AS aktual_pemakaian
     FROM rap_bahan_proyek rbp
     JOIN projects p ON p.id = rbp.project_id
     JOIN materials m ON m.id = rbp.material_id
     LEFT JOIN (
        SELECT project_id, material_id, SUM(qty) AS total FROM material_usage GROUP BY project_id, material_id
     ) usage_total ON usage_total.project_id = rbp.project_id AND usage_total.material_id = rbp.material_id
     $scopeSql
     ORDER BY p.nama, m.nama"
);
$stmt->execute($params);

$out = array_map(function ($r) {
    $rap = (float)$r['rap_qty'];
    $aktual = (float)$r['aktual_pemakaian'];
    // PDO returns DECIMAL columns as strings (e.g. "300.000") — cast
    // back to real JSON numbers so the frontend's typeof-based number
    // formatting (exports especially) doesn't treat them as text.
    $r['rap_qty'] = $rap;
    $r['aktual_pemakaian'] = $aktual;
    $r['deviasi'] = $aktual - $rap;
    if ($rap <= 0) {
        $r['persentase'] = null;
        $r['status'] = 'DATA_TIDAK_LENGKAP';
    } else {
        $pct = $aktual / $rap;
        $r['persentase'] = $pct;
        $r['status'] = $pct > 1 ? 'MELEBIHI' : ($pct >= 0.9 ? 'HAMPIR_HABIS' : 'AMAN');
    }
    return $r;
}, $stmt->fetchAll());

json_response($out);
