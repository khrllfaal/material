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

// Period semantics (same as Monitor Stok): "Sampai" is the as-of date for
// the cumulative figures (datang, terpakai, sisa pagu, status — the pagu is
// one budget for the whole project, so it is always judged cumulatively),
// while Dari–Sampai narrows only the *_periode columns (movement inside the
// chosen window). With no dates both are the same full totals.
$toCond = !empty($_GET['tgl_to']) ? 'WHERE tgl <= ?' : '';
$toParams = !empty($_GET['tgl_to']) ? [$_GET['tgl_to']] : [];
$pConds = []; $pParams = [];
if (!empty($_GET['tgl_from'])) { $pConds[] = 'tgl >= ?'; $pParams[] = $_GET['tgl_from']; }
if (!empty($_GET['tgl_to']))   { $pConds[] = 'tgl <= ?'; $pParams[] = $_GET['tgl_to']; }
$pWhere = $pConds ? ('WHERE ' . implode(' AND ', $pConds)) : '';

$stmt = db()->prepare(
    "SELECT
        rbp.project_id, p.nama AS project_nama,
        rbp.material_id, m.nama AS material_nama, m.satuan,
        rbp.rap_qty,
        COALESCE(usage_total.total, 0) AS aktual_pemakaian,
        COALESCE(receipt_total.total, 0) AS total_masuk,
        COALESCE(usage_p.total, 0) AS usage_periode,
        COALESCE(receipt_p.total, 0) AS masuk_periode
     FROM rap_bahan_proyek rbp
     JOIN projects p ON p.id = rbp.project_id
     JOIN materials m ON m.id = rbp.material_id
     LEFT JOIN (
        SELECT project_id, material_id, SUM(qty) AS total FROM material_usage $toCond GROUP BY project_id, material_id
     ) usage_total ON usage_total.project_id = rbp.project_id AND usage_total.material_id = rbp.material_id
     LEFT JOIN (
        SELECT project_id, material_id, SUM(qty) AS total FROM material_receipts $toCond GROUP BY project_id, material_id
     ) receipt_total ON receipt_total.project_id = rbp.project_id AND receipt_total.material_id = rbp.material_id
     LEFT JOIN (
        SELECT project_id, material_id, SUM(qty) AS total FROM material_usage $pWhere GROUP BY project_id, material_id
     ) usage_p ON usage_p.project_id = rbp.project_id AND usage_p.material_id = rbp.material_id
     LEFT JOIN (
        SELECT project_id, material_id, SUM(qty) AS total FROM material_receipts $pWhere GROUP BY project_id, material_id
     ) receipt_p ON receipt_p.project_id = rbp.project_id AND receipt_p.material_id = rbp.material_id
     $scopeSql
     ORDER BY p.nama, m.nama"
);
$stmt->execute(array_merge($toParams, $toParams, $pParams, $pParams, $params));

$out = array_map(function ($r) {
    $rap = (float)$r['rap_qty'];
    $aktual = (float)$r['aktual_pemakaian'];
    $totalMasuk = (float)$r['total_masuk'];
    // PDO returns DECIMAL columns as strings (e.g. "300.000") — cast
    // back to real JSON numbers so the frontend's typeof-based number
    // formatting (exports especially) doesn't treat them as text.
    $r['rap_qty'] = $rap;
    $r['aktual_pemakaian'] = $aktual;
    $r['total_masuk'] = $totalMasuk;
    $r['usage_periode'] = (float)$r['usage_periode'];
    $r['masuk_periode'] = (float)$r['masuk_periode'];
    $r['deviasi'] = $aktual - $rap;
    // Receiving control (kedatangan vs pagu) — independent of
    // Pemakaian entirely, so it still works even for a proyek whose
    // admin lapangan never logs usage. Lives here (not Monitor Stok)
    // because it's a RAP-budget question, not a physical-stock one.
    $r['pct_didatangkan'] = $rap > 0 ? $totalMasuk / $rap : null;
    $r['sisa_kebutuhan'] = max(0, $rap - $aktual);
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
