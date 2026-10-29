<?php
declare(strict_types=1);
namespace App\Application;

use App\Domain\{Borongan, DomainError, NomorBukti};
use App\Infrastructure\{BoronganRepository, DocCounter, Tx};

/**
 * Menu Borongan: kontrak per tim (nilai, retensi), addendum, termin pembayaran, potong kasbon tim, buku besar, dan jurnal.
 * Peran: Admin Pusat dan Akuntan membuat dan mengajukan; hanya Akuntan menyetujui, membayar, menyelesaikan, dan membatalkan kontrak yang sudah berjalan; Owner hanya membaca.
 * Setiap perubahan mengunci baris kontrak lebih dulu, jadi dua orang yang menekan tombol bersamaan berjalan berurutan.
 */
final class BoronganService {
    /** @param callable $audit @param callable $akses fn(array $user, string $projectId) */
    public function __construct(private \PDO $db, private BoronganRepository $repo, private KasbonService $kasbon, private $audit, private $akses) {}

    private static function peran(array $user, array $boleh, string $pesan = 'Anda tidak punya akses untuk aksi ini.'): void {
        if (!in_array($user['role'], $boleh, true)) throw new DomainError($pesan, 403);
    }
    private function catat(string $aksi, string $tabel, string $id, string $ket): void { ($this->audit)($aksi, $tabel, $id, $ket); }
    private function akses(array $user, string $projectId): void { ($this->akses)($user, $projectId); }
    private static function teks(mixed $v, int $n): string { return mb_substr(trim((string)$v), 0, $n); }
    private static function angka(mixed $v, string $label, bool $boleh0 = false): float {
        if (!is_numeric($v) || abs((float)$v) > 1e11) throw new DomainError("$label harus berupa angka.", 422);
        $f = round((float)$v, 2);
        if ($f < 0 || (!$boleh0 && $f == 0.0)) throw new DomainError("$label harus lebih dari 0.", 422);
        return $f;
    }

    // ---------------------------------------------------------------- membaca

    /** Melampirkan addendum, termin, dan ringkasan ke setiap kontrak. */
    private function muat(array $rows): array {
        $ids = array_column($rows, 'id');
        $add = []; foreach ($this->repo->addenda($ids) as $a) $add[$a['kontrak_id']][] = $a;
        $ter = []; foreach ($this->repo->termin($ids) as $t) $ter[$t['kontrak_id']][] = $t;
        foreach ($rows as &$k) {
            $k['addenda'] = $add[$k['id']] ?? []; $k['termin'] = $ter[$k['id']] ?? [];
            $k['ringkas'] = Borongan::ringkas($k, $k['addenda'], $k['termin']);
        }
        unset($k);
        return $rows;
    }

