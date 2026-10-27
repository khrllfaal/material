<?php
// Uji unit murni (tanpa database, tanpa server): php scripts/qa/unit.php
declare(strict_types=1);
spl_autoload_register(function ($c) { $f = __DIR__ . '/../../backend/api/src/' . str_replace('\\', '/', substr($c, 4)) . '.php'; if (is_file($f)) require $f; });
use App\Domain\{PayrollCalculator, KasbonPlanner, PeriodRules, DomainError};

$ok = 0; $bad = 0;
function t(string $nama, bool $kondisi, string $info = ''): void { global $ok, $bad; if ($kondisi) $ok++; else { $bad++; echo "GAGAL: $nama $info\n"; } }
function mirip(float $a, float $b): bool { return abs($a - $b) < 0.005; }

$grades = ['KTK' => 'Kepala Tukang', 'TK' => 'Tukang', 'LAIN' => 'Lainnya'];
$calc = new PayrollCalculator($grades, 0.0);
function absen(string $w, string $tgl, float $hari, float $lembur = 0, float $th = 100000, float $tl = 10000, array $o = []): array {
    return $o + ['worker_id' => $w, 'project_id' => 'P', 'tgl' => $tgl, 'hari' => $hari, 'lembur_jam' => $lembur, 'grade' => 'TK', 'tim' => 'A', 'tarif_harian' => $th,
        'tarif_lembur' => $tl, 'nama' => "Pekerja $w", 'no_rek' => '', 'aktif' => 1, 'diverifikasi' => 1, 'borongan' => 0, 'induk_id' => null, 'project_nama' => 'Proyek P'];
}
function periode(array $adj = [], array $pay = [], string $mulai = '2026-10-16', string $akhir = '2026-10-22'): array {
    return ['id' => 'per1', 'project_id' => 'P', 'project_nama' => 'Proyek P', 'tgl_mulai' => $mulai, 'tgl_akhir' => $akhir, 'status' => 'draft', 'adjustments' => $adj, 'payments' => $pay];
}
function adj(?string $w, string $jenis, float $jumlah, string $kat = 'tambahan'): array { return ['worker_id' => $w, 'tim' => '', 'jenis' => $jenis, 'kategori' => $kat, 'label' => 'x', 'jumlah' => $jumlah, 'qty' => null, 'harga' => null, 'period_id' => 'per1']; }
function masuk(array $o = []): array { return $o + ['att' => [], 'ganda' => [], 'roster' => [], 'periods' => [], 'workers_by_id' => [], 'borongan' => []]; }

// --- gaji dasar: hari x tarif + lembur x tarif
$h = $calc->hitung(masuk(['att' => [absen('w1', '2026-10-16', 1, 2), absen('w1', '2026-10-17', 0.5)], 'periods' => [periode()]]));
$r = $h['rows'][0];
t('gaji = 1,5 hari x 100.000', mirip($r['gaji'], 150000));
t('lembur = 2 jam x 10.000', mirip($r['lembur_rp'], 20000));
t('net = gaji + lembur', mirip($r['net'], 170000));
t('belum dibayar: status belum, sisa penuh', $r['status_bayar'] === 'belum' && mirip($r['sisa'], 170000));
t('total periode dari absen', mirip($h['periods'][0]['total_bayar'], 170000) && $h['periods'][0]['pekerja'] === 1);
t('hari 0 tidak dihitung sebagai pekerja hadir pada periode', true);

// --- tambahan, potongan, pembayaran sebagian & lunas
$h = $calc->hitung(masuk(['att' => [absen('w1', '2026-10-16', 2)], 'periods' => [periode([adj('w1', 'tambah', 50000), adj('w1', 'potong', 20000)], [['worker_id' => 'w1', 'jumlah' => 100000.0]])]]));
$r = $h['rows'][0];
t('net = 200.000 + 50.000 - 20.000', mirip($r['net'], 230000));
t('dibayar sebagian 100.000, sisa 130.000', $r['status_bayar'] === 'sebagian' && mirip($r['sisa'], 130000));
$h = $calc->hitung(masuk(['att' => [absen('w1', '2026-10-16', 2)], 'periods' => [periode([], [['worker_id' => 'w1', 'jumlah' => 200000.0]])]]));
t('dibayar penuh = lunas, sisa 0', $h['rows'][0]['status_bayar'] === 'lunas' && mirip($h['rows'][0]['sisa'], 0));
t('total periode lunas: sisa 0', mirip($h['totals']['sisa'], 0));

