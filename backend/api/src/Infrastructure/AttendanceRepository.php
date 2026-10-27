<?php
declare(strict_types=1);
namespace App\Infrastructure;

final class AttendanceRepository {
    public function __construct(private \PDO $db) {}

    public function daftar(?array $scope, array $f, bool $lapangan): array {
        $conds = []; $params = [];
        if ($scope !== null) {
            $conds[] = 'a.project_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')';
            $params = array_merge($params, $scope);
        }
        if (!empty($f['project_id'])) { $conds[] = 'a.project_id = ?'; $params[] = $f['project_id']; }
        if (!empty($f['tgl_from'])) { $conds[] = 'a.tgl >= ?'; $params[] = $f['tgl_from']; }
        if (!empty($f['tgl_to']))   { $conds[] = 'a.tgl <= ?'; $params[] = $f['tgl_to']; }
        $cols = $lapangan ? 'a.worker_id, a.project_id, a.tgl, a.hari, a.lembur_jam, a.ket, a.rev'
                          : 'a.worker_id, a.project_id, a.tgl, a.hari, a.lembur_jam, a.ket, a.rev, a.grade, a.tim, a.tarif_harian, a.tarif_lembur';
        $st = $this->db->prepare("SELECT $cols FROM attendance a" . ($conds ? ' WHERE ' . implode(' AND ', $conds) : '') . ' ORDER BY a.tgl, a.worker_id');
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @return array<string,array> id => baris pekerja */
    public function pekerjaByIds(array $ids): array {
        $out = [];
        if (!$ids) return $out;
        $q = $this->db->prepare('SELECT * FROM workers WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $q->execute($ids);
        foreach ($q->fetchAll() as $w) $out[$w['id']] = $w;
        return $out;
    }

    /** Keadaan satu hari sebelum disimpan: worker_id => ['v'=>[hari,lembur], 'rev'=>int]. Tanpa kunci baris (baris periode sudah terkunci). */
    public function keadaanHari(string $projectId, string $tgl): array {
        $q = $this->db->prepare('SELECT worker_id, hari, lembur_jam, rev FROM attendance WHERE project_id = ? AND tgl = ?');
        $q->execute([$projectId, $tgl]);
        $out = [];
        foreach ($q->fetchAll() as $o) $out[$o['worker_id']] = ['v' => [(float)$o['hari'], (float)$o['lembur_jam']], 'rev' => (int)$o['rev']];
        return $out;
    }

    public function simpan(string $wid, string $projectId, string $tgl, float $hari, float $lembur, string $grade, string $tim, float $harian, float $lemburRate, string $ket, string $userId): void {
        $this->db->prepare(
            'INSERT INTO attendance (worker_id, project_id, tgl, hari, lembur_jam, grade, tim, tarif_harian, tarif_lembur, ket, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE hari=VALUES(hari), lembur_jam=VALUES(lembur_jam), ket=VALUES(ket), rev = rev + 1,
               tarif_harian = IF(tarif_harian = 0, VALUES(tarif_harian), tarif_harian),
               tarif_lembur = IF(tarif_lembur = 0, VALUES(tarif_lembur), tarif_lembur)')
            ->execute([$wid, $projectId, $tgl, $hari, $lembur, $grade, $tim, $harian, $lemburRate, $ket, $userId]);
    }

    public function hapus(string $wid, string $projectId, string $tgl): void {
        $this->db->prepare('DELETE FROM attendance WHERE worker_id=? AND project_id=? AND tgl=?')->execute([$wid, $projectId, $tgl]);
    }

    /** Persetujuan dicabut karena absen berubah: kembali ke "diajukan", potongan kasbon otomatis dihitung ulang saat disetujui lagi. */
    public function cabutPersetujuan(string $periodId): void {
        $this->db->prepare("UPDATE payroll_periods SET status='diajukan', disetujui_at=NULL, disetujui_by=NULL WHERE id=?")->execute([$periodId]);
        $this->db->prepare("DELETE FROM payroll_adjustments WHERE period_id = ? AND kategori = 'kasbon' AND label LIKE '%(otomatis)'")->execute([$periodId]);
    }

    public function revisiHari(string $projectId, string $tgl): array {
        $q = $this->db->prepare('SELECT worker_id, rev FROM attendance WHERE project_id = ? AND tgl = ?');
        $q->execute([$projectId, $tgl]);
        $out = [];
        foreach ($q->fetchAll() as $r) $out[$r['worker_id']] = (int)$r['rev'];
        return $out;
    }

    /** Nama orang yang total harinya >1 pada tanggal itu (lintas proyek). */
    public function namaGanda(string $tgl, array $orang): array {
        $q = $this->db->prepare('SELECT MIN(w.nama) AS nama FROM attendance a JOIN workers w ON w.id = a.worker_id
                                  WHERE a.tgl = ? AND COALESCE(w.induk_id, w.id) IN (' . implode(',', array_fill(0, count($orang), '?')) . ')
                                  GROUP BY COALESCE(w.induk_id, w.id) HAVING SUM(a.hari) > 1');
        $q->execute(array_merge([$tgl], $orang));
        return array_column($q->fetchAll(), 'nama');
    }
}