    /** Daftar kontrak dan ringkasan per tim (termasuk tim yang belum punya kontrak). */
    public function daftar(?array $scope, ?string $projectId): array {
        if ($scope !== null && !$scope) return ['kontrak' => [], 'tim' => []];
        $pids = $projectId ? [$projectId] : $scope;
        if ($projectId && $scope !== null && !in_array($projectId, $scope, true)) return ['kontrak' => [], 'tim' => []];
        $kontrak = $this->muat($this->repo->daftar($pids));
        $saldo = $this->kasbon->saldoMap($pids);
        $tim = [];
        foreach ($this->repo->daftarTim($pids) as $w) {
            $tim[$w['id']] = ['worker_id' => $w['id'], 'nama' => $w['nama'], 'project_id' => $w['project_id'], 'project_nama' => $w['project_nama'], 'aktif' => (int)$w['aktif'], 'no_rek' => $w['no_rek'],
                'kontrak_berjalan' => 0, 'nilai_kini' => 0.0, 'dibayar_bersih' => 0.0, 'hutang_saldo' => 0.0, 'retensi_ditahan' => 0.0, 'kasbon_saldo' => round($saldo[$w['project_id'] . '|' . $w['id']] ?? 0.0, 2), 'perlu_tindakan' => 0];
        }
        foreach ($kontrak as $k) {
            if (!isset($tim[$k['worker_id']])) continue;
            $t = &$tim[$k['worker_id']];
            $r = $k['ringkas'];
            if (in_array($k['status'], ['disetujui', 'selesai'], true)) { $t['nilai_kini'] += $r['nilai_kini']; $t['dibayar_bersih'] += $r['dibayar_bersih']; $t['hutang_saldo'] += $r['hutang_saldo']; $t['retensi_ditahan'] += $r['retensi_ditahan']; }
            if ($k['status'] === 'disetujui') $t['kontrak_berjalan']++;
            if ($k['status'] === 'diajukan') $t['perlu_tindakan']++;
            foreach ($k['termin'] as $x) if (in_array($x['status'], ['diajukan', 'disetujui'], true)) $t['perlu_tindakan']++;
            foreach ($k['addenda'] as $x) if ($x['status'] === 'diajukan') $t['perlu_tindakan']++;
            unset($t);
        }
        foreach ($tim as &$t) foreach (['nilai_kini', 'dibayar_bersih', 'hutang_saldo', 'retensi_ditahan'] as $c) $t[$c] = round($t[$c], 2);
        unset($t);
        // yang belum punya kontrak dan tidak aktif tidak perlu tampil
        $tim = array_filter($tim, fn($t) => $t['aktif'] || $t['nilai_kini'] > 0 || $t['kasbon_saldo'] != 0 || $t['perlu_tindakan'] > 0);
        return ['kontrak' => $kontrak, 'tim' => array_values($tim)];
    }

    /** Buku besar satu tim: semua kontraknya, kejadian berurut dengan saldo berjalan, dan kasbon tim. */
    public function tim(array $user, string $workerId): array {
        $w = $this->repo->tim($workerId);
        if (!$w || !(int)$w['borongan']) throw new DomainError('Tim borongan tidak ditemukan.', 404);
        $this->akses($user, $w['project_id']);
        $kontrak = $this->muat($this->repo->daftar([$w['project_id']], $workerId));
        $buku = Borongan::buku(array_reverse($kontrak));
        $kas = []; $run = 0.0;
        foreach ($this->repo->kasbonTim($w['project_id'], $workerId) as $k) { $run = round($run + $k['jumlah'], 2); $kas[] = $k + ['saldo' => $run]; }
        return ['tim' => $w, 'kontrak' => $kontrak, 'buku' => $buku['entries'], 'hutang_saldo' => $buku['saldo'], 'kasbon' => $kas, 'kasbon_saldo' => $run];
    }

    public function kontrak(array $user, string $id): array {
        $k = $this->repo->cari($id);
        if (!$k) throw new DomainError('Kontrak tidak ditemukan.', 404);
        $this->akses($user, $k['project_id']);
        $k = $this->muat([$k])[0];
        $k['kasbon_saldo'] = round($this->kasbon->saldo($k['project_id'], $k['worker_id']), 2);
        $nama = $this->repo->namaUser([$k['created_by'], $k['disetujui_by']]);
        $k['dibuat_oleh'] = $nama[$k['created_by']] ?? ''; $k['disetujui_oleh'] = $nama[$k['disetujui_by']] ?? '';
        return $k;
    }

    /** Peristiwa jurnal borongan pada rentang tanggal (untuk tab Pembukuan & Jurnal). */
    public function jurnal(?array $scope, ?string $projectId, string $dari, string $sampai): array {
        $d = $this->daftar($scope, $projectId);
        $ev = Borongan::jurnal($d['kontrak']);
        return array_values(array_filter($ev, fn($e) => $e['tgl'] >= $dari && $e['tgl'] <= $sampai));
    }

    // ---------------------------------------------------------------- kontrak

