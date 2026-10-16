# Deploy di Hostinger (public_html)

Struktur yang benar di File Manager:

```
public_html/
  index.html          <- membuka frontend/ otomatis (dari repo)
  .htaccess
  frontend/           <- index.html, logo.png, vendor/, sw.js, ...
  backend/
    api/              <- *.php + config.php (dibuat manual, JANGAN dari repo)
    mysql/            <- schema.sql (tidak bisa diunduh berkat .htaccess)
```

Jangan menaruh isi `frontend/` langsung di `public_html` kecuali `backend-config.js`
diubah ke `backend/api` (bukan `../backend/api`).

## Langkah
1. hPanel > Databases > buat database + user. Impor `backend/mysql/database_lengkap.sql` lewat phpMyAdmin (menghapus tabel lama; login awal `adminpusat`, segera ganti password).
2. Salin `backend/api/config.sample.php` jadi `backend/api/config.php`, isi kredensial database.
3. Pastikan versi PHP 8.1+ (hPanel > Advanced > PHP Configuration).
4. Akun Admin Pusat awal sudah ada di database_lengkap.sql (username `adminpusat`). Akun lain dibuat dari menu Kelola User.
   Database lama yang datanya masih dipakai: tidak perlu impor ulang — struktur baru ditambahkan otomatis saat Admin login.
5. Buka `https://domain-anda/` — otomatis masuk ke aplikasi.

## 403 Forbidden di halaman utama
Penyebab paling umum: `public_html` tidak punya `index.html`/`index.php`
(file ada di subfolder `frontend/`). Solusi: upload `index.html` + `.htaccess` dari root repo.
Penyebab lain: izin file/folder (folder 755, file 644), atau file `.htaccess` rusak
(hapus sementara untuk menguji), atau domain diarahkan ke folder yang salah.

## Diagnosa cepat
- `https://domain/frontend/index.html` terbuka? Jika ya, masalahnya hanya index di root.
- `https://domain/backend/api/auth_me.php` harus menjawab JSON `{"error":"Belum login."}`.
  403/500 di sini = salah letak folder, izin, atau `config.php` belum dibuat / salah.
- `https://domain/backend/api/config.php` harus DITOLAK (403) — itu normal dan aman.
