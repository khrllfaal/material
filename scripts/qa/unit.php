<?php
// Uji unit murni (tanpa database, tanpa server): php scripts/qa/unit.php
declare(strict_types=1);
spl_autoload_register(function ($c) { $f = __DIR__ . '/../../backend/api/src/' . str_replace('\\', '/', substr($c, 4)) . '.php'; if (is_file($f)) require $f; });
use App\Domain\{PayrollCalculator, KasbonPlanner, PeriodRules, DomainError, AttendanceRules, WorkerRules, KasbonRules, ClientIp, NomorBukti, BuktiPembayaran};

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

function tolak(string $nama, callable $fn, int $kode): void { try { $fn(); t("$nama harus ditolak", false); } catch (DomainError $e) { t("$nama ditolak ($kode)", $e->status() === $kode, $e->getMessage()); } }
function lolos(string $nama, callable $fn): void { try { $fn(); t("$nama lolos", true); } catch (DomainError $e) { t("$nama lolos", false, $e->getMessage()); } }

// --- AttendanceRules
tolak('absen lapangan tanggal depan', fn() => AttendanceRules::periksaTanggalLapangan('2026-10-28', '2026-10-27', '2026-10-20'), 422);
tolak('absen lapangan >7 hari', fn() => AttendanceRules::periksaTanggalLapangan('2026-10-19', '2026-10-27', '2026-10-20'), 403);
lolos('absen lapangan tepat 7 hari lalu', fn() => AttendanceRules::periksaTanggalLapangan('2026-10-20', '2026-10-27', '2026-10-20'));
lolos('absen lapangan hari ini', fn() => AttendanceRules::periksaTanggalLapangan('2026-10-27', '2026-10-27', '2026-10-20'));
tolak('periode dibayar terkunci (admin)', fn() => AttendanceRules::periksaStatusPeriode('dibayar', false), 409);
tolak('lapangan setelah diajukan', fn() => AttendanceRules::periksaStatusPeriode('diajukan', true), 409);
lolos('admin setelah diajukan', fn() => AttendanceRules::periksaStatusPeriode('diajukan', false));
lolos('lapangan saat draft', fn() => AttendanceRules::periksaStatusPeriode('draft', true));
tolak('hari 0,3', fn() => AttendanceRules::periksaNilai(0.3, 0), 422);
tolak('lembur 0,3', fn() => AttendanceRules::periksaNilai(1, 0.3), 422);
tolak('lembur 16,5', fn() => AttendanceRules::periksaNilai(1, 17), 422);
tolak('lembur tanpa hadir', fn() => AttendanceRules::periksaNilai(0, 2), 422);
t('nilai sah 0,5 hari + 1,5 jam', AttendanceRules::periksaNilai(0.5, 1.5) === [0.5, 1.5]);
t('nilai sah 16 jam', AttendanceRules::periksaNilai(1, 16) === [1.0, 16.0]);
t('versi: tanpa ver => simpan', AttendanceRules::periksaVersi(['v' => [1.0, 0.0], 'rev' => 2], ['hari' => 1], true) === 'simpan');
t('versi cocok => simpan', AttendanceRules::periksaVersi(['v' => [1.0, 0.0], 'rev' => 2], ['hari' => 0.5, 'ver' => 2], true) === 'simpan');
t('versi basi => bentrok', AttendanceRules::periksaVersi(['v' => [1.0, 0.0], 'rev' => 3], ['hari' => 0.5, 'ver' => 2], true) === 'bentrok');
t('versi basi tapi nilai sama => lewati', AttendanceRules::periksaVersi(['v' => [1.0, 2.0], 'rev' => 3], ['hari' => 1, 'lembur_jam' => 2, 'ver' => 2], true) === 'lewati');
t('baris baru (ver null) padahal sudah ada => bentrok', AttendanceRules::periksaVersi(['v' => [1.0, 0.0], 'rev' => 0], ['hari' => 0.5, 'ver' => null], true) === 'bentrok');
t('hapus baris yang sudah tak ada => lewati', AttendanceRules::periksaVersi(null, ['hari' => null, 'ver' => 4], false) === 'lewati');
t('format ringkas', AttendanceRules::format([1.0, 2.5]) === '1h+2,5j' && AttendanceRules::format(null) === 'kosong' && AttendanceRules::format([0.5, 0.0]) === '0,5h+0j');
t('audit memuat peringatan setelah diajukan', str_contains(AttendanceRules::ringkasAudit('2026-10-27', 2, ['A: baru -> 1h+0j'], 'diajukan'), 'SETELAH DIAJUKAN (diajukan)'));
t('audit draft tanpa penanda', !str_contains(AttendanceRules::ringkasAudit('2026-10-27', 2, ['x'], 'draft'), 'SETELAH'));
t('pesan bentrok memotong >5 nama', str_contains(AttendanceRules::pesanBentrok(['a', 'b', 'c', 'd', 'e', 'f']), 'dll'));

