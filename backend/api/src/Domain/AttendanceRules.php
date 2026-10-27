<?php
declare(strict_types=1);
namespace App\Domain;

/** Aturan absen harian (murni): jendela waktu lapangan, nilai hari/lembur yang sah, deteksi bentrok versi, ringkasan perubahan. */
final class AttendanceRules {
    /** Lapangan: tidak boleh tanggal depan, dan hanya sampai 7 hari ke belakang. */
    public static function periksaTanggalLapangan(string $tgl, string $hariIni, string $batasAwal): void {
        if ($tgl > $hariIni) throw new DomainError('Absen tidak bisa diisi untuk tanggal yang belum tiba.', 422);
        if ($tgl < $batasAwal) throw new DomainError('Absen lebih dari 7 hari lalu hanya bisa diubah Admin Pusat.', 403);
    }

    public static function periksaStatusPeriode(string $status, bool $lapangan): void {
        if ($status === 'dibayar') throw new DomainError('Periode ini sudah dibayar dan terkunci. Koreksi dibuat sebagai penyesuaian di periode berikutnya.', 409);
        if ($lapangan && $status !== 'draft') throw new DomainError('Absen minggu ini sudah diajukan. Hubungi Admin Pusat untuk koreksi.', 409);
    }

    /** @return array{0:float,1:float} [hari, lembur] */
    public static function periksaNilai(mixed $hari, mixed $lembur): array {
        $hari = (float)$hari; $lembur = (float)($lembur ?? 0);
        if (!in_array($hari, [0.0, 0.5, 1.0], true)) throw new DomainError('Kehadiran harus 0, ½, atau 1 hari.', 422);
        if ($lembur < 0 || $lembur > 16 || fmod($lembur * 2, 1.0) != 0.0) throw new DomainError('Jam lembur 0–16 dalam kelipatan 0,5 jam.', 422);
        if ($hari == 0.0 && $lembur > 0) throw new DomainError('Lembur hanya untuk pekerja yang hadir.', 422);
        return [$hari, $lembur];
    }

    /**
     * Pemeriksaan optimistis: klien menyebut versi (rev) yang dilihatnya (null = belum ada baris). Bila orang lain sudah menyimpan di antaranya, ditolak
     * — kecuali nilai tersimpan sudah persis sama dengan yang dikirim (kirim ulang tidak berbahaya).
     * @param ?array $cur ['v'=>[hari,lembur], 'rev'=>int] atau null  @return string 'bentrok' | 'lewati' | 'simpan'
     */
    public static function periksaVersi(?array $cur, array $entri, bool $punyaHari): string {
        if (!array_key_exists('ver', $entri)) return 'simpan';
        $ver = $entri['ver'] === null ? null : (int)$entri['ver'];
        $sama = $cur !== null && $punyaHari && $cur['v'] === [(float)$entri['hari'], (float)($entri['lembur_jam'] ?? 0)];
        $hilang = $cur === null && !$punyaHari;
        if (($cur === null ? null : $cur['rev']) !== $ver && !$sama && !$hilang) return 'bentrok';
        return ($sama || $hilang) ? 'lewati' : 'simpan';
    }

    public static function format(?array $v): string {
        if ($v === null) return 'kosong';
        $n = fn(float $x) => rtrim(rtrim(number_format($x, 1, ',', ''), '0'), ',');
        return $n($v[0]) . 'h+' . $n($v[1]) . 'j';
    }

    public static function pesanBentrok(array $nama): string {
        return 'Absen ini baru saja diubah orang lain (' . implode(', ', array_slice($nama, 0, 5)) . (count($nama) > 5 ? ' dll' : '') . '). Layar dimuat ulang supaya tidak saling menimpa.';
    }

    /** Teks audit: "tgl: n pekerja | k perubahan: …". */
    public static function ringkasAudit(string $tgl, int $disimpan, array $perubahan, string $statusPeriode): string {
        $d = "$tgl: $disimpan pekerja";
        if ($perubahan) {
            $d .= ' | ' . count($perubahan) . ' perubahan: ' . implode('; ', array_slice($perubahan, 0, 8)) . (count($perubahan) > 8 ? '; +' . (count($perubahan) - 8) . ' lainnya' : '');
            if ($statusPeriode !== 'draft') $d .= ' | SETELAH DIAJUKAN (' . $statusPeriode . ')';
        }
        return $d;
    }
}