    private function periksaIsian(array $b): array {
        $nama = self::teks($b['nama_pekerjaan'] ?? '', 160);
        if ($nama === '') throw new DomainError('Nama pekerjaan wajib diisi.', 422);
        $tgl = require_valid_date($b['tgl_mulai'] ?? '', 'Tanggal mulai');
        $nilai = self::angka($b['nilai'] ?? null, 'Nilai kontrak');
        $ret = round((float)($b['retensi_persen'] ?? 0), 2);
        if ($ret < 0 || $ret > 30) throw new DomainError('Retensi 0 sampai 30 persen.', 422);
        return [$nama, $tgl, $nilai, $ret, self::teks($b['catatan'] ?? '', 255)];
    }

    public function buat(array $user, array $b): array {
        self::peran($user, ['admin', 'akuntan']);
        $tim = $this->repo->tim((string)($b['worker_id'] ?? ''));
        if (!$tim || !(int)$tim['borongan']) throw new DomainError('Pilih tim borongan.', 422);
        $this->akses($user, $tim['project_id']);
        if (!(int)$tim['aktif']) throw new DomainError('Tim ini tidak aktif. Aktifkan dulu di Pekerja & Tarif.', 409);
        [$nama, $tgl, $nilai, $ret, $cat] = $this->periksaIsian($b);
        $id = new_id('bko_');
        $no = Tx::jalankan($this->db, function () use ($id, $tim, $nama, $tgl, $nilai, $ret, $cat, $user) {
            $no = (new DocCounter($this->db))->berikutnya('BK', $tgl);
            $this->repo->tambahKontrak(['id' => $id, 'no_kontrak' => $no, 'project_id' => $tim['project_id'], 'worker_id' => $tim['id'], 'nama_pekerjaan' => $nama, 'tgl_mulai' => $tgl,
                'nilai' => $nilai, 'retensi_persen' => $ret, 'catatan' => $cat, 'created_by' => $user['id']]);
            return $no;
        });
        $this->catat('create', 'borongan_kontrak', $id, "$no · {$tim['nama']} · $nama · $nilai");
        return ['ok' => true, 'id' => $id, 'no_kontrak' => $no];
    }

    private function dalamTx(string $id, callable $fn): mixed {
        return Tx::jalankan($this->db, function () use ($id, $fn) {
            $k = $this->repo->kunci($id); // pernyataan pertama: kunci baris kontrak
            if (!$k) throw new DomainError('Kontrak tidak ditemukan.', 404);
            return $fn($k);
        });
    }
    private function aksesKontrak(array $user, array $k): void { $this->akses($user, $k['project_id']); }

    public function ubah(array $user, array $b): array {
        self::peran($user, ['admin', 'akuntan']);
        $id = (string)($b['id'] ?? '');
        [$nama, $tgl, $nilai, $ret, $cat] = $this->periksaIsian($b);
        $k = $this->dalamTx($id, function ($k) use ($user, $id, $nama, $tgl, $nilai, $ret, $cat) {
            $this->aksesKontrak($user, $k);
            if ($k['status'] !== 'draft') throw new DomainError('Kontrak yang sudah diajukan tidak bisa diubah. Gunakan addendum untuk mengubah nilai.', 409);
            $this->repo->ubahKontrak($id, $nama, $tgl, $nilai, $ret, $cat);
            return $k;
        });
        $this->catat('update', 'borongan_kontrak', $id, $k['no_kontrak'] . ' diubah');
        return ['ok' => true];
    }

    public function ajukan(array $user, string $id): array {
        self::peran($user, ['admin', 'akuntan']);
        $k = $this->dalamTx($id, function ($k) use ($user, $id) {
            $this->aksesKontrak($user, $k);
            if ($k['status'] !== 'draft') throw new DomainError('Kontrak ini sudah diajukan.', 409);
            $this->repo->setStatusKontrak($id, 'diajukan', $user['id']);
            return $k;
        });
        $this->catat('update', 'borongan_kontrak', $id, $k['no_kontrak'] . ' diajukan');
        return ['ok' => true, 'status' => 'diajukan'];
    }