// --- WorkerRules
t('normal: huruf besar & spasi', WorkerRules::normal('  Tim   A ') === 'tim a');
t('rapikanNama', WorkerRules::rapikanNama("  Budi   Santoso ") === 'Budi Santoso');
$grades = ['KTK' => 'a', 'TK' => 'b', 'LAIN' => 'c'];
tolak('isian tanpa proyek', fn() => WorkerRules::periksaIsian('', 'X', 'TK', '', $grades), 422);
tolak('isian nama kosong', fn() => WorkerRules::periksaIsian('P', '', 'TK', '', $grades), 422);
tolak('isian nama >120', fn() => WorkerRules::periksaIsian('P', str_repeat('a', 121), 'TK', '', $grades), 422);
tolak('isian grade salah', fn() => WorkerRules::periksaIsian('P', 'X', 'ZZ', '', $grades), 422);
tolak('isian tim >80', fn() => WorkerRules::periksaIsian('P', 'X', 'TK', str_repeat('t', 81), $grades), 422);
lolos('isian sah', fn() => WorkerRules::periksaIsian('P', 'X', 'TK', 'Tim A', $grades));
t('ejaan tim disamakan', WorkerRules::samakanEjaanTim('tim a ', ['Tim B', 'Tim A']) === 'Tim A' && WorkerRules::samakanEjaanTim('Tim Z', ['Tim A']) === 'Tim Z');
t('duplikat: nama & tim sama', WorkerRules::adaDuplikat([['nama' => 'Budi', 'tim' => 'Tim A']], ' budi', 'tim a') && !WorkerRules::adaDuplikat([['nama' => 'Budi', 'tim' => 'Tim A']], 'Budi', 'Tim B'));
t('nonaktif sama nama', (WorkerRules::cariNonaktifSama([['id' => 'x', 'nama' => 'Endang', 'tim' => '']], 'ENDANG') ?? [])['id'] === 'x' && WorkerRules::cariNonaktifSama([], 'A') === null);

// --- KasbonRules
t('kunciNama', KasbonRules::kunciNama('w', '') === 'w' && KasbonRules::kunciNama(null, 'A') === 't:A' && KasbonRules::kunciNama(null, '') === 'g');
tolak('jumlah 0', fn() => KasbonRules::periksaJumlahPengajuan(0), 422);
tolak('jumlah 2 miliar', fn() => KasbonRules::periksaJumlahPengajuan(2e9), 422);
lolos('jumlah 100.000', fn() => KasbonRules::periksaJumlahPengajuan(100000));
tolak('id pengajuan salah', fn() => KasbonRules::periksaIdPengajuan('BAD'), 422);
lolos('id pengajuan benar', fn() => KasbonRules::periksaIdPengajuan('ksr_abc123def456'));
t('id batch', KasbonRules::idBatch('bat_abc123def') === 'bat_abc123def' && KasbonRules::idBatch('x') === null && KasbonRules::idBatch(null) === null);
tolak('batas terbuka 60', fn() => KasbonRules::periksaBatasTerbuka(59, 2), 429);
lolos('batas terbuka tepat 60', fn() => KasbonRules::periksaBatasTerbuka(58, 2));
tolak('pelunasan > saldo', fn() => KasbonRules::periksaPelunasan(101.0, 100.0), 422);
lolos('pelunasan = saldo', fn() => KasbonRules::periksaPelunasan(100.0, 100.0));
tolak('hapus kasbon yang sudah terpotong', fn() => KasbonRules::periksaBisaDihapus(100.0, -50.0), 409);
lolos('hapus kasbon belum terpotong', fn() => KasbonRules::periksaBisaDihapus(100.0, 0.0));
lolos('hapus pelunasan (negatif) selalu boleh', fn() => KasbonRules::periksaBisaDihapus(-100.0, -500.0));
t('nama pihak', KasbonRules::namaPihak('w', '') === 'pekerja ini' && KasbonRules::namaPihak(null, 'A') === 'Tim A' && KasbonRules::namaPihak(null, '') === 'grup');

