<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
ensure_payroll_controls();

/**
 * One calculation used by Rekap Gaji, the payment endpoint and the exports,
 * so the number on screen, the number paid and the number downloaded can
 * never differ. All money is computed here from the *snapshot* rates stored
 * on each attendance row.
 *
 * Gaji        = sum(hari x tarif_harian)
 * Lembur      = sum(jam x tarif_lembur)
 * Diterima    = Gaji + Lembur + tambahan pekerja - potongan pekerja
 * Total bayar = sum(Diterima) + tambahan grup - potongan grup
 */
function payroll_compute(?array $projectIds, string $from, string $to): array {
    $db = db();
    $pc = ''; $pp = [];
    if ($projectIds !== null) {
        if (!$projectIds) return ['rows' => [], 'periods' => [], 'totals' => payroll_totals([], [])];
        $pc = ' AND a.project_id IN (' . implode(',', array_fill(0, count($projectIds), '?')) . ')';
        $pp = $projectIds;
    }
    $st = $db->prepare(
        "SELECT a.worker_id, a.project_id, a.tgl, a.hari, a.lembur_jam, a.grade, a.tim, a.tarif_harian, a.tarif_lembur,
                w.nama, w.no_rek, w.aktif, w.diverifikasi, p.nama AS project_nama
           FROM attendance a JOIN workers w ON w.id = a.worker_id JOIN projects p ON p.id = a.project_id
          WHERE a.tgl BETWEEN ? AND ? $pc ORDER BY a.tgl");
    $st->execute(array_merge([$from, $to], $pp));
    $att = $st->fetchAll();

    $rows = [];       // key worker|project
    $pstat = [];      // project|week-start => attendance totals of that period
    $dates = [];      // project|tgl => filled
    foreach ($att as $a) {
        $k = $a['worker_id'] . '|' . $a['project_id'];
        if (!isset($rows[$k])) {
            $rows[$k] = [
                'worker_id' => $a['worker_id'], 'nama' => $a['nama'], 'no_rek' => $a['no_rek'],
                'grade' => $a['grade'], 'grade_nama' => WORKER_GRADES[$a['grade']] ?? $a['grade'], 'tim' => $a['tim'],
                'project_id' => $a['project_id'], 'project_nama' => $a['project_nama'], 'diverifikasi' => 1,
                'hari' => 0.0, 'lembur_jam' => 0.0, 'lembur_jam_bayar' => 0.0, 'gaji' => 0.0, 'lembur_rp' => 0.0,
                'tarif_harian' => (float)$a['tarif_harian'], 'tarif_lembur' => (float)$a['tarif_lembur'],
                'days' => [], 'flags' => [], 'tambah' => 0.0, 'potong' => 0.0,
            ];
        }
        $r = &$rows[$k];
        $h = (float)$a['hari']; $l = (float)$a['lembur_jam'];
        $lp = lembur_paid_hours($l);
        $r['hari'] += $h; $r['lembur_jam'] += $l; $r['lembur_jam_bayar'] += $lp;
        $r['gaji'] += $h * (float)$a['tarif_harian'];
        $r['lembur_rp'] += $lp * (float)$a['tarif_lembur'];
        $r['days'][$a['tgl']] = [$h, $l];
        $r['tarif_harian'] = (float)$a['tarif_harian']; $r['tarif_lembur'] = (float)$a['tarif_lembur'];
        if ($h > 0 && (float)$a['tarif_harian'] <= 0 && !in_array('tarif_kosong', $r['flags'], true)) $r['flags'][] = 'tarif_kosong';
        if ($h > 0 && false && !in_array('belum_verifikasi', $r['flags'], true)) $r['flags'][] = 'belum_verifikasi';
        if ($l > 8 && !in_array('lembur_tinggi', $r['flags'], true)) $r['flags'][] = 'lembur_tinggi';
        unset($r);
        $dates[$a['project_id'] . '|' . $a['tgl']] = true;
        $ws = payroll_week($a['tgl'])[0];
        $pk = $a['project_id'] . '|' . $ws;
        if (!isset($pstat[$pk])) $pstat[$pk] = ['hari' => 0.0, 'gaji' => 0.0, 'lembur_rp' => 0.0, 'workers' => []];
        $pstat[$pk]['hari'] += $h; $pstat[$pk]['gaji'] += $h * (float)$a['tarif_harian']; $pstat[$pk]['lembur_rp'] += $lp * (float)$a['tarif_lembur'];
        if ($h > 0) $pstat[$pk]['workers'][$a['worker_id']] = 1;
    }

    // Same person counted more than 1 day on one date across projects.
    if ($rows) {
        $wids = array_values(array_unique(array_column($rows, 'worker_id')));
        $in = implode(',', array_fill(0, count($wids), '?'));
        $g = $db->prepare("SELECT worker_id, tgl, SUM(hari) h FROM attendance WHERE tgl BETWEEN ? AND ? AND worker_id IN ($in) GROUP BY worker_id, tgl HAVING h > 1");
        $g->execute(array_merge([$from, $to], $wids));
        foreach ($g->fetchAll() as $x) {
            foreach ($rows as $k => &$r) if ($r['worker_id'] === $x['worker_id'] && isset($r['days'][$x['tgl']]) && !in_array('ganda', $r['flags'], true)) $r['flags'][] = 'ganda';
            unset($r);
        }
    }

    // Workers missing on dates that were otherwise filled in for their project.
    $act = $db->query('SELECT id, project_id, DATE(created_at) AS since FROM workers WHERE aktif = 1')->fetchAll();
    $byProj = []; $since = [];
    foreach ($act as $w) { $byProj[$w['project_id']][] = $w['id']; $since[$w['id']] = $w['since']; }
    $kosong = [];
    foreach (array_keys($dates) as $pd) {
        [$pid, $tgl] = explode('|', $pd, 2);
        foreach ($byProj[$pid] ?? [] as $wid) {
            if ($tgl < $since[$wid]) continue; // not on the roster yet that day
            if (!isset($rows[$wid . '|' . $pid]['days'][$tgl])) {
                $kosong[$wid . '|' . $pid] = ($kosong[$wid . '|' . $pid] ?? 0) + 1;
            }
        }
    }

    // Periods overlapping the range (for adjustments, payments, status).
    $pcq = ''; $ppq = [];
    if ($projectIds !== null) { $pcq = ' AND p.project_id IN (' . implode(',', array_fill(0, count($projectIds), '?')) . ')'; $ppq = $projectIds; }
    $ps = $db->prepare("SELECT p.*, pr.nama AS project_nama FROM payroll_periods p JOIN projects pr ON pr.id = p.project_id
                         WHERE p.tgl_mulai <= ? AND p.tgl_akhir >= ? $pcq ORDER BY p.tgl_mulai, pr.nama");
    $ps->execute(array_merge([$to, $from], $ppq));
    $periods = [];
    foreach ($ps->fetchAll() as $p) { $p['adjustments'] = []; $p['payments'] = []; $periods[$p['id']] = $p; }

    if ($periods) {
        $in = implode(',', array_fill(0, count($periods), '?'));
        $ids = array_keys($periods);
        $as = $db->prepare("SELECT x.*, w.nama AS worker_nama FROM payroll_adjustments x LEFT JOIN workers w ON w.id = x.worker_id WHERE x.period_id IN ($in) ORDER BY x.created_at");
        $as->execute($ids);
        foreach ($as->fetchAll() as $x) {
            $x['jumlah'] = (float)$x['jumlah'];
            $x['qty'] = $x['qty'] === null ? null : (float)$x['qty']; $x['harga'] = $x['harga'] === null ? null : (float)$x['harga'];
            $periods[$x['period_id']]['adjustments'][] = $x;
        }
        $pays = $db->prepare("SELECT * FROM payroll_payments WHERE period_id IN ($in) ORDER BY created_at");
        $pays->execute($ids);
        foreach ($pays->fetchAll() as $x) { $x['jumlah'] = (float)$x['jumlah']; $periods[$x['period_id']]['payments'][] = $x; }
    }

    // Workers with an adjustment but no attendance in range still get a row.
    foreach ($periods as $p) foreach ($p['adjustments'] as $x) {
        if ($x['worker_id'] && !isset($rows[$x['worker_id'] . '|' . $p['project_id']])) {
            $w = $db->prepare('SELECT nama, grade, tim, no_rek FROM workers WHERE id = ?'); $w->execute([$x['worker_id']]); $wr = $w->fetch();
            if ($wr) $rows[$x['worker_id'] . '|' . $p['project_id']] = [
                'worker_id' => $x['worker_id'], 'nama' => $wr['nama'], 'no_rek' => $wr['no_rek'], 'grade' => $wr['grade'],
                'grade_nama' => WORKER_GRADES[$wr['grade']] ?? $wr['grade'], 'tim' => $wr['tim'],
                'project_id' => $p['project_id'], 'project_nama' => $p['project_nama'], 'diverifikasi' => 1,
                'hari' => 0.0, 'lembur_jam' => 0.0, 'lembur_jam_bayar' => 0.0, 'gaji' => 0.0, 'lembur_rp' => 0.0, 'tarif_harian' => 0.0, 'tarif_lembur' => 0.0,
                'days' => [], 'flags' => [], 'tambah' => 0.0, 'potong' => 0.0,
            ];
        }
    }

    // Per-worker adjustments and payments.
    $paidBy = [];
    foreach ($periods as $p) {
        foreach ($p['adjustments'] as $x) {
            if (!$x['worker_id']) continue;
            $k = $x['worker_id'] . '|' . $p['project_id'];
            if (!isset($rows[$k])) continue;
            if ($x['jenis'] === 'tambah') $rows[$k]['tambah'] += $x['jumlah']; else $rows[$k]['potong'] += $x['jumlah'];
        }
        foreach ($p['payments'] as $x) if ($x['worker_id']) {
            $k = $x['worker_id'] . '|' . $p['project_id']; $paidBy[$k] = ($paidBy[$k] ?? 0) + $x['jumlah'];
        }
    }
    foreach ($rows as $k => &$r) {
        $r['net'] = $r['gaji'] + $r['lembur_rp'] + $r['tambah'] - $r['potong'];
        $r['dibayar'] = $paidBy[$k] ?? 0.0;
        $r['sisa'] = max(0.0, $r['net'] - $r['dibayar']);
        $r['status_bayar'] = $r['net'] <= 0 ? 'nol' : ($r['dibayar'] >= $r['net'] - 0.005 ? 'lunas' : ($r['dibayar'] > 0 ? 'sebagian' : 'belum'));
        $r['kosong'] = $kosong[$k] ?? 0;
        if ($r['kosong'] > 0) $r['flags'][] = 'belum_diisi';
        if ($r['net'] < -0.005) $r['flags'][] = 'net_minus'; // deductions larger than the pay
        ksort($r['days']);
    }
    unset($r);
    $rows = array_values($rows);
    usort($rows, fn($a, $b) => [$a['project_nama'], $a['tim'], array_search($a['grade'], array_keys(WORKER_GRADES)), $a['nama']]
                           <=> [$b['project_nama'], $b['tim'], array_search($b['grade'], array_keys(WORKER_GRADES)), $b['nama']]);

    // Group-level lines & per-period totals.
    foreach ($periods as &$p) {
        $tg = 0.0; $pg = 0.0; $gp = 0.0;
        foreach ($p['adjustments'] as $x) if (!$x['worker_id']) { if ($x['jenis'] === 'tambah') $tg += $x['jumlah']; else $pg += $x['jumlah']; }
        foreach ($p['payments'] as $x) if (!$x['worker_id']) $gp += $x['jumlah'];
        $p['grup_tambah'] = $tg; $p['grup_potong'] = $pg; $p['grup_net'] = $tg - $pg; $p['grup_dibayar'] = $gp;
        $st2 = $pstat[$p['project_id'] . '|' . $p['tgl_mulai']] ?? ['hari' => 0.0, 'gaji' => 0.0, 'lembur_rp' => 0.0, 'workers' => []];
        $at = 0.0; $ap = 0.0; $paid = 0.0;
        foreach ($p['adjustments'] as $x) { if ($x['jenis'] === 'tambah') $at += $x['jumlah']; else $ap += $x['jumlah']; }
        foreach ($p['payments'] as $x) $paid += $x['jumlah'];
        $p['hari'] = $st2['hari']; $p['pekerja'] = count($st2['workers']); $p['gaji'] = $st2['gaji']; $p['lembur_rp'] = $st2['lembur_rp'];
        $p['adj_tambah'] = $at; $p['adj_potong'] = $ap;
        $p['total_bayar'] = $st2['gaji'] + $st2['lembur_rp'] + $at - $ap;
        $p['dibayar'] = $paid; $p['sisa'] = $p['total_bayar'] - $paid;
    }
    unset($p);

    return ['rows' => $rows, 'periods' => array_values($periods), 'totals' => payroll_totals($rows, array_values($periods))];
}

function payroll_totals(array $rows, array $periods): array {
    $t = ['pekerja' => 0, 'hari' => 0.0, 'lembur_jam' => 0.0, 'lembur_jam_bayar' => 0.0, 'gaji' => 0.0, 'lembur_rp' => 0.0, 'tambah' => 0.0, 'potong' => 0.0,
          'grup_tambah' => 0.0, 'grup_potong' => 0.0, 'total_bayar' => 0.0, 'dibayar' => 0.0, 'sisa' => 0.0, 'perlu_dicek' => 0, 'belum_dibayar' => 0];
    foreach ($rows as $r) {
        if ($r['hari'] > 0 || $r['net'] != 0) $t['pekerja']++;
        $t['hari'] += $r['hari']; $t['lembur_jam'] += $r['lembur_jam']; $t['lembur_jam_bayar'] += $r['lembur_jam_bayar']; $t['gaji'] += $r['gaji']; $t['lembur_rp'] += $r['lembur_rp'];
        $t['tambah'] += $r['tambah']; $t['potong'] += $r['potong'];
        if ($r['flags']) $t['perlu_dicek']++;
        if ($r['status_bayar'] === 'belum' || $r['status_bayar'] === 'sebagian') $t['belum_dibayar']++;
        $t['dibayar'] += $r['dibayar'];
    }
    foreach ($periods as $p) { $t['grup_tambah'] += $p['grup_tambah']; $t['grup_potong'] += $p['grup_potong']; $t['dibayar'] += $p['grup_dibayar']; }
    $workersNet = array_sum(array_column($rows, 'net'));
    $t['total_bayar'] = $workersNet + $t['grup_tambah'] - $t['grup_potong'];
    $t['sisa'] = max(0.0, $t['total_bayar'] - $t['dibayar']);
    return $t;
}

/** "Tim A" stays "Tim A"; a bare name such as "Pondasi" becomes "Tim Pondasi". */
function tim_label(string $t): string { return preg_match('/^tim\b/i', $t) ? $t : 'Tim ' . $t; }

/** Saldo kasbon for (project, worker | tim | grup): given - deducted. A kasbon is "atas nama" one worker, one tim
 *  (worker empty, tim filled) or the whole group (both empty). Repayments (pelunasan) are negative kasbon rows. */
function kasbon_saldo(string $projectId, ?string $workerId, string $tim = ''): float {
    if ($workerId !== null) { $cond = 'worker_id = ?'; $p = [$projectId, $workerId]; $cond2 = 'x.worker_id = ?'; }
    elseif ($tim !== '')    { $cond = 'worker_id IS NULL AND tim = ?'; $p = [$projectId, $tim]; $cond2 = 'x.worker_id IS NULL AND x.tim = ?'; }
    else                    { $cond = "worker_id IS NULL AND tim = ''"; $p = [$projectId]; $cond2 = "x.worker_id IS NULL AND x.tim = ''"; }
    $a = db()->prepare("SELECT COALESCE(SUM(jumlah),0) FROM kasbon WHERE project_id = ? AND $cond");
    $a->execute($p);
    $given = (float)$a->fetchColumn();
    $b = db()->prepare("SELECT COALESCE(SUM(x.jumlah),0) FROM payroll_adjustments x JOIN payroll_periods pp ON pp.id = x.period_id
                         WHERE pp.project_id = ? AND x.kategori = 'kasbon' AND x.jenis = 'potong' AND $cond2");
    $b->execute($p);
    return $given - (float)$b->fetchColumn();
}

/** Saldo kasbon for many at once — two grouped queries. Keys: "projectId|workerId", "projectId|t:TimName" or "projectId|" (grup).
 *  Only non-zero balances are returned. */
function kasbon_saldo_map(?array $projectIds): array {
    $cond = ''; $params = [];
    if ($projectIds !== null) {
        if (!$projectIds) return [];
        $cond = ' WHERE project_id IN (' . implode(',', array_fill(0, count($projectIds), '?')) . ')';
        $params = $projectIds;
    }
    $key = fn($pid, $w, $t) => $pid . '|' . ($w !== '' ? $w : ($t !== '' ? 't:' . $t : ''));
    $map = [];
    $g = db()->prepare("SELECT project_id, COALESCE(worker_id, '') AS w, tim, SUM(jumlah) AS t FROM kasbon$cond GROUP BY project_id, w, tim");
    $g->execute($params);
    foreach ($g->fetchAll() as $r) $map[$key($r['project_id'], $r['w'], $r['w'] === '' ? $r['tim'] : '')] = ($map[$key($r['project_id'], $r['w'], $r['w'] === '' ? $r['tim'] : '')] ?? 0.0) + (float)$r['t'];
    $condP = $projectIds !== null ? ' AND p.project_id IN (' . implode(',', array_fill(0, count($projectIds), '?')) . ')' : '';
    $b = db()->prepare("SELECT p.project_id, COALESCE(x.worker_id, '') AS w, x.tim, SUM(x.jumlah) AS t
                          FROM payroll_adjustments x JOIN payroll_periods p ON p.id = x.period_id
                         WHERE x.kategori = 'kasbon' AND x.jenis = 'potong'$condP GROUP BY p.project_id, w, x.tim");
    $b->execute($params);
    foreach ($b->fetchAll() as $r) { $k = $key($r['project_id'], $r['w'], $r['w'] === '' ? $r['tim'] : ''); $map[$k] = ($map[$k] ?? 0.0) - (float)$r['t']; }
    return array_filter($map, fn($v) => abs($v) > 0.005);
}

/** Automatic kasbon deduction for one period (what the Akuntan sees before approving, and what approval writes).
 *  Rule: kasbon given up to the end of the period is deducted; kasbon given after it waits for the next payment
 *  (a kasbon handed over after the week ended was not part of that week's pay). Each person / tim / the group is deducted
 *  min(saldo still owed, pay of the period before this deduction) — so a zero saldo is never deducted, and what
 *  cannot be taken now carries over to the next period. Lines made earlier by this routine are ignored when
 *  recomputing, so approving twice never deducts twice. A tim's kasbon is taken from the pay of that tim's workers. */
function kasbon_auto_plan(string $projectId, array $p): array {
    $db = db();
    $kf = fn($w, $t) => $w !== '' ? $w : ($t !== '' ? 't:' . $t : '');
    $given = []; $deducted = [];
    $g = $db->prepare("SELECT COALESCE(worker_id, '') AS w, tim, SUM(jumlah) AS t FROM kasbon WHERE project_id = ? AND tgl <= ? GROUP BY w, tim");
    $g->execute([$projectId, $p['tgl_akhir']]);
    foreach ($g->fetchAll() as $r) { $k = $kf($r['w'], $r['w'] === '' ? $r['tim'] : ''); $given[$k] = ($given[$k] ?? 0.0) + (float)$r['t']; }
    if (!$given) return ['items' => [], 'total' => 0.0];
    $d = $db->prepare("SELECT COALESCE(x.worker_id, '') AS w, x.tim, SUM(x.jumlah) AS t FROM payroll_adjustments x JOIN payroll_periods pp ON pp.id = x.period_id
                        WHERE pp.project_id = ? AND x.kategori = 'kasbon' AND x.jenis = 'potong' AND NOT (x.period_id = ? AND x.label LIKE '%(otomatis)')
                        GROUP BY w, x.tim");
    $d->execute([$projectId, $p['id']]);
    foreach ($d->fetchAll() as $r) { $k = $kf($r['w'], $r['w'] === '' ? $r['tim'] : ''); $deducted[$k] = ($deducted[$k] ?? 0.0) + (float)$r['t']; }
    // pay of this period before any automatic kasbon line
    $adj = [];
    $a = $db->prepare("SELECT COALESCE(worker_id, '') AS w, tim, jenis, SUM(jumlah) AS t FROM payroll_adjustments
                        WHERE period_id = ? AND NOT (kategori = 'kasbon' AND label LIKE '%(otomatis)') GROUP BY w, tim, jenis");
    $a->execute([$p['id']]);
    foreach ($a->fetchAll() as $r) { $k = $kf($r['w'], $r['w'] === '' ? $r['tim'] : ''); $adj[$k][$r['jenis']] = ($adj[$k][$r['jenis']] ?? 0.0) + (float)$r['t']; }
    $calc = payroll_compute([$projectId], $p['tgl_mulai'], $p['tgl_akhir']);
    $items = []; $total = 0.0; $leftAll = 0.0; $leftTim = [];
    foreach ($calc['rows'] as $r) {
        $w = $r['worker_id'];
        $cap = $r['gaji'] + $r['lembur_rp'] + ($adj[$w]['tambah'] ?? 0.0) - ($adj[$w]['potong'] ?? 0.0);
        $avail = max(0.0, ($given[$w] ?? 0.0) - ($deducted[$w] ?? 0.0));
        $take = $avail > 0.005 && $cap > 0.005 ? round(min($avail, $cap), 2) : 0.0;
        $rest = max(0.0, $cap - $take);
        $leftAll += $rest; $leftTim[$r['tim']] = ($leftTim[$r['tim']] ?? 0.0) + $rest;
        if ($avail > 0.005) { $items[] = ['worker_id' => $w, 'tim' => '', 'nama' => $r['nama'], 'saldo' => round($avail, 2), 'potong' => $take]; $total += $take; }
    }
    // tims: taken from that tim's workers' remaining pay
    foreach ($given as $k => $v) {
        if (strncmp($k, 't:', 2) !== 0) continue;
        $tim = substr($k, 2);
        $avail = max(0.0, $v - ($deducted[$k] ?? 0.0));
        if ($avail <= 0.005) continue;
        $cap = max(0.0, ($leftTim[$tim] ?? 0.0) + ($adj[$k]['tambah'] ?? 0.0) - ($adj[$k]['potong'] ?? 0.0));
        $take = $cap > 0.005 ? round(min($avail, $cap), 2) : 0.0;
        $leftAll -= $take; $total += $take;
        $items[] = ['worker_id' => null, 'tim' => $tim, 'nama' => tim_label($tim), 'saldo' => round($avail, 2), 'potong' => $take];
    }
    $availG = max(0.0, ($given[''] ?? 0.0) - ($deducted[''] ?? 0.0));
    if ($availG > 0.005) {
        $capG = max(0.0, $leftAll + ($adj['']['tambah'] ?? 0.0) - ($adj['']['potong'] ?? 0.0));
        $takeG = $capG > 0.005 ? round(min($availG, $capG), 2) : 0.0;
        $items[] = ['worker_id' => null, 'tim' => '', 'nama' => 'Grup / penanggung jawab', 'saldo' => round($availG, 2), 'potong' => $takeG]; $total += $takeG;
    }
    return ['items' => $items, 'total' => round($total, 2)];
}
