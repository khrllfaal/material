<?php
declare(strict_types=1);
namespace App\Application;

use App\Domain\{DomainError, KasbonPlanner, KasbonRules};
use App\Infrastructure\KasbonBukuRepository;

/** Buku kasbon: kasbon yang diberikan, pelunasan (kasbon negatif), dan saldo. Potongan gaji ada di PeriodService. */
final class KasbonBukuService {
    /** @param callable $audit */
    public function __construct(private KasbonBukuRepository $repo, private KasbonService $kasbon, private $audit) {}

    public function daftar(): array {
        $out = [];
        foreach ($this->repo->semua() as $r) { $r['jumlah'] = (float)$r['jumlah']; $out[] = $r; }
        $pn = $this->repo->namaProyek(); $wn = $this->repo->namaPekerja();
        $saldo = [];
        foreach ($this->kasbon->saldoMap(null) as $key => $v) {
            [$pid, $who] = explode('|', $key, 2);
            $isTim = strncmp($who, 't:', 2) === 0;
            $saldo[] = ['project_id' => $pid, 'project_nama' => $pn[$pid] ?? $pid, 'worker_id' => ($who !== '' && !$isTim) ? $who : null,
                        'tim' => $isTim ? substr($who, 2) : '', 'nama' => $isTim ? KasbonPlanner::labelTim(substr($who, 2)) : ($who !== '' ? ($wn[$who] ?? $who) : 'Grup / penanggung jawab'), 'saldo' => round($v, 2)];
        }
        usort($saldo, fn($a, $b) => [$a['project_nama'], $a['nama']] <=> [$b['project_nama'], $b['nama']]);
        return ['kasbon' => $out, 'saldo' => $saldo];
    }

    public function catat(array $user, array $b): array {
        $projectId = (string)($b['project_id'] ?? '');
        $tgl = require_valid_date($b['tgl'] ?? '', 'Tanggal kasbon');
        $jumlah = money_in($b['jumlah'] ?? null, 'Jumlah');
        $pelunasan = (string)($b['jenis'] ?? '') === 'pelunasan'; // pekerja / tim mengembalikan uang (mis. proyek selesai dengan saldo)
        if ($projectId === '' || $jumlah <= 0) throw new DomainError('Proyek dan jumlah wajib diisi.', 422);
        $workerId = !empty($b['worker_id']) ? (string)$b['worker_id'] : null;
        $tim = $workerId === null ? mb_substr(trim((string)($b['tim'] ?? '')), 0, 80) : '';
        if ($workerId) {
            $wp = $this->repo->proyekPekerja($workerId);
            if ($wp === false) throw new DomainError('Pekerja tidak ditemukan.', 422);
            if ($wp !== $projectId) throw new DomainError('Pekerja bukan bagian dari proyek ini.', 422);
        }
        if ($tim !== '' && !$this->repo->timAda($projectId, $tim)) throw new DomainError('Tim tidak ditemukan di proyek ini.', 422);
        if ($pelunasan) KasbonRules::periksaPelunasan($jumlah, $this->kasbon->saldo($projectId, $workerId, $tim));
        $id = new_id('ksb_');
        $ket = mb_substr(trim((string)($b['ket'] ?? '')), 0, 240);
        if ($pelunasan) $ket = 'Pelunasan' . ($ket !== '' ? ': ' . $ket : '');
        $this->repo->tambah($id, $projectId, $workerId, $tim, $tgl, $pelunasan ? -$jumlah : $jumlah, $ket, $user['id']);
        ($this->audit)('create', 'kasbon', $id, ($pelunasan ? 'pelunasan ' : '') . $jumlah);
        return ['ok' => true, 'id' => $id];
    }

    public function hapus(string $id): array {
        $k = $this->repo->cari($id);
        if (!$k) throw new DomainError('Data tidak ditemukan.', 404);
        if ((float)$k['jumlah'] > 0) KasbonRules::periksaBisaDihapus((float)$k['jumlah'], $this->kasbon->saldo($k['project_id'], $k['worker_id'], (string)$k['tim']) - (float)$k['jumlah']);
        $this->repo->hapus($id);
        ($this->audit)('delete', 'kasbon', $id, (string)$k['jumlah']);
        return ['ok' => true];
    }
}
