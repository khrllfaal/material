<?php
declare(strict_types=1);
namespace App\Domain;

/** Aturan siklus periode gaji (murni): minggu Jumat–Kamis, pemotongan periode saat diajukan lebih awal, dan alur status. */
final class PeriodRules {
    /** Jumat–Kamis yang memuat tanggal itu. @return array{0:string,1:string} */
    public static function minggu(string $tgl): array {
        $t = strtotime($tgl . ' 12:00:00');
        $daysSinceFriday = ((int)date('N', $t) + 2) % 7; // N: Sen=1..Min=7; Jumat=5 -> 0
        $start = strtotime("-$daysSinceFriday days", $t);
        return [date('Y-m-d', $start), date('Y-m-d', strtotime('+6 days', $start))];
    }

    /** Diajukan sebelum minggu berakhir (mis. proyek berhenti di hari ke-4): periode berakhir di hari kerja terakhir. Null = tidak dipotong. */
    public static function akhirDipotong(string $lastDay, string $tglAkhir, string $hariIni): ?string {
        return ($lastDay !== '' && $lastDay < $tglAkhir && $hariIni < $tglAkhir) ? $lastDay : null;
    }

    /** Periode lunas bila tidak ada sisa dan tidak ada potongan grup yang masih ditahan. */
    public static function terbuka(float $sisa, float $grupNetBelumDibayar): bool { return $sisa > 0.005 || $grupNetBelumDibayar < -0.005; }

    public static function pastikanBisaDiajukan(string $status): void {
        if ($status !== 'draft') throw new DomainError('Minggu ini sudah diajukan.', 409);
    }
    public static function pastikanBisaDisetujui(string $status): void {
        if ($status !== 'diajukan') throw new DomainError($status === 'draft' ? 'Periode belum diajukan oleh lapangan.' : 'Periode ini sudah disetujui.', 409);
    }
    public static function pastikanBisaDibayar(string $status): void {
        if ($status === 'draft') throw new DomainError('Periode belum diajukan oleh lapangan.', 409);
        if ($status === 'diajukan') throw new DomainError('Periode belum diperiksa dan disetujui. Setujui dulu.', 409);
    }
    public static function pastikanBisaDikembalikan(string $status): void {
        if (!in_array($status, ['diajukan', 'disetujui'], true)) throw new DomainError('Hanya pengajuan yang belum dibayar yang bisa dikembalikan.', 409);
    }
}
