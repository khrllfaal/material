<?php
declare(strict_types=1);
require_once __DIR__ . '/payroll_lib.php';

/**
 * Siklus periode gaji mingguan per proyek: draft -> diajukan -> dibayar.
 *   GET  ?project_id=&tgl=      status minggu itu (tanpa nominal, aman untuk lapangan)
 *   POST action=header          isi penanggung jawab / nama grup / catatan
 *   POST action=ajukan          lapangan/admin/akuntan mengajukan minggu ke pusat
 *   POST action=ajukan          lapangan (atau akuntan atas nama lapangan); catatan keterangan opsional
 *   POST action=setujui         Akuntan memeriksa & menyetujui (syarat sebelum dibayar)
 *   POST action=kembalikan      Akuntan mengembalikan ke lapangan dengan alasan (belum ada pembayaran)
 *   POST action=bayar           Akuntan mencatat pembayaran — hanya periode yang sudah disetujui
 * Admin Pusat tidak menyetujui/membayar: perannya mengoreksi absen, tarif, pekerja, dan menghapus data.
 */
send_cors_headers();
$method = $_SERVER['REQUEST_METHOD'];
$user = $method === 'GET' ? require_login() : require_role(['admin', 'akuntan', 'lapangan']); // each action below checks its own role
$role = $user['role'];

if ($method === 'GET') {
    $projectId = (string)($_GET['project_id'] ?? '');
    if ($projectId === '' && in_array($role, ['admin', 'akuntan', 'owner'], true)) {
        // Pusat view: which weeks are waiting (e.g. status=diajukan -> ready to be paid). No money here.
        $status = (string)($_GET['status'] ?? '');
        $st = db()->prepare('SELECT p.id, p.project_id, pr.nama AS project_nama, p.tgl_mulai, p.tgl_akhir, p.status, p.pj_nama, p.grup_nama,
                                    (SELECT COUNT(*) FROM attendance a WHERE a.project_id = p.project_id AND a.tgl BETWEEN p.tgl_mulai AND p.tgl_akhir) AS n_absen
                               FROM payroll_periods p JOIN projects pr ON pr.id = p.project_id'
                           . ($status !== '' ? ' WHERE p.status = ?' : '') . ' ORDER BY p.tgl_mulai DESC, pr.nama LIMIT 200');
        $st->execute($status !== '' ? [$status] : []);
        json_response($st->fetchAll());
    }
    if ($projectId === '') json_error('Proyek wajib dipilih.', 422);
    require_project_access($user, $projectId);
    if (($_GET['action'] ?? '') === 'kasbon_plan') { // preview of the automatic kasbon deduction (money: pusat roles only)
        if (!in_array($role, ['admin', 'akuntan', 'owner'], true)) json_error('Anda tidak punya akses untuk aksi ini.', 403);
        $per = payroll_period($projectId, !empty($_GET['tgl']) ? require_valid_date($_GET['tgl']) : date('Y-m-d'));
        json_response(kasbon_auto_plan($projectId, $per));
    }
    $tgl = !empty($_GET['tgl']) ? require_valid_date($_GET['tgl']) : date('Y-m-d');
    [$s, $e] = period_bounds($projectId, $tgl);
    $st = db()->prepare('SELECT id, project_id, tgl_mulai, tgl_akhir, status, pj_nama, grup_nama, catatan FROM payroll_periods WHERE project_id = ? AND tgl_mulai = ?');
    $st->execute([$projectId, $s]);
    $p = $st->fetch() ?: ['id' => null, 'project_id' => $projectId, 'tgl_mulai' => $s, 'tgl_akhir' => $e, 'status' => 'draft', 'pj_nama' => '', 'grup_nama' => '', 'catatan' => ''];
    if ($p['pj_nama'] === '' && $p['grup_nama'] === '') { // remember the last values used on this project
        $l = db()->prepare("SELECT pj_nama, grup_nama FROM payroll_periods WHERE project_id = ? AND (pj_nama <> '' OR grup_nama <> '') ORDER BY tgl_mulai DESC LIMIT 1");
        $l->execute([$projectId]);
        if ($x = $l->fetch()) { $p['pj_nama'] = $x['pj_nama']; $p['grup_nama'] = $x['grup_nama']; }
    }
    json_response($p);
}

$b = read_json_body();
$action = (string)($b['action'] ?? '');
$projectId = (string)($b['project_id'] ?? '');
if ($projectId === '') json_error('Proyek wajib dipilih.', 422);
require_project_access($user, $projectId);
$tgl = require_valid_date($b['tgl'] ?? date('Y-m-d'), 'Tanggal');
payroll_period($projectId, $tgl); // ensure the row exists
$clip = fn($v, $n) => mb_substr(trim((string)$v), 0, $n);
$db = db();

