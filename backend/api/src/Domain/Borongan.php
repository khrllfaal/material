<?php
declare(strict_types=1);
namespace App\Domain;

/**
 * Aturan kontrak borongan (murni, tanpa database). Satu kontrak = satu pekerjaan untuk satu tim: nilai, addendum (tambah/kurang), termin pembayaran,
 * retensi yang ditahan, dan potong kasbon tim. Semua uang dibulatkan 2 desimal.
 *
 * Istilah:
 *   nilai_kini      nilai awal + addendum yang disetujui
 *   ditagih         jumlah bruto termin yang sudah disetujui/dibayar (bukan pelepasan retensi)
 *   belum_ditagih   nilai_kini - bruto semua termin yang masih berjalan (diajukan + disetujui + dibayar), supaya tidak ditagih melebihi nilai
 *   retensi_ditahan retensi yang dipotong dari termin disetujui/dibayar - pelepasan retensi disetujui/dibayar
 *   hutang_saldo    kewajiban kepada tim yang tersisa = nilai_kini - jumlah (bersih + kasbon + denda) dari termin yang SUDAH DIBAYAR
 * Satu termin: bruto = retensi + denda + potong_kasbon + bersih (bersih = uang yang diterima tim).
 */
final class Borongan {
    public const AKTIF = ['diajukan', 'disetujui', 'dibayar'];

    private static function r(float $v): float { return round($v, 2); }

    public static function ringkas(array $kontrak, array $addenda, array $termin): array {
        $nilai = (float)$kontrak['nilai'];
        foreach ($addenda as $a) if ($a['status'] === 'disetujui') $nilai += (float)$a['jumlah'];
        $committed = $ditagih = $retensiTahan = $lepasPending = $bersih = $kasbon = $denda = $dibayarBruto = 0.0; $pending = 0;
        foreach ($termin as $t) {
            $bruto = (float)$t['bruto']; $lepas = $t['jenis'] === 'lepas_retensi';
            if (!in_array($t['status'], self::AKTIF, true)) continue;
            if (in_array($t['status'], ['diajukan', 'disetujui'], true)) $pending++;
            if (!$lepas) {
                $committed += $bruto;
                if ($t['status'] !== 'diajukan') { $ditagih += $bruto; $retensiTahan += (float)$t['retensi']; }
            } else {
                if ($t['status'] === 'diajukan') $lepasPending += $bruto; else $retensiTahan -= $bruto;
            }
            if ($t['status'] === 'dibayar') { $bersih += (float)$t['bersih']; $kasbon += (float)$t['potong_kasbon']; $denda += (float)$t['denda']; $dibayarBruto += $lepas ? 0.0 : $bruto; }
        }
        return [
            'nilai_awal' => self::r((float)$kontrak['nilai']), 'nilai_kini' => self::r($nilai), 'ditagih' => self::r($ditagih), 'belum_ditagih' => self::r($nilai - $committed),
            'retensi_ditahan' => self::r($retensiTahan), 'pelepasan_diajukan' => self::r($lepasPending),
            'dibayar_bersih' => self::r($bersih), 'kasbon_dipotong' => self::r($kasbon), 'denda' => self::r($denda),
            'hutang_saldo' => self::r($nilai - $bersih - $kasbon - $denda), 'termin_berjalan' => $pending,
        ];
    }

    /** Hitung pemecahan satu termin. @return array{retensi:float, potong_kasbon:float, bersih:float} */
    public static function hitungTermin(string $jenis, float $bruto, float $retensiPersen, float $denda, float $saldoKasbon, bool $potongKasbon): array {
        if ($bruto <= 0) throw new DomainError('Jumlah termin harus lebih dari 0.', 422);
        if ($denda < 0) throw new DomainError('Denda tidak boleh negatif.', 422);
        $retensi = $jenis === 'termin' ? self::r($bruto * $retensiPersen / 100) : 0.0;
        $dasar = $bruto - $retensi - $denda;
        if ($dasar < -0.005) throw new DomainError('Retensi dan denda melebihi jumlah termin.', 422);
        $dasar = max(0.0, $dasar);
        $kasbon = ($potongKasbon && $saldoKasbon > 0.005) ? self::r(min($saldoKasbon, $dasar)) : 0.0;
        return ['retensi' => $retensi, 'potong_kasbon' => $kasbon, 'bersih' => self::r($dasar - $kasbon)];
    }

