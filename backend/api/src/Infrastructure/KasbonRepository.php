<?php
declare(strict_types=1);
namespace App\Infrastructure;

use App\Domain\KasbonPlanner;

/** Akses data kasbon. Kasbon "atas nama" satu pekerja, satu tim (pekerja kosong, tim terisi) atau grup (keduanya kosong); pelunasan = kasbon bernilai negatif. */
final class KasbonRepository {
    public function __construct(private \PDO $db) {}

    /** Saldo satu pihak: diberikan - sudah dipotong. */
    public function saldo(string $projectId, ?string $workerId, string $tim = ''): float {
        if ($workerId !== null) { $cond = 'worker_id = ?'; $p = [$projectId, $workerId]; $cond2 = 'x.worker_id = ?'; }
        elseif ($tim !== '')    { $cond = 'worker_id IS NULL AND tim = ?'; $p = [$projectId, $tim]; $cond2 = 'x.worker_id IS NULL AND x.tim = ?'; }
        else                    { $cond = "worker_id IS NULL AND tim = ''"; $p = [$projectId]; $cond2 = "x.worker_id IS NULL AND x.tim = ''"; }
        $a = $this->db->prepare("SELECT COALESCE(SUM(jumlah),0) FROM kasbon WHERE project_id = ? AND $cond");
        $a->execute($p);
        $given = (float)$a->fetchColumn();
        $b = $this->db->prepare("SELECT COALESCE(SUM(x.jumlah),0) FROM payroll_adjustments x JOIN payroll_periods pp ON pp.id = x.period_id
                                  WHERE pp.project_id = ? AND x.kategori = 'kasbon' AND x.jenis = 'potong' AND $cond2");
        $b->execute($p);
        return $given - (float)$b->fetchColumn();
    }

    /** Saldo banyak pihak sekaligus (dua kueri berkelompok). Kunci "proyek|pekerja", "proyek|t:Tim", "proyek|" (grup); hanya saldo tidak nol. */
    public function saldoMap(?array $projectIds): array {
        $cond = ''; $params = [];
        if ($projectIds !== null) {
            if (!$projectIds) return [];
            $cond = ' WHERE project_id IN (' . implode(',', array_fill(0, count($projectIds), '?')) . ')';
            $params = $projectIds;
        }
        $key = fn($pid, $w, $t) => $pid . '|' . KasbonPlanner::kunci($w, $t);
        $map = [];
        $g = $this->db->prepare("SELECT project_id, COALESCE(worker_id, '') AS w, tim, SUM(jumlah) AS t FROM kasbon$cond GROUP BY project_id, w, tim");
        $g->execute($params);
        foreach ($g->fetchAll() as $r) { $k = $key($r['project_id'], $r['w'], $r['w'] === '' ? $r['tim'] : ''); $map[$k] = ($map[$k] ?? 0.0) + (float)$r['t']; }
        $condP = $projectIds !== null ? ' AND p.project_id IN (' . implode(',', array_fill(0, count($projectIds), '?')) . ')' : '';
        $b = $this->db->prepare("SELECT p.project_id, COALESCE(x.worker_id, '') AS w, x.tim, SUM(x.jumlah) AS t
                                   FROM payroll_adjustments x JOIN payroll_periods p ON p.id = x.period_id
                                  WHERE x.kategori = 'kasbon' AND x.jenis = 'potong'$condP GROUP BY p.project_id, w, x.tim");
        $b->execute($params);
        foreach ($b->fetchAll() as $r) { $k = $key($r['project_id'], $r['w'], $r['w'] === '' ? $r['tim'] : ''); $map[$k] = ($map[$k] ?? 0.0) - (float)$r['t']; }
        return array_filter($map, fn($v) => abs($v) > 0.005);
    }

    /** Kasbon yang diberikan sampai akhir periode, per kunci. */
    public function diberikanSampai(string $projectId, string $tglAkhir): array {
        $g = $this->db->prepare("SELECT COALESCE(worker_id, '') AS w, tim, SUM(jumlah) AS t FROM kasbon WHERE project_id = ? AND tgl <= ? GROUP BY w, tim");
        $g->execute([$projectId, $tglAkhir]);
        $out = [];
        foreach ($g->fetchAll() as $r) { $k = KasbonPlanner::kunci($r['w'], $r['w'] === '' ? $r['tim'] : ''); $out[$k] = ($out[$k] ?? 0.0) + (float)$r['t']; }
        return $out;
    }

    /** Kasbon yang sudah dipotong (selain baris otomatis periode ini, yang dihitung ulang). */
    public function sudahDipotong(string $projectId, string $periodId): array {
        $d = $this->db->prepare("SELECT COALESCE(x.worker_id, '') AS w, x.tim, SUM(x.jumlah) AS t FROM payroll_adjustments x JOIN payroll_periods pp ON pp.id = x.period_id
                                  WHERE pp.project_id = ? AND x.kategori = 'kasbon' AND x.jenis = 'potong' AND NOT (x.period_id = ? AND x.label LIKE '%(otomatis)')
                                  GROUP BY w, x.tim");
        $d->execute([$projectId, $periodId]);
        $out = [];
        foreach ($d->fetchAll() as $r) { $k = KasbonPlanner::kunci($r['w'], $r['w'] === '' ? $r['tim'] : ''); $out[$k] = ($out[$k] ?? 0.0) + (float)$r['t']; }
        return $out;
    }

    /** Penyesuaian periode ini selain baris kasbon otomatis: [kunci][jenis] => jumlah. */
    public function penyesuaianPeriode(string $periodId): array {
        $a = $this->db->prepare("SELECT COALESCE(worker_id, '') AS w, tim, jenis, SUM(jumlah) AS t FROM payroll_adjustments
                                  WHERE period_id = ? AND NOT (kategori = 'kasbon' AND label LIKE '%(otomatis)') GROUP BY w, tim, jenis");
        $a->execute([$periodId]);
        $adj = [];
        foreach ($a->fetchAll() as $r) { $k = KasbonPlanner::kunci($r['w'], $r['w'] === '' ? $r['tim'] : ''); $adj[$k][$r['jenis']] = ($adj[$k][$r['jenis']] ?? 0.0) + (float)$r['t']; }
        return $adj;
    }
}