/** Locks the period row for the rest of the request so two people pressing
 *  approve / pay / return at the same moment run one after the other, and the
 *  status checked is the real, current one. */
function lock_period(string $projectId, string $tgl): array {
    $st = db()->prepare('SELECT * FROM payroll_periods WHERE project_id = ? AND tgl_mulai = ? FOR UPDATE');
    $st->execute([$projectId, period_bounds($projectId, $tgl)[0]]);
    return $st->fetch();
}

if ($action === 'header') {
    $db->beginTransaction();
    $p = lock_period($projectId, $tgl);
    if ($p['status'] === 'dibayar') json_error('Periode sudah dibayar dan terkunci.', 409);
    if ($role === 'lapangan' && $p['status'] !== 'draft') json_error('Minggu ini sudah diajukan.', 409);
    $db->prepare('UPDATE payroll_periods SET pj_nama=?, grup_nama=?, catatan=? WHERE id=?')
       ->execute([$clip($b['pj_nama'] ?? '', 120), $clip($b['grup_nama'] ?? '', 120), $clip($b['catatan'] ?? '', 255), $p['id']]);
    $db->commit();
    audit('update', 'payroll_periods', $p['id'], 'header');
    json_response(['ok' => true]);
}

// Lapangan submits; Akuntan may submit on the field's behalf. Admin Pusat edits/deletes only.
if ($action === 'ajukan') {
    if (!in_array($role, ['lapangan', 'akuntan'], true)) json_error('Anda tidak punya akses untuk aksi ini.', 403);
    $db->beginTransaction();
    $p = lock_period($projectId, $tgl);
    if ($p['status'] !== 'draft') json_error('Minggu ini sudah diajukan.', 409);
    $c = $db->prepare('SELECT COUNT(*) FROM attendance WHERE project_id = ? AND tgl BETWEEN ? AND ?');
    $c->execute([$projectId, $p['tgl_mulai'], $p['tgl_akhir']]);
    if ((int)$c->fetchColumn() === 0) json_error('Belum ada absen pada minggu ini.', 422);
    // Submitted before the week is over (e.g. the project stopped on day 4): the period ends on the
    // last working day. If work resumes later that week, those days form their own follow-up period.
    $mx = $db->prepare('SELECT MAX(tgl) FROM attendance WHERE project_id = ? AND tgl BETWEEN ? AND ?');
    $mx->execute([$projectId, $p['tgl_mulai'], $p['tgl_akhir']]);
    $lastDay = (string)$mx->fetchColumn();
    if ($lastDay !== '' && $lastDay < $p['tgl_akhir'] && date('Y-m-d') < $p['tgl_akhir']) {
        $db->prepare('UPDATE payroll_periods SET tgl_akhir = ? WHERE id = ?')->execute([$lastDay, $p['id']]);
        $p['tgl_akhir'] = $lastDay;
    }
    $pj = $clip($b['pj_nama'] ?? $p['pj_nama'], 120);
    if ($pj === '') json_error('Nama penanggung jawab wajib diisi.', 422);
    $catatan = array_key_exists('catatan', $b) ? $clip($b['catatan'], 255) : '';
    $db->prepare("UPDATE payroll_periods SET status='diajukan', pj_nama=?, grup_nama=?, catatan=?, diajukan_at=NOW(), diajukan_by=? WHERE id=?")
       ->execute([$pj, $clip($b['grup_nama'] ?? $p['grup_nama'], 120), $catatan, $user['id'], $p['id']]);
    $db->commit();
    audit('update', 'payroll_periods', $p['id'], 'diajukan' . ($catatan !== '' ? ': ' . $catatan : ''));
    json_response(['ok' => true, 'status' => 'diajukan']);
}

