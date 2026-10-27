<?php
declare(strict_types=1);
namespace App\Infrastructure;

/** Akses data siklus periode gaji (payroll_periods dan tabel turunannya). */
final class PeriodRepository {
    public function __construct(private \PDO $db) {}

    /** Menjalankan $fn dalam satu transaksi; gagal/tolak => batal semua. Pernyataan pertama $fn HARUS kunci(): kunci baris periode sebelum membaca apa pun. */
    public function transaksi(callable $fn): mixed {
        $this->db->beginTransaction();
        try { $r = $fn(); $this->db->commit(); return $r; }
        catch (\Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }

    /** Mengunci baris periode yang memuat $tgl (permintaan lain menunggu), lalu mengembalikan status terkini. */
    public function kunci(string $projectId, string $tgl): array {
        $st = $this->db->prepare('SELECT * FROM payroll_periods WHERE project_id = ? AND tgl_mulai = ? FOR UPDATE');
        $st->execute([$projectId, period_bounds($projectId, $tgl)[0]]);
        return $st->fetch();
    }

    private function scalar(string $sql, array $p): mixed { $s = $this->db->prepare($sql); $s->execute($p); return $s->fetchColumn(); }

    public function jumlahAbsen(string $projectId, string $dari, string $sampai): int {
        return (int)$this->scalar('SELECT COUNT(*) FROM attendance WHERE project_id = ? AND tgl BETWEEN ? AND ?', [$projectId, $dari, $sampai]);
    }
    public function hariAbsenTerakhir(string $projectId, string $dari, string $sampai): string {
        return (string)$this->scalar('SELECT MAX(tgl) FROM attendance WHERE project_id = ? AND tgl BETWEEN ? AND ?', [$projectId, $dari, $sampai]);
    }
    public function jumlahPenyesuaian(string $periodId): int { return (int)$this->scalar('SELECT COUNT(*) FROM payroll_adjustments WHERE period_id = ?', [$periodId]); }
    public function jumlahPembayaran(string $periodId): int { return (int)$this->scalar('SELECT COUNT(*) FROM payroll_payments WHERE period_id = ?', [$periodId]); }

    /** Nama pekerja harian yang punya hari kerja tetapi tarifnya masih 0 (maks 5). */
    public function pekerjaTanpaTarif(string $projectId, string $dari, string $sampai): array {
        $s = $this->db->prepare("SELECT w.nama FROM attendance a JOIN workers w ON w.id = a.worker_id
                                  WHERE a.project_id = ? AND a.tgl BETWEEN ? AND ? AND a.hari > 0 AND a.tarif_harian <= 0 AND w.borongan = 0 GROUP BY w.id, w.nama LIMIT 5");
        $s->execute([$projectId, $dari, $sampai]);
        return array_column($s->fetchAll(), 'nama');
    }
    public function kasbonOtomatisPekerja(string $periodId, string $workerId): float {
        return (float)$this->scalar("SELECT COALESCE(SUM(jumlah),0) FROM payroll_adjustments WHERE period_id = ? AND worker_id = ? AND kategori = 'kasbon' AND label LIKE '%(otomatis)'", [$periodId, $workerId]);
    }

    public function ubahHeader(string $id, string $pj, string $grup, string $catatan): void {
        $this->db->prepare('UPDATE payroll_periods SET pj_nama=?, grup_nama=?, catatan=? WHERE id=?')->execute([$pj, $grup, $catatan, $id]);
    }
    public function potongAkhir(string $id, string $tglAkhir): void { $this->db->prepare('UPDATE payroll_periods SET tgl_akhir = ? WHERE id = ?')->execute([$tglAkhir, $id]); }
    public function tandaiDiajukan(string $id, string $pj, string $grup, string $catatan, string $userId): void {
        $this->db->prepare("UPDATE payroll_periods SET status='diajukan', pj_nama=?, grup_nama=?, catatan=?, diajukan_at=NOW(), diajukan_by=? WHERE id=?")->execute([$pj, $grup, $catatan, $userId, $id]);
    }
    public function tandaiDisetujui(string $id, string $userId): void {
        $this->db->prepare("UPDATE payroll_periods SET status='disetujui', disetujui_at=NOW(), disetujui_by=? WHERE id=?")->execute([$userId, $id]);
    }
    public function tandaiDraft(string $id, string $catatan): void {
        $this->db->prepare("UPDATE payroll_periods SET status='draft', diajukan_at=NULL, diajukan_by=NULL, disetujui_at=NULL, disetujui_by=NULL, catatan=? WHERE id=?")->execute([$catatan, $id]);
    }
    public function tandaiDibayar(string $id, string $userId): void {
        $this->db->prepare("UPDATE payroll_periods SET status='dibayar', dibayar_at=NOW(), dibayar_by=? WHERE id=?")->execute([$userId, $id]);
    }

    public function hapusKasbonOtomatis(string $periodId): void {
        $this->db->prepare("DELETE FROM payroll_adjustments WHERE period_id = ? AND kategori = 'kasbon' AND label LIKE '%(otomatis)'")->execute([$periodId]);
    }
    public function tambahKasbonOtomatis(string $periodId, ?string $workerId, string $tim, float $jumlah, string $userId): void {
        $this->db->prepare("INSERT INTO payroll_adjustments (id, period_id, worker_id, tim, jenis, kategori, label, jumlah, created_by) VALUES (?,?,?,?,'potong','kasbon','Potong kasbon (otomatis)',?,?)")
                 ->execute([new_id('adj_'), $periodId, $workerId, $tim, $jumlah, $userId]);
    }
    public function catatPembayaran(string $periodId, ?string $workerId, float $jumlah, string $tglBayar, string $metode, string $ket, string $userId): void {
        $this->db->prepare('INSERT INTO payroll_payments (id, period_id, worker_id, jumlah, tgl_bayar, metode, ket, created_by) VALUES (?,?,?,?,?,?,?,?)')
                 ->execute([new_id('pay_'), $periodId, $workerId, $jumlah, $tglBayar, $metode, $ket, $userId]);
    }
}
