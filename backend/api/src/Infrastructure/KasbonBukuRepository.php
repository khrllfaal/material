<?php
declare(strict_types=1);
namespace App\Infrastructure;

/** Tabel kasbon (buku kasbon) dan pengajuan kasbon dari lapangan. */
final class KasbonBukuRepository {
    public function __construct(private \PDO $db) {}

    public function semua(): array {
        return $this->db->query("SELECT k.*, w.nama AS worker_nama, p.nama AS project_nama FROM kasbon k
                                   JOIN projects p ON p.id = k.project_id LEFT JOIN workers w ON w.id = k.worker_id ORDER BY k.tgl DESC, k.created_at DESC")->fetchAll();
    }
    public function namaProyek(): array { $o = []; foreach ($this->db->query('SELECT id, nama FROM projects')->fetchAll() as $r) $o[$r['id']] = $r['nama']; return $o; }
    public function namaPekerja(): array { $o = []; foreach ($this->db->query('SELECT id, nama FROM workers')->fetchAll() as $r) $o[$r['id']] = $r['nama']; return $o; }

    public function proyekPekerja(string $workerId): string|false|null {
        $w = $this->db->prepare('SELECT project_id FROM workers WHERE id = ?'); $w->execute([$workerId]);
        return $w->fetchColumn();
    }
    public function timAda(string $projectId, string $tim): bool {
        $q = $this->db->prepare('SELECT 1 FROM workers WHERE project_id = ? AND tim = ? LIMIT 1'); $q->execute([$projectId, $tim]);
        return (bool)$q->fetchColumn();
    }
    public function tambah(string $id, string $projectId, ?string $workerId, string $tim, string $tgl, float $jumlah, string $ket, string $userId): void {
        $this->db->prepare('INSERT INTO kasbon (id, project_id, worker_id, tim, tgl, jumlah, ket, created_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$id, $projectId, $workerId, $tim, $tgl, $jumlah, $ket, $userId]);
    }
    public function cari(string $id): ?array { $st = $this->db->prepare('SELECT * FROM kasbon WHERE id = ?'); $st->execute([$id]); return $st->fetch() ?: null; }
    public function hapus(string $id): void { $this->db->prepare('DELETE FROM kasbon WHERE id = ?')->execute([$id]); }
}