    public function setujui(array $user, string $id): array {
        self::peran($user, ['akuntan'], 'Hanya Akuntan yang menyetujui kontrak borongan.');
        $k = $this->dalamTx($id, function ($k) use ($user, $id) {
            $this->aksesKontrak($user, $k);
            if ($k['status'] !== 'diajukan') throw new DomainError($k['status'] === 'draft' ? 'Kontrak belum diajukan.' : 'Kontrak ini sudah diproses.', 409);
            $this->repo->setStatusKontrak($id, 'disetujui', $user['id']);
            return $k;
        });
        $this->catat('update', 'borongan_kontrak', $id, $k['no_kontrak'] . ' disetujui');
        return ['ok' => true, 'status' => 'disetujui'];
    }

    public function kembalikan(array $user, string $id, string $alasan): array {
        self::peran($user, ['akuntan'], 'Hanya Akuntan yang mengembalikan kontrak ke draft.');
        $alasan = self::teks($alasan, 200);
        if ($alasan === '') throw new DomainError('Alasan wajib diisi.', 422);
        $k = $this->dalamTx($id, function ($k) use ($user, $id, $alasan) {
            $this->aksesKontrak($user, $k);
            if (!in_array($k['status'], ['diajukan', 'disetujui'], true)) throw new DomainError('Hanya kontrak yang diajukan atau berjalan yang bisa dikembalikan.', 409);
            foreach ($this->repo->termin([$id]) as $t) if ($t['status'] !== 'ditolak') throw new DomainError('Kontrak ini sudah punya termin, jadi tidak bisa dikembalikan.', 409);
            foreach ($this->repo->addenda([$id]) as $a) if ($a['status'] === 'disetujui') throw new DomainError('Kontrak ini sudah punya addendum yang disetujui, jadi tidak bisa dikembalikan.', 409);
            $this->repo->setStatusKontrak($id, 'draft', $user['id'], $alasan);
            return $k;
        });
        $this->catat('update', 'borongan_kontrak', $id, $k['no_kontrak'] . ' dikembalikan: ' . $alasan);
        return ['ok' => true, 'status' => 'draft'];
    }

    public function selesai(array $user, string $id, bool $kurangiNilai): array {
        self::peran($user, ['akuntan'], 'Hanya Akuntan yang menyelesaikan kontrak borongan.');
        [$k, $kurang, $r] = $this->dalamTx($id, function ($k) use ($user, $id, $kurangiNilai) {
            $this->aksesKontrak($user, $k);
            $add = $this->repo->addenda([$id]); $ter = $this->repo->termin([$id]);
            $r = Borongan::ringkas($k, $add, $ter);
            $kurang = Borongan::periksaBisaSelesai($k, $r, $kurangiNilai);
            if ($kurang > 0.004) $this->repo->tambahAddendum(['id' => new_id('bad_'), 'kontrak_id' => $id, 'tgl' => date('Y-m-d'), 'jumlah' => -$kurang, 'alasan' => 'Penutupan kontrak: nilai dikurangi sebesar sisa yang tidak ditagih', 'status' => 'disetujui', 'created_by' => $user['id']]);
            foreach ($add as $a) if ($a['status'] === 'diajukan') $this->repo->prosesAddendum($a['id'], 'ditolak', $user['id']);
            $this->repo->setStatusKontrak($id, 'selesai', $user['id']);
            return [$k, $kurang, $r];
        });
        $this->catat('update', 'borongan_kontrak', $id, $k['no_kontrak'] . ' selesai' . ($kurang > 0 ? " (nilai dikurangi $kurang)" : ''));
        return ['ok' => true, 'status' => 'selesai', 'dikurangi' => round($kurang, 2), 'retensi_ditahan' => $r['retensi_ditahan'], 'kasbon_saldo' => round($this->kasbon->saldo($k['project_id'], $k['worker_id']), 2)];
    }