// --- net nol & minus
$h = $calc->hitung(masuk(['att' => [absen('w1', '2026-10-16', 1)], 'periods' => [periode([adj('w1', 'potong', 100000)])]]));
t('net nol => status nol', $h['rows'][0]['status_bayar'] === 'nol');
$h = $calc->hitung(masuk(['att' => [absen('w1', '2026-10-16', 1)], 'periods' => [periode([adj('w1', 'potong', 150000)])]]));
t('potongan > gaji => bendera net_minus', in_array('net_minus', $h['rows'][0]['flags'], true));
t('net minus tidak membuat sisa negatif', $h['rows'][0]['sisa'] >= 0);

// --- bendera
$h = $calc->hitung(masuk(['att' => [absen('w1', '2026-10-16', 1, 9), absen('w2', '2026-10-16', 1, 0, 0, 0)], 'periods' => [periode()]]));
$f = array_column($h['rows'], 'flags', 'worker_id');
t('lembur > 8 jam => lembur_tinggi', in_array('lembur_tinggi', $f['w1'], true));
t('tarif 0 => tarif_kosong', in_array('tarif_kosong', $f['w2'], true));

// --- ganda
$h = $calc->hitung(masuk(['att' => [absen('w1', '2026-10-16', 0.5, 0, 100000, 0, ['induk_id' => 'w0']), absen('w9', '2026-10-16', 1, 0, 100000, 0, ['induk_id' => 'w0', 'project_id' => 'Q'])]]));
t('gandaDariAbsen: 1,5 hari sehari untuk orang yang sama', isset(PayrollCalculator::gandaDariAbsen([absen('w1', '2026-10-16', 0.5, 0, 1, 0, ['induk_id' => 'w0']), absen('w9', '2026-10-16', 1, 0, 1, 0, ['induk_id' => 'w0'])])['w0|2026-10-16']));
t('gandaDariAbsen: 0,5 + 0,5 = 1 bukan ganda', !PayrollCalculator::gandaDariAbsen([absen('w1', '2026-10-16', 0.5, 0, 1, 0, ['induk_id' => 'w0']), absen('w9', '2026-10-16', 0.5, 0, 1, 0, ['induk_id' => 'w0'])]));
$h = $calc->hitung(masuk(['att' => [absen('w1', '2026-10-16', 1)], 'ganda' => ['w1|2026-10-16' => true]]));
t('baris yang tanggalnya ganda diberi bendera ganda', in_array('ganda', $h['rows'][0]['flags'], true));

// --- hari yang belum diisi (roster)
$h = $calc->hitung(masuk(['att' => [absen('w1', '2026-10-16', 1), absen('w1', '2026-10-17', 1)], 'roster' => [['id' => 'w1', 'project_id' => 'P', 'since' => '2026-01-01'], ['id' => 'w2', 'project_id' => 'P', 'since' => '2026-01-01'], ['id' => 'w3', 'project_id' => 'P', 'since' => '2026-10-17']]]));
t('roster: w2 tidak ada di 2 hari yang diisi tetapi tidak punya baris', count($h['rows']) === 1);
$h = $calc->hitung(masuk(['att' => [absen('w1', '2026-10-16', 1), absen('w2', '2026-10-17', 1)], 'roster' => [['id' => 'w1', 'project_id' => 'P', 'since' => '2026-01-01'], ['id' => 'w2', 'project_id' => 'P', 'since' => '2026-10-17']]]));
$k = array_column($h['rows'], 'kosong', 'worker_id');
t('w1 kosong 1 hari (17 Okt), diberi bendera belum_diisi', $k['w1'] === 1 && in_array('belum_diisi', array_column($h['rows'], 'flags', 'worker_id')['w1'], true));
t('w2 baru terdaftar 17 Okt: 16 Okt tidak dihitung kosong', $k['w2'] === 0);

