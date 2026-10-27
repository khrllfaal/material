# Uji otomatis (lokal)

1. `./scripts/qa/up_env.sh` membuat database uji + akun uji + server `php -S` di port 8899.
2. Jalankan (Playwright + Chromium harus ada; `NODE_PATH` ke modul playwright):
   - `node scripts/qa/core.js` — hitungan gaji, kasbon otomatis, periode susulan, peran, keamanan, hapus proyek.
   - `node scripts/qa/borongan_nonaktif_induk.js` — tim borongan, pekerja berhenti/aktif kembali, pekerja di dua proyek.
   - `node scripts/qa/ui.js` — HP lapangan dan layar Akuntan untuk fitur di atas.
   - `node scripts/qa/crawl.js` — semua halaman Admin/Akuntan/Owner + 4 ukuran layar HP.
3. Bersihkan: hapus `backend/api/config.php`, `DROP DATABASE material`, matikan `php -S`.

Tanggal di beberapa skrip (minggu 16–29 Okt 2026) mengikuti tanggal uji; ubah `D0/D1` bila dijalankan di minggu lain.