    public function batal(array $user, string $id, string $alasan): array {
        self::peran($user, ['admin', 'akuntan']);
        $alasan = self::teks($alasan, 200);
        $k = $this->dalamTx($id, function ($k) use ($user, $id, $alasan) {
            $this->aksesKontrak($user, $k);
            if ($user['role'] === 'admin' && $k['status'] !== 'draft') throw new DomainError('Hanya Akuntan yang bisa membatalkan kontrak yang sudah diajukan.', 403);
            if ($k['status'] !== 'draft' && $alasan === '') throw new DomainError('Alasan wajib diisi.', 422);
            $ter = $this->repo->termin([$id]);
            Borongan::periksaBisaBatal($k, $ter);
            if ($k['status'] === 'selesai') throw new DomainError('Kontrak yang sudah selesai tidak bisa dibatalkan.', 409);
            foreach ($ter as $t) if (in_array($t['status'], ['diajukan', 'disetujui'], true)) $this->repo->tolakTermin($t['id'], 'kontrak dibatalkan', $user['id']);
            foreach ($this->repo->addenda([$id]) as $a) if ($a['status'] === 'diajukan') $this->repo->prosesAddendum($a['id'], 'ditolak', $user['id']);
            $this->repo->setStatusKontrak($id, 'batal', $user['id'], $alasan !== '' ? $alasan : 'dibatalkan saat draft');
            return $k;
        });
        $this->catat('update', 'borongan_kontrak', $id, $k['no_kontrak'] . ' dibatalkan: ' . $alasan);
        return ['ok' => true, 'status' => 'batal'];
    }

    // ---------------------------------------------------------------- addendum

    public function addendumBuat(array $user, array $b): array {
        self::peran($user, ['admin', 'akuntan']);
        $id = (string)($b['kontrak_id'] ?? '');
        if (!isset($b['jumlah']) || !is_numeric($b['jumlah']) || abs((float)$b['jumlah']) > 1e11) throw new DomainError('Jumlah addendum harus berupa angka.', 422);
        $jumlah = round((float)$b['jumlah'], 2);
        $alasan = self::teks($b['alasan'] ?? '', 200);
        if ($alasan === '') throw new DomainError('Alasan addendum wajib diisi.', 422);
        $tgl = require_valid_date($b['tgl'] ?? date('Y-m-d'), 'Tanggal addendum');
        $aid = new_id('bad_');
        $k = $this->dalamTx($id, function ($k) use ($user, $id, $jumlah, $alasan, $tgl, $aid) {
            $this->aksesKontrak($user, $k);
            $r = Borongan::ringkas($k, $this->repo->addenda([$id]), $this->repo->termin([$id]));
            Borongan::periksaBisaAddendum($k, $r, $jumlah);
            $this->repo->tambahAddendum(['id' => $aid, 'kontrak_id' => $id, 'tgl' => $tgl, 'jumlah' => $jumlah, 'alasan' => $alasan, 'status' => 'diajukan', 'created_by' => $user['id']]);
            return $k;
        });
        $this->catat('create', 'borongan_addendum', $aid, $k['no_kontrak'] . " · $jumlah · $alasan");
        return ['ok' => true, 'id' => $aid];
    }

    public function addendumProses(array $user, string $addendumId, bool $setuju): array {
        self::peran($user, ['akuntan'], 'Hanya Akuntan yang menyetujui addendum.');
        $a0 = $this->repo->cariAddendum($addendumId);
        if (!$a0) throw new DomainError('Addendum tidak ditemukan.', 404);
        $k = $this->dalamTx($a0['kontrak_id'], function ($k) use ($user, $addendumId, $setuju) {
            $this->aksesKontrak($user, $k);
            $a = $this->repo->cariAddendum($addendumId);
            if ($a['status'] !== 'diajukan') throw new DomainError('Addendum ini sudah diproses.', 409);
            if ($setuju) {
                $r = Borongan::ringkas($k, $this->repo->addenda([$k['id']]), $this->repo->termin([$k['id']]));
                Borongan::periksaBisaAddendum($k, $r, (float)$a['jumlah']);
            }
            $this->repo->prosesAddendum($addendumId, $setuju ? 'disetujui' : 'ditolak', $user['id']);
            return $k;
        });
        $this->catat('update', 'borongan_addendum', $addendumId, $k['no_kontrak'] . ($setuju ? ' disetujui' : ' ditolak'));
        return ['ok' => true, 'status' => $setuju ? 'disetujui' : 'ditolak'];
    }