// --- borongan
$wb = ['nama' => 'Tim Kaca', 'grade' => 'LAIN', 'tim' => 'Tim Kaca', 'no_rek' => '', 'borongan' => 1, 'induk_id' => null];
$h = $calc->hitung(masuk(['periods' => [periode([adj('b1', 'tambah', 8000000)], [['worker_id' => 'b1', 'jumlah' => 3000000.0]])], 'workers_by_id' => ['b1' => $wb]]));
$r = $h['rows'][0];
t('borongan tanpa absen tetap punya baris', count($h['rows']) === 1 && $r['borongan'] === 1 && $r['grade_nama'] === 'Tim borongan');
t('borongan: net 8.000.000, sisa 5.000.000', mirip($r['net'], 8000000) && mirip($r['sisa'], 5000000) && $r['status_bayar'] === 'sebagian');
$h = $calc->hitung(masuk(['att' => [absen('b1', '2026-10-16', 1, 0, 0, 0, ['borongan' => 1])], 'periods' => [periode()]]));
t('borongan dengan absen tetapi tanpa nominal => borongan_kosong, bukan tarif_kosong', in_array('borongan_kosong', $h['rows'][0]['flags'], true) && !in_array('tarif_kosong', $h['rows'][0]['flags'], true));

// --- grup
$h = $calc->hitung(masuk(['att' => [absen('w1', '2026-10-16', 1)], 'periods' => [periode([adj(null, 'tambah', 30000), adj(null, 'potong', 10000)], [['worker_id' => null, 'jumlah' => 20000.0]])]]));
t('grup: net 20.000, dibayar 20.000', mirip($h['periods'][0]['grup_net'], 20000) && mirip($h['periods'][0]['grup_dibayar'], 20000));
t('total periode = gaji 100.000 + grup 20.000', mirip($h['periods'][0]['total_bayar'], 120000));

// --- periode terpotong: hanya hari dalam periode yang dihitung ke total periode
$h = $calc->hitung(masuk(['att' => [absen('w1', '2026-10-16', 1), absen('w1', '2026-10-20', 1)], 'periods' => [periode([], [], '2026-10-16', '2026-10-18')]]));
t('total periode hanya memuat hari 16-18 (bukan 20)', mirip($h['periods'][0]['total_bayar'], 100000));

// --- urutan baris: proyek, tim, grade, nama
$h = $calc->hitung(masuk(['att' => [absen('a', '2026-10-16', 1, 0, 1, 0, ['nama' => 'Zed', 'grade' => 'TK']), absen('b', '2026-10-16', 1, 0, 1, 0, ['nama' => 'Ali', 'grade' => 'KTK'])]]));
t('Kepala Tukang tampil sebelum Tukang', $h['rows'][0]['nama'] === 'Ali');

// --- KasbonPlanner
$rows = [['worker_id' => 'w1', 'nama' => 'A', 'tim' => 'T1', 'gaji' => 300000.0, 'lembur_rp' => 0.0], ['worker_id' => 'w2', 'nama' => 'B', 'tim' => 'T1', 'gaji' => 100000.0, 'lembur_rp' => 0.0]];
$p = KasbonPlanner::rencana(['w1' => 100000.0], [], [], $rows);
t('kasbon 100.000 < gaji: dipotong penuh', mirip($p['total'], 100000) && mirip($p['items'][0]['potong'], 100000));
$p = KasbonPlanner::rencana(['w2' => 500000.0], [], [], $rows);
t('kasbon melebihi gaji: dipotong sebatas gaji, sisanya dibawa', mirip($p['total'], 100000) && mirip($p['items'][0]['saldo'], 500000));
$p = KasbonPlanner::rencana(['w1' => 100000.0], ['w1' => 100000.0], [], $rows);
t('saldo nol tidak pernah dipotong', $p['items'] === [] && mirip($p['total'], 0));
$p = KasbonPlanner::rencana(['w1' => 400000.0], [], ['w1' => ['tambah' => 50000.0, 'potong' => 20000.0]], $rows);
t('batas potong memperhitungkan tambahan & potongan lain (330.000)', mirip($p['total'], 330000));
$p = KasbonPlanner::rencana(['t:T1' => 150000.0], [], [], $rows);
t('kasbon tim diambil dari sisa gaji pekerja tim', mirip($p['total'], 150000) && $p['items'][0]['nama'] === 'Tim T1' && $p['items'][0]['worker_id'] === null);
$p = KasbonPlanner::rencana(['t:T1' => 900000.0], [], [], $rows);
t('kasbon tim dibatasi total gaji tim (400.000)', mirip($p['total'], 400000));
$p = KasbonPlanner::rencana(['w1' => 300000.0, 't:T1' => 300000.0], [], [], $rows);
t('kasbon pekerja dipotong dulu, tim dari sisa (300k + 100k)', mirip($p['total'], 400000));
$p = KasbonPlanner::rencana([''=> 50000.0], [], [], $rows);
t('kasbon grup dipotong dari sisa gaji', mirip($p['total'], 50000) && $p['items'][0]['nama'] === 'Grup / penanggung jawab');
t('label tim', KasbonPlanner::labelTim('A') === 'Tim A' && KasbonPlanner::labelTim('Tim B') === 'Tim B' && KasbonPlanner::labelTim('tim c') === 'tim c');
t('kunci', KasbonPlanner::kunci('w', 'x') === 'w' && KasbonPlanner::kunci('', 'x') === 't:x' && KasbonPlanner::kunci('', '') === '');

