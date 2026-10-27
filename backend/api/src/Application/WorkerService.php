<?php
declare(strict_types=1);
namespace App\Application;

use App\Domain\{DomainError, WorkerRules};
use App\Infrastructure\WorkerRepository;

/**
 * Master pekerja per proyek. Lapangan: melihat daftar proyeknya (nama, status, tim — tanpa tarif/rekening), menambah pekerja, menonaktifkan/mengaktifkan.
 * Admin Pusat & Akuntan: mengelola semuanya termasuk tarif; Owner hanya melihat.
 */
final class WorkerService {
    /** @param array<string,string> $grades @param callable $akses fn(array $user, string $projectId): void  @param callable $audit  @param callable $isiSnapshot fn(string $proyek, ?string $grade, string $workerId) */
    public function __construct(private WorkerRepository $repo, private array $grades, private $akses, private $audit, private $isiSnapshot) {}

    private function catat(string $aksi, string $tabel, string $id, string $ket = ''): void { ($this->audit)($aksi, $tabel, $id, $ket); }
    private function akses(array $user, string $projectId): void { ($this->akses)($user, $projectId); }

    public function daftar(array $user, ?array $scope, array $f): array {
        $lapangan = $user['role'] === 'lapangan';
        if ($scope !== null && !$scope) return [];
        if (!empty($f['kandidat'])) return $this->repo->kandidat((string)($f['project_id'] ?? ''), $scope);
        return array_map(function ($r) use ($lapangan) {
            $r['aktif'] = (int)$r['aktif']; $r['borongan'] = (int)$r['borongan'];
            $r['diverifikasi'] = 1; // verifikasi pekerja baru dinonaktifkan
            if (!$lapangan) {
                $r['tarif_harian'] = $r['tarif_harian'] === null ? null : (float)$r['tarif_harian'];
                $r['tarif_lembur'] = $r['tarif_lembur'] === null ? null : (float)$r['tarif_lembur'];
            }
            return $r;
        }, $this->repo->daftar($scope, $f, $lapangan));
    }

    /** Pusat memastikan pekerja yang ditambah dari lapangan memang orang sungguhan (kontrol pekerja fiktif). */
    public function verifikasi(array $user, array $b): array {
        if ($user['role'] !== 'akuntan') throw new DomainError('Hanya Akuntan yang memverifikasi pekerja baru.', 403);
        $ids = array_values(array_filter(array_map('strval', (array)($b['ids'] ?? []))));
        if (!empty($b['project_id']) && !empty($b['semua'])) $ids = array_merge($ids, $this->repo->belumDiverifikasi((string)$b['project_id']));
        if (!$ids) throw new DomainError('Tidak ada pekerja yang dipilih.', 422);
        $n = 0;
        foreach (array_unique($ids) as $wid) $n += $this->repo->verifikasi($wid, $user['id']);
        $this->catat('update', 'workers', 'verifikasi', "$n pekerja diverifikasi");
        return ['ok' => true, 'diverifikasi' => $n];
    }

    /** Berhenti / bekerja lagi: hanya saklar aktif yang berubah, riwayat tetap. Lapangan boleh untuk proyeknya sendiri. */
    public function setAktif(array $user, array $b): array {
        $wid = (string)($b['id'] ?? '');
        $w0 = $this->repo->cari($wid);
        if (!$w0) throw new DomainError('Pekerja tidak ditemukan.', 404);
        $this->akses($user, $w0['project_id']);
        $val = !empty($b['aktif']) ? 1 : 0;
        $this->repo->setAktif($wid, $val);
        $this->catat('update', 'workers', $wid, ($val ? 'diaktifkan kembali: ' : 'tidak bekerja lagi: ') . $w0['nama']);
        return ['ok' => true, 'aktif' => $val];
    }

    /** Gabung / ganti nama tim di mana-mana supaya tidak ada yang yatim. */
    public function gantiNamaTim(array $user, array $b): array {
        if ($user['role'] === 'lapangan') throw new DomainError('Anda tidak punya akses untuk aksi ini.', 403);
        $pid = (string)($b['project_id'] ?? ''); $dari = trim((string)($b['dari'] ?? '')); $ke = trim((string)($b['ke'] ?? ''));
        if ($pid === '' || $dari === '' || $ke === '' || mb_strlen($ke) > 80) throw new DomainError('Isi tim asal dan nama tim baru (maksimal 80 karakter).', 422);
        if ($dari === $ke) throw new DomainError('Nama tim baru sama dengan yang lama.', 422);
        $this->akses($user, $pid);
        $nw = $this->repo->gantiNamaTim($pid, $dari, $ke);
        $this->catat('update', 'workers', $pid, "tim '$dari' -> '$ke' ($nw pekerja)");
        return ['ok' => true, 'pekerja' => $nw];
    }

