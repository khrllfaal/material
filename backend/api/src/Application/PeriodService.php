<?php
declare(strict_types=1);
namespace App\Application;

use App\Domain\DomainError;
use App\Domain\PeriodRules;
use App\Infrastructure\PeriodRepository;

/**
 * Siklus periode gaji: draft -> diajukan -> disetujui -> dibayar. Tiap aksi = satu use case:
 * kunci baris periode (urutan tunggal untuk permintaan bersamaan) -> periksa aturan -> ubah -> catat audit.
 * Peran: lapangan mengajukan; Akuntan (juga atas nama lapangan) mengajukan, menyetujui, mengembalikan, membayar; Admin Pusat tidak menyetujui/membayar.
 */
final class PeriodService {
    /** @param callable $audit fn(string $aksi, string $tabel, string $id, string $ket) */
    public function __construct(private PeriodRepository $repo, private PayrollService $payroll, private KasbonService $kasbon, private $audit) {}

    private static function potong(mixed $v, int $n): string { return mb_substr(trim((string)$v), 0, $n); }
    private static function peran(array $user, array $boleh, string $pesan = 'Anda tidak punya akses untuk aksi ini.'): void {
        if (!in_array($user['role'], $boleh, true)) throw new DomainError($pesan, 403);
    }
    private function catat(string $aksi, string $tabel, string $id, string $ket): void { ($this->audit)($aksi, $tabel, $id, $ket); }

    public function header(array $user, string $projectId, string $tgl, array $b): array {
        $p = $this->repo->transaksi(function () use ($user, $projectId, $tgl, $b) {
            $p = $this->repo->kunci($projectId, $tgl);
            if ($p['status'] === 'dibayar') throw new DomainError('Periode sudah dibayar dan terkunci.', 409);
            if ($user['role'] === 'lapangan' && $p['status'] !== 'draft') throw new DomainError('Minggu ini sudah diajukan.', 409);
            $this->repo->ubahHeader($p['id'], self::potong($b['pj_nama'] ?? '', 120), self::potong($b['grup_nama'] ?? '', 120), self::potong($b['catatan'] ?? '', 255));
            return $p;
        });
        $this->catat('update', 'payroll_periods', $p['id'], 'header');
        return ['ok' => true];
    }

    public function ajukan(array $user, string $projectId, string $tgl, array $b): array {
        self::peran($user, ['lapangan', 'akuntan']);
        [$p, $catatan] = $this->repo->transaksi(function () use ($user, $projectId, $tgl, $b) {
            $p = $this->repo->kunci($projectId, $tgl);
            PeriodRules::pastikanBisaDiajukan($p['status']);
            // Minggu yang hanya berisi nominal borongan (tanpa absen harian) juga sah.
            if ($this->repo->jumlahAbsen($projectId, $p['tgl_mulai'], $p['tgl_akhir']) === 0 && $this->repo->jumlahPenyesuaian($p['id']) === 0)
                throw new DomainError('Belum ada absen atau tambahan (mis. nominal borongan) pada minggu ini.', 422);
            // Diajukan sebelum minggu berakhir: periode berakhir di hari kerja terakhir; hari sisanya membentuk periode susulan.
            $akhir = PeriodRules::akhirDipotong($this->repo->hariAbsenTerakhir($projectId, $p['tgl_mulai'], $p['tgl_akhir']), $p['tgl_akhir'], date('Y-m-d'));
            if ($akhir !== null) { $this->repo->potongAkhir($p['id'], $akhir); $p['tgl_akhir'] = $akhir; }
            $pj = self::potong($b['pj_nama'] ?? $p['pj_nama'], 120);
            if ($pj === '') throw new DomainError('Nama penanggung jawab wajib diisi.', 422);
            $catatan = array_key_exists('catatan', $b) ? self::potong($b['catatan'], 255) : '';
            $this->repo->tandaiDiajukan($p['id'], $pj, self::potong($b['grup_nama'] ?? $p['grup_nama'], 120), $catatan, $user['id']);
            return [$p, $catatan];
        });
        $this->catat('update', 'payroll_periods', $p['id'], 'diajukan' . ($catatan !== '' ? ': ' . $catatan : ''));
        return ['ok' => true, 'status' => 'diajukan'];
    }

