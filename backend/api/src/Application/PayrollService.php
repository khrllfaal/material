<?php
declare(strict_types=1);
namespace App\Application;

use App\Domain\PayrollCalculator;
use App\Infrastructure\PayrollRepository;

/** Menyusun hitungan gaji: ambil data (repository) -> hitung (domain). Dipakai rekap, pembayaran, dan ekspor. */
final class PayrollService {
    public function __construct(private PayrollRepository $repo, private PayrollCalculator $calc) {}

    /** @param ?array $projectIds null = semua proyek; [] = tidak ada akses */
    public function hitung(?array $projectIds, string $from, string $to): array {
        if ($projectIds !== null && !$projectIds) return ['rows' => [], 'periods' => [], 'totals' => PayrollCalculator::total([], [])];
        $att = $this->repo->absen($projectIds, $from, $to);
        $ganda = [];
        if ($att) {
            // tampilan seluruh perusahaan sudah memuat semua absen; tampilan satu proyek perlu menghitung lintas proyek
            $ganda = $projectIds === null ? PayrollCalculator::gandaDariAbsen($att)
                                          : $this->repo->gandaLintasProyek(PayrollCalculator::orangDariAbsen($att), $from, $to);
        }
        $periods = $this->repo->periodeBeririsan($projectIds, $from, $to);
        $wids = [];
        foreach ($periods as $p) foreach ($p['adjustments'] as $x) if ($x['worker_id']) $wids[$x['worker_id']] = true;
        return $this->calc->hitung([
            'att' => $att, 'ganda' => $ganda, 'roster' => $this->repo->rosterAktif(), 'periods' => $periods,
            'workers_by_id' => $this->repo->pekerjaByIds(array_keys($wids)), 'borongan' => $this->repo->timBorongan($projectIds),
        ]);
    }
}