    // ---------------------------------------------------------------- termin

    public function terminBuat(array $user, array $b): array {
        self::peran($user, ['admin', 'akuntan']);
        $id = (string)($b['kontrak_id'] ?? '');
        $jenis = ($b['jenis'] ?? 'termin') === 'lepas_retensi' ? 'lepas_retensi' : 'termin';
        $bruto = self::angka($b['bruto'] ?? null, 'Jumlah termin');
        $denda = self::angka($b['denda'] ?? 0, 'Denda', true);
        $dendaKet = self::teks($b['denda_ket'] ?? '', 160);
        if ($denda > 0 && $dendaKet === '') throw new DomainError('Alasan denda atau potongan wajib diisi.', 422);
        $aktif = !array_key_exists('potong_kasbon', $b) || !empty($b['potong_kasbon']);
        $tgl = require_valid_date($b['tgl'] ?? date('Y-m-d'), 'Tanggal termin');
        $ket = self::teks($b['ket'] ?? '', 255);
        $tid = new_id('btm_');
        [$k, $split, $no] = $this->dalamTx($id, function ($k) use ($user, $id, $jenis, $bruto, $denda, $dendaKet, $aktif, $tgl, $ket, $tid) {
            $this->aksesKontrak($user, $k);
            $r = Borongan::ringkas($k, $this->repo->addenda([$id]), $this->repo->termin([$id]));
            Borongan::periksaBisaTermin($k, $r, $jenis, $bruto);
            $split = Borongan::hitungTermin($jenis, $bruto, (float)$k['retensi_persen'], $denda, $this->kasbon->saldo($k['project_id'], $k['worker_id']), $aktif);
            $no = $jenis === 'termin' ? $this->repo->nomorTerminBerikut($id) : 0;
            $this->repo->tambahTermin(['id' => $tid, 'kontrak_id' => $id, 'no_termin' => $no, 'jenis' => $jenis, 'tgl' => $tgl, 'bruto' => $bruto, 'retensi' => $split['retensi'], 'potong_kasbon_aktif' => $aktif ? 1 : 0,
                'potong_kasbon' => $split['potong_kasbon'], 'denda' => $denda, 'denda_ket' => $dendaKet, 'bersih' => $split['bersih'], 'status' => 'diajukan', 'ket' => $ket, 'created_by' => $user['id']]);
            return [$k, $split, $no];
        });
        $this->catat('create', 'borongan_termin', $tid, $k['no_kontrak'] . ($jenis === 'termin' ? " termin $no" : ' pelepasan retensi') . " · bruto $bruto");
        return ['ok' => true, 'id' => $tid, 'no_termin' => $no] + $split;
    }

    /** Termin yang sama dengan daftar lain tanpa dirinya (untuk pemeriksaan ulang saat disetujui). */
    private function tanpa(array $termin, string $id): array { return array_values(array_filter($termin, fn($t) => $t['id'] !== $id)); }

