<?php
declare(strict_types=1);
namespace App\Infrastructure;

final class WorkerRepository {
    public function __construct(private \PDO $db) {}

    public function daftar(?array $scope, array $f, bool $lapangan): array {
        $conds = []; $params = [];
        if ($scope !== null) { $conds[] = 'project_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')'; $params = array_merge($params, $scope); }
        if (!empty($f['project_id'])) { $conds[] = 'project_id = ?'; $params[] = $f['project_id']; }
        if (empty($f['all'])) $conds[] = 'aktif = 1';
        $cols = $lapangan ? 'id, project_id, nama, grade, tim, aktif, diverifikasi, borongan, induk_id'
                          : 'id, project_id, nama, grade, tim, tarif_harian, tarif_lembur, no_rek, aktif, diverifikasi, borongan, induk_id, created_at';
        $st = $this->db->prepare("SELECT $cols FROM workers" . ($conds ? ' WHERE ' . implode(' AND ', $conds) : '') . ' ORDER BY tim, FIELD(grade,\'KTK\',\'TK\',\'LD\',\'OBK\',\'SPR\',\'OMX\',\'LAIN\'), nama');
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Orang yang sama sudah terdaftar di proyek lain (untuk "setengah hari di proyek lain"). */
    public function kandidat(string $proyekIni, ?array $scope): array {
        $kc = ['w.aktif = 1', 'w.borongan = 0', 'w.project_id <> ?']; $kp = [$proyekIni];
        if ($scope !== null) { $kc[] = 'w.project_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')'; $kp = array_merge($kp, $scope); }
        $q = $this->db->prepare('SELECT w.id, w.project_id, p.nama AS project_nama, w.nama, w.grade, w.tim, COALESCE(w.induk_id, w.id) AS orang
                                   FROM workers w JOIN projects p ON p.id = w.project_id WHERE ' . implode(' AND ', $kc) . ' ORDER BY w.nama LIMIT 500');
        $q->execute($kp);
        return $q->fetchAll();
    }

    public function cari(string $id): ?array {
        $q = $this->db->prepare('SELECT * FROM workers WHERE id = ?'); $q->execute([$id]);
        return $q->fetch() ?: null;
    }
    public function belumDiverifikasi(string $projectId): array {
        $st = $this->db->prepare('SELECT id FROM workers WHERE project_id = ? AND diverifikasi = 0'); $st->execute([$projectId]);
        return array_column($st->fetchAll(), 'id');
    }
    public function verifikasi(string $wid, string $userId): int {
        $up = $this->db->prepare('UPDATE workers SET diverifikasi = 1, diverifikasi_by = ?, diverifikasi_at = NOW() WHERE id = ? AND diverifikasi = 0');
        $up->execute([$userId, $wid]);
        return $up->rowCount();
    }
    public function setAktif(string $wid, int $val): void { $this->db->prepare('UPDATE workers SET aktif = ? WHERE id = ?')->execute([$val, $wid]); }

    public function timDiProyek(string $projectId): array {
        $tq = $this->db->prepare('SELECT tim FROM workers WHERE project_id = ? AND tim <> \'\' GROUP BY tim'); $tq->execute([$projectId]);
        return array_column($tq->fetchAll(), 'tim');
    }
    public function nonaktifDiProyek(string $projectId): array {
        $q = $this->db->prepare('SELECT id, nama, tim FROM workers WHERE project_id = ? AND aktif = 0'); $q->execute([$projectId]);
        return $q->fetchAll();
    }
    public function sesamaGrade(string $projectId, string $grade, string $kecualiId): array {
        $q = $this->db->prepare('SELECT id, nama, tim FROM workers WHERE project_id = ? AND grade = ? AND id <> ?'); $q->execute([$projectId, $grade, $kecualiId]);
        return $q->fetchAll();
    }

    public function tambahLapangan(string $id, string $projectId, string $nama, string $grade, string $tim, bool $borongan, ?string $induk, string $userId): void {
        $this->db->prepare('INSERT INTO workers (id, project_id, nama, grade, tim, tarif_harian, tarif_lembur, aktif, diverifikasi, borongan, induk_id, created_by) VALUES (?,?,?,?,?,?,?,1,1,?,?,?)')
            ->execute([$id, $projectId, $nama, $grade, $tim, $borongan ? 0 : null, $borongan ? 0 : null, $borongan ? 1 : 0, $induk, $userId]);
    }
    public function tambahPusat(string $id, string $projectId, string $nama, string $grade, string $tim, ?float $harian, ?float $lembur, string $rek, int $aktif, bool $borongan, ?string $induk, string $userId): void {
        $this->db->prepare('INSERT INTO workers (id, project_id, nama, grade, tim, tarif_harian, tarif_lembur, no_rek, aktif, borongan, induk_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $projectId, $nama, $grade, $tim, $harian, $lembur, $rek, $aktif, $borongan ? 1 : 0, $induk, $userId]);
    }
    public function ubahPusat(string $id, string $projectId, string $nama, string $grade, string $tim, ?float $harian, ?float $lembur, string $rek, int $aktif, bool $borongan): void {
        $this->db->prepare('UPDATE workers SET project_id=?, nama=?, grade=?, tim=?, tarif_harian=?, tarif_lembur=?, no_rek=?, aktif=?, borongan=? WHERE id=?')
            ->execute([$projectId, $nama, $grade, $tim, $harian, $lembur, $rek, $aktif, $borongan ? 1 : 0, $id]);
    }
    public function punyaAbsen(string $id): bool {
        $c = $this->db->prepare('SELECT COUNT(*) FROM attendance WHERE worker_id = ?'); $c->execute([$id]);
        return (int)$c->fetchColumn() > 0;
    }
    public function hapus(string $id): void { $this->db->prepare('DELETE FROM workers WHERE id = ?')->execute([$id]); }

    /** Ganti nama tim di semua tempat (pekerja, tarif tim, kasbon, penyesuaian, absen yang belum dibayar). Mengembalikan jumlah pekerja. */
    public function gantiNamaTim(string $pid, string $dari, string $ke): int {
        return (int)Tx::jalankan($this->db, function () use ($pid, $dari, $ke) {
            $n = $this->db->prepare('UPDATE workers SET tim = ? WHERE project_id = ? AND tim = ?'); $n->execute([$ke, $pid, $dari]); $nw = $n->rowCount();
            $this->db->prepare('UPDATE IGNORE labor_rates SET tim = ? WHERE project_id = ? AND tim = ?')->execute([$ke, $pid, $dari]);
            $this->db->prepare('DELETE FROM labor_rates WHERE project_id = ? AND tim = ?')->execute([$pid, $dari]); // tim tujuan sudah punya tarifnya sendiri
            $this->db->prepare('UPDATE kasbon SET tim = ? WHERE project_id = ? AND worker_id IS NULL AND tim = ?')->execute([$ke, $pid, $dari]);
            $this->db->prepare('UPDATE kasbon_requests SET tim = ? WHERE project_id = ? AND worker_id IS NULL AND tim = ?')->execute([$ke, $pid, $dari]);
            $this->db->prepare('UPDATE payroll_adjustments x JOIN payroll_periods p ON p.id = x.period_id SET x.tim = ? WHERE p.project_id = ? AND x.worker_id IS NULL AND x.tim = ?')->execute([$ke, $pid, $dari]);
            $this->db->prepare("UPDATE attendance a JOIN payroll_periods p ON p.project_id = a.project_id AND a.tgl BETWEEN p.tgl_mulai AND p.tgl_akhir
                                   SET a.tim = ? WHERE a.project_id = ? AND a.tim = ? AND p.status <> 'dibayar'")->execute([$ke, $pid, $dari]); // minggu yang sudah dibayar menyimpan nama saat dibayar
            return $nw;
        });
    }
}
