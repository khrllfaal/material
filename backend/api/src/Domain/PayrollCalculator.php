<?php
declare(strict_types=1);
namespace App\Domain;

/**
 * Hitungan gaji MURNI: tidak membaca database, tidak tahu HTTP. Menerima data mentah (absen, periode, penyesuaian,
 * pembayaran) lalu mengembalikan baris per pekerja + total per periode. Satu-satunya tempat angka gaji dihitung,
 * sehingga layar rekap, pembayaran, dan ekspor selalu sama.
 *
 *   Gaji     = jumlah(hari x tarif_harian)        Lembur = jumlah(jam dibayar x tarif_lembur)
 *   Diterima = Gaji + Lembur + tambahan - potongan (per pekerja)
 *   Total    = jumlah(Diterima) + tambahan grup - potongan grup
 * Tarif yang dipakai adalah tarif yang tersimpan (snapshot) pada setiap baris absen.
 */
final class PayrollCalculator {
    /** @param array<string,string> $gradeNames kode grade => nama; urutan array menentukan urutan tampil */
    public function __construct(private array $gradeNames, private float $istirahatJam = 0.0) {}

    public function lemburDibayar(float $jam): float { return $jam > 0 ? max(0.0, $jam - $this->istirahatJam) : 0.0; }

    /** Pekerja yang sama (induk_id) tercatat >1 hari pada satu tanggal, dihitung dari absen yang ada. @return array<string,true> "orang|tgl" */
    public static function gandaDariAbsen(array $att): array {
        $sum = [];
        foreach ($att as $a) { $k = ($a['induk_id'] ?: $a['worker_id']) . '|' . $a['tgl']; $sum[$k] = ($sum[$k] ?? 0.0) + (float)$a['hari']; }
        $out = [];
        foreach ($sum as $k => $tot) if ($tot > 1.0001) $out[$k] = true;
        return $out;
    }

    /** Daftar orang (induk) yang muncul di absen, untuk mencari kelebihan hari lintas proyek. */
    public static function orangDariAbsen(array $att): array {
        $o = [];
        foreach ($att as $a) $o[$a['induk_id'] ?: $a['worker_id']] = true;
        return array_keys($o);
    }

    /**
     * @param array $in att[] (baris absen + data pekerja/proyek, urut tanggal), ganda (set "orang|tgl"), roster[] (pekerja aktif non-borongan: id, project_id, since),
     *                  periods[] (periode + adjustments[] + payments[] + project_nama), workers_by_id (pekerja yang hanya punya penyesuaian), borongan[] (tim aktif)
     */
    public function hitung(array $in): array {
        $att = $in['att']; $periods = [];
        foreach ($in['periods'] as $p) $periods[$p['id']] = $p;

        $rows = []; $pstat = []; $dates = [];
        foreach ($att as $a) {
            $k = $a['worker_id'] . '|' . $a['project_id'];
            if (!isset($rows[$k])) $rows[$k] = $this->barisBaru($a['worker_id'], $a['nama'], $a['no_rek'], $a['grade'], (int)$a['borongan'], $a['tim'],
                $a['project_id'], $a['project_nama'], $a['induk_id'], (float)$a['tarif_harian'], (float)$a['tarif_lembur']);
            $r = &$rows[$k];
            $h = (float)$a['hari']; $l = (float)$a['lembur_jam'];
            $lp = $this->lemburDibayar($l);
            $r['hari'] += $h; $r['lembur_jam'] += $l; $r['lembur_jam_bayar'] += $lp;
            $r['gaji'] += $h * (float)$a['tarif_harian'];
            $r['lembur_rp'] += $lp * (float)$a['tarif_lembur'];
            $r['days'][$a['tgl']] = [$h, $l];
            $r['tarif_harian'] = (float)$a['tarif_harian']; $r['tarif_lembur'] = (float)$a['tarif_lembur'];
            if ($h > 0 && !(int)$a['borongan'] && (float)$a['tarif_harian'] <= 0 && !in_array('tarif_kosong', $r['flags'], true)) $r['flags'][] = 'tarif_kosong';
            if ($l > 8 && !in_array('lembur_tinggi', $r['flags'], true)) $r['flags'][] = 'lembur_tinggi';
            unset($r);
            $dates[$a['project_id'] . '|' . $a['tgl']] = true;
            $pk = $a['project_id'] . '|' . $a['tgl']; // per hari; dijumlah per periode di bawah (periode bisa separuh minggu)
            if (!isset($pstat[$pk])) $pstat[$pk] = ['hari' => 0.0, 'gaji' => 0.0, 'lembur_rp' => 0.0, 'workers' => []];
            $pstat[$pk]['hari'] += $h; $pstat[$pk]['gaji'] += $h * (float)$a['tarif_harian']; $pstat[$pk]['lembur_rp'] += $lp * (float)$a['tarif_lembur'];
            if ($h > 0) $pstat[$pk]['workers'][$a['worker_id']] = 1;
        }

        if ($rows && $in['ganda']) foreach ($rows as &$r) {
            foreach ($r['days'] as $d => $_) if (isset($in['ganda'][$r['orang'] . '|' . $d])) { if (!in_array('ganda', $r['flags'], true)) $r['flags'][] = 'ganda'; break; }
        }
        unset($r);

        // Pekerja yang kosong pada tanggal yang diisi untuk proyeknya.
        $byProj = []; $since = [];
        foreach ($in['roster'] as $w) { $byProj[$w['project_id']][] = $w['id']; $since[$w['id']] = $w['since']; }
        $kosong = [];
        foreach (array_keys($dates) as $pd) {
            [$pid, $tgl] = explode('|', $pd, 2);
            foreach ($byProj[$pid] ?? [] as $wid) {
                if ($tgl < $since[$wid]) continue; // belum terdaftar pada hari itu
                if (!isset($rows[$wid . '|' . $pid]['days'][$tgl])) $kosong[$wid . '|' . $pid] = ($kosong[$wid . '|' . $pid] ?? 0) + 1;
            }
        }

        // Pekerja yang hanya punya penyesuaian (tanpa absen) tetap mendapat baris.
        foreach ($periods as $p) foreach ($p['adjustments'] as $x) {
            if ($x['worker_id'] && !isset($rows[$x['worker_id'] . '|' . $p['project_id']])) {
                $wr = $in['workers_by_id'][$x['worker_id']] ?? null;
                if ($wr) $rows[$x['worker_id'] . '|' . $p['project_id']] = $this->barisBaru($x['worker_id'], $wr['nama'], $wr['no_rek'], $wr['grade'], (int)$wr['borongan'],
                    $wr['tim'], $p['project_id'], $p['project_nama'], $wr['induk_id'], 0.0, 0.0);
            }
        }

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
            if ($r['net'] < -0.005) $r['flags'][] = 'net_minus';
            if ($r['borongan'] && $r['hari'] > 0 && $r['tambah'] <= 0.005) $r['flags'][] = 'borongan_kosong';
            ksort($r['days']);
        }
        unset($r);
        $rows = array_values($rows);
        $gk = array_keys($this->gradeNames);
        usort($rows, fn($a, $b) => [$a['project_nama'], $a['tim'], array_search($a['grade'], $gk), $a['nama']]
                               <=> [$b['project_nama'], $b['tim'], array_search($b['grade'], $gk), $b['nama']]);

