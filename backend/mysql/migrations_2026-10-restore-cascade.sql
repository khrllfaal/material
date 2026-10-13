-- Hanya perlu dijalankan bila database Anda sempat memakai
-- migrations_2026-10-protect-history.sql (RESTRICT). Mengembalikan
-- aturan: hapus Proyek/Bahan => riwayat transaksinya ikut terhapus.
ALTER TABLE material_receipts DROP FOREIGN KEY material_receipts_ibfk_1, DROP FOREIGN KEY material_receipts_ibfk_2;
ALTER TABLE material_receipts
  ADD CONSTRAINT material_receipts_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  ADD CONSTRAINT material_receipts_ibfk_2 FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE;
ALTER TABLE material_usage DROP FOREIGN KEY material_usage_ibfk_1, DROP FOREIGN KEY material_usage_ibfk_3;
ALTER TABLE material_usage
  ADD CONSTRAINT material_usage_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  ADD CONSTRAINT material_usage_ibfk_3 FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE;
ALTER TABLE stock_counts DROP FOREIGN KEY stock_counts_ibfk_1, DROP FOREIGN KEY stock_counts_ibfk_2;
ALTER TABLE stock_counts
  ADD CONSTRAINT stock_counts_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  ADD CONSTRAINT stock_counts_ibfk_2 FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE;