    public function terminSetujui(array $user, string $terminId): array {
        self::peran($user, ['akuntan'], 'Hanya Akuntan yang menyetujui termin.');
        $t0 = $this->repo->cariTermin($terminId);
        if (!$t0) throw new DomainError('Termin tidak ditemukan.', 404);
        [$k, $split] = $this->dalamTx($t0['kontrak_id'], function ($k) use ($user, $terminId) {
            $this->aksesKontrak($user, $k);
            $t = $this->repo->cariTermin($terminId);
            if ($t['status'] !== 'diajukan') throw new DomainError($t['status'] === 'ditolak' ? 'Termin ini sudah ditolak.' : 'Termin ini sudah diproses.', 409);
            $lain = $this->tanpa($this->repo->termin([$k['id']]), $terminId);
            $r = Borongan::ringkas($k, $this->repo->addenda([$k['id']]), $lain);
            Borongan::periksaBisaTermin($k, $r, $t['jenis'], (float)$t['bruto']);
            // potongan kasbon dihitung ulang dari saldo sebenarnya saat persetujuan
            $split = Borongan::hitungTermin($t['jenis'], (float)$t['bruto'], (float)$k['retensi_persen'], (float)$t['denda'], $this->kasbon->saldo($k['project_id'], $k['worker_id']), (bool)(int)$t['potong_kasbon_aktif']);
            $this->repo->setujuiTermin($terminId, $split['retensi'], $split['potong_kasbon'], $split['bersih'], $user['id']);
            return [$k, $split];
        });
        $this->catat('update', 'borongan_termin', $terminId, $k['no_kontrak'] . ' disetujui');
        return ['ok' => true, 'status' => 'disetujui'] + $split;
    }

    public function terminTolak(array $user, string $terminId, string $alasan): array {
        self::peran($user, ['akuntan'], 'Hanya Akuntan yang menolak termin.');
        $alasan = self::teks($alasan, 200);
        if ($alasan === '') throw new DomainError('Alasan wajib diisi.', 422);
        $t0 = $this->repo->cariTermin($terminId);
        if (!$t0) throw new DomainError('Termin tidak ditemukan.', 404);
        $k = $this->dalamTx($t0['kontrak_id'], function ($k) use ($user, $terminId, $alasan) {
            $this->aksesKontrak($user, $k);
            $t = $this->repo->cariTermin($terminId);
            if (!in_array($t['status'], ['diajukan', 'disetujui'], true)) throw new DomainError($t['status'] === 'dibayar' ? 'Termin yang sudah dibayar tidak bisa ditolak.' : 'Termin ini sudah diproses.', 409);
            $this->repo->tolakTermin($terminId, $alasan, $user['id']);
            return $k;
        });
        $this->catat('update', 'borongan_termin', $terminId, $k['no_kontrak'] . ' ditolak: ' . $alasan);
        return ['ok' => true, 'status' => 'ditolak'];
    }