    public function setujui(array $user, string $projectId, string $tgl): array {
        self::peran($user, ['akuntan'], 'Hanya Akuntan yang memeriksa dan menyetujui absen untuk dibayar.');
        [$p, $plan, $n] = $this->repo->transaksi(function () use ($user, $projectId, $tgl) {
            $p = $this->repo->kunci($projectId, $tgl);
            PeriodRules::pastikanBisaDisetujui($p['status']);
            $tanpaTarif = $this->repo->pekerjaTanpaTarif($projectId, $p['tgl_mulai'], $p['tgl_akhir']);
            if ($tanpaTarif) throw new DomainError('Tarif belum diisi untuk: ' . implode(', ', $tanpaTarif) . '. Isi dulu di Pekerja & Tarif.', 409);
            $minus = [];
            foreach ($this->payroll->hitung([$projectId], $p['tgl_mulai'], $p['tgl_akhir'])['rows'] as $rw)
                if ($rw['net'] + $this->repo->kasbonOtomatisPekerja($p['id'], $rw['worker_id']) < -0.005) $minus[] = $rw['nama'];
            if ($minus) throw new DomainError('Gaji minus (potongan lebih besar dari gaji) untuk: ' . implode(', ', array_slice($minus, 0, 5)) . '. Perbaiki potongan dulu.', 409);
            // Potong kasbon otomatis dihitung ulang dari saldo sebenarnya saat persetujuan (baris otomatis lama diganti).
            $this->repo->hapusKasbonOtomatis($p['id']);
            $plan = $this->kasbon->rencanaPotong($projectId, $p);
            $n = 0;
            foreach ($plan['items'] as $it) if ($it['potong'] > 0.005) { $this->repo->tambahKasbonOtomatis($p['id'], $it['worker_id'], $it['tim'], $it['potong'], $user['id']); $n++; }
            $this->repo->tandaiDisetujui($p['id'], $user['id']);
            return [$p, $plan, $n];
        });
        if ($n) $this->catat('create', 'payroll_adjustments', $p['id'], "potong kasbon otomatis: $n orang, total {$plan['total']}");
        $this->catat('update', 'payroll_periods', $p['id'], 'disetujui');
        return ['ok' => true, 'status' => 'disetujui', 'potong_kasbon' => $plan['total'], 'orang' => $n];
    }

    public function kembalikan(array $user, string $projectId, string $tgl, array $b): array {
        self::peran($user, ['akuntan'], 'Hanya Akuntan yang bisa mengembalikan pengajuan ke lapangan.');
        $alasan = self::potong($b['alasan'] ?? '', 200);
        if ($alasan === '') throw new DomainError('Alasan wajib diisi.', 422);
        $p = $this->repo->transaksi(function () use ($projectId, $tgl, $alasan) {
            $p = $this->repo->kunci($projectId, $tgl);
            PeriodRules::pastikanBisaDikembalikan($p['status']);
            if ($this->repo->jumlahPembayaran($p['id']) > 0) throw new DomainError('Sudah ada pembayaran pada periode ini, tidak bisa dikembalikan. Buat penyesuaian di periode berikutnya.', 409);
            $this->repo->hapusKasbonOtomatis($p['id']);
            $this->repo->tandaiDraft($p['id'], self::potong('Dikembalikan: ' . $alasan, 255));
            return $p;
        });
        $this->catat('update', 'payroll_periods', $p['id'], "dikembalikan ke lapangan: $alasan");
        return ['ok' => true, 'status' => 'draft'];
    }