// --- PeriodRules
t('minggu: Jumat 16 Okt 2026 mulai 16, akhir 22', PeriodRules::minggu('2026-10-16') === ['2026-10-16', '2026-10-22']);
t('minggu: Kamis 22 Okt masih minggu yang sama', PeriodRules::minggu('2026-10-22') === ['2026-10-16', '2026-10-22']);
t('minggu: Sabtu 17 Okt', PeriodRules::minggu('2026-10-17')[0] === '2026-10-16');
t('minggu: Jumat 23 Okt memulai minggu baru', PeriodRules::minggu('2026-10-23') === ['2026-10-23', '2026-10-29']);
t('minggu: Rabu 28 Okt', PeriodRules::minggu('2026-10-28') === ['2026-10-23', '2026-10-29']);
t('minggu: akhir tahun (Kamis 31 Des 2026)', PeriodRules::minggu('2026-12-31') === ['2026-12-25', '2026-12-31']);
t('minggu: Jumat 1 Jan 2027 memulai minggu baru', PeriodRules::minggu('2027-01-01') === ['2027-01-01', '2027-01-07']);
t('minggu: Rabu 30 Des 2026 dalam minggu 25-31 Des', PeriodRules::minggu('2026-12-30')[0] === '2026-12-25');
t('akhirDipotong: diajukan sebelum minggu berakhir', PeriodRules::akhirDipotong('2026-10-25', '2026-10-29', '2026-10-27') === '2026-10-25');
t('akhirDipotong: minggu sudah lewat => tidak dipotong', PeriodRules::akhirDipotong('2026-10-25', '2026-10-29', '2026-10-30') === null);
t('akhirDipotong: absen sampai hari terakhir => tidak dipotong', PeriodRules::akhirDipotong('2026-10-29', '2026-10-29', '2026-10-27') === null);
t('akhirDipotong: tanpa absen => tidak dipotong', PeriodRules::akhirDipotong('', '2026-10-29', '2026-10-27') === null);
t('terbuka: ada sisa', PeriodRules::terbuka(10.0, 0.0) && !PeriodRules::terbuka(0.0, 0.0) && PeriodRules::terbuka(0.0, -50.0));
foreach ([['pastikanBisaDiajukan', 'diajukan', 409], ['pastikanBisaDisetujui', 'draft', 409], ['pastikanBisaDibayar', 'diajukan', 409], ['pastikanBisaDikembalikan', 'dibayar', 409]] as [$m, $st, $kode]) {
    try { PeriodRules::$m($st); t("$m($st) harus ditolak", false); } catch (DomainError $e) { t("$m($st) ditolak dengan $kode", $e->status() === $kode); }
}
foreach ([['pastikanBisaDiajukan', 'draft'], ['pastikanBisaDisetujui', 'diajukan'], ['pastikanBisaDibayar', 'disetujui'], ['pastikanBisaDikembalikan', 'disetujui']] as [$m, $st]) {
    try { PeriodRules::$m($st); t("$m($st) lolos", true); } catch (DomainError) { t("$m($st) lolos", false); }
}

echo "== uji unit: LULUS $ok / GAGAL $bad\n";
exit($bad ? 1 : 0);