        foreach ($periods as &$p) {
            $tg = 0.0; $pg = 0.0; $gp = 0.0;
            foreach ($p['adjustments'] as $x) if (!$x['worker_id']) { if ($x['jenis'] === 'tambah') $tg += $x['jumlah']; else $pg += $x['jumlah']; }
            foreach ($p['payments'] as $x) if (!$x['worker_id']) $gp += $x['jumlah'];
            $p['grup_tambah'] = $tg; $p['grup_potong'] = $pg; $p['grup_net'] = $tg - $pg; $p['grup_dibayar'] = $gp;
            $st = ['hari' => 0.0, 'gaji' => 0.0, 'lembur_rp' => 0.0, 'workers' => []];
            for ($dd = $p['tgl_mulai']; $dd <= $p['tgl_akhir']; $dd = date('Y-m-d', strtotime($dd . ' +1 day'))) {
                $x = $pstat[$p['project_id'] . '|' . $dd] ?? null;
                if (!$x) continue;
                $st['hari'] += $x['hari']; $st['gaji'] += $x['gaji']; $st['lembur_rp'] += $x['lembur_rp'];
                $st['workers'] += $x['workers'];
            }
            $at = 0.0; $ap = 0.0; $paid = 0.0;
            foreach ($p['adjustments'] as $x) { if ($x['jenis'] === 'tambah') $at += $x['jumlah']; else $ap += $x['jumlah']; }
            foreach ($p['payments'] as $x) $paid += $x['jumlah'];
            $p['hari'] = $st['hari']; $p['pekerja'] = count($st['workers']); $p['gaji'] = $st['gaji']; $p['lembur_rp'] = $st['lembur_rp'];
            $p['adj_tambah'] = $at; $p['adj_potong'] = $ap;
            $p['total_bayar'] = $st['gaji'] + $st['lembur_rp'] + $at - $ap;
            $p['dibayar'] = $paid; $p['sisa'] = $p['total_bayar'] - $paid;
        }
        unset($p);

        $plist = array_values($periods);
        return ['rows' => $rows, 'periods' => $plist, 'totals' => self::total($rows, $plist), 'borongan' => $in['borongan']];
    }

    public static function total(array $rows, array $periods): array {
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

    private function barisBaru(string $wid, string $nama, ?string $noRek, string $grade, int $borongan, string $tim, string $pid, string $pnama, ?string $induk, float $th, float $tl): array {
        return [
            'worker_id' => $wid, 'nama' => $nama, 'no_rek' => $noRek, 'grade' => $grade,
            'grade_nama' => $borongan ? 'Tim borongan' : ($this->gradeNames[$grade] ?? $grade), 'tim' => $tim,
            'project_id' => $pid, 'project_nama' => $pnama, 'diverifikasi' => 1,
            'hari' => 0.0, 'lembur_jam' => 0.0, 'lembur_jam_bayar' => 0.0, 'gaji' => 0.0, 'lembur_rp' => 0.0,
            'tarif_harian' => $th, 'tarif_lembur' => $tl,
            'days' => [], 'flags' => [], 'tambah' => 0.0, 'potong' => 0.0,
            'borongan' => $borongan, 'orang' => $induk ?: $wid,
        ];
    }
}