// --- ClientIp (Cloudflare)
t('IP Cloudflare dikenali (IPv4)', ClientIp::dalamRentang('172.67.10.20', ClientIp::CLOUDFLARE) && ClientIp::dalamRentang('104.18.5.5', ClientIp::CLOUDFLARE));
t('IP Cloudflare dikenali (IPv6)', ClientIp::dalamRentang('2606:4700:10::1', ClientIp::CLOUDFLARE));
t('IP biasa bukan Cloudflare', !ClientIp::dalamRentang('36.70.1.1', ClientIp::CLOUDFLARE) && !ClientIp::dalamRentang('127.0.0.1', ClientIp::CLOUDFLARE) && !ClientIp::dalamRentang('2001:db8::1', ClientIp::CLOUDFLARE));
t('batas rentang /20 tepat (173.245.48.0 - 173.245.63.255)', ClientIp::dalamRentang('173.245.63.255', ClientIp::CLOUDFLARE) && !ClientIp::dalamRentang('173.245.64.0', ClientIp::CLOUDFLARE));
t('lewat Cloudflare: pakai CF-Connecting-IP', ClientIp::resolve(['REMOTE_ADDR' => '172.67.10.20', 'HTTP_CF_CONNECTING_IP' => '36.70.1.1']) === '36.70.1.1');
t('langsung ke server: header CF palsu diabaikan', ClientIp::resolve(['REMOTE_ADDR' => '36.70.9.9', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4']) === '36.70.9.9');
t('header CF berisi sampah diabaikan', ClientIp::resolve(['REMOTE_ADDR' => '172.67.10.20', 'HTTP_CF_CONNECTING_IP' => 'bukan-ip']) === '172.67.10.20');
t('tanpa header: REMOTE_ADDR', ClientIp::resolve(['REMOTE_ADDR' => '36.70.9.9']) === '36.70.9.9' && ClientIp::resolve([]) === '');

// --- NomorBukti
t('format nomor bukti', NomorBukti::format('BP', '2026-10-29', 7) === 'BP/2026/10/0007' && NomorBukti::format('BR', '2027-01-02', 12345) === 'BR/2027/01/12345');
tolak('nomor urut 0 ditolak', fn() => NomorBukti::format('BP', '2026-10-29', 0), 500);
t('urai nomor', NomorBukti::urai('BP/2026/10/0007') === ['kode' => 'BP', 'tahun' => '2026', 'bulan' => '10', 'urut' => 7] && NomorBukti::urai('abc') === null);
t('urutan: 0009 sebelum 0010 (bukan urut abjad kasar)', NomorBukti::lebihAwal('BP/2026/10/0009', 'BP/2026/10/0010') && !NomorBukti::lebihAwal('BP/2026/10/0010', 'BP/2026/10/0009'));
t('urutan lintas bulan dan tahun', NomorBukti::lebihAwal('BP/2026/09/0099', 'BP/2026/10/0001') && NomorBukti::lebihAwal('BP/2026/12/0001', 'BP/2027/01/0001'));
t('data lama tanpa nomor dianggap paling awal', NomorBukti::lebihAwal(null, 'BP/2026/10/0001') && !NomorBukti::lebihAwal('BP/2026/10/0001', null) && !NomorBukti::lebihAwal(null, null));
t('nomor sama bukan lebih awal', !NomorBukti::lebihAwal('BP/2026/10/0001', 'BP/2026/10/0001'));

// --- BuktiPembayaran
$rowsW = ['w1' => ['nama' => 'Asep', 'no_rek' => 'BCA 1', 'grade_nama' => 'Tukang', 'tim' => 'A', 'borongan' => 0, 'hari' => 2, 'gaji' => 300000.0, 'lembur_rp' => 20000.0, 'tambah' => 0.0, 'potong' => 20000.0, 'net' => 300000.0],
          'w2' => ['nama' => 'Budi', 'no_rek' => '', 'grade_nama' => 'Tukang', 'tim' => 'A', 'borongan' => 0, 'hari' => 1, 'gaji' => 150000.0, 'lembur_rp' => 0.0, 'tambah' => 0.0, 'potong' => 0.0, 'net' => 150000.0]];
$semua = [['worker_id' => 'w1', 'jumlah' => 100000, 'bukti_no' => 'BP/2026/10/0001'], ['worker_id' => 'w1', 'jumlah' => 200000, 'bukti_no' => 'BP/2026/10/0002'], ['worker_id' => 'w2', 'jumlah' => 150000, 'bukti_no' => 'BP/2026/10/0002'],
          ['worker_id' => 'w1', 'jumlah' => 5000, 'bukti_no' => null], ['worker_id' => null, 'jumlah' => -10000, 'bukti_no' => 'BP/2026/10/0002']];
$b2 = BuktiPembayaran::susun('BP/2026/10/0002', array_values(array_filter($semua, fn($p) => $p['bukti_no'] === 'BP/2026/10/0002')), $semua, $rowsW);
$l = array_column($b2['lines'], null, 'worker_id');
t('bukti 0002: Asep dibayar 200.000, sebelumnya 105.000 (100.000 + data lama 5.000), sisa -5.000 dibulatkan', mirip($l['w1']['dibayar_ini'], 200000) && mirip($l['w1']['sebelumnya'], 105000) && mirip($l['w1']['sisa_setelah'], 300000 - 105000 - 200000));
t('bukti 0002: Budi dibayar penuh, sisa 0', mirip($l['w2']['dibayar_ini'], 150000) && mirip($l['w2']['sisa_setelah'], 0));
t('bukti 0002: baris grup -10.000 dan total = 200.000 + 150.000 - 10.000', mirip($b2['grup']['dibayar_ini'], -10000) && mirip($b2['total'], 340000));
$b1 = BuktiPembayaran::susun('BP/2026/10/0001', [$semua[0]], $semua, $rowsW);
t('bukti 0001: hanya pembayaran lebih awal yang dihitung (data lama 5.000 sebelumnya; 0002 belum ada)', mirip($b1['lines'][0]['sebelumnya'], 5000) && mirip($b1['lines'][0]['sisa_setelah'], 300000 - 5000 - 100000) && $b1['grup'] === []);
t('total bukti = jumlah baris dibayar ini', mirip($b1['total'], 100000));

echo "== uji unit: LULUS $ok / GAGAL $bad\n";
exit($bad ? 1 : 0);
