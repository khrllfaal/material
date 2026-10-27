<?php
declare(strict_types=1);
namespace App\Application;

use App\Domain\{DomainError, KasbonRules};
use App\Infrastructure\{KasbonRequestRepository, Tx};

/**
 * Pengajuan kasbon dari lapangan. Lapangan hanya bisa mengajukan — tidak pernah menyetujui atau menyentuh buku kasbon.
 * Satu pengajuan boleh berisi beberapa baris (mis. Tim A + Tim B): tiap baris punya batch_id yang sama dan diputuskan bersama.
 * Akuntan menyetujui (masuk buku kasbon) atau menolak dengan alasan; Admin Pusat dan Owner tidak menyetujui.
 */
final class KasbonRequestService {
    /** @param callable $audit @param callable $akses fn(array $user, string $projectId) @param callable $mingguIni fn(string $tgl): array{0:string,1:string} */
    public function __construct(private \PDO $db, private KasbonRequestRepository $repo, private $audit, private $akses, private $mingguIni) {}

    private function catat(string $aksi, string $id, string $ket): void { ($this->audit)($aksi, 'kasbon_requests', $id, $ket); }

    public function daftar(?array $scope, ?string $status): array {
        if ($scope !== null && !$scope) return [];
        return array_map(function ($r) { $r['jumlah'] = (float)$r['jumlah']; return $r; }, $this->repo->daftar($scope, $status));
    }

    public function ajukan(array $user, array $b): array {
        $projectId = (string)($b['project_id'] ?? '');
        if ($projectId === '') throw new DomainError('Proyek wajib dipilih.', 422);
        ($this->akses)($user, $projectId);
        $items = (isset($b['items']) && is_array($b['items'])) ? $b['items'] : [['id' => $b['id'] ?? '', 'worker_id' => $b['worker_id'] ?? null, 'tim' => $b['tim'] ?? '', 'jumlah' => $b['jumlah'] ?? null]];
        if (!$items || count($items) > KasbonRules::MAKS_BARIS) throw new DomainError('Isi 1 sampai 20 baris kasbon.', 422);
        $ket = mb_substr(trim((string)($b['ket'] ?? '')), 0, 255);
        $lines = []; $seen = [];
        foreach ($items as $it) {
            $id = (string)($it['id'] ?? '');
            if ($id === '') $id = new_id('ksr_');
            KasbonRules::periksaIdPengajuan($id);
            $jumlah = money_in($it['jumlah'] ?? null, 'Jumlah');
            KasbonRules::periksaJumlahPengajuan($jumlah);
            $workerId = !empty($it['worker_id']) ? (string)$it['worker_id'] : null;
            if ($workerId && $this->repo->proyekPekerja($workerId) !== $projectId) throw new DomainError('Pekerja bukan bagian dari proyek ini.', 422);
            $tim = $workerId ? '' : mb_substr(trim((string)($it['tim'] ?? '')), 0, 80);
            if ($tim !== '' && !$this->repo->timAda($projectId, $tim)) throw new DomainError('Tim tidak ditemukan di proyek ini.', 422);
            $key = KasbonRules::kunciNama($workerId, $tim);
            if (isset($seen[$key])) throw new DomainError('Satu nama hanya boleh satu baris dalam satu pengajuan.', 422);
            $seen[$key] = true;
            $lines[] = ['id' => $id, 'worker_id' => $workerId, 'tim' => $tim, 'jumlah' => $jumlah];
        }
        if ($this->repo->idAda($lines[0]['id'])) throw new DomainError('Pengajuan sudah tersimpan.', 403); // kirim ulang saat offline: dianggap selesai
        KasbonRules::periksaBatasTerbuka($this->repo->jumlahTerbuka($projectId), count($lines));
        // Aturan: satu pengajuan per nama (pekerja / tim / grup) per minggu gaji; yang ditolak tidak dihitung. Minggu menurut waktu Jakarta.
        if ($user['role'] === 'lapangan') {
            [$dari, $sampai] = ($this->mingguIni)(date('Y-m-d'));
            foreach ($lines as $ln) if ($this->repo->adaMingguIni($projectId, $dari, $sampai, $ln['worker_id'], $ln['tim']))
                throw new DomainError('Kasbon untuk ' . KasbonRules::namaPihak($ln['worker_id'], $ln['tim']) . ' sudah diajukan minggu ini. Satu kali per minggu — hubungi Akuntan bila perlu tambahan.', 409);
        }
        $batch = count($lines) > 1 ? (KasbonRules::idBatch(isset($b['batch_id']) ? (string)$b['batch_id'] : null) ?? new_id('bat_')) : null;
        Tx::jalankan($this->db, function () use ($lines, $projectId, $batch, $ket, $user) {
            foreach ($lines as $ln) $this->repo->tambah($ln['id'], $projectId, $ln['worker_id'], $ln['tim'], $batch, $ln['jumlah'], $ket, $user['id']);
        });
        $this->catat('create', $lines[0]['id'], count($lines) . ' baris, total ' . array_sum(array_column($lines, 'jumlah')));
        return ['ok' => true, 'id' => $lines[0]['id'], 'ids' => array_column($lines, 'id'), 'batch_id' => $batch, 'status' => 'diajukan'];
    }

