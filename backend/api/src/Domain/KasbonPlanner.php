<?php
declare(strict_types=1);
namespace App\Domain;

/**
 * Rencana potong kasbon otomatis untuk satu periode (murni, tanpa database).
 *
 * Aturan: kasbon yang diberikan sampai akhir periode dipotong; yang diberikan sesudahnya menunggu pembayaran berikutnya.
 * Tiap orang / tim / grup dipotong min(saldo yang masih terutang, gaji periode itu sebelum potongan ini) — saldo nol tidak
 * pernah dipotong, sisanya dibawa ke periode berikutnya. Kasbon tim diambil dari sisa gaji pekerja tim itu.
 *
 * Kunci: id pekerja | "t:NamaTim" | "" (grup).
 */
final class KasbonPlanner {
    public static function kunci(string $workerId, string $tim): string { return $workerId !== '' ? $workerId : ($tim !== '' ? 't:' . $tim : ''); }

    /** "Tim A" tetap "Tim A"; "Pondasi" menjadi "Tim Pondasi". */
    public static function labelTim(string $t): string { return preg_match('/^tim\b/i', $t) ? $t : 'Tim ' . $t; }

    /**
     * @param array<string,float> $given    kasbon diberikan sampai akhir periode
     * @param array<string,float> $deducted kasbon yang sudah dipotong di periode lain / potongan manual
     * @param array<string,array{tambah?:float,potong?:float}> $adj penyesuaian periode ini (tanpa baris kasbon otomatis)
     * @param array $rows baris hasil PayrollCalculator::hitung()['rows']
     * @param ?array<string,float> $saldoSekarang saldo kasbon sebenarnya saat ini per kunci (semua tanggal). Bila diberikan, potongan tidak pernah melebihinya:
     *        mencegah potong ganda saat kasbon sudah dilunasi/dipotong lewat jalur lain (mis. termin borongan) yang tanggalnya setelah periode ini.
     */
    public static function rencana(array $given, array $deducted, array $adj, array $rows, ?array $saldoSekarang = null): array {
        $batas = fn(string $k, float $v): float => $saldoSekarang === null ? $v : min($v, max(0.0, $saldoSekarang[$k] ?? 0.0));
        if (!$given) return ['items' => [], 'total' => 0.0];
        $items = []; $total = 0.0; $leftAll = 0.0; $leftTim = [];
        foreach ($rows as $r) {
            $w = $r['worker_id'];
            $cap = $r['gaji'] + $r['lembur_rp'] + ($adj[$w]['tambah'] ?? 0.0) - ($adj[$w]['potong'] ?? 0.0);
            $avail = $batas($w, max(0.0, ($given[$w] ?? 0.0) - ($deducted[$w] ?? 0.0)));
            $take = $avail > 0.005 && $cap > 0.005 ? round(min($avail, $cap), 2) : 0.0;
            $rest = max(0.0, $cap - $take);
            $leftAll += $rest; $leftTim[$r['tim']] = ($leftTim[$r['tim']] ?? 0.0) + $rest;
            if ($avail > 0.005) { $items[] = ['worker_id' => $w, 'tim' => '', 'nama' => $r['nama'], 'saldo' => round($avail, 2), 'potong' => $take]; $total += $take; }
        }
        foreach ($given as $k => $v) { // tim: diambil dari sisa gaji pekerja tim itu
            if (strncmp((string)$k, 't:', 2) !== 0) continue;
            $tim = substr((string)$k, 2);
            $avail = $batas((string)$k, max(0.0, $v - ($deducted[$k] ?? 0.0)));
            if ($avail <= 0.005) continue;
            $cap = max(0.0, ($leftTim[$tim] ?? 0.0) + ($adj[$k]['tambah'] ?? 0.0) - ($adj[$k]['potong'] ?? 0.0));
            $take = $cap > 0.005 ? round(min($avail, $cap), 2) : 0.0;
            $leftAll -= $take; $total += $take;
            $items[] = ['worker_id' => null, 'tim' => $tim, 'nama' => self::labelTim($tim), 'saldo' => round($avail, 2), 'potong' => $take];
        }
        $availG = $batas('', max(0.0, ($given[''] ?? 0.0) - ($deducted[''] ?? 0.0)));
        if ($availG > 0.005) {
            $capG = max(0.0, $leftAll + ($adj['']['tambah'] ?? 0.0) - ($adj['']['potong'] ?? 0.0));
            $takeG = $capG > 0.005 ? round(min($availG, $capG), 2) : 0.0;
            $items[] = ['worker_id' => null, 'tim' => '', 'nama' => 'Grup / penanggung jawab', 'saldo' => round($availG, 2), 'potong' => $takeG]; $total += $takeG;
        }
        return ['items' => $items, 'total' => round($total, 2)];
    }

    /** Kunci ubahan: worker_id, 't:'.tim, atau '' (grup). */
    public static function kunciItem(array $it): string { return $it['worker_id'] ?? ($it['tim'] !== '' ? 't:' . $it['tim'] : ''); }

    /**
     * Akuntan boleh MENURUNKAN potongan otomatis (mis. kasbon besar, gaji minggu ini mau disisakan). Tidak boleh menaikkan
     * melebihi rencana, tidak boleh negatif. Sisanya tetap jadi saldo kasbon dan dipotong di pembayaran berikutnya.
     * @param array<string,mixed> $ubah kunci => jumlah yang diinginkan
     */
    public static function terapkanUbahan(array $plan, array $ubah): array {
        if (!$ubah) return $plan;
        $total = 0.0;
        foreach ($plan['items'] as &$it) {
            $k = self::kunciItem($it);
            if (array_key_exists($k, $ubah)) {
                $v = $ubah[$k];
                if (!is_numeric($v) || (float)$v < 0) throw new DomainError('Jumlah potongan kasbon tidak valid (' . $it['nama'] . ').', 422);
                $v = round((float)$v, 2);
                if ($v > $it['potong'] + 0.005) throw new DomainError('Potongan kasbon ' . $it['nama'] . ' melebihi batas (' . number_format($it['potong'], 0, ',', '.') . '): tidak boleh lebih dari saldo atau gaji.', 422);
                $it['potong'] = $v;
            }
            $total += $it['potong'];
        }
        unset($it);
        $plan['total'] = round($total, 2);
        return $plan;
    }
}
