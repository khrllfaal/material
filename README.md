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
  schema.sql    skema tabel MySQL/MariaDB — aman dijalankan ulang

backend/api/    REST API PHP 8 di atas MySQL (sesi cookie, audit log)

scripts/
  build-tailwind.sh   build ulang frontend/vendor/tailwind.css setelah
                      mengubah class Tailwind di frontend/*.html
                      (butuh Node.js sekali saat development — tidak
                      dibutuhkan sama sekali saat aplikasi jalan/di-hosting)
```

## Setup cepat

1. Buat database MySQL, jalankan `backend/mysql/schema.sql` lewat
   phpMyAdmin (tab SQL) atau `mysql -u <user> -p <db> < schema.sql`.
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
5. Upload semua isi `frontend/` dan `backend/` ke hosting, buka
   `frontend/index.html`. Semua role login di halaman yang sama.

## Role (ditegakkan di backend, bukan cuma disembunyikan di UI)

- **Admin Lapangan** (`lapangan`) — hanya boleh input Bahan Masuk &
  Pemakaian, dibatasi ke proyek yang ditugaskan (menu "Kelola User").
  Tidak bisa mengakses data proyek lain sama sekali. Setelah login
  otomatis melihat tampilan tab HP, bukan sidebar desktop.
- **Admin Pusat** (`admin`) — akses penuh: kelola Master Proyek,
  Master Bahan, Master Pekerjaan, Konfigurasi RAP, lihat semua
  laporan, dan kelola user.
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
