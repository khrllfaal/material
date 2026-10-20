# Mencoba frontend di Netlify (backend tetap di hosting PHP)

Cara paling aman: Netlify **meneruskan** (proxy) permintaan API ke hosting, sehingga browser melihat satu situs saja.
Cookie login tetap berfungsi, tanpa mengubah kode.

1. `frontend/backend-config.js` biarkan: `window.API_BASE_URL = '/coba/api';` (alamat relatif).
2. Taruh file `_redirects` (satu baris) di folder yang di-upload ke Netlify, sejajar dengan `index.html`:

       /coba/api/*  https://DOMAIN-HOSTING-ANDA/coba/api/:splat  200!

3. Di server hosting, `backend/api/config.php` (atau folder `coba/api/config.php`), isi alamat Netlify:

       'cors_origins' => ['https://NAMA-SITUS.netlify.app'],

   (alamat persis seperti di browser, tanpa garis miring di akhir)
4. Buka `https://NAMA-SITUS.netlify.app`, login, lalu tes. Untuk produksi cukup pakai satu domain (hosting) tanpa Netlify.

Catatan: jangan memakai alamat API lintas-situs langsung (`https://domain-hosting/...` dari Netlify) —
cookie login `SameSite=Lax` tidak dikirim lintas situs dan sebagian browser memblokir cookie pihak ketiga.