    /** Pemeriksaan oleh Akuntan: setujui / tolak satu baris, atau seluruh batch. */
    public function tinjau(array $user, array $b): array {
        if ($user['role'] !== 'akuntan') throw new DomainError('Hanya Akuntan yang memeriksa pengajuan kasbon.', 403);
        $action = (string)($b['action'] ?? '');
        $catatan = mb_substr(trim((string)($b['catatan'] ?? '')), 0, 255);
        if ($action === 'setujui_batch' || $action === 'tolak_batch') return $this->tinjauBatch($user, $action, (string)($b['batch_id'] ?? ''), $b, $catatan);

        $id = (string)($b['id'] ?? '');
        [$hasil, $audit] = Tx::jalankan($this->db, function () use ($user, $b, $action, $id, $catatan) {
            $r = $this->repo->cariKunci($id);
            if (!$r) throw new DomainError('Pengajuan tidak ditemukan.', 404);
            if ($r['status'] !== 'diajukan') throw new DomainError('Pengajuan ini sudah diproses.', 409);
            if ($action === 'tolak') {
                if ($catatan === '') throw new DomainError('Alasan penolakan wajib diisi.', 422);
                $this->repo->tolak($id, $catatan, $user['id']);
                return [['ok' => true, 'status' => 'ditolak'], 'ditolak: ' . $catatan];
            }
            if ($action === 'setujui') {
                $jumlah = isset($b['jumlah']) && $b['jumlah'] !== '' ? money_in($b['jumlah'], 'Jumlah') : (float)$r['jumlah'];
                if ($jumlah <= 0) throw new DomainError('Jumlah harus lebih dari 0.', 422);
                $tgl = require_valid_date($b['tgl'] ?? date('Y-m-d'), 'Tanggal kasbon');
                $kid = $this->repo->setujui($r, $jumlah, $tgl, $catatan, $user['id']);
                return [['ok' => true, 'status' => 'disetujui', 'kasbon_id' => $kid], "disetujui $jumlah -> $kid"];
            }
            throw new DomainError('Aksi tidak dikenal.', 422);
        });
        $this->catat('update', $id, $audit);
        return $hasil;
    }

    private function tinjauBatch(array $user, string $action, string $batch, array $b, string $catatan): array {
        [$hasil, $audit] = Tx::jalankan($this->db, function () use ($user, $action, $batch, $b, $catatan) {
            $rows = $this->repo->batchTerbuka($batch);
            if (!$rows) throw new DomainError('Pengajuan ini sudah diproses atau tidak ditemukan.', 404);
            if ($action === 'tolak_batch') {
                if ($catatan === '') throw new DomainError('Alasan penolakan wajib diisi.', 422);
                $this->repo->tolakBatch($batch, $catatan, $user['id']);
                return [['ok' => true, 'status' => 'ditolak', 'baris' => count($rows)], 'ditolak (' . count($rows) . ' baris): ' . $catatan];
            }
            $tgl = require_valid_date($b['tgl'] ?? date('Y-m-d'), 'Tanggal kasbon');
            $total = 0.0;
            foreach ($rows as $r) { $this->repo->setujui($r, (float)$r['jumlah'], $tgl, $catatan, $user['id']); $total += (float)$r['jumlah']; }
            return [['ok' => true, 'status' => 'disetujui', 'baris' => count($rows), 'total' => $total], 'disetujui ' . count($rows) . ' baris, total ' . $total];
        });
        $this->catat('update', $batch, $audit);
        return $hasil;
    }
}
