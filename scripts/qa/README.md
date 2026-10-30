# Uji otomatis (lokal)

0. Tanpa database: `php scripts/qa/unit.php` (uji unit murni aturan gaji/kasbon/periode).
1. `./scripts/qa/up_env.sh` membuat database uji + akun uji + server `php -S` di port 8899.
2. Jalankan (Playwright + Chromium harus ada; `NODE_PATH` ke modul playwright):
   - `node scripts/qa/borongan_modul.js` — menu Borongan lewat API (72 pemeriksaan: alur, peran, retensi, kasbon, addendum, bersamaan). Jalankan di bawah jam terkunci: `scripts/qa/waktu_uji.sh mulai`, lalu `scripts/qa/waktu_uji.sh node borongan_modul.js`.
   - `node scripts/qa/bukti.js` — nomor Bukti Pembayaran berurut, isi bukti, akses (jam terkunci).
   - `node scripts/qa/ui_borongan.js`, `ui_bukti.js`, `ui_pengajuan.js` — layar Borongan, cetak bukti PDF, layar Pengajuan (jam nyata; `waktu_uji.sh berhenti` dulu bila server sedang dikunci).
   - `node scripts/qa/audit_keamanan.js` — 23 pemeriksaan keamanan (sesi, isolasi data, XSS, batas beban); database baru.
   - `node scripts/qa/ui_kasbon_riwayat.js` — HP lapangan: kotak pengajuan kasbon ringkas + tombol riwayat.
   - `node scripts/qa/ui_kasbon_riwayat.js` — HP lapangan: kotak pengajuan kasbon ringkas + tombol riwayat.
   - `node scripts/qa/kasbon_ubah.js` — potongan kasbon mingguan bisa diturunkan Akuntan saat Setujui (jam terkunci); `ui_kasbon_ubah.js` untuk layarnya (jam nyata).
   - `node scripts/qa/keamanan.js` — batas login per IP.
   - `node scripts/qa/aksesibilitas.js` — audit kontras teks, tombol tanpa nama, isian tanpa label (hanya membaca; jalankan setelah borongan_nonaktif_induk.js agar halaman berisi).
   - `node scripts/qa/golden2.js` — 134 respons pekerja/absen/kasbon harus identik dengan `golden2.json` (database baru; tanggal minggu 23–29 Okt 2026).
   - `node scripts/qa/golden.js` — 46 respons API harus identik dengan rekaman (`golden.json`); `golden.js simpan` hanya bila perubahan hitungan disengaja.
   - `node scripts/qa/core.js` — hitungan gaji, kasbon otomatis, periode susulan, peran, keamanan, hapus proyek.
   - `node scripts/qa/borongan_nonaktif_induk.js` — tim borongan, pekerja berhenti/aktif kembali, pekerja di dua proyek.
   - `node scripts/qa/borongan_pusat.js` / `borongan_dua_kali.js` — borongan dikelola pusat saja; satu tim dengan dua pekerjaan berjeda lama.
   - `node scripts/qa/ui_borongan_pusat.js` — Akuntan mengajukan/menyetujui minggu borongan tanpa absen lewat tombol di layar (database baru).
   - `node scripts/qa/ui.js` — HP lapangan dan layar Akuntan untuk fitur di atas.
   - `node scripts/qa/crawl.js` (jalankan setelah `borongan_nonaktif_induk.js` pada database yang sama: layar HP butuh pekerja di Proyek B) — semua halaman Admin/Akuntan/Owner + 4 ukuran layar HP.
   - `node scripts/qa/balapan.js` — permintaan bersamaan: persetujuan, pembayaran, kasbon, simpan absen tidak boleh ganda/menimpa.
     **Wajib** dengan server paralel: `PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8899 -t .` (server bawaan satu proses tidak menguji balapan sungguhan).
   - Beban: `python3 scripts/qa/data_besar.py | mysql -uroot material`, lalu `node scripts/qa/beban.js` dan `node scripts/qa/beban_panas.js`.
3. Bersihkan: hapus `backend/api/config.php`, `DROP DATABASE material`, matikan `php -S`.

Tanggal di beberapa skrip (minggu 16–29 Okt 2026) mengikuti tanggal uji; ubah `D0/D1` bila dijalankan di minggu lain.