    /** Tambah / ubah pekerja. Lapangan hanya boleh menambah; Pusat/Akuntan juga mengubah dan mengatur tarif. */
    public function simpan(array $user, array $b): array {
        $lapangan = $user['role'] === 'lapangan';
        $id = (string)($b['id'] ?? '');
        $projectId = (string)($b['project_id'] ?? '');
        $nama = WorkerRules::rapikanNama($b['nama'] ?? '');
        $grade = (string)($b['grade'] ?? '');
        $tim = trim((string)($b['tim'] ?? ''));
        $borongan = !empty($b['borongan']);
        if ($borongan) $grade = 'LAIN'; // tim borongan = satu penerima: tanpa status, tanpa upah harian
        WorkerRules::periksaIsian($projectId, $nama, $grade, $tim, $this->grades);
        $this->akses($user, $projectId);
        if ($borongan && $tim === '') $tim = $nama; // tim membawa nama tim borongan
        $induk = null;
        if (!empty($b['induk_id'])) { // orang yang sama sudah terdaftar di proyek lain
            $iw = $this->repo->cari((string)$b['induk_id']);
            if (!$iw || $iw['project_id'] === $projectId || (int)$iw['borongan'] === 1) throw new DomainError('Pekerja di proyek lain tidak valid.', 422);
            $this->akses($user, $iw['project_id']);
            $induk = $iw['induk_id'] ?: $iw['id'];
        }
        if ($tim !== '') $tim = WorkerRules::samakanEjaanTim($tim, $this->repo->timDiProyek($projectId));

        $exists = null;
        if ($id !== '') {
            if (!preg_match('/^wrk_[a-z0-9]{6,30}$/', $id)) throw new DomainError('id tidak valid.', 422);
            $exists = $this->repo->cari($id);
        }
        if ($lapangan && $exists) throw new DomainError('Data pekerja sudah tersimpan. Perubahan dilakukan oleh Admin Pusat.', 403);

        // Nama sama sudah ada tetapi berhenti: tawarkan mengaktifkan orang itu kembali, bukan membuat catatan kedua.
        if (!$exists && empty($b['abaikan_nonaktif'])) {
            $n0 = WorkerRules::cariNonaktifSama($this->repo->nonaktifDiProyek($projectId), $nama);
            if ($n0) throw new DomainError('"' . $n0['nama'] . '" pernah ada di proyek ini dan sedang tidak aktif.', 409, ['nonaktif_id' => $n0['id'], 'nama' => $n0['nama'], 'tim' => $n0['tim']]);
        }
        if (WorkerRules::adaDuplikat($this->repo->sesamaGrade($projectId, $grade, $id), $nama, $tim)) throw new DomainError(WorkerRules::pesanDuplikat(), 409);

        $aktif = array_key_exists('aktif', $b) ? ((int)(bool)$b['aktif']) : 1;
        if ($lapangan) {
            $id = $id !== '' ? $id : new_id('wrk_');
            $this->repo->tambahLapangan($id, $projectId, $nama, $grade, $tim, $borongan, $induk, $user['id']);
            $this->catat('create', 'workers', $id, "$nama ($grade)" . ($borongan ? ' [borongan]' : '') . ($induk ? ' [pekerja yang sama di proyek lain]' : ''));
            return ['ok' => true, 'id' => $id, 'borongan' => $borongan ? 1 : 0, 'induk_id' => $induk, 'grade' => $grade, 'tim' => $tim];
        }

        $harian = (isset($b['tarif_harian']) && $b['tarif_harian'] !== '') ? money_in($b['tarif_harian'], 'Tarif harian') : null;
        $lembur = (isset($b['tarif_lembur']) && $b['tarif_lembur'] !== '') ? money_in($b['tarif_lembur'], 'Tarif lembur') : null;
        $rek = mb_substr(trim((string)($b['no_rek'] ?? '')), 0, 80);
        if ($borongan) { $harian = 0.0; $lembur = 0.0; } // tim borongan tidak pernah dapat upah harian; nominalnya dari Tambahan
        if ($exists) {
            if (!array_key_exists('borongan', $b)) { $borongan = (int)$exists['borongan'] === 1; if ($borongan) { $harian = 0.0; $lembur = 0.0; $grade = 'LAIN'; } }
            $this->repo->ubahPusat($id, $projectId, $nama, $grade, $tim, $harian, $lembur, $rek, $aktif, $borongan);
            $detail = '';
            if ((string)$exists['tarif_harian'] !== (string)$harian || (string)$exists['tarif_lembur'] !== (string)$lembur || $exists['grade'] !== $grade) {
                $detail = sprintf('tarif/status: %s %s/%s -> %s %s/%s', $exists['grade'], $exists['tarif_harian'] ?? '-', $exists['tarif_lembur'] ?? '-', $grade, $harian ?? '-', $lembur ?? '-');
            }
            $this->catat('update', 'workers', $id, $detail);
        } else {
            $id = $id !== '' ? $id : new_id('wrk_');
            $this->repo->tambahPusat($id, $projectId, $nama, $grade, $tim, $harian, $lembur, $rek, $aktif, $borongan, $induk, $user['id']);
            $this->catat('create', 'workers', $id, "$nama ($grade)");
        }
        ($this->isiSnapshot)($projectId, null, $id);
        return ['ok' => true, 'id' => $id, 'borongan' => $borongan ? 1 : 0, 'grade' => $grade, 'tim' => $tim];
    }

    public function hapus(array $user, string $id): array {
        if ($user['role'] !== 'admin') throw new DomainError('Hanya Admin Pusat yang bisa menghapus pekerja. Nonaktifkan bila sudah pernah absen.', 403);
        if ($id === '') throw new DomainError('id wajib diisi', 422);
        if ($this->repo->punyaAbsen($id)) throw new DomainError('Pekerja ini sudah punya riwayat absen, jadi tidak dihapus. Ubah menjadi Nonaktif.', 409);
        $this->repo->hapus($id);
        $this->catat('delete', 'workers', $id);
        return ['ok' => true];
    }
}
