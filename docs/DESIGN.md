# Arah desain

Pengguna: Akuntan dan Admin Pusat di komputer, Admin Lapangan di HP saat di proyek (cahaya terang, tangan kotor, sinyal buruk). Prioritas: terbaca, cepat diisi, tidak salah ketuk.

Aturan yang dipakai:
- Satu halaman, satu tugas. Judul jelas di bagian atas, tombol utama satu per layar.
- Angka rata kanan, `tabular-nums`, titik ribuan otomatis saat mengetik.
- Warna menandai status (hijau siap, kuning perlu dicek, merah masalah) dan selalu disertai teks.
- Teks sekunder minimal kontras 4,5:1. Isian punya label atau `aria-label`. Tombol berikon punya nama.
- Target ketuk di HP minimal 44 px. Fokus keyboard terlihat. Animasi mati bila perangkat meminta `prefers-reduced-motion`.
- Tanpa dekorasi, tanpa angka karangan, tanpa label hype. Teks antarmuka pendek, langsung, dan menyebut apa yang terjadi.
- Tiap perubahan diuji lewat `scripts/qa/aksesibilitas.js` (kontras, nama tombol, label isian).
