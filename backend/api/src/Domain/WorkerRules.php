<?php
declare(strict_types=1);
namespace App\Domain;

/** Aturan data pekerja (murni): penulisan nama/tim, validasi isian, deteksi pekerja ganda. */
final class WorkerRules {
    /** Nama dibandingkan tanpa beda huruf besar/kecil dan spasi berlebih. */
    public static function normal(string $s): string { return mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $s))); }

    public static function rapikanNama(mixed $s): string { return trim((string)preg_replace('/\s+/u', ' ', (string)$s)); }

    /** @param array<string,string> $grades kode => nama */
    public static function periksaIsian(string $projectId, string $nama, string $grade, string $tim, array $grades): void {
        if ($projectId === '') throw new DomainError('Proyek wajib dipilih.', 422);
        if ($nama === '' || mb_strlen($nama) > 120) throw new DomainError('Nama pekerja wajib diisi (maksimal 120 karakter).', 422);
        if (!isset($grades[$grade])) throw new DomainError('Status pekerja tidak valid.', 422);
        if (mb_strlen($tim) > 80) throw new DomainError('Nama tim terlalu panjang.', 422);
    }

    /** "tim a" / "Tim A " tidak boleh menjadi tim kedua: pakai ejaan yang sudah ada di proyek ini. */
    public static function samakanEjaanTim(string $tim, array $timAda): string {
        foreach ($timAda as $t0) if (self::normal($t0) === self::normal($tim)) return $t0;
        return $tim;
    }

    /** Pekerja tidak aktif dengan nama sama (untuk menawarkan "aktifkan kembali"). @return ?array baris atau null */
    public static function cariNonaktifSama(array $nonaktif, string $nama): ?array {
        foreach ($nonaktif as $n0) if (self::normal($n0['nama']) === self::normal($nama)) return $n0;
        return null;
    }

    /** Nama + status + tim sama dalam satu proyek = hampir pasti dobel. */
    public static function adaDuplikat(array $sesama, string $nama, string $tim): bool {
        foreach ($sesama as $d) if (self::normal($d['nama']) === self::normal($nama) && self::normal($d['tim']) === self::normal($tim)) return true;
        return false;
    }

    public static function pesanDuplikat(): string {
        return 'Pekerja bernama sama (status dan tim sama) sudah ada di proyek ini. Pilih dari daftar, atau beri tambahan nama agar berbeda (mis. "Endang B").';
    }
}
