<?php
declare(strict_types=1);
namespace App\Application;

use App\Domain\{AttendanceRules, DomainError};
use App\Infrastructure\{AttendanceRepository, PeriodRepository, Tx};

/**
 * Absensi harian. Satu POST menyimpan satu proyek + satu tanggal untuk banyak pekerja (upsert, jadi kirim ulang tidak menggandakan).
 * Lapangan: proyek sendiri, hari ini s/d 7 hari lalu, selama minggu itu masih draft, tanpa melihat tarif. Admin Pusat: sampai besok, sampai periode dibayar.
 */
final class AttendanceService {
    /** @param callable $tarif fn(array $pekerja): array{0:float,1:float}  @param callable $audit  @param callable $siapkanPeriode fn(string $proyek, string $tgl)
     *  @param \PDO $db hanya untuk transaksi */
    public function __construct(private \PDO $db, private AttendanceRepository $repo, private PeriodRepository $periods, private $tarif, private $audit, private $siapkanPeriode) {}

    public function daftar(?array $scope, array $filter, bool $lapangan): array {
        if ($scope !== null && !$scope) return [];
        return array_map(function ($r) {
            $r['hari'] = (float)$r['hari']; $r['lembur_jam'] = (float)$r['lembur_jam']; $r['rev'] = (int)$r['rev'];
            if (isset($r['tarif_harian'])) { $r['tarif_harian'] = (float)$r['tarif_harian']; $r['tarif_lembur'] = (float)$r['tarif_lembur']; }
            return $r;
        }, $this->repo->daftar($scope, $filter, $lapangan));
    }

    /** @param array $user sesi; $tgl sudah divalidasi formatnya oleh lapisan HTTP */
    public function simpan(array $user, string $projectId, string $tgl, array $entries): array {
        $lapangan = $user['role'] === 'lapangan';
        if (!$entries) throw new DomainError('Tidak ada data absen untuk disimpan.', 422);
        if (count($entries) > 400) throw new DomainError('Terlalu banyak pekerja dalam satu kiriman.', 422);
        if ($lapangan) AttendanceRules::periksaTanggalLapangan($tgl, date('Y-m-d'), date('Y-m-d', strtotime('-7 days')));
        ($this->siapkanPeriode)($projectId, $tgl); // baris periode minggu itu ada — dicari SEBELUM transaksi

        $wMap = $this->repo->pekerjaByIds(array_values(array_unique(array_map(fn($e) => (string)($e['worker_id'] ?? ''), $entries))));

        // Banyak HP menyimpan bersamaan bisa membuat database memilih satu transaksi sebagai korban deadlock; belum ada yang tertulis, jadi diulang (maks 4x).
        [$saved, $changes, $period] = Tx::jalankan($this->db, function () use ($user, $projectId, $tgl, $entries, $wMap, $lapangan) {
            // Baris periode dikunci selama penyimpanan: tak ada yang bisa menyetujui/membayar di tengah jalan, dan dua simpanan minggu yang sama berurutan.
            $period = $this->periods->kunci($projectId, $tgl);
            AttendanceRules::periksaStatusPeriode($period['status'], $lapangan);
            $before = $this->repo->keadaanHari($projectId, $tgl);
            $saved = 0; $changes = []; $conflicts = [];
            foreach ($entries as $e) {
                $wid = (string)($e['worker_id'] ?? '');
                $w = $wMap[$wid] ?? null;
                if (!$w) throw new DomainError('Pekerja tidak ditemukan. Muat ulang halaman.', 422);
                if ($w['project_id'] !== $projectId && $lapangan) throw new DomainError('Pekerja bukan bagian dari proyek ini.', 403);
                $cur = $before[$wid] ?? null;
                $hasHari = array_key_exists('hari', $e) && $e['hari'] !== null;
                $v = AttendanceRules::periksaVersi($cur, $e, $hasHari);
                if ($v === 'bentrok') { $conflicts[] = $w['nama']; continue; }
                if ($v === 'lewati') { $saved++; continue; }
                if (!$hasHari) { // kosongkan isian
                    if ($cur) $changes[] = $w['nama'] . ': ' . AttendanceRules::format($cur['v']) . ' -> kosong';
                    $this->repo->hapus($wid, $projectId, $tgl);
                    $saved++;
                    continue;
                }
                [$hari, $lembur] = AttendanceRules::periksaNilai($e['hari'], $e['lembur_jam'] ?? 0);
                $ket = mb_substr(trim((string)($e['ket'] ?? '')), 0, 255);
                $now = [$hari, $lembur];
                if ($cur === null || $cur['v'] !== $now) $changes[] = $w['nama'] . ': ' . ($cur ? AttendanceRules::format($cur['v']) : 'baru') . ' -> ' . AttendanceRules::format($now);
                [$harian, $lemburRate] = ($this->tarif)($w);
                $this->repo->simpan($wid, $projectId, $tgl, $hari, $lembur, $w['grade'], $w['tim'], $harian, $lemburRate, $ket, $user['id']);
                $saved++;
            }
            if ($conflicts) throw new DomainError(AttendanceRules::pesanBentrok($conflicts), 409);
            // Minggu yang sudah disetujui lalu diubah kehilangan persetujuannya: harus diperiksa ulang.
            if ($period['status'] === 'disetujui' && $changes) $this->repo->cabutPersetujuan($period['id']);
            return [$saved, $changes, $period];
        }, 4);

        ($this->audit)('update', 'attendance', $projectId . '/' . $tgl, AttendanceRules::ringkasAudit($tgl, $saved, $changes, $period['status']));
        // Orang yang sama tercatat di dua proyek pada hari ini dengan total >1 hari: beri tahu yang menyimpan (nama saja).
        $keys = [];
        foreach ($entries as $e) {
            $w = $wMap[(string)($e['worker_id'] ?? '')] ?? null;
            if ($w && array_key_exists('hari', $e) && $e['hari'] !== null && (float)$e['hari'] > 0) $keys[($w['induk_id'] ?? null) ?: $w['id']] = true;
        }
        return ['ok' => true, 'saved' => $saved, 'revs' => $this->repo->revisiHari($projectId, $tgl),
                'periode_status' => ($period['status'] === 'disetujui' && $changes) ? 'diajukan' : $period['status'],
                'peringatan_ganda' => $keys ? $this->repo->namaGanda($tgl, array_keys($keys)) : []];
    }
}