    public static function periksaBisaTermin(array $kontrak, array $ringkas, string $jenis, float $bruto): void {
        if ($bruto <= 0) throw new DomainError('Jumlah termin harus lebih dari 0.', 422);
        if ($jenis === 'lepas_retensi') {
            if (!in_array($kontrak['status'], ['disetujui', 'selesai'], true)) throw new DomainError('Retensi hanya bisa dilepas pada kontrak yang berjalan atau selesai.', 409);
            if ($bruto > $ringkas['retensi_ditahan'] - $ringkas['pelepasan_diajukan'] + 0.005) throw new DomainError('Pelepasan retensi melebihi retensi yang masih ditahan (' . self::fmt($ringkas['retensi_ditahan'] - $ringkas['pelepasan_diajukan']) . ').', 422);
            return;
        }
        if ($kontrak['status'] !== 'disetujui') throw new DomainError($kontrak['status'] === 'selesai' ? 'Kontrak ini sudah selesai.' : 'Termin hanya bisa dibuat pada kontrak yang sudah disetujui dan berjalan.', 409);
        if ($bruto > $ringkas['belum_ditagih'] + 0.005) throw new DomainError('Termin melebihi sisa nilai kontrak yang belum ditagih (' . self::fmt($ringkas['belum_ditagih']) . ').', 422);
    }

    public static function periksaBisaAddendum(array $kontrak, array $ringkas, float $jumlah): void {
        if (abs($jumlah) < 0.005) throw new DomainError('Jumlah addendum tidak boleh nol.', 422);
        if (!in_array($kontrak['status'], ['disetujui'], true)) throw new DomainError('Addendum hanya untuk kontrak yang berjalan.', 409);
        if ($jumlah < 0 && $ringkas['nilai_kini'] + $jumlah < $ringkas['nilai_kini'] - $ringkas['belum_ditagih'] - 0.005)
            throw new DomainError('Pengurangan nilai melebihi sisa yang belum ditagih (' . self::fmt($ringkas['belum_ditagih']) . ').', 422);
    }

    /** Menutup kontrak: tidak boleh ada termin yang belum selesai. Sisa nilai yang belum ditagih harus dikurangi (addendum minus otomatis). @return float pengurangan nilai (>=0) */
    public static function periksaBisaSelesai(array $kontrak, array $ringkas, bool $kurangiNilai): float {
        if ($kontrak['status'] !== 'disetujui') throw new DomainError('Hanya kontrak yang berjalan yang bisa diselesaikan.', 409);
        if ($ringkas['termin_berjalan'] > 0) throw new DomainError('Masih ada termin yang belum dibayar atau ditolak. Selesaikan dulu.', 409);
        if ($ringkas['belum_ditagih'] > 0.005) {
            if (!$kurangiNilai) throw new DomainError('Nilai kontrak belum ditagih seluruhnya (sisa ' . self::fmt($ringkas['belum_ditagih']) . '). Tagih sisanya, atau pilih mengurangi nilai kontrak.', 409);
            return $ringkas['belum_ditagih'];
        }
        return 0.0;
    }

    public static function periksaBisaBatal(array $kontrak, array $termin): void {
        if ($kontrak['status'] === 'batal') throw new DomainError('Kontrak sudah dibatalkan.', 409);
        foreach ($termin as $t) if ($t['status'] === 'dibayar') throw new DomainError('Kontrak ini sudah punya pembayaran, jadi tidak bisa dibatalkan. Selesaikan dengan mengurangi nilai.', 409);
    }

