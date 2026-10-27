<?php
declare(strict_types=1);
namespace App\Application;

use App\Domain\KasbonPlanner;
use App\Infrastructure\KasbonRepository;

final class KasbonService {
    public function __construct(private KasbonRepository $repo, private PayrollService $payroll) {}

    public function saldo(string $projectId, ?string $workerId, string $tim = ''): float { return $this->repo->saldo($projectId, $workerId, $tim); }
    public function saldoMap(?array $projectIds): array { return $this->repo->saldoMap($projectIds); }

    /** Rencana potong kasbon otomatis untuk periode $p (baris payroll_periods). */
    public function rencanaPotong(string $projectId, array $p): array {
        $given = $this->repo->diberikanSampai($projectId, $p['tgl_akhir']);
        if (!$given) return ['items' => [], 'total' => 0.0];
        $deducted = $this->repo->sudahDipotong($projectId, $p['id']);
        $adj = $this->repo->penyesuaianPeriode($p['id']);
        $calc = $this->payroll->hitung([$projectId], $p['tgl_mulai'], $p['tgl_akhir']);
        return KasbonPlanner::rencana($given, $deducted, $adj, $calc['rows']);
    }
}
