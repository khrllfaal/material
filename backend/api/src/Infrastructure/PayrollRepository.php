<?php
declare(strict_types=1);
namespace App\Infrastructure;

/** Akses data untuk hitungan gaji: hanya SQL, tanpa aturan hitung. */
final class PayrollRepository {
    public function __construct(private \PDO $db) {}

    private static function in(array $v): string { return implode(',', array_fill(0, count($v), '?')); }

    public function absen(?array $projectIds, string $from, string $to): array {
        $pc = $projectIds !== null ? ' AND a.project_id IN (' . self::in($projectIds) . ')' : '';
        $st = $this->db->prepare(
            "SELECT a.worker_id, a.project_id, a.tgl, a.hari, a.lembur_jam, a.grade, a.tim, a.tarif_harian, a.tarif_lembur,
                    w.nama, w.no_rek, w.aktif, w.diverifikasi, w.borongan, w.induk_id, p.nama AS project_nama
               FROM attendance a JOIN workers w ON w.id = a.worker_id JOIN projects p ON p.id = a.project_id
              WHERE a.tgl BETWEEN ? AND ? $pc ORDER BY a.tgl");
        $st->execute(array_merge([$from, $to], $projectIds ?? []));
        return $st->fetchAll();
    }

    /** Orang yang tercatat >1 hari pada satu tanggal di seluruh proyek (juga bila punya catatan pekerja terpisah per proyek). @return array<string,true> */
    public function gandaLintasProyek(array $orang, string $from, string $to): array {
        $in = self::in($orang);
        $g = $this->db->prepare("SELECT COALESCE(w.induk_id, w.id) AS orang, a.tgl, SUM(a.hari) h FROM attendance a JOIN workers w ON w.id = a.worker_id
                                  WHERE a.tgl BETWEEN ? AND ? AND (COALESCE(w.induk_id, w.id) IN ($in) OR w.id IN ($in))
                                  GROUP BY COALESCE(w.induk_id, w.id), a.tgl HAVING h > 1");
        $g->execute(array_merge([$from, $to], $orang, $orang));
        $out = [];
        foreach ($g->fetchAll() as $x) $out[$x['orang'] . '|' . $x['tgl']] = true;
        return $out;
    }

    /** Pekerja aktif yang wajib diabsen harian (tim borongan tidak). */
    public function rosterAktif(): array {
        return $this->db->query('SELECT id, project_id, DATE(created_at) AS since FROM workers WHERE aktif = 1 AND borongan = 0')->fetchAll();
    }

    /** Periode yang beririsan dengan rentang, lengkap dengan penyesuaian dan pembayarannya. */
    public function periodeBeririsan(?array $projectIds, string $from, string $to): array {
        $pc = $projectIds !== null ? ' AND p.project_id IN (' . self::in($projectIds) . ')' : '';
        $ps = $this->db->prepare("SELECT p.*, pr.nama AS project_nama FROM payroll_periods p JOIN projects pr ON pr.id = p.project_id
                                   WHERE p.tgl_mulai <= ? AND p.tgl_akhir >= ? $pc ORDER BY p.tgl_mulai, pr.nama");
        $ps->execute(array_merge([$to, $from], $projectIds ?? []));
        $periods = [];
        foreach ($ps->fetchAll() as $p) { $p['adjustments'] = []; $p['payments'] = []; $periods[$p['id']] = $p; }
        if (!$periods) return [];
        $ids = array_keys($periods); $in = self::in($ids);
        $as = $this->db->prepare("SELECT x.*, w.nama AS worker_nama FROM payroll_adjustments x LEFT JOIN workers w ON w.id = x.worker_id WHERE x.period_id IN ($in) ORDER BY x.created_at");
        $as->execute($ids);
        foreach ($as->fetchAll() as $x) {
            $x['jumlah'] = (float)$x['jumlah'];
            $x['qty'] = $x['qty'] === null ? null : (float)$x['qty']; $x['harga'] = $x['harga'] === null ? null : (float)$x['harga'];
            $periods[$x['period_id']]['adjustments'][] = $x;
        }
        $pays = $this->db->prepare("SELECT * FROM payroll_payments WHERE period_id IN ($in) ORDER BY created_at");
        $pays->execute($ids);
        foreach ($pays->fetchAll() as $x) { $x['jumlah'] = (float)$x['jumlah']; $periods[$x['period_id']]['payments'][] = $x; }
        return array_values($periods);
    }

    /** @return array<string,array> id => pekerja */
    public function pekerjaByIds(array $ids): array {
        if (!$ids) return [];
        $w = $this->db->prepare('SELECT id, nama, grade, tim, no_rek, borongan, induk_id FROM workers WHERE id IN (' . self::in($ids) . ')');
        $w->execute(array_values($ids));
        $out = [];
        foreach ($w->fetchAll() as $r) $out[$r['id']] = $r;
        return $out;
    }

    public function timBorongan(?array $projectIds): array {
        $q = 'SELECT id AS worker_id, nama, tim, project_id FROM workers WHERE borongan = 1 AND aktif = 1'
           . ($projectIds !== null ? ' AND project_id IN (' . self::in($projectIds) . ')' : '') . ' ORDER BY nama';
        $s = $this->db->prepare($q); $s->execute($projectIds ?? []);
        return $s->fetchAll();
    }
}
