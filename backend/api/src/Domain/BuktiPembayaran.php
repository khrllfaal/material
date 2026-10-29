<?php
declare(strict_types=1);
namespace App\Domain;

/**
 * Menyusun isi Bukti Pembayaran (murni): per penerima, berapa yang dibayar pada nomor bukti ini, berapa yang sudah dibayar sebelumnya,
 * dan sisa sesudahnya. Pembayaran "sebelumnya" = pembayaran lain pada periode yang sama dengan nomor bukti lebih awal (atau tanpa nomor, data lama).
 */
final class BuktiPembayaran {
    /**
     * @param array $payBukti    pembayaran dengan nomor bukti ini (worker_id|null, jumlah)
     * @param array $semuaBayar  semua pembayaran pada periode yang sama (worker_id|null, jumlah, bukti_no)
     * @param array $rowsByWorker baris hasil PayrollCalculator, kunci worker_id
     * @return array{lines:array, grup:array, total:float}
     */
    public static function susun(string $buktiNo, array $payBukti, array $semuaBayar, array $rowsByWorker): array {
        $ini = []; $grupIni = 0.0;
        foreach ($payBukti as $p) {
            if ($p['worker_id'] === null) $grupIni += (float)$p['jumlah'];
            else $ini[$p['worker_id']] = ($ini[$p['worker_id']] ?? 0.0) + (float)$p['jumlah'];
        }
        $sebelum = []; $grupSebelum = 0.0;
        foreach ($semuaBayar as $p) {
            if ($p['bukti_no'] === $buktiNo || !NomorBukti::lebihAwal($p['bukti_no'], $buktiNo)) continue;
            if ($p['worker_id'] === null) $grupSebelum += (float)$p['jumlah'];
            else $sebelum[$p['worker_id']] = ($sebelum[$p['worker_id']] ?? 0.0) + (float)$p['jumlah'];
        }
        $lines = []; $total = 0.0;
        foreach ($ini as $wid => $jml) {
            $r = $rowsByWorker[$wid] ?? null;
            $net = $r ? (float)$r['net'] : 0.0;
            $lines[] = [
                'worker_id' => $wid, 'nama' => $r['nama'] ?? $wid, 'no_rek' => $r['no_rek'] ?? '', 'grade_nama' => $r['grade_nama'] ?? '', 'tim' => $r['tim'] ?? '',
                'borongan' => (int)($r['borongan'] ?? 0), 'hari' => (float)($r['hari'] ?? 0),
                'gaji' => round((float)($r['gaji'] ?? 0), 2), 'lembur_rp' => round((float)($r['lembur_rp'] ?? 0), 2),
                'tambah' => round((float)($r['tambah'] ?? 0), 2), 'potong' => round((float)($r['potong'] ?? 0), 2),
                'diterima' => round($net, 2), 'sebelumnya' => round($sebelum[$wid] ?? 0.0, 2), 'dibayar_ini' => round($jml, 2),
                'sisa_setelah' => round($net - ($sebelum[$wid] ?? 0.0) - $jml, 2),
            ];
            $total += $jml;
        }
        usort($lines, fn($a, $b) => [$a['tim'], $a['nama']] <=> [$b['tim'], $b['nama']]);
        $grup = abs($grupIni) > 0.004 ? ['dibayar_ini' => round($grupIni, 2), 'sebelumnya' => round($grupSebelum, 2)] : [];
        return ['lines' => $lines, 'grup' => $grup, 'total' => round($total + $grupIni, 2)];
    }
}
