# Arsitektur backend (gaji, kasbon, absen, pekerja)

Tujuan: aturan uang berada di satu tempat yang bisa diuji tanpa database, dan SQL tidak bercampur dengan aturan.
Bagian material/stok/proyek/pengguna masih endpoint prosedural biasa dan dipindah bertahap — lihat "Peta jalan".

```
backend/api/
├── payroll_periods.php, payroll.php, attendance.php, workers.php, kasbon.php, kasbon_requests.php …
│                                                     HTTP: baca request, cek peran/proyek, panggil use case, tulis JSON (± 10–25 baris)
├── payroll_lib.php                                    perakit: payroll_services(), attendance_service(), worker_service(), … + fungsi lama (payroll_compute, kasbon_saldo, …)
└── src/                                               (dimuat otomatis, namespace App\…; tidak pernah dilayani web)
    ├── Domain/            aturan murni — TANPA database, TANPA HTTP
    │   ├── PayrollCalculator, KasbonPlanner, PeriodRules      gaji, potong kasbon otomatis, minggu Jumat–Kamis & alur status
    │   ├── Borongan            kontrak, termin, retensi, kasbon tim, buku besar, jurnal borongan
    │   ├── NomorBukti, BuktiPembayaran   nomor dokumen berurut dan isi bukti pembayaran
    │   ├── AttendanceRules     jendela waktu lapangan, nilai hari/lembur, bentrok versi, ringkasan audit
    │   ├── WorkerRules         nama/tim, validasi isian, deteksi pekerja ganda
    │   ├── KasbonRules         validasi pengajuan, batas terbuka, syarat pelunasan & hapus
    │   └── DomainError         pelanggaran aturan (pesan + kode status + data tambahan)
    ├── Infrastructure/    semua SQL
    │   ├── PayrollRepository, KasbonRepository, PeriodRepository, AttendanceRepository,
    │   │   WorkerRepository, KasbonBukuRepository, KasbonRequestRepository, BoronganRepository
    │   ├── DocCounter, SkemaBorongan   penghitung nomor dokumen, skema tabel untuk migrasi otomatis
    │   └── Tx             transaksi (batal bila gagal; opsional diulang saat deadlock)
    └── Application/       use case: ambil data → panggil aturan → simpan → audit
        ├── PayrollService, KasbonService, PeriodService (ajukan/setujui/kembalikan/bayar)
        ├── AttendanceService, WorkerService
        ├── KasbonBukuService, KasbonRequestService
        └── BuktiService, BoronganService (lihat docs/BORONGAN.md)
```

Arah ketergantungan: HTTP → Application → Domain ← Infrastructure. Domain tidak mengenal yang lain.

## Aturan yang harus dijaga
- `PeriodRepository::kunci()` selalu **pernyataan pertama** dalam transaksi (baris periode dikunci `FOR UPDATE`), dan periode dicari **sebelum** transaksi dibuka
  (`payroll_period()` di endpoint). Ini yang mencegah bayar/setujui ganda saat dua orang menekan tombol bersamaan (diuji `scripts/qa/balapan.js`).
- Pelanggaran aturan dilempar sebagai `DomainError`; `helpers.php` mengubahnya menjadi `{"error": "..."}` dengan kode status, dan transaksi dibatalkan.
- Angka gaji hanya dihitung di `PayrollCalculator`. Layar rekap, pembayaran, dan ekspor memakai hasil yang sama.

## Pengaman refaktor
| Alat | Fungsi |
|---|---|
| `php scripts/qa/unit.php` | 174 uji unit murni (hitungan gaji, kasbon, minggu/status, absen, pekerja, pengajuan kasbon) — tanpa database, hitungan detik |
| `node scripts/qa/golden.js` | 46 respons API gaji (rekap, periode, kasbon, bayar, susulan, ganda, borongan) dibandingkan dengan rekaman sebelum refaktor — harus identik |
| `scripts/qa/waktu_uji.sh` | menjalankan database dan server dengan jam dikunci 27 Okt 2026 (perlu paket `faketime`), supaya uji bertanggal tetap stabil kapan pun dijalankan |
| `node scripts/qa/golden2.js` | 134 respons API pekerja, absen (bentrok versi, jendela 7 hari, terkunci), buku kasbon, pengajuan kasbon — harus identik (tanggal mengikuti minggu 23–29 Okt 2026) |
| `balapan.js`, `core.js`, `borongan_*.js`, `ui.js`, `crawl.js` | uji tersambung (lihat `scripts/qa/README.md`) |

Jika sengaja mengubah aturan hitung: ubah dulu uji unit, jalankan `node golden.js simpan` setelah memeriksa selisihnya satu per satu, lalu commit keduanya.

## Peta jalan (belum dikerjakan)
1. Backend sisanya (material, stok, RAP, proyek, pengguna) mengikuti pola yang sama bila diperlukan.
2. Frontend: `index.html` (± 4.600 baris) dipecah per halaman + komponen bersama tanpa framework; ini bukan Atomic Design murni.
3. Opsional: framework/bundler hanya bila memang diperlukan.
