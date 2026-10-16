<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

/**
 * Tarif harian & lembur per proyek per status. Readable by Admin Pusat,
 * Akuntan and Owner; only Admin Pusat and Akuntan can change them. Every
 * change is audited with old -> new. A change never rewrites history unless
 * "apply_unpaid" is set, and even then only for periods not yet paid.
 */
send_cors_headers();
$method = $_SERVER['REQUEST_METHOD'];
$user = $method === 'GET' ? require_role(['admin', 'akuntan', 'owner']) : require_role(['admin', 'akuntan']);

if ($method === 'GET') {
    $rows = db()->query('SELECT project_id, tim, grade, tarif_harian, tarif_lembur FROM labor_rates ORDER BY project_id, tim, grade')->fetchAll();
    json_response(array_map(function ($r) {
        $r['tarif_harian'] = (float)$r['tarif_harian']; $r['tarif_lembur'] = (float)$r['tarif_lembur']; return $r;
    }, $rows));
}

if ($method === 'POST') {
    $b = read_json_body();
    $projectId = (string)($b['project_id'] ?? ''); $grade = (string)($b['grade'] ?? '');
    $tim = mb_substr(trim((string)($b['tim'] ?? '')), 0, 80); // '' = standar proyek, isi = tarif khusus satu tim
    if ($projectId === '' || !isset(WORKER_GRADES[$grade])) json_error('Proyek dan status wajib diisi.', 422);
    if (($b['action'] ?? '') === 'hapus') { // kembalikan tim ke tarif standar proyek
        if ($tim === '') json_error('Tarif standar proyek tidak bisa dihapus, hanya diubah.', 422);
        $del = db()->prepare('DELETE FROM labor_rates WHERE project_id = ? AND tim = ? AND grade = ?');
        $del->execute([$projectId, $tim, $grade]);
        audit('delete', 'labor_rates', "$projectId/$tim/$grade", 'tarif khusus tim dihapus, ikut tarif standar');
        json_response(['ok' => true, 'dihapus' => $del->rowCount()]);
    }
    $harian = money_in($b['tarif_harian'] ?? null, 'Tarif harian');
    $lembur = (isset($b['tarif_lembur']) && $b['tarif_lembur'] !== '' && $b['tarif_lembur'] !== null)
        ? money_in($b['tarif_lembur'], 'Tarif lembur') : default_lembur_rate($harian);
    $old = db()->prepare('SELECT tarif_harian, tarif_lembur FROM labor_rates WHERE project_id=? AND tim=? AND grade=?');
    $old->execute([$projectId, $tim, $grade]);
    $o = $old->fetch();
    db()->prepare('INSERT INTO labor_rates (project_id, tim, grade, tarif_harian, tarif_lembur, updated_by) VALUES (?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE tarif_harian=VALUES(tarif_harian), tarif_lembur=VALUES(tarif_lembur), updated_by=VALUES(updated_by)')
        ->execute([$projectId, $tim, $grade, $harian, $lembur, $user['id']]);

    fill_zero_snapshots($projectId, $grade, null);
    $applied = 0;
    if (!empty($b['apply_unpaid'])) {
        // Only workers on the status rate (no personal override), only periods
        // that are still draft/diajukan — paid periods stay exactly as paid.
        // A tim's own rate touches only that tim; the standard rate touches every tim that has no rate of its own.
        $scopeSql = $tim !== ''
            ? 'AND a.tim = ?'
            : "AND NOT EXISTS (SELECT 1 FROM labor_rates lr WHERE lr.project_id = a.project_id AND lr.grade = a.grade AND lr.tim = a.tim AND lr.tim <> '')";
        $st = db()->prepare(
            "UPDATE attendance a
               JOIN workers w ON w.id = a.worker_id
               JOIN payroll_periods p ON p.project_id = a.project_id AND a.tgl BETWEEN p.tgl_mulai AND p.tgl_akhir
                SET a.tarif_harian = ?, a.tarif_lembur = ?
              WHERE a.project_id = ? AND a.grade = ? AND p.status <> 'dibayar'
                AND w.tarif_harian IS NULL AND w.tarif_lembur IS NULL $scopeSql");
        $st->execute($tim !== '' ? [$harian, $lembur, $projectId, $grade, $tim] : [$harian, $lembur, $projectId, $grade]);
        $applied = $st->rowCount();
    }
    audit('update', 'labor_rates', "$projectId/" . ($tim !== '' ? "$tim/" : '') . $grade,
        sprintf('%s/%s -> %s/%s%s', $o['tarif_harian'] ?? '-', $o['tarif_lembur'] ?? '-', $harian, $lembur, $applied ? " (diterapkan ke $applied absen belum dibayar)" : ''));
    json_response(['ok' => true, 'tarif_lembur' => $lembur, 'diterapkan' => $applied]);
}
json_error('Method not allowed', 405);
