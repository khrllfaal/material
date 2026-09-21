<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

/**
 * EVM-style deviation report: for every pekerjaan+material with a RAP
 * coefficient configured, compares actual material usage so far
 * against the ideal amount for the work volume completed so far
 * (koefisien * volume_aktual terakhir). Positive deviasi = pemborosan
 * (overuse vs. plan).
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
    $scopeSql = "WHERE pk.project_id IN ($placeholders)";
    $params = $scope;
}

$stmt = db()->prepare(
    "SELECT
        pk.project_id, p.nama AS project_nama,
        pk.id AS pekerjaan_id, pk.nama AS pekerjaan_nama, pk.satuan AS pekerjaan_satuan, pk.volume_kontrak,
        rm.material_id, m.nama AS material_nama, m.satuan AS material_satuan,
        rm.koefisien,
        COALESCE(latest_progress.volume_aktual, 0) AS volume_aktual,
        COALESCE(usage_total.total, 0) AS aktual_material
     FROM rap_material rm
     JOIN pekerjaan pk ON pk.id = rm.pekerjaan_id
     JOIN projects p ON p.id = pk.project_id
     JOIN materials m ON m.id = rm.material_id
     LEFT JOIN (
        SELECT pp1.pekerjaan_id, pp1.volume_aktual
        FROM progress_pekerjaan pp1
        INNER JOIN (
            SELECT pekerjaan_id, MAX(tgl) AS max_tgl FROM progress_pekerjaan GROUP BY pekerjaan_id
        ) latest ON latest.pekerjaan_id = pp1.pekerjaan_id AND latest.max_tgl = pp1.tgl
     ) latest_progress ON latest_progress.pekerjaan_id = pk.id
     LEFT JOIN (
        SELECT pekerjaan_id, material_id, SUM(qty) AS total FROM material_usage
        WHERE pekerjaan_id IS NOT NULL GROUP BY pekerjaan_id, material_id
     ) usage_total ON usage_total.pekerjaan_id = rm.pekerjaan_id AND usage_total.material_id = rm.material_id
     $scopeSql
     ORDER BY p.nama, pk.nama, m.nama"
);
$stmt->execute($params);

$out = array_map(function ($r) {
    $volumeAktual = (float)$r['volume_aktual'];
    $koefisien = (float)$r['koefisien'];
    $aktualMaterial = (float)$r['aktual_material'];
    $targetIdeal = $koefisien * $volumeAktual;
    $r['target_material_ideal'] = $targetIdeal;
    $r['deviasi'] = $aktualMaterial - $targetIdeal;
    if ($targetIdeal <= 0) {
        $r['persentase'] = null;
        $r['status'] = 'DATA_TIDAK_LENGKAP';
    } else {
        $pct = $aktualMaterial / $targetIdeal;
        $r['persentase'] = $pct;
        $r['status'] = $pct > 1 ? 'MELEBIHI' : ($pct >= 0.9 ? 'HAMPIR_HABIS' : 'AMAN');
    }
    return $r;
}, $stmt->fetchAll());

json_response($out);