    public function terminBayar(array $user, string $terminId, array $b): array {
        self::peran($user, ['akuntan'], 'Hanya Akuntan yang mencatat pembayaran.');
        $tglBayar = require_valid_date($b['tgl_bayar'] ?? date('Y-m-d'), 'Tanggal bayar');
        $metode = (($b['metode'] ?? '') === 'transfer') ? 'transfer' : 'tunai';
        $t0 = $this->repo->cariTermin($terminId);
        if (!$t0) throw new DomainError('Termin tidak ditemukan.', 404);
        [$k, $t, $bukti] = $this->dalamTx($t0['kontrak_id'], function ($k) use ($user, $terminId, $tglBayar, $metode) {
            $this->aksesKontrak($user, $k);
            $t = $this->repo->cariTermin($terminId);
            if ($t['status'] === 'diajukan') throw new DomainError('Termin belum disetujui. Setujui dulu.', 409);
            if ($t['status'] === 'dibayar') throw new DomainError('Termin ini sudah dibayar.', 409);
            if ($t['status'] !== 'disetujui') throw new DomainError('Termin ini sudah ditolak.', 409);
            if (!in_array($k['status'], ['disetujui', 'selesai'], true)) throw new DomainError('Kontrak ini tidak aktif.', 409);
            if ($tglBayar < $t['tgl']) throw new DomainError('Tanggal bayar tidak boleh sebelum tanggal termin.', 422);
            // potongan kasbon yang disetujui harus masih tertutup oleh saldo kasbon saat ini (baris kasbon tim dikunci selama pembayaran)
            if ($t['potong_kasbon'] > 0.004) {
                $saldo = $this->repo->saldoKasbonKunci($k['project_id'], $k['worker_id']);
                if ($t['potong_kasbon'] > $saldo + 0.005) throw new DomainError('Saldo kasbon tim berubah sejak termin disetujui (kini Rp ' . number_format(max(0, $saldo), 0, ',', '.') . '). Tolak termin ini lalu buat ulang.', 409);
            }
            $bukti = (new DocCounter($this->db))->berikutnya('BP', $tglBayar);
            $kasbonId = null;
            if ($t['potong_kasbon'] > 0.004) {
                $nm = ($t['jenis'] === 'lepas_retensi' ? 'pelepasan retensi' : 'termin ' . $t['no_termin']) . ' ' . $k['no_kontrak'];
                $kasbonId = $this->repo->catatPotongKasbon($k['project_id'], $k['worker_id'], $tglBayar, (float)$t['potong_kasbon'], 'Potong dari ' . $nm, $terminId, $user['id']);
            }
            $this->repo->bayarTermin($terminId, $tglBayar, $metode, $bukti, $kasbonId, $user['id']);
            return [$k, $t, $bukti];
        });
        $this->catat('update', 'borongan_termin', $terminId, $k['no_kontrak'] . " dibayar $bukti · bersih {$t['bersih']}" . ($t['potong_kasbon'] > 0 ? " · potong kasbon {$t['potong_kasbon']}" : ''));
        return ['ok' => true, 'status' => 'dibayar', 'bukti_no' => $bukti, 'dibayar' => (float)$t['bersih'], 'potong_kasbon' => (float)$t['potong_kasbon']];
    }

    /** Data untuk Bukti Pembayaran termin borongan. */
    public function bukti(array $user, string $buktiNo): array {
        $t = $this->repo->cariTerminByBukti($buktiNo);
        $k = $t ? $this->repo->cari($t['kontrak_id']) : null;
        if (!$t || !$k) throw new DomainError('Bukti pembayaran tidak ditemukan.', 404);
        $this->akses($user, $k['project_id']);
        $semua = $this->repo->termin([$k['id']]);
        $r = Borongan::ringkas($k, $this->repo->addenda([$k['id']]), $semua);
        $sampai = 0.0;
        foreach ($semua as $x) if ($x['status'] === 'dibayar' && $x['jenis'] === 'termin' && ($x['bukti_no'] === $buktiNo || NomorBukti::lebihAwal($x['bukti_no'], $buktiNo))) $sampai += $x['bruto'];
        $nama = $this->repo->namaUser([$t['dibayar_by']]);
        return ['bukti_no' => $buktiNo, 'tgl_bayar' => $t['tgl_bayar'], 'metode' => $t['metode'], 'dibuat_oleh' => $nama[$t['dibayar_by']] ?? '',
            'project_nama' => $k['project_nama'], 'tim_nama' => $k['tim_nama'], 'no_rek' => $k['no_rek'], 'no_kontrak' => $k['no_kontrak'], 'nama_pekerjaan' => $k['nama_pekerjaan'],
            'jenis' => $t['jenis'], 'no_termin' => $t['no_termin'], 'tgl_termin' => $t['tgl'], 'nilai_kini' => $r['nilai_kini'], 'retensi_persen' => $k['retensi_persen'],
            'bruto' => $t['bruto'], 'retensi' => $t['retensi'], 'denda' => $t['denda'], 'denda_ket' => $t['denda_ket'], 'potong_kasbon' => $t['potong_kasbon'], 'bersih' => $t['bersih'],
            'ditagih_sampai_ini' => round($sampai, 2), 'retensi_ditahan' => $r['retensi_ditahan'], 'ket' => $t['ket']];
    }
}
