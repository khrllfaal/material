# Arsitektur backend (bagian gaji & kasbon)

Tujuan: aturan uang berada di satu tempat yang bisa diuji tanpa database, dan SQL tidak bercampur dengan aturan.
Bagian lain (absen, pekerja, material, dsb.) masih berupa endpoint prosedural dan dipindah bertahap — lihat "Peta jalan".

```
backend/api/
├── payroll_periods.php, payroll.php, kasbon*.php …   HTTP: baca request, cek peran/proyek, panggil use case, tulis JSON
├── payroll_lib.php                                    pintu masuk lama (payroll_compute, kasbon_saldo, …) → meneruskan ke Application
└── src/                                               (dimuat otomatis, namespace App\…; tidak pernah dilayani web)
    ├── Domain/            aturan murni — TANPA database, TANPA HTTP
    │   ├── PayrollCalculator   hitung gaji, lembur, tambahan/potongan, pembayaran, bendera "perlu dicek", total per periode
    │   ├── KasbonPlanner       rencana potong kasbon otomatis
    │   ├── PeriodRules         minggu Jumat–Kamis, periode dipotong saat diajukan lebih awal, alur status
    │   └── DomainError         pelanggaran aturan (pesan + kode status)
    ├── Infrastructure/    semua SQL
    │   ├── PayrollRepository, KasbonRepository, PeriodRepository
    └── Application/       use case: ambil data → panggil aturan → simpan
        ├── PayrollService, KasbonService, PeriodService (header/ajukan/setujui/kembalikan/bayar)
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
| `php scripts/qa/unit.php` | 59 uji unit murni (hitungan, kasbon, aturan minggu/status) — tanpa database, hitungan detik |
| `node scripts/qa/golden.js` | 46 respons API (rekap, periode, kasbon, bayar, susulan, ganda, borongan) dibandingkan dengan rekaman sebelum refaktor — harus identik |
| `balapan.js`, `core.js`, `borongan_*.js`, `ui.js`, `crawl.js` | uji tersambung (lihat `scripts/qa/README.md`) |

Jika sengaja mengubah aturan hitung: ubah dulu uji unit, jalankan `node golden.js simpan` setelah memeriksa selisihnya satu per satu, lalu commit keduanya.

## Peta jalan (belum dikerjakan)
1. Fase 2 backend: pekerja, absen, kasbon (catat/permintaan) mengikuti pola yang sama.
2. Frontend: `index.html` (± 4.600 baris) dipecah per halaman + komponen bersama tanpa framework; ini bukan Atomic Design murni.
3. Opsional: framework/bundler hanya bila memang diperlukan.
