-- Menghapus 3 bahan lama: Semen (Sak), Pasir Cor (m3), Splite (M3).
-- Bahan lain (termasuk Sirtu) TIDAK disentuh.
-- PERHATIAN: Bahan Masuk, Pemakaian, hitungan fisik, dan pagu RAP milik
-- ketiga bahan ini IKUT TERHAPUS (aturan hapus berantai) dan tidak bisa
-- dikembalikan. Jalankan SEBELUM import_master_bahan.sql.
--
-- Langkah 1 (opsional, hanya melihat) — berapa data yang akan ikut hilang:
--   SELECT m.kode, m.nama,
--     (SELECT COUNT(*) FROM material_receipts r WHERE r.material_id = m.id) AS bahan_masuk,
--     (SELECT COUNT(*) FROM material_usage u WHERE u.material_id = m.id) AS pemakaian
--   FROM materials m
--   WHERE m.id IN ('mat_mv4vvja3r2aeo','mat_mv4vvq8zsha69','mat_mv4vvbhvp0mwu');

-- Langkah 2 — hapus. Syarat ID + nama dicocokkan supaya tidak salah hapus.
DELETE FROM materials WHERE id = 'mat_mv4vvja3r2aeo' AND nama = 'Semen'     AND satuan = 'Sak';
DELETE FROM materials WHERE id = 'mat_mv4vvq8zsha69' AND nama = 'Pasir Cor' AND satuan = 'm3';
DELETE FROM materials WHERE id = 'mat_mv4vvbhvp0mwu' AND nama = 'Splite'    AND satuan = 'M3';

SELECT kode, nama, satuan FROM materials ORDER BY kode;