    /**
     * Buku besar tim: urut waktu, saldo berjalan "kewajiban kepada tim". Hanya kejadian yang sudah berlaku (kontrak disetujui, addendum disetujui, termin dibayar).
     * @param array $kontrakList tiap elemen: kontrak + 'addenda' + 'termin'
     * @return array{entries:array, saldo:float}
     */
    public static function buku(array $kontrakList): array {
        $ev = []; $seq = 0;
        foreach ($kontrakList as $k) {
            if (!in_array($k['status'], ['disetujui', 'selesai'], true)) continue;
            $ev[] = ['tgl' => $k['tgl_mulai'], 'seq' => $seq++, 'jenis' => 'kontrak', 'ket' => 'Kontrak ' . $k['no_kontrak'] . ' · ' . $k['nama_pekerjaan'], 'tambah' => self::r((float)$k['nilai']), 'kurang' => 0.0, 'kontrak_id' => $k['id']];
            foreach ($k['addenda'] as $a) if ($a['status'] === 'disetujui') {
                $v = (float)$a['jumlah'];
                $ev[] = ['tgl' => $a['tgl'], 'seq' => $seq++, 'jenis' => 'addendum', 'ket' => 'Addendum ' . $k['no_kontrak'] . ($a['alasan'] !== '' ? ' · ' . $a['alasan'] : ''), 'tambah' => $v > 0 ? self::r($v) : 0.0, 'kurang' => $v < 0 ? self::r(-$v) : 0.0, 'kontrak_id' => $k['id']];
            }
            foreach ($k['termin'] as $t) if ($t['status'] === 'dibayar') {
                $lepas = $t['jenis'] === 'lepas_retensi';
                $nm = ($lepas ? 'Pelepasan retensi' : 'Termin ' . $t['no_termin']) . ' ' . $k['no_kontrak'];
                if ((float)$t['bersih'] > 0.004) $ev[] = ['tgl' => $t['tgl_bayar'], 'seq' => $seq++, 'jenis' => 'bayar', 'ket' => $nm . ' dibayar' . ($t['bukti_no'] ? ' (' . $t['bukti_no'] . ')' : ''), 'tambah' => 0.0, 'kurang' => self::r((float)$t['bersih']), 'kontrak_id' => $k['id']];
                if ((float)$t['potong_kasbon'] > 0.004) $ev[] = ['tgl' => $t['tgl_bayar'], 'seq' => $seq++, 'jenis' => 'kasbon', 'ket' => $nm . ' · potong kasbon', 'tambah' => 0.0, 'kurang' => self::r((float)$t['potong_kasbon']), 'kontrak_id' => $k['id']];
                if ((float)$t['denda'] > 0.004) $ev[] = ['tgl' => $t['tgl_bayar'], 'seq' => $seq++, 'jenis' => 'denda', 'ket' => $nm . ' · denda/potongan', 'tambah' => 0.0, 'kurang' => self::r((float)$t['denda']), 'kontrak_id' => $k['id']];
            }
        }
        usort($ev, fn($a, $b) => [$a['tgl'], $a['seq']] <=> [$b['tgl'], $b['seq']]);
        $saldo = 0.0;
        foreach ($ev as &$e) { $saldo = self::r($saldo + $e['tambah'] - $e['kurang']); $e['saldo'] = $saldo; unset($e['seq']); }
        unset($e);
        return ['entries' => $ev, 'saldo' => $saldo];
    }

    /**
     * Jurnal akuntansi (double-entry) dari kontrak borongan. Beban diakui saat termin disetujui (seperti gaji mingguan diakui saat periode disetujui);
     * kas keluar saat dibayar; kasbon yang dipotong mengurangi hutang dan piutang kasbon (bukan biaya).
     * @param array $kontrakList kontrak + 'termin' + 'project_nama'
     * @return array<int,array{tgl:string,ket:string,lines:array}> setiap kejadian seimbang (debit = kredit)
     */
    public static function jurnal(array $kontrakList): array {
        $ev = [];
        foreach ($kontrakList as $k) {
            if (!in_array($k['status'], ['disetujui', 'selesai'], true)) continue;
            $beban = 'Beban Borongan – ' . $k['project_nama'];
            foreach ($k['termin'] as $t) {
                if (!in_array($t['status'], ['disetujui', 'dibayar'], true)) continue;
                $bruto = (float)$t['bruto']; $ret = (float)$t['retensi']; $den = (float)$t['denda']; $kas = (float)$t['potong_kasbon']; $bers = (float)$t['bersih'];
                $nm = ($t['jenis'] === 'lepas_retensi' ? 'Pelepasan retensi' : 'Termin ' . $t['no_termin']) . ' ' . $k['no_kontrak'] . ' · ' . $k['nama_pekerjaan'];
                if ($t['jenis'] === 'termin') {
                    $lines = [[$beban, self::r($bruto - $den), 0.0], ['Hutang Borongan', 0.0, self::r($bruto - $ret - $den)]];
                    if ($ret > 0.004) $lines[] = ['Hutang Retensi', 0.0, self::r($ret)];
                } else {
                    $lines = [['Hutang Retensi', self::r($bruto), 0.0], ['Hutang Borongan', 0.0, self::r($bruto - $den)]];
                    if ($den > 0.004) $lines[] = [$beban, 0.0, self::r($den)];
                }
                $ev[] = ['tgl' => $t['tgl'], 'ket' => $nm . ' (disetujui)', 'lines' => $lines];
                if ($t['status'] === 'dibayar') {
                    $akun = ($t['metode'] ?? 'tunai') === 'transfer' ? 'Bank' : 'Kas';
                    $pl = [['Hutang Borongan', self::r($bers + $kas), 0.0]];
                    if ($bers > 0.004) $pl[] = [$akun, 0.0, self::r($bers)];
                    if ($kas > 0.004) $pl[] = ['Piutang Kasbon', 0.0, self::r($kas)];
                    $ev[] = ['tgl' => $t['tgl_bayar'], 'ket' => 'Pembayaran ' . $nm . ($t['bukti_no'] ? ' (' . $t['bukti_no'] . ')' : ''), 'lines' => $pl];
                }
            }
        }
        usort($ev, fn($a, $b) => $a['tgl'] <=> $b['tgl']);
        return $ev;
    }

    private static function fmt(float $v): string { return 'Rp ' . number_format(max(0, $v), 0, ',', '.'); }
}
