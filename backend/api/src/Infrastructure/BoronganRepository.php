<?php
declare(strict_types=1);
namespace App\Infrastructure;

/** Akses data kontrak borongan, addendum, dan termin. */
final class BoronganRepository {
    public function __construct(private \PDO $db) {}

    private static function in(array $v): string { return implode(',', array_fill(0, count($v), '?')); }
    private static function num(array $r, array $cols): array { foreach ($cols as $c) if (array_key_exists($c, $r) && $r[$c] !== null) $r[$c] = (float)$r[$c]; return $r; }

    /** Kunci baris kontrak: permintaan lain untuk kontrak yang sama menunggu. Pernyataan pertama dalam setiap transaksi ubah. */
    public function kunci(string $id): ?array {
        $q = $this->db->prepare('SELECT * FROM borongan_kontrak WHERE id = ? FOR UPDATE'); $q->execute([$id]);
        return ($r = $q->fetch()) ? self::num($r, ['nilai', 'retensi_persen']) : null;
    }
    public function cari(string $id): ?array {
        $q = $this->db->prepare('SELECT k.*, p.nama AS project_nama, w.nama AS tim_nama, w.no_rek, w.aktif AS tim_aktif FROM borongan_kontrak k
                                   JOIN projects p ON p.id = k.project_id JOIN workers w ON w.id = k.worker_id WHERE k.id = ?');
        $q->execute([$id]);
        return ($r = $q->fetch()) ? self::num($r, ['nilai', 'retensi_persen']) : null;
    }
    /** @param ?array $projectIds null = semua */
    public function daftar(?array $projectIds, ?string $workerId = null): array {
        $c = []; $p = [];
        if ($projectIds !== null) { if (!$projectIds) return []; $c[] = 'k.project_id IN (' . self::in($projectIds) . ')'; $p = array_merge($p, $projectIds); }
        if ($workerId) { $c[] = 'k.worker_id = ?'; $p[] = $workerId; }
        $q = $this->db->prepare('SELECT k.*, p.nama AS project_nama, w.nama AS tim_nama, w.no_rek, w.aktif AS tim_aktif FROM borongan_kontrak k
                                   JOIN projects p ON p.id = k.project_id JOIN workers w ON w.id = k.worker_id' . ($c ? ' WHERE ' . implode(' AND ', $c) : '') . ' ORDER BY k.tgl_mulai DESC, k.no_kontrak DESC');
        $q->execute($p);
        return array_map(fn($r) => self::num($r, ['nilai', 'retensi_persen']), $q->fetchAll());
    }
    public function addenda(array $kontrakIds): array {
        if (!$kontrakIds) return [];
        $q = $this->db->prepare('SELECT * FROM borongan_addendum WHERE kontrak_id IN (' . self::in($kontrakIds) . ') ORDER BY tgl, created_at, id'); $q->execute(array_values($kontrakIds));
        return array_map(fn($r) => self::num($r, ['jumlah']), $q->fetchAll());
    }
    public function termin(array $kontrakIds): array {
        if (!$kontrakIds) return [];
        $q = $this->db->prepare('SELECT * FROM borongan_termin WHERE kontrak_id IN (' . self::in($kontrakIds) . ') ORDER BY kontrak_id, no_termin, created_at, id'); $q->execute(array_values($kontrakIds));
        return array_map(fn($r) => self::num($r, ['bruto', 'retensi', 'potong_kasbon', 'denda', 'bersih']), $q->fetchAll());
    }
    public function cariTermin(string $id): ?array {
        $q = $this->db->prepare('SELECT * FROM borongan_termin WHERE id = ?'); $q->execute([$id]);
        return ($r = $q->fetch()) ? self::num($r, ['bruto', 'retensi', 'potong_kasbon', 'denda', 'bersih']) : null;
    }
    public function cariTerminByBukti(string $buktiNo): ?array {
        $q = $this->db->prepare('SELECT * FROM borongan_termin WHERE bukti_no = ? LIMIT 1'); $q->execute([$buktiNo]);
        return ($r = $q->fetch()) ? self::num($r, ['bruto', 'retensi', 'potong_kasbon', 'denda', 'bersih']) : null;
    }
    public function cariAddendum(string $id): ?array {
        $q = $this->db->prepare('SELECT * FROM borongan_addendum WHERE id = ?'); $q->execute([$id]);
        return ($r = $q->fetch()) ? self::num($r, ['jumlah']) : null;
    }
    public function tim(string $workerId): ?array {
        $q = $this->db->prepare('SELECT id, nama, project_id, aktif, borongan, no_rek, tim FROM workers WHERE id = ?'); $q->execute([$workerId]);
        return $q->fetch() ?: null;
    }
    /** Tim borongan aktif (untuk memilih saat membuat kontrak). */
    public function daftarTim(?array $projectIds): array {
        $c = ['w.borongan = 1']; $p = [];
        if ($projectIds !== null) { if (!$projectIds) return []; $c[] = 'w.project_id IN (' . self::in($projectIds) . ')'; $p = $projectIds; }
        $q = $this->db->prepare('SELECT w.id, w.nama, w.project_id, w.aktif, w.no_rek, pr.nama AS project_nama FROM workers w JOIN projects pr ON pr.id = w.project_id WHERE ' . implode(' AND ', $c) . ' ORDER BY pr.nama, w.nama');
        $q->execute($p);
        return $q->fetchAll();
    }

    public function tambahKontrak(array $k): void {
        $this->db->prepare('INSERT INTO borongan_kontrak (id, no_kontrak, project_id, worker_id, nama_pekerjaan, tgl_mulai, nilai, retensi_persen, catatan, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$k['id'], $k['no_kontrak'], $k['project_id'], $k['worker_id'], $k['nama_pekerjaan'], $k['tgl_mulai'], $k['nilai'], $k['retensi_persen'], $k['catatan'], $k['created_by']]);
    }
    public function ubahKontrak(string $id, string $nama, string $tgl, float $nilai, float $retensi, string $catatan): void {
        $this->db->prepare('UPDATE borongan_kontrak SET nama_pekerjaan=?, tgl_mulai=?, nilai=?, retensi_persen=?, catatan=? WHERE id=?')->execute([$nama, $tgl, $nilai, $retensi, $catatan, $id]);
    }
    /** Ubah status; $kolom = kolom waktu/pelaku opsional yang ikut diisi (mis. disetujui). */
    public function setStatusKontrak(string $id, string $status, ?string $by = null, ?string $alasan = null): void {
        $extra = ['diajukan' => ', diajukan_at = NOW(), diajukan_by = ?', 'disetujui' => ', disetujui_at = NOW(), disetujui_by = ?', 'selesai' => ', selesai_at = NOW(), selesai_by = ?',
                  'batal' => ', batal_at = NOW(), batal_alasan = ?', 'draft' => ', diajukan_at = NULL, diajukan_by = NULL, disetujui_at = NULL, disetujui_by = NULL, catatan_kembali = ?'][$status];
        $this->db->prepare("UPDATE borongan_kontrak SET status = ?$extra WHERE id = ?")->execute([$status, $status === 'batal' || $status === 'draft' ? $alasan : $by, $id]);
    }

    public function tambahAddendum(array $a): void {
        $this->db->prepare('INSERT INTO borongan_addendum (id, kontrak_id, tgl, jumlah, alasan, status, created_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$a['id'], $a['kontrak_id'], $a['tgl'], $a['jumlah'], $a['alasan'], $a['status'], $a['created_by']]);
    }
    public function prosesAddendum(string $id, string $status, string $by): void {
        $this->db->prepare('UPDATE borongan_addendum SET status = ?, diproses_by = ?, diproses_at = NOW() WHERE id = ?')->execute([$status, $by, $id]);
    }

    public function nomorTerminBerikut(string $kontrakId): int {
        $q = $this->db->prepare("SELECT COALESCE(MAX(no_termin), 0) + 1 FROM borongan_termin WHERE kontrak_id = ? AND jenis = 'termin'"); $q->execute([$kontrakId]);
        return (int)$q->fetchColumn();
    }
    public function tambahTermin(array $t): void {
        $this->db->prepare('INSERT INTO borongan_termin (id, kontrak_id, no_termin, jenis, tgl, bruto, retensi, potong_kasbon_aktif, potong_kasbon, denda, denda_ket, bersih, status, ket, created_by)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$t['id'], $t['kontrak_id'], $t['no_termin'], $t['jenis'], $t['tgl'], $t['bruto'], $t['retensi'], $t['potong_kasbon_aktif'], $t['potong_kasbon'], $t['denda'], $t['denda_ket'], $t['bersih'], $t['status'], $t['ket'], $t['created_by']]);
    }
    public function setujuiTermin(string $id, float $retensi, float $potongKasbon, float $bersih, string $by): void {
        $this->db->prepare("UPDATE borongan_termin SET status='disetujui', retensi=?, potong_kasbon=?, bersih=?, disetujui_at=NOW(), disetujui_by=? WHERE id=?")->execute([$retensi, $potongKasbon, $bersih, $by, $id]);
    }
    public function tolakTermin(string $id, string $alasan, string $by): void {
        $this->db->prepare("UPDATE borongan_termin SET status='ditolak', ket=CONCAT(ket, CASE WHEN ket='' THEN '' ELSE ' | ' END, ?), disetujui_by=?, disetujui_at=NOW() WHERE id=?")->execute(['Ditolak: ' . $alasan, $by, $id]);
    }
    public function bayarTermin(string $id, string $tglBayar, string $metode, string $buktiNo, ?string $kasbonId, string $by): void {
        $this->db->prepare("UPDATE borongan_termin SET status='dibayar', tgl_bayar=?, metode=?, bukti_no=?, kasbon_id=?, dibayar_at=NOW(), dibayar_by=? WHERE id=?")->execute([$tglBayar, $metode, $buktiNo, $kasbonId, $by, $id]);
    }

    /** Saldo kasbon tim di proyek, dengan kunci baris (dipakai saat pembayaran supaya potongan tidak melebihi saldo). */
    public function saldoKasbonKunci(string $projectId, string $workerId): float {
        $q = $this->db->prepare('SELECT COALESCE(SUM(jumlah),0) FROM (SELECT jumlah FROM kasbon WHERE project_id = ? AND worker_id = ? FOR UPDATE) x'); $q->execute([$projectId, $workerId]);
        $given = (float)$q->fetchColumn();
        $b = $this->db->prepare("SELECT COALESCE(SUM(x.jumlah),0) FROM payroll_adjustments x JOIN payroll_periods pp ON pp.id = x.period_id
                                  WHERE pp.project_id = ? AND x.kategori = 'kasbon' AND x.jenis = 'potong' AND x.worker_id = ?");
        $b->execute([$projectId, $workerId]);
        return $given - (float)$b->fetchColumn();
    }
    public function catatPotongKasbon(string $projectId, string $workerId, string $tgl, float $jumlah, string $ket, string $ref, string $userId): string {
        $id = new_id('ksb_');
        $this->db->prepare('INSERT INTO kasbon (id, project_id, worker_id, tim, tgl, jumlah, ket, created_by, ref) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$id, $projectId, $workerId, '', $tgl, -$jumlah, $ket, $userId, $ref]);
        return $id;
    }
    /** Semua gerakan kasbon tim: kasbon diberikan, pelunasan/potongan termin (buku kasbon), dan potongan dari gaji mingguan (jalur lama), urut waktu. */
    public function kasbonTim(string $projectId, string $workerId): array {
        $q = $this->db->prepare('SELECT id, tgl, jumlah, ket, ref, created_at FROM kasbon WHERE project_id = ? AND worker_id = ?'); $q->execute([$projectId, $workerId]);
        $rows = array_map(fn($r) => self::num($r, ['jumlah']), $q->fetchAll());
        $a = $this->db->prepare("SELECT x.id, pp.tgl_akhir AS tgl, x.jumlah, pp.tgl_mulai, pp.tgl_akhir, x.created_at FROM payroll_adjustments x JOIN payroll_periods pp ON pp.id = x.period_id
                                  WHERE pp.project_id = ? AND x.worker_id = ? AND x.kategori = 'kasbon' AND x.jenis = 'potong'");
        $a->execute([$projectId, $workerId]);
        foreach ($a->fetchAll() as $r) $rows[] = ['id' => $r['id'], 'tgl' => $r['tgl'], 'jumlah' => -(float)$r['jumlah'], 'ket' => 'Potong dari gaji minggu ' . $r['tgl_mulai'] . ' s/d ' . $r['tgl_akhir'], 'ref' => 'gaji', 'created_at' => $r['created_at']];
        usort($rows, fn($x, $y) => [$x['tgl'], $x['created_at'], $x['id']] <=> [$y['tgl'], $y['created_at'], $y['id']]);
        return array_map(function ($r) { unset($r['created_at']); return $r; }, $rows);
    }
    public function namaUser(array $ids): array {
        $ids = array_values(array_filter(array_unique($ids)));
        if (!$ids) return [];
        $q = $this->db->prepare('SELECT id, nama FROM users WHERE id IN (' . self::in($ids) . ')'); $q->execute($ids);
        $o = []; foreach ($q->fetchAll() as $r) $o[$r['id']] = $r['nama'];
        return $o;
    }
}
