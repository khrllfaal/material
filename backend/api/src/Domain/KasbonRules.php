<?php
declare(strict_types=1);
namespace App\Domain;

/** Aturan kasbon (murni): kunci nama, validasi baris pengajuan, batas pengajuan terbuka, syarat pelunasan & penghapusan. */
final class KasbonRules {
    public const MAKS_BARIS = 20;
    public const MAKS_TERBUKA = 60;
    public const MAKS_JUMLAH = 1e9;

    public static function kunciNama(?string $workerId, string $tim): string { return $workerId ?: ($tim !== '' ? 't:' . $tim : 'g'); }

    public static function periksaJumlahPengajuan(float $jumlah): void {
        if ($jumlah <= 0) throw new DomainError('Jumlah kasbon harus lebih dari 0.', 422);
        if ($jumlah > self::MAKS_JUMLAH) throw new DomainError('Jumlah terlalu besar.', 422);
    }
    public static function periksaIdPengajuan(string $id): void {
        if (!preg_match('/^ksr_[a-z0-9]{6,30}$/', $id)) throw new DomainError('id tidak valid.', 422);
    }
    public static function idBatch(?string $diminta): ?string { return ($diminta !== null && $diminta !== '' && preg_match('/^bat_[a-z0-9]{6,30}$/', $diminta)) ? $diminta : null; }

    public static function periksaBatasTerbuka(int $terbuka, int $baru): void {
        if ($terbuka + $baru > self::MAKS_TERBUKA) throw new DomainError('Terlalu banyak pengajuan yang belum diperiksa. Hubungi Akuntan.', 429);
    }
    public static function namaPihak(?string $workerId, string $tim): string { return $workerId ? 'pekerja ini' : ($tim !== '' ? 'Tim ' . $tim : 'grup'); }

    public static function periksaPelunasan(float $jumlah, float $saldo): void {
        if ($jumlah > $saldo + 0.005) throw new DomainError('Pelunasan melebihi saldo kasbon (' . number_format(max(0, $saldo), 0, ',', '.') . ').', 422);
    }
    /** Kasbon (bukan pelunasan) yang sebagian/seluruhnya sudah terpotong dari gaji tidak boleh dihapus. */
    public static function periksaBisaDihapus(float $jumlah, float $saldoSetelahDihapus): void {
        if ($jumlah > 0 && $saldoSetelahDihapus < -0.005) throw new DomainError('Kasbon ini sudah dipotong dari gaji, jadi tidak bisa dihapus.', 409);
    }
}
