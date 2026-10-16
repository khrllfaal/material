# Material & Stok

Sistem input & monitoring bahan material untuk proyek konstruksi —
admin lapangan input Bahan Masuk & Pemakaian dari HP, pusat memantau
stok (seperti inventory minimarket) dan pemborosan bahan (RAP vs
aktual) lintas puluhan proyek sekaligus.

Backend **PHP + MySQL** (cocok langsung di hosting shared seperti
Hostinger, database dikelola lewat phpMyAdmin). Frontend pakai
Tailwind CSS + ikon Lucide, tapi **di-build jadi file statis** dan
disimpan di repo (`frontend/vendor/`) — bukan dimuat dari CDN saat
halaman dibuka. Ini sengaja: admin lapangan sering kerja dengan sinyal
HP pas-pasan di lokasi proyek, jadi halaman harus tetap cepat & tetap
tampil rapi walau internet lambat/putus-putus, bukan bergantung ke
CDN pihak ketiga setiap kali dibuka.

## Struktur repo

Frontend sengaja **satu file HTML saja** (`index.html`) untuk Admin
Pusat, Owner, maupun Admin Lapangan — supaya revisi ke depan (ganti
warna, tambah menu, perbaiki bug) cukup edit satu file, bukan tiga.
Setelah login, halaman otomatis menampilkan tampilan sidebar desktop
(Admin Pusat/Owner) atau tampilan tab HP (Admin Lapangan) sesuai role
akun — bukan berdasarkan halaman mana yang dibuka.

```
frontend/
  index.html    SATU halaman untuk semua role — tampilan menyesuaikan
                otomatis setelah login (sidebar desktop untuk Admin
                Pusat/Owner, tab HP untuk Admin Lapangan)
  backend-config.js   API_BASE_URL (arahkan ke folder backend/api/)
  vendor/
    tailwind.css  CSS terkompilasi (hasil build, lihat scripts/build-tailwind.sh)
    icons.js      subset ikon Lucide, di-inline lokal (bukan CDN)

backend/mysql/
  database_lengkap.sql  DATABASE BARU lengkap (21 tabel + akun Admin Pusat awal). MENGHAPUS tabel lama lalu membuat ulang
  schema.sql    skema yang sama tanpa menghapus apa pun (CREATE IF NOT EXISTS) — sumber database_lengkap.sql

backend/api/    REST API PHP 8 di atas MySQL (sesi cookie, audit log)

scripts/
  build-tailwind.sh   build ulang frontend/vendor/tailwind.css setelah
                      mengubah class Tailwind di frontend/*.html
                      (butuh Node.js sekali saat development — tidak
                      dibutuhkan sama sekali saat aplikasi jalan/di-hosting)
```

## Setup cepat

1. Buat database MySQL, jalankan `backend/mysql/database_lengkap.sql` lewat
   phpMyAdmin (tab SQL). Perhatian: file ini menghapus tabel lama. Login awal: `adminpusat` (ganti password di Kelola User).
2. Salin `backend/api/config.sample.php` → `backend/api/config.php`,
   isi kredensial database Anda.
3. Buat akun Admin Pusat pertama:
   - Ada akses SSH: `php backend/api/bin/create_user.php admin@contoh.com "PasswordKuat123" "Nama Admin" admin`
   - Tidak ada SSH (Hostinger shared hosting): jalankan skrip itu di
     komputer lokal untuk mendapatkan password hash, lalu `INSERT` baris
     ke tabel `users` lewat phpMyAdmin.
4. Isi `frontend/backend-config.js` — `window.API_BASE_URL` diarahkan
   ke folder `backend/api` (default `../backend/api`, sudah pas kalau
   `frontend/` dan `backend/` diupload sejajar di domain yang sama).
5. Upload `index.html`, `.htaccess`, folder `frontend/` dan `backend/` ke
   `public_html` (lihat `docs/DEPLOY_HOSTINGER.md`), lalu buka domain Anda. Semua role login di halaman yang sama.

## Role (ditegakkan di backend, bukan cuma disembunyikan di UI)

- **Admin Lapangan** (`lapangan`) — hanya boleh input Bahan Masuk &
  Pemakaian, dibatasi ke proyek yang ditugaskan (menu "Kelola User").
  Tidak bisa mengakses data proyek lain sama sekali. Setelah login
  otomatis melihat tampilan tab HP, bukan sidebar desktop.
- **Admin Pusat** (`admin`) — akses penuh untuk mengelola dan mengoreksi data: kelola Master Proyek,
  Master Bahan, Master Pekerjaan, Konfigurasi RAP, lihat semua
  laporan, dan kelola user.
- **Akuntan** (`akuntan`) — hanya 3 menu Tenaga Kerja: Pengajuan & Approval, Rekap & Pembukuan, Pekerja & Tarif.
  Memeriksa dan menyetujui absen yang diajukan, memeriksa pengajuan kasbon, dan membayar; tidak
  bisa mengubah absen, dan seluruh endpoint stok/bahan/user ditolak (403).
- **Owner** (`owner`) — bisa melihat semua menu, tapi setiap endpoint
  menolak permintaan tulis (POST/DELETE) dari role ini dengan HTTP
  403; tombol Tambah/Edit/Hapus juga disembunyikan di UI.

## Alur kerja

1. **Admin Pusat** mengisi Master Proyek, Master Bahan, Master
   Pekerjaan (item BOQ per proyek), dan Konfigurasi RAP (koefisien
   bahan ideal per satuan volume pekerjaan).
2. **Admin Lapangan** input Bahan Masuk (qty diterima) dan Pemakaian
   (qty dipakai, opsional ditautkan ke satu pekerjaan) dari HP.
