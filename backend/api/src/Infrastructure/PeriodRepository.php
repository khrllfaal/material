<?php
declare(strict_types=1);
namespace App\Infrastructure;

/** Akses data siklus periode gaji (payroll_periods dan tabel turunannya). */
final class PeriodRepository {
    public function __construct(private \PDO $db) {}

    /** Menjalankan $fn dalam satu transaksi; gagal/tolak => batal semua. Pernyataan pertama $fn HARUS kunci(): kunci baris periode sebelum membaca apa pun. */
    public function transaksi(callable $fn): mixed { return Tx::jalankan($this->db, $fn); }

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
    public function catatPembayaran(string $periodId, ?string $workerId, float $jumlah, string $tglBayar, string $metode, string $ket, string $userId, ?string $buktiNo = null): void {
        $this->db->prepare('INSERT INTO payroll_payments (id, period_id, worker_id, jumlah, tgl_bayar, metode, ket, created_by, bukti_no) VALUES (?,?,?,?,?,?,?,?,?)')
                 ->execute([new_id('pay_'), $periodId, $workerId, $jumlah, $tglBayar, $metode, $ket, $userId, $buktiNo]);
    }

    /** Nomor bukti berikutnya untuk bulan $tgl (di dalam transaksi pembayaran). */
    public function nomorBerikutnya(string $kode, string $tgl): string { return (new DocCounter($this->db))->berikutnya($kode, $tgl); }

    /** Pembayaran dengan nomor bukti ini, beserta periodenya. */
    public function pembayaranByBukti(string $buktiNo): array {
        $q = $this->db->prepare('SELECT x.*, p.project_id, p.tgl_mulai, p.tgl_akhir, p.pj_nama, p.grup_nama, pr.nama AS project_nama, u.nama AS dibuat_nama
                                   FROM payroll_payments x JOIN payroll_periods p ON p.id = x.period_id JOIN projects pr ON pr.id = p.project_id
                                   LEFT JOIN users u ON u.id = x.created_by WHERE x.bukti_no = ? ORDER BY x.created_at, x.id');
        $q->execute([$buktiNo]);
        return $q->fetchAll();
    }
    public function pembayaranPeriode(string $periodId): array {
        $q = $this->db->prepare('SELECT worker_id, jumlah, bukti_no FROM payroll_payments WHERE period_id = ?');
        $q->execute([$periodId]);
        return $q->fetchAll();
    }
}
