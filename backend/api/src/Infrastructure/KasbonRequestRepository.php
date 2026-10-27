<?php
declare(strict_types=1);
namespace App\Infrastructure;

final class KasbonRequestRepository {
    public function __construct(private \PDO $db) {}

    public function daftar(?array $scope, ?string $status): array {
        $conds = []; $params = [];
        if ($scope !== null) { $conds[] = 'r.project_id IN (' . implode(',', array_fill(0, count($scope), '?')) . ')'; $params = $scope; }
        if ($status) { $conds[] = 'r.status = ?'; $params[] = $status; }
        $st = $this->db->prepare(
            "SELECT r.id, r.project_id, p.nama AS project_nama, r.worker_id, w.nama AS worker_nama, r.tim, r.batch_id, r.jumlah, r.ket, r.status, r.catatan, r.created_at, r.diproses_at,
                   COALESCE(DATE(CONVERT_TZ(r.created_at, @@session.time_zone, '+07:00')), DATE(r.created_at)) AS tgl_wib
               FROM kasbon_requests r JOIN projects p ON p.id = r.project_id LEFT JOIN workers w ON w.id = r.worker_id"
            . ($conds ? ' WHERE ' . implode(' AND ', $conds) : '') . ' ORDER BY FIELD(r.status, \'diajukan\', \'disetujui\', \'ditolak\'), r.created_at DESC LIMIT 200');
        $st->execute($params);
        return $st->fetchAll();
    }
    public function proyekPekerja(string $workerId): mixed { $w = $this->db->prepare('SELECT project_id FROM workers WHERE id = ?'); $w->execute([$workerId]); return $w->fetchColumn(); }
    public function timAda(string $projectId, string $tim): bool { $q = $this->db->prepare('SELECT 1 FROM workers WHERE project_id = ? AND tim = ? LIMIT 1'); $q->execute([$projectId, $tim]); return (bool)$q->fetchColumn(); }
    public function idAda(string $id): bool { $q = $this->db->prepare('SELECT 1 FROM kasbon_requests WHERE id = ?'); $q->execute([$id]); return (bool)$q->fetchColumn(); }
    public function jumlahTerbuka(string $projectId): int { $q = $this->db->prepare("SELECT COUNT(*) FROM kasbon_requests WHERE project_id = ? AND status = 'diajukan'"); $q->execute([$projectId]); return (int)$q->fetchColumn(); }
    /** Sudah ada pengajuan (bukan yang ditolak) untuk pihak ini pada minggu gaji ini (tanggal WIB)? */
    public function adaMingguIni(string $projectId, string $dari, string $sampai, ?string $workerId, string $tim): bool {
        $q = $this->db->prepare("SELECT COUNT(*) FROM kasbon_requests WHERE project_id = ? AND status <> 'ditolak'
                                  AND COALESCE(DATE(CONVERT_TZ(created_at, @@session.time_zone, '+07:00')), DATE(created_at)) BETWEEN ? AND ?
                                  AND " . ($workerId ? 'worker_id = ?' : 'worker_id IS NULL AND tim = ?'));
        $q->execute([$projectId, $dari, $sampai, $workerId ?: $tim]);
        return (int)$q->fetchColumn() > 0;
    }
    public function tambah(string $id, string $projectId, ?string $workerId, string $tim, ?string $batch, float $jumlah, string $ket, string $userId): void {
        $this->db->prepare('INSERT INTO kasbon_requests (id, project_id, worker_id, tim, batch_id, jumlah, ket, created_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id, $projectId, $workerId, $tim, $batch, $jumlah, $ket, $userId]);
    }
    /** Baris batch yang masih diajukan, dikunci. */
    public function batchTerbuka(string $batch): array { $st = $this->db->prepare("SELECT * FROM kasbon_requests WHERE batch_id = ? AND status = 'diajukan' FOR UPDATE"); $st->execute([$batch]); return $st->fetchAll(); }
    public function tolakBatch(string $batch, string $catatan, string $userId): void {
        $this->db->prepare("UPDATE kasbon_requests SET status='ditolak', catatan=?, diproses_by=?, diproses_at=NOW() WHERE batch_id = ? AND status = 'diajukan'")->execute([$catatan, $userId, $batch]);
    }
    public function cariKunci(string $id): ?array { $st = $this->db->prepare('SELECT * FROM kasbon_requests WHERE id = ? FOR UPDATE'); $st->execute([$id]); return $st->fetch() ?: null; }
    public function tolak(string $id, string $catatan, string $userId): void {
        $this->db->prepare("UPDATE kasbon_requests SET status='ditolak', catatan=?, diproses_by=?, diproses_at=NOW() WHERE id=?")->execute([$catatan, $userId, $id]);
    }
    /** Menulis kasbon yang disetujui ke buku kasbon dan menutup pengajuan. */
    public function setujui(array $r, float $jumlah, string $tgl, string $catatan, string $userId): string {
        $kid = new_id('ksb_');
        $this->db->prepare('INSERT INTO kasbon (id, project_id, worker_id, tim, tgl, jumlah, ket, created_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$kid, $r['project_id'], $r['worker_id'], $r['tim'], $tgl, $jumlah, mb_substr('Pengajuan lapangan' . ($r['tim'] !== '' ? ' (' . $r['tim'] . ')' : '') . ($r['ket'] !== '' ? ': ' . $r['ket'] : ''), 0, 255), $userId]);
        $this->db->prepare("UPDATE kasbon_requests SET status='disetujui', catatan=?, kasbon_id=?, diproses_by=?, diproses_at=NOW() WHERE id=?")->execute([$catatan, $kid, $userId, $r['id']]);
        return $kid;
    }
}
