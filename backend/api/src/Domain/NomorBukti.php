<?php
declare(strict_types=1);
namespace App\Domain;

/** Nomor dokumen berurut per bulan, mis. BP/2026/10/0007 (BP = Bukti Pembayaran, BR = Borongan). Urutan nol-berpadding supaya urut abjad = urut angka. */
final class NomorBukti {
    public static function bulan(string $tgl): string { return substr($tgl, 0, 7); } // "2026-10"

    public static function format(string $kode, string $tgl, int $urut): string {
        if ($urut < 1) throw new DomainError('Nomor urut tidak valid.', 500);
        return sprintf('%s/%s/%s/%04d', $kode, substr($tgl, 0, 4), substr($tgl, 5, 2), $urut);
    }

    /** @return ?array{kode:string, tahun:string, bulan:string, urut:int} */
    public static function urai(string $no): ?array {
        if (!preg_match('~^([A-Z]{2,4})/(\d{4})/(\d{2})/(\d{4,})$~', $no, $m)) return null;
        return ['kode' => $m[1], 'tahun' => $m[2], 'bulan' => $m[3], 'urut' => (int)$m[4]];
    }

    /** Apakah $a diterbitkan sebelum $b (urutan penerbitan)? Nomor tak berformat (data lama) dianggap paling awal. */
    public static function lebihAwal(?string $a, ?string $b): bool {
        $pa = $a === null ? null : self::urai($a); $pb = $b === null ? null : self::urai($b);
        if ($pb === null) return false;
        if ($pa === null) return true;
        return [$pa['tahun'], $pa['bulan'], $pa['urut']] < [$pb['tahun'], $pb['bulan'], $pb['urut']];
    }
}