3. Dashboard **Monitor Stok** otomatis menghitung stok berjalan
   (masuk − keluar) tiap proyek dan menandai status Aman / Perlu
   Dikirim / Habis berdasarkan stok minimum tiap bahan.
4. Dashboard **Analisis RAP Bahan** membandingkan pemakaian aktual
   dengan target ideal (koefisien RAP × progress volume pekerjaan
   terakhir), menandai pemborosan (Melebihi RAP) per pekerjaan.

## Absensi & Rekap Gaji

- Admin Lapangan: menu **Absen** di bar bawah HP — satu ketukan per pekerja (Hadir / ½ / Absen), tombol **Semua hadir**
  (per tim bila ada beberapa tim), *Lembur standar*, jam lembur bisa diketik (1,5 atau 1.5), cari nama, filter
  Belum/Hadir/½/Absen, pilih tanggal dengan mengetuk tanggal. **Tambah pekerja** (tim dipilih dari daftar atau tim baru),
  lalu **Ajukan** absen mingguan (Jumat–Kamis) untuk **satu proyek** — tombolnya berwarna: abu-abu (belum ada absen),
  kuning (ada hari belum lengkap), hijau (siap diajukan); setelah diajukan berubah jadi lencana status.
  Minggu dengan hanya 2–3 hari kerja: hari tanpa pekerjaan ditandai lewat **Tandai libur / tidak kerja** (pengingat
  hari kosong); hari kosong dianggap tidak kerja. Tidak pernah melihat nominal gaji.
  Tombol **Kasbon**: atas nama **tim** (pilihan pertama) atau pekerja (bisa dicari); **satu pengajuan per nama per
  minggu**; kasbon dipotong dari gaji saat dibayar sehingga tidak perlu menunggu absen minggu itu lengkap.
- **Pengajuan & Approval** (Akuntan; Admin Pusat/Owner hanya melihat & mengoreksi): kartu *Harus dibayar sekarang*,
  *Menunggu persetujuan*, *Kasbon menunggu*, filter proyek/status/tanggal, tab **Gaji Mingguan** (Periksa & Setujui —
  dengan rincian per tim —, Catat Pembayaran, Kembalikan, Tambahan/Potongan, unduh Absen Upah per tim, Daftar Bayar PDF,
  Daftar Transfer) dan tab **Kasbon** (Setujui/Tolak, Catat Kasbon langsung). Excel/PDF daftar pengajuan.
- **Rekap & Pembukuan**: kartu ringkasan, tab **Rincian Pekerja**, **Pembukuan & Jurnal** (beban upah per proyek +
  jurnal debit/kredit: beban upah, potong kasbon, pembayaran kas/bank, kasbon diberikan), **Buku Kasbon**,
  **Evaluasi** (kehadiran %, lembur, biaya per hari, per tim & per pekerja, biaya per minggu). Semua bisa diunduh.
- **Pekerja & Tarif**: kartu ringkasan, tarif per status per proyek, dan **tarif khusus per tim** (kolom tim; kosong =
  ikut tarif standar proyek). Urutan tarif: pribadi pekerja → tarif tim → tarif standar proyek.
- Gaji = hari × tarif harian; lembur = jam lembur × tarif lembur; tarif disimpan
  per baris absen sehingga kenaikan tarif tidak mengubah periode yang sudah lewat.
- Struktur tabel baru (kolom tim, peran Akuntan, dsb.) ditambahkan otomatis oleh backend saat Admin login; untuk database baru cukup `database_lengkap.sql`.
  Opsional: `seed_absensi_percobaan.sql` (2 proyek percobaan), `import_master_bahan.sql` (201 bahan).

### Pengendalian (anti-kecurangan & keamanan)
- Alur gaji: **Lapangan mengajukan → Akuntan memeriksa & menyetujui → Akuntan membayar.**
  **Admin Pusat** hanya mengoreksi dan menghapus data (absen, pekerja, tarif, penyesuaian) — tidak menyetujui
  atau membayar. Absen yang diubah setelah disetujui otomatis membatalkan persetujuan.
- Pekerja baru yang ditambah lapangan langsung aktif (tanpa verifikasi, agar sederhana). Tarif kosong menahan persetujuan.
- Setiap penyimpanan menampilkan indikator *Menyimpan…* dan menonaktifkan tombol Simpan sampai selesai (mencegah dobel input).
- **Log Aktivitas** (Admin Pusat & Owner): siapa mengubah apa, sebelum → sesudah, dan penanda "SETELAH DIAJUKAN".
- Tidak ada data yang saling menimpa: dua HP yang mengedit absen yang sama tidak saling menimpa (yang
  terlambat ditolak dan layar dimuat ulang), dan persetujuan/pembayaran dikunci per periode sehingga tidak bisa
  terbayar dua kali walau ditekan bersamaan.
- Keamanan: sesi tetap aktif (1 tahun sejak pemakaian terakhir, di tabel database `php_sessions`, tidak hilang saat file sesi hosting dibersihkan; folder `backend/sessions` hanya cadangan), cookie HttpOnly + SameSite,
  tolak permintaan lintas-situs (CSRF: wajib JSON & Origin sama), pertanyaan SQL terparameter, teks pengguna
  selalu di-escape, waktu login sama untuk user dikenal/tidak (anti-enumerasi), penguncian akun setelah 5 kali
  salah, file internal (`helpers.php`, `payroll_lib.php`, `bin/`, `sessions/`) diblokir dari akses web.
- Kolom/tabel baru dibuat otomatis saat pertama dipakai (perlu hak ALTER/CREATE pada database).
