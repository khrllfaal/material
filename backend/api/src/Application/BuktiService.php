<?php
declare(strict_types=1);
namespace App\Application;

use App\Domain\{BuktiPembayaran, DomainError};
use App\Infrastructure\PeriodRepository;

/** Data untuk mencetak Bukti Pembayaran (angka dihitung dari sumber yang sama dengan rekap, jadi selalu sama dengan layar). */
final class BuktiService {
    public function __construct(private PeriodRepository $repo, private PayrollService $payroll) {}

    public function data(string $buktiNo): array {
        $pays = $this->repo->pembayaranByBukti($buktiNo);
        if (!$pays) throw new DomainError('Bukti pembayaran tidak ditemukan.', 404);
        $h = $pays[0];
        $calc = $this->payroll->hitung([$h['project_id']], $h['tgl_mulai'], $h['tgl_akhir']);
        $byWorker = [];
        foreach ($calc['rows'] as $r) $byWorker[$r['worker_id']] = $r;
        $isi = BuktiPembayaran::susun($buktiNo, $pays, $this->repo->pembayaranPeriode($h['period_id']), $byWorker);
        return [
            'bukti_no' => $buktiNo, 'tgl_bayar' => $h['tgl_bayar'], 'metode' => $h['metode'], 'ket' => $h['ket'], 'dibuat_oleh' => $h['dibuat_nama'] ?? '', 'dibuat_at' => $h['created_at'],
            'project_id' => $h['project_id'], 'project_nama' => $h['project_nama'], 'tgl_mulai' => $h['tgl_mulai'], 'tgl_akhir' => $h['tgl_akhir'],
            'pj_nama' => $h['pj_nama'], 'grup_nama' => $h['grup_nama'],
        ] + $isi;
    }
}