// Akuntan checks and approves.
if ($action === 'setujui') {
    if ($role !== 'akuntan') json_error('Hanya Akuntan yang memeriksa dan menyetujui absen untuk dibayar.', 403);
    $db->beginTransaction();
    $p = lock_period($projectId, $tgl);
    if ($p['status'] !== 'diajukan') json_error($p['status'] === 'draft' ? 'Periode belum diajukan oleh lapangan.' : 'Periode ini sudah disetujui.', 409);
    $zero = $db->prepare("SELECT w.nama FROM attendance a JOIN workers w ON w.id = a.worker_id
                            WHERE a.project_id = ? AND a.tgl BETWEEN ? AND ? AND a.hari > 0 AND a.tarif_harian <= 0 GROUP BY w.id, w.nama LIMIT 5");
    $zero->execute([$projectId, $p['tgl_mulai'], $p['tgl_akhir']]);
    $zn = array_column($zero->fetchAll(), 'nama');
    if ($zn) json_error('Tarif belum diisi untuk: ' . implode(', ', $zn) . '. Isi dulu di Pekerja & Tarif.', 409);
    $neg = [];
    foreach (payroll_compute([$projectId], $p['tgl_mulai'], $p['tgl_akhir'])['rows'] as $rw) {
        $adjAuto = $db->prepare("SELECT COALESCE(SUM(jumlah),0) FROM payroll_adjustments WHERE period_id = ? AND worker_id = ? AND kategori = 'kasbon' AND label LIKE '%(otomatis)'");
        $adjAuto->execute([$p['id'], $rw['worker_id']]);
        if ($rw['net'] + (float)$adjAuto->fetchColumn() < -0.005) $neg[] = $rw['nama'];
    }
    if ($neg) json_error('Gaji minus (potongan lebih besar dari gaji) untuk: ' . implode(', ', array_slice($neg, 0, 5)) . '. Perbaiki potongan dulu.', 409);
    $unvRows = []; // verifikasi pekerja baru dinonaktifkan (disederhanakan)
    // Automatic kasbon deduction: recomputed from the real saldo at the moment of approval (earlier automatic lines are replaced).
    $db->prepare("DELETE FROM payroll_adjustments WHERE period_id = ? AND kategori = 'kasbon' AND label LIKE '%(otomatis)'")->execute([$p['id']]);
    $plan = kasbon_auto_plan($projectId, $p);
    $insAdj = $db->prepare("INSERT INTO payroll_adjustments (id, period_id, worker_id, tim, jenis, kategori, label, jumlah, created_by) VALUES (?,?,?,?,'potong','kasbon','Potong kasbon (otomatis)',?,?)");
    $nAuto = 0;
    foreach ($plan['items'] as $it) if ($it['potong'] > 0.005) { $insAdj->execute([new_id('adj_'), $p['id'], $it['worker_id'], $it['tim'], $it['potong'], $user['id']]); $nAuto++; }
    $db->prepare("UPDATE payroll_periods SET status='disetujui', disetujui_at=NOW(), disetujui_by=? WHERE id=?")->execute([$user['id'], $p['id']]);
    $db->commit();
    if ($nAuto) audit('create', 'payroll_adjustments', $p['id'], "potong kasbon otomatis: $nAuto orang, total {$plan['total']}");
    if ($unvRows) audit('update', 'workers', 'verifikasi', count($unvRows) . ' pekerja diverifikasi saat persetujuan ' . $p['id']);
    audit('update', 'payroll_periods', $p['id'], 'disetujui' . ($unvRows ? ' (+' . count($unvRows) . ' pekerja diverifikasi)' : ''));
    json_response(['ok' => true, 'status' => 'disetujui', 'potong_kasbon' => $plan['total'], 'orang' => $nAuto]);
}

if ($action === 'kembalikan' || $action === 'buka') {
    if ($role !== 'akuntan') json_error('Hanya Akuntan yang bisa mengembalikan pengajuan ke lapangan.', 403);
    $alasan = $clip($b['alasan'] ?? '', 200);
    if ($alasan === '') json_error('Alasan wajib diisi.', 422);
    $db->beginTransaction();
    $p = lock_period($projectId, $tgl);
    if (!in_array($p['status'], ['diajukan', 'disetujui'], true)) json_error('Hanya pengajuan yang belum dibayar yang bisa dikembalikan.', 409);
    $c = $db->prepare('SELECT COUNT(*) FROM payroll_payments WHERE period_id = ?'); $c->execute([$p['id']]);
    if ((int)$c->fetchColumn() > 0) json_error('Sudah ada pembayaran pada periode ini, tidak bisa dikembalikan. Buat penyesuaian di periode berikutnya.', 409);
    $db->prepare("DELETE FROM payroll_adjustments WHERE period_id = ? AND kategori = 'kasbon' AND label LIKE '%(otomatis)'")->execute([$p['id']]);
    $db->prepare("UPDATE payroll_periods SET status='draft', diajukan_at=NULL, diajukan_by=NULL, disetujui_at=NULL, disetujui_by=NULL, catatan=? WHERE id=?")
       ->execute([$clip('Dikembalikan: ' . $alasan, 255), $p['id']]);
    $db->commit();
    audit('update', 'payroll_periods', $p['id'], "dikembalikan ke lapangan: $alasan");
    json_response(['ok' => true, 'status' => 'draft']);
}

if ($action === 'bayar') {
    if ($role !== 'akuntan') json_error('Hanya Akuntan yang bisa mencatat pembayaran.', 403);
    $tglBayar = require_valid_date($b['tgl_bayar'] ?? date('Y-m-d'), 'Tanggal bayar');
    $metode = (($b['metode'] ?? '') === 'transfer') ? 'transfer' : 'tunai';
    $db->beginTransaction();
    $p = lock_period($projectId, $tgl);
    $pid = $p['id'];
    if ($p['status'] === 'draft') json_error('Periode belum diajukan oleh lapangan.', 409);
    if ($p['status'] === 'diajukan') json_error('Periode belum diperiksa dan disetujui. Setujui dulu.', 409);
    $res = payroll_compute([$projectId], $p['tgl_mulai'], $p['tgl_akhir']);
    $thisP = null;
    foreach ($res['periods'] as $x) if ($x['id'] === $pid) $thisP = $x;
    $sisaW = []; foreach ($res['rows'] as $r) $sisaW[$r['worker_id']] = $r['sisa'];
    $groupOut = $thisP ? ($thisP['grup_net'] - $thisP['grup_dibayar']) : 0.0;

    $items = [];
    if (!empty($b['semua'])) {
        foreach ($sisaW as $wid => $s) if ($s > 0.004) $items[] = ['worker_id' => $wid, 'jumlah' => round($s, 2)];
        if (abs($groupOut) > 0.004) $items[] = ['worker_id' => null, 'jumlah' => round($groupOut, 2)];
    } else {
        foreach ((array)($b['items'] ?? []) as $it) $items[] = ['worker_id' => !empty($it['worker_id']) ? (string)$it['worker_id'] : null, 'jumlah' => round((float)($it['jumlah'] ?? 0), 2)];
    }
    if (!$items) json_error('Tidak ada pembayaran yang perlu dicatat.', 422);

    $ins = $db->prepare('INSERT INTO payroll_payments (id, period_id, worker_id, jumlah, tgl_bayar, metode, ket, created_by) VALUES (?,?,?,?,?,?,?,?)');
    $total = 0.0; $seen = [];
    foreach ($items as $it) {
        $key = $it['worker_id'] ?? '_grup';
        if (isset($seen[$key])) json_error('Satu pekerja hanya boleh muncul sekali dalam satu pembayaran.', 422);
        $seen[$key] = 1;
        if ($it['worker_id'] === null) {
            // group line: must not overshoot what is owed (a negative amount is a retained deduction)
            if (abs($it['jumlah']) > abs($groupOut) + 0.005) json_error('Jumlah penyesuaian grup melebihi yang harus dibayar.', 422);
        } else {
            if (!isset($sisaW[$it['worker_id']])) json_error('Pekerja tidak termasuk dalam periode ini.', 422);
            if ($it['jumlah'] <= 0 || $it['jumlah'] > $sisaW[$it['worker_id']] + 0.005) json_error('Jumlah bayar melebihi sisa gaji pekerja.', 422);
        }
        $ins->execute([new_id('pay_'), $pid, $it['worker_id'], $it['jumlah'], $tglBayar, $metode, $clip($b['ket'] ?? '', 255), $user['id']]);
        $total += $it['jumlah'];
    }
    // closed once nothing is outstanding
    $after = payroll_compute([$projectId], $p['tgl_mulai'], $p['tgl_akhir']);
    $out = $after['totals']['sisa'];
    $neg = 0.0;
    foreach ($after['periods'] as $x) if ($x['id'] === $pid) $neg = $x['grup_net'] - $x['grup_dibayar'];
    $open = $out > 0.005 || $neg < -0.005;
    if (!$open) $db->prepare("UPDATE payroll_periods SET status='dibayar', dibayar_at=NOW(), dibayar_by=? WHERE id=?")->execute([$user['id'], $pid]);
    $db->commit();
    audit('update', 'payroll_payments', $pid, sprintf('%d baris, total %s, %s%s', count($items), $total, $metode, $open ? '' : ' — LUNAS'));
    json_response(['ok' => true, 'dibayar' => $total, 'lunas' => !$open, 'status' => $open ? 'disetujui' : 'dibayar']);
}
json_error('Aksi tidak dikenal.', 422);
