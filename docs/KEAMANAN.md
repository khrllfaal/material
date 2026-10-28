# Keamanan

## Yang sudah ada di kode
- Kata sandi disimpan dengan bcrypt. Akun terkunci 15 menit setelah 5 kali salah. Tiap alamat IP dibatasi 20 kali gagal per 10 menit (tabel `login_attempts`, dibuat otomatis).
- Sesi disimpan di database, cookie `HttpOnly`, `SameSite=Lax`, `Secure` saat HTTPS. ID sesi diganti saat login.
- Semua kueri memakai parameter (PDO prepared). Permintaan tulis wajib JSON dan berasal dari situs sendiri (permintaan dari situs lain ditolak).
- Hak akses diperiksa di server, bukan di layar: Lapangan tidak pernah menerima angka gaji, Owner hanya membaca, hanya Akuntan yang menyetujui dan membayar.
- Header keamanan: CSP ketat (tidak memuat apa pun dari luar), HSTS, `nosniff`, larangan dipasang dalam frame, COOP.
- Folder `src`, `bin`, `sessions`, `backups`, `mysql`, `docs`, `scripts`, dan berkas `.sql/.md/.sh/.log` tidak bisa dibuka lewat web.
- Alamat IP di log audit dan pembatas login memakai IP pengunjung sebenarnya. Header `CF-Connecting-IP` hanya dipercaya bila sambungan datang dari rentang IP Cloudflare.

## Yang tidak bisa dilakukan kode: pasang Cloudflare
Perlindungan serangan massal (DDoS), aturan firewall terkelola, dan penyaring bot bekerja sebelum permintaan sampai ke Hostinger. Kode aplikasi tidak bisa menggantikannya. Cloudflare paket gratis cukup.

Langkah, sekitar 20 menit:
1. Daftar di cloudflare.com, tambahkan domain, lalu ganti nameserver di tempat domain dibeli sesuai yang ditunjukkan Cloudflare. Tunggu status "Active".
2. DNS: pastikan record `A` dan `www` berawan oranye (Proxied).
3. SSL/TLS: mode **Full (strict)**. Aktifkan **Always Use HTTPS** dan **Minimum TLS 1.2**.
4. Security > Settings: aktifkan **Bot Fight Mode**, Security Level **Medium**.
5. Security > WAF > Rate limiting rules: buat satu aturan. Jika URI Path berisi `/api/auth_login.php`, batasi 10 permintaan per 10 detik per IP, lalu blokir 10 menit.
6. Caching: matikan **Rocket Loader** dan **Auto Minify** (bisa merusak skrip di halaman). Buat Cache Rule: URI Path berisi `/api/` maka **Bypass cache**. Berkas aplikasi sudah mengatur cache sendiri.
7. Setelah semua berjalan beberapa hari, aktifkan HSTS di Cloudflare (SSL/TLS > Edge Certificates).

Uji setelah pasang: login, isi absen dari HP, buka Rekap. Jika ada yang gagal, ubah awan oranye menjadi abu-abu sementara, lalu kabari saya.

## Batas yang perlu diketahui
- Pembatas IP bisa menahan satu kantor atau satu jaringan seluler bila banyak orang salah kata sandi bersamaan. Batasnya 20 kali per 10 menit, jadi jarang terjadi.
- Kunci per akun bisa dipakai orang iseng untuk mengunci akun orang lain selama 15 menit. Aturan rate limit Cloudflare di langkah 5 mengurangi risikonya.
- Autentikasi dua langkah belum ada. Untuk akun Akuntan dan Admin Pusat, ini peningkatan berikutnya yang paling bernilai.
