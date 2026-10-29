<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
ensure_payroll_controls();

/**
 * Pintu masuk lama ke hitungan gaji & kasbon. Logikanya kini ada di src/ (lihat docs/ARSITEKTUR.md):
 *   Domain/PayrollCalculator, Domain/KasbonPlanner      aturan hitung (murni, tanpa database)
 *   Infrastructure/*Repository                           semua SQL
 *   Application/PayrollService, KasbonService, PeriodService   use case
 * Fungsi di bawah dipertahankan agar endpoint yang sudah ada (rekap, kasbon, penyesuaian, ekspor) tidak berubah.
 */
function payroll_services(): array {
    static $s = null;
    if ($s === null) {
        $pdo = db();
        $payroll = new \App\Application\PayrollService(new \App\Infrastructure\PayrollRepository($pdo), new \App\Domain\PayrollCalculator(WORKER_GRADES, (float)LEMBUR_ISTIRAHAT_JAM));
        $kasbon = new \App\Application\KasbonService(new \App\Infrastructure\KasbonRepository($pdo), $payroll);
        $period = new \App\Application\PeriodService(new \App\Infrastructure\PeriodRepository($pdo), $payroll, $kasbon, 'audit');
        $s = ['payroll' => $payroll, 'kasbon' => $kasbon, 'period' => $period];
    }
    return $s;
}

/** Satu hitungan untuk Rekap Gaji, pembayaran, dan ekspor: angka di layar = yang dibayar = yang diunduh. */
function payroll_compute(?array $projectIds, string $from, string $to): array { return payroll_services()['payroll']->hitung($projectIds, $from, $to); }
function payroll_totals(array $rows, array $periods): array { return \App\Domain\PayrollCalculator::total($rows, $periods); }
function tim_label(string $t): string { return \App\Domain\KasbonPlanner::labelTim($t); }
function kasbon_saldo(string $projectId, ?string $workerId, string $tim = ''): float { return payroll_services()['kasbon']->saldo($projectId, $workerId, $tim); }
function kasbon_saldo_map(?array $projectIds): array { return payroll_services()['kasbon']->saldoMap($projectIds); }
function kasbon_auto_plan(string $projectId, array $p): array { return payroll_services()['kasbon']->rencanaPotong($projectId, $p); }

/** Layanan absen (Application/AttendanceService) dengan ketergantungannya. */
function attendance_service(): \App\Application\AttendanceService {
    static $s = null;
    if ($s === null) {
        $pdo = db();
        $s = new \App\Application\AttendanceService($pdo, new \App\Infrastructure\AttendanceRepository($pdo), new \App\Infrastructure\PeriodRepository($pdo),
            'worker_rates', 'audit', 'payroll_period');
    }
    return $s;
}

function worker_service(): \App\Application\WorkerService {
    static $s = null;
    if ($s === null) $s = new \App\Application\WorkerService(new \App\Infrastructure\WorkerRepository(db()), WORKER_GRADES, 'require_project_access', 'audit', 'fill_zero_snapshots');
    return $s;
}

function kasbon_buku_service(): \App\Application\KasbonBukuService {
    static $s = null;
    if ($s === null) $s = new \App\Application\KasbonBukuService(new \App\Infrastructure\KasbonBukuRepository(db()), payroll_services()['kasbon'], 'audit');
    return $s;
}
function kasbon_request_service(): \App\Application\KasbonRequestService {
    static $s = null;
    if ($s === null) $s = new \App\Application\KasbonRequestService(db(), new \App\Infrastructure\KasbonRequestRepository(db()), 'audit', 'require_project_access', 'payroll_week');
    return $s;
}

function bukti_service(): \App\Application\BuktiService {
    static $s = null;
    if ($s === null) $s = new \App\Application\BuktiService(new \App\Infrastructure\PeriodRepository(db()), payroll_services()['payroll']);
    return $s;
}