    public function bayar(array $user, string $projectId, string $tgl, array $b, string $tglBayar): array {
        self::peran($user, ['akuntan'], 'Hanya Akuntan yang bisa mencatat pembayaran.');
        $metode = (($b['metode'] ?? '') === 'transfer') ? 'transfer' : 'tunai';
        [$pid, $total, $n, $open] = $this->repo->transaksi(function () use ($user, $projectId, $tgl, $b, $tglBayar, $metode) {
            $p = $this->repo->kunci($projectId, $tgl);
            $pid = $p['id'];
            PeriodRules::pastikanBisaDibayar($p['status']);
            $res = $this->payroll->hitung([$projectId], $p['tgl_mulai'], $p['tgl_akhir']);
            $thisP = null;
            foreach ($res['periods'] as $x) if ($x['id'] === $pid) $thisP = $x;
            $sisaW = []; foreach ($res['rows'] as $r) $sisaW[$r['worker_id']] = $r['sisa'];
            $groupOut = $thisP ? ($thisP['grup_net'] - $thisP['grup_dibayar']) : 0.0;

            $items = [];
            if (!empty($b['semua'])) {
                foreach ($sisaW as $wid => $s) if ($s > 0.004) $items[] = ['worker_id' => $wid, 'jumlah' => round($s, 2)];
                if (abs($groupOut) > 0.004) $items[] = ['worker_id' => null, 'jumlah' => round($groupOut, 2)];
            } else {
                foreach ((array)($b['items'] ?? []) as $it) $items[] = ['worker_id' => !empty($it['worker_id']) ? (string)$it['worker_id'] : null, 'jumlah' => round((float)($it['jumlah'] ?? 0), 2)];
            }
            if (!$items) throw new DomainError('Tidak ada pembayaran yang perlu dicatat.', 422);

            $total = 0.0; $seen = [];
            foreach ($items as $it) {
                $key = $it['worker_id'] ?? '_grup';
                if (isset($seen[$key])) throw new DomainError('Satu pekerja hanya boleh muncul sekali dalam satu pembayaran.', 422);
                $seen[$key] = 1;
                if ($it['worker_id'] === null) {
                    // baris grup tidak boleh melebihi yang terutang (jumlah negatif = potongan yang ditahan)
                    if (abs($it['jumlah']) > abs($groupOut) + 0.005) throw new DomainError('Jumlah penyesuaian grup melebihi yang harus dibayar.', 422);
                } else {
                    if (!isset($sisaW[$it['worker_id']])) throw new DomainError('Pekerja tidak termasuk dalam periode ini.', 422);
                    if ($it['jumlah'] <= 0 || $it['jumlah'] > $sisaW[$it['worker_id']] + 0.005) throw new DomainError('Jumlah bayar melebihi sisa gaji pekerja.', 422);
                }
                $this->repo->catatPembayaran($pid, $it['worker_id'], $it['jumlah'], $tglBayar, $metode, self::potong($b['ket'] ?? '', 255), $user['id']);
                $total += $it['jumlah'];
            }
            // ditutup begitu tidak ada yang tersisa
            $after = $this->payroll->hitung([$projectId], $p['tgl_mulai'], $p['tgl_akhir']);
            $grupSisa = 0.0;
            foreach ($after['periods'] as $x) if ($x['id'] === $pid) $grupSisa = $x['grup_net'] - $x['grup_dibayar'];
            $open = PeriodRules::terbuka($after['totals']['sisa'], $grupSisa);
            if (!$open) $this->repo->tandaiDibayar($pid, $user['id']);
            return [$pid, $total, count($items), $open];
        });
        $this->catat('update', 'payroll_payments', $pid, sprintf('%d baris, total %s, %s%s', $n, $total, $metode, $open ? '' : ' — LUNAS'));
        return ['ok' => true, 'dibayar' => $total, 'lunas' => !$open, 'status' => $open ? 'disetujui' : 'dibayar'];
    }
}
