# Menu Borongan

Satu tim borongan bisa punya beberapa **kontrak** dari waktu ke waktu. Setiap kontrak berisi nilai, retensi, termin pembayaran, dan addendum. Kasbon tim dipotong otomatis dari termin. Semua tercatat di buku besar per tim.

## Alur
1. **Tim borongan**: dibuat dari Borongan > "Tim borongan baru" (atau Pekerja & Tarif > "Tim borongan"). Satu tim = satu penerima pembayaran.
2. **Kontrak** (Admin Pusat atau Akuntan): nama pekerjaan, tanggal mulai, nilai, retensi (%). Statusnya Draft. Nomor otomatis, mis. `BK/2026/10/0001`.
3. **Ajukan**, lalu **Akuntan menyetujui**. Kontrak berstatus Berjalan.
4. **Termin** (Admin Pusat atau Akuntan): jumlah, tanggal, denda bila ada, potong kasbon ya atau tidak. Status Menunggu persetujuan.
5. **Akuntan menyetujui termin**, lalu **mencatat pembayaran** (tanggal, tunai atau transfer). Sistem memberi nomor bukti `BP/2026/10/0001` dan menyiapkan **Bukti Pembayaran (PDF)**.
6. **Selesaikan** kontrak (Akuntan) bila pekerjaan tuntas. Retensi yang ditahan bisa dilepas sesudahnya.

## Rumus satu termin
`Jumlah termin = retensi + denda + potong kasbon + diterima tim`
- Retensi = jumlah termin x persen retensi kontrak.
- Potong kasbon = yang terkecil antara saldo kasbon tim dan (jumlah - retensi - denda). Bila saldo tidak cukup, sisanya tetap menjadi saldo kasbon dan dipotong di termin berikutnya.
- Potongan kasbon dihitung ulang dari saldo sebenarnya saat termin disetujui, dan diperiksa lagi saat dibayar. Jika saldo kasbon turun di bawah potongan yang disetujui, pembayaran ditolak dan termin harus dibuat ulang.

## Aturan yang dijaga sistem
- Jumlah semua termin tidak boleh melebihi nilai kontrak (termasuk termin yang masih menunggu persetujuan).
- Pelepasan retensi tidak boleh melebihi retensi yang ditahan.
- Addendum (tambah atau kurang nilai) berlaku setelah disetujui Akuntan. Pengurangan tidak boleh melebihi sisa nilai yang belum ditagih.
- Satu termin hanya bisa dibayar sekali, juga bila dua orang menekan tombol bersamaan.
- Kontrak yang sudah ada pembayarannya tidak bisa dibatalkan. Tutup dengan "Selesaikan" dan kurangi nilai sebesar sisa yang tidak ditagih.
- Nomor kontrak dan nomor bukti berurut per bulan, tanpa nomor ganda dan tanpa lubang. Nomor bukti satu deret dengan bukti gaji mingguan.

## Buku besar tim
Saldo "kewajiban kepada tim" = nilai kontrak - yang dibayar - potong kasbon - denda. Retensi yang ditahan masih termasuk kewajiban. Yang tampil hanya kejadian yang sudah berlaku: kontrak disetujui, addendum disetujui, termin yang sudah dibayar. Ada ekspor Excel dan PDF.

## Jurnal (Rekap & Pembukuan > Pembukuan & Jurnal)
Beban diakui saat termin disetujui, kas keluar saat dibayar. Setiap peristiwa seimbang (debit = kredit).
| Peristiwa | Debit | Kredit |
|---|---|---|
| Termin disetujui | Beban Borongan (jumlah - denda) | Hutang Borongan (jumlah - retensi - denda), Hutang Retensi (retensi) |
| Pelepasan retensi disetujui | Hutang Retensi | Hutang Borongan, Beban Borongan (denda, bila ada) |
| Termin dibayar | Hutang Borongan | Kas atau Bank (diterima tim), Piutang Kasbon (kasbon dipotong) |

## Peran
| Peran | Boleh |
|---|---|
| Admin Pusat | membuat dan mengubah draft kontrak, mengajukan, membuat termin dan addendum, membatalkan draft |
| Akuntan | semua di atas, ditambah menyetujui, menolak, membayar, mengembalikan, menyelesaikan, membatalkan kontrak berjalan |
| Owner | hanya membaca |
| Admin Lapangan | tidak melihat menu ini |

## Catatan lama
Borongan yang dulu dicatat sebagai "Tambahan" pada minggu gaji tetap utuh dan masih tampil di Pengajuan dan rekap mingguan. Borongan baru sebaiknya lewat menu ini. Pajak (mis. PPh jasa konstruksi) belum dihitung otomatis.

## Uji
`scripts/qa/unit.php` (aturan hitung dan jurnal), `scripts/qa/borongan_modul.js` (alur lengkap, peran, pembayaran dan termin bersamaan), `scripts/qa/ui_borongan.js` (layar, PDF, jurnal).
