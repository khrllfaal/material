<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

// Days of stock to keep on hand before reordering: delivery time plus a
// safety buffer, one company-wide value so nobody has to fill it per bahan.
const REORDER_COVER_DAYS = 10;

/**
 * Read-only "minimarket inventory" view: current stock per project +
 * material (masuk - keluar) against a reorder point, so pusat can see
 * at a glance which projects need a resupply without opening every
 * project one by one.
 *
 * Reorder point (titik pesan ulang) = pemakaian harian x (lead time + safety
 * hari), both set per bahan in Master Bahan. Stock at or below it means
 * "order now". Without usable usage history the point falls back to the
 * remaining RAP need, and the response says which basis was used. When there
 * is no RAP either, the row is flagged DATA_TIDAK_LENGKAP instead of guessed.
 *
 * Date filtering here is deliberately not a simple [from, to] window on
 * everything: "Sisa Stok" is a running balance, so bounding it with a
 * lower date would silently drop whatever was carried over from before
 * that date and report a wrong physical quantity. Instead:
 *   - tgl_to (optional, "as of" date) bounds the cumulative totals —
 *     a valid point-in-time snapshot ("stock as of 30 Sept"), same
 *     math as today's unbounded view, just evaluated at an earlier date.
 *   - tgl_from/tgl_to independently narrow masuk_periode/keluar_periode
 *     ("movement in this window") the same way Analisis RAP per Proyek's
 *     own tgl_from/tgl_to narrow its Pemakaian — a from-only, to-only, or
 *     both bound all work. Total Masuk, Total Pemakaian and Sisa Stok
 *     never take the lower bound, so the running balance stays correct
 *     regardless of what the period fields are set to.
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

$tglTo = !empty($_GET['tgl_to']) ? $_GET['tgl_to'] : null;
$tglFrom = !empty($_GET['tgl_from']) ? $_GET['tgl_from'] : null;
$toCond = $tglTo ? 'AND tgl <= ?' : '';
// Same partial-range shape as material_rap_proyek_report.php's usage
// filter: each bound is independent, and with neither set this matches
// everything (so masuk_periode/keluar_periode equal the full totals by
// default, not zero).
$periodConds = [];
$periodParams = [];
if ($tglFrom) { $periodConds[] = 'tgl >= ?'; $periodParams[] = $tglFrom; }
if ($tglTo)   { $periodConds[] = 'tgl <= ?'; $periodParams[] = $tglTo; }
$periodCond = $periodConds ? implode(' AND ', $periodConds) : '1=1';

$stmt = db()->prepare(
    "SELECT
        p.id AS project_id, p.nama AS project_nama,
        m.id AS material_id, m.nama AS material_nama, m.satuan,
        rap.rap_qty AS rap_qty,
        COALESCE(masuk.total, 0) AS total_masuk,
        COALESCE(keluar.total, 0) AS total_keluar,
        COALESCE(masuk.total, 0) - COALESCE(keluar.total, 0) AS stok,
        masuk.last_tgl AS last_masuk_tgl, keluar.last_tgl AS last_keluar_tgl, keluar.first_tgl AS first_keluar_tgl,
        COALESCE(masuk_p.total, 0) AS masuk_periode,
        COALESCE(keluar_p.total, 0) AS keluar_periode
     FROM (
        SELECT project_id, material_id FROM material_receipts
        UNION
        SELECT project_id, material_id FROM material_usage
        UNION
        SELECT project_id, material_id FROM rap_bahan_proyek
     ) pm
     JOIN projects p ON p.id = pm.project_id
     JOIN materials m ON m.id = pm.material_id
     LEFT JOIN (SELECT project_id, material_id, SUM(qty) AS total, MAX(tgl) AS last_tgl FROM material_receipts WHERE 1=1 $toCond GROUP BY project_id, material_id) masuk
        ON masuk.project_id = pm.project_id AND masuk.material_id = pm.material_id
     LEFT JOIN (SELECT project_id, material_id, SUM(qty) AS total, MAX(tgl) AS last_tgl, MIN(tgl) AS first_tgl FROM material_usage WHERE 1=1 $toCond GROUP BY project_id, material_id) keluar
        ON keluar.project_id = pm.project_id AND keluar.material_id = pm.material_id
     LEFT JOIN (SELECT project_id, material_id, SUM(qty) AS total FROM material_receipts WHERE $periodCond GROUP BY project_id, material_id) masuk_p
        ON masuk_p.project_id = pm.project_id AND masuk_p.material_id = pm.material_id
     LEFT JOIN (SELECT project_id, material_id, SUM(qty) AS total FROM material_usage WHERE $periodCond GROUP BY project_id, material_id) keluar_p
        ON keluar_p.project_id = pm.project_id AND keluar_p.material_id = pm.material_id
     LEFT JOIN rap_bahan_proyek rap
        ON rap.project_id = pm.project_id AND rap.material_id = pm.material_id
     $scopeSql
     ORDER BY p.nama, m.nama"
);
// Physical counts: the last two per project+material, as of tgl_to.
$countWhere = [];
$countParams = [];
if ($tglTo) { $countWhere[] = 'tgl <= ?'; $countParams[] = $tglTo; }
if ($scope !== null) {
    $countWhere[] = 'project_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
    $countParams = array_merge($countParams, $scope);
}
$countSql = 'SELECT project_id, material_id, tgl, qty FROM stock_counts' . ($countWhere ? ' WHERE ' . implode(' AND ', $countWhere) : '') . ' ORDER BY tgl';
$countRows = [];
try {
    $countStmt = db()->prepare($countSql);
    $countStmt->execute($countParams);
    $countRows = $countStmt->fetchAll();
} catch (PDOException $e) {
    // Table missing on a database that skipped the migration: create it
    // once and retry; if that's not allowed, carry on without physical
    // counts (stock still works from receipts/usage) rather than 500.
    if (ensure_stock_counts_table()) {
        try { $countStmt = db()->prepare($countSql); $countStmt->execute($countParams); $countRows = $countStmt->fetchAll(); }
        catch (PDOException $e2) { error_log('[material] stock_counts: ' . $e2->getMessage()); }
    }
}
$countsByPm = [];
foreach ($countRows as $c) {
    $list = &$countsByPm[$c['project_id'].'|'.$c['material_id']];
    $list[] = ['tgl' => $c['tgl'], 'qty' => (float)$c['qty']];
    if (count($list) > 2) array_shift($list);
    unset($list);
}

// Bind order must match placeholder order left-to-right in the SQL above.
$bindParams = [];
if ($tglTo) $bindParams[] = $tglTo;     // masuk subquery
if ($tglTo) $bindParams[] = $tglTo;     // keluar subquery
$bindParams = array_merge($bindParams, $periodParams, $periodParams); // masuk_p, keluar_p
$stmt->execute(array_merge($bindParams, $params));
/* Physical usage between two counts: what was there at the earlier count,
   plus what arrived since, minus what is there now. Compared with what was
   recorded as Pemakaian in the same window, the gap is material that left the
   site without being recorded (loss, theft, or simply unlogged use). */
function count_window(string $pid, string $mid, array $c1, array $c2): array {
    $sum = function (string $table) use ($pid, $mid, $c1, $c2): float {
        $st = db()->prepare("SELECT COALESCE(SUM(qty),0) FROM $table WHERE project_id = ? AND material_id = ? AND tgl > ? AND tgl <= ?");
        $st->execute([$pid, $mid, $c1['tgl'], $c2['tgl']]);
        return (float)$st->fetchColumn();
    };
    $masuk = $sum('material_receipts');
    $tercatat = $sum('material_usage');
    $fisik = $c1['qty'] + $masuk - $c2['qty'];
    $days = max(1, (strtotime($c2['tgl']) - strtotime($c1['tgl'])) / 86400);
    return [
        'rate' => max(0, $fisik) / $days,
        'tidak_tercatat' => $fisik - $tercatat,
        'days' => $days,
    ];
}

$out = array_map(function ($r) use ($tglTo, $countsByPm) {
    $stok = (float)$r['stok'];
    $pemakaian = (float)$r['total_keluar'];
    $hasRap = $r['rap_qty'] !== null;
    $rapQty = $hasRap ? (float)$r['rap_qty'] : null;
    $sisaKebutuhan = $hasRap ? max(0, $rapQty - $pemakaian) : null;
    // PDO returns DECIMAL columns as strings — cast back to real JSON
    // numbers so the frontend's typeof-based number formatting
    // (exports especially) doesn't treat them as pre-formatted text.
    $r['stok'] = $stok;
    $r['total_masuk'] = (float)$r['total_masuk'];
    $r['total_keluar'] = (float)$r['total_keluar'];
    $r['masuk_periode'] = (float)$r['masuk_periode'];
    $r['keluar_periode'] = (float)$r['keluar_periode'];
    // Opening balance of the chosen window (kartu stok: awal + masuk - keluar = akhir).
    $r['stok_awal'] = round($stok - $r['masuk_periode'] + $r['keluar_periode'], 3);
    $r['rap_qty'] = $rapQty;
    $r['sisa_kebutuhan'] = $sisaKebutuhan;
    // Flags a project+material that keeps receiving deliveries but
    // hasn't had a Pemakaian entry logged in a while (or ever) — a
    // common field-data gap where usage is under-reported because it
    // takes a deliberate extra input, unlike a receipt that's tied to
    // an obvious delivery event. Left unflagged, stock/RAP status both
    // look falsely healthy while real consumption goes untracked.
    $usageStale = false;
    if ($r['last_masuk_tgl'] !== null) {
        if ($r['last_keluar_tgl'] === null) {
            $usageStale = true;
        } else {
            $gapDays = (strtotime($r['last_masuk_tgl']) - strtotime($r['last_keluar_tgl'])) / 86400;
            $usageStale = $gapDays > 14;
        }
    }
    $r['usage_stale'] = $usageStale;
    // Simple run-rate forecast (the standard "days of supply" metric
    // construction/retail inventory systems use): average daily usage
    // over the span actually observed, projected forward against
    // current stock. Deliberately not shown when usage is stale/absent
    // — a rate computed from almost no data, or data that stopped
    // updating, would just be a confident-looking wrong number.
    $anchor = $tglTo ?: date('Y-m-d');
    $dailyRate = null;
    $rateSource = null;
    if (!$usageStale && $pemakaian > 0 && $r['last_keluar_tgl'] !== null && $r['first_keluar_tgl'] !== null) {
        // Measured up to the snapshot date, not to the last entry: one
        // Pemakaian logged 9 days ago must not look like a whole day's rate.
        $spanDays = (strtotime($anchor) - strtotime($r['first_keluar_tgl'])) / 86400 + 1;
        $dailyRate = $pemakaian / max(1, $spanDays);
        $rateSource = 'TERCATAT';
    }
    // A physical count pair beats recorded usage when it is recent: it is what
    // was really consumed, so it still works when Pemakaian is never logged.
    $counts = $countsByPm[$r['project_id'].'|'.$r['material_id']] ?? [];
    $window = null;
    if (count($counts) === 2) {
        $window = count_window($r['project_id'], $r['material_id'], $counts[0], $counts[1]);
        $age = (strtotime($anchor) - strtotime($counts[1]['tgl'])) / 86400;
        if ($window['rate'] > 0 && $age <= 14) {
            $dailyRate = $window['rate'];
            $rateSource = 'FISIK';
        }
    }
    $forecastDays = ($dailyRate !== null && $dailyRate > 0 && $stok > 0) ? (int)floor($stok / $dailyRate) : null;
    $r['daily_usage_rate'] = $dailyRate;
    $r['rate_source'] = $rateSource;
    $r['forecast_days'] = $forecastDays;
    $last = $counts ? end($counts) : null;
    $r['stok_fisik'] = $last ? $last['qty'] : null;
    $r['stok_fisik_tgl'] = $last ? $last['tgl'] : null;
    $r['tidak_tercatat'] = $window ? round($window['tidak_tercatat'], 3) : null;
    // Anchor the projection to the stock snapshot date (tgl_to), not
    // always "today" — if viewing stock as of a past date, the forecast
    // is "from that date forward", not from right now.
    $forecastAnchor = $anchor;
    $r['forecast_date'] = $forecastDays !== null ? date('Y-m-d', strtotime("$forecastAnchor +$forecastDays days")) : null;
    unset($r['first_keluar_tgl']);
    // Reorder point: order again when stock falls to the cover needed to
    // survive delivery time plus a safety buffer, at the observed daily usage.
    // Without a current usage rate there is no honest reorder point: pagu is a
    // budget, not a consumption rate, so it is never used as a stand-in.
    $reorderPoint = ($dailyRate !== null && $dailyRate > 0) ? $dailyRate * REORDER_COVER_DAYS : null;
    $r['reorder_point'] = $reorderPoint;
    $r['reorder_cover_hari'] = REORDER_COVER_DAYS;
    // A row with Pagu RAP but zero Bahan Masuk and zero Pemakaian (e.g.
    // right after the one receipt that created it gets corrected/deleted,
    // or a proyek whose material simply hasn't arrived yet) is not the
    // same situation as "received some, then ran out" — labeling both
    // HABIS reads as "stock ran out" when really nothing has happened
    // yet, which misleads pusat into thinking a resupply is overdue.
    $totalMasuk = (float)$r['total_masuk'];
    if ($totalMasuk <= 0 && $pemakaian <= 0) $status = 'BELUM_ADA_TRANSAKSI';
    elseif ($stok <= 0) $status = 'HABIS';
    elseif (!$hasRap) $status = 'DATA_TIDAK_LENGKAP';
    elseif ($reorderPoint === null) $status = 'BELUM_ADA_HITUNGAN';
    elseif ($stok <= $reorderPoint) $status = 'MENIPIS';
    else $status = 'AMAN';
    $r['status'] = $status;
    return $r;
}, $stmt->fetchAll());

json_response($out);
