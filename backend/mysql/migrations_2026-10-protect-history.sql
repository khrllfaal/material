-- Run once on an existing database. Makes it impossible (even from
-- phpMyAdmin) to delete a Proyek or Bahan that still has transactions:
-- previously ON DELETE CASCADE wiped all their Bahan Masuk/Pemakaian.
ALTER TABLE material_receipts DROP FOREIGN KEY material_receipts_ibfk_1, DROP FOREIGN KEY material_receipts_ibfk_2;
ALTER TABLE material_receipts
  ADD CONSTRAINT material_receipts_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
  ADD CONSTRAINT material_receipts_ibfk_2 FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE RESTRICT;

ALTER TABLE material_usage DROP FOREIGN KEY material_usage_ibfk_1, DROP FOREIGN KEY material_usage_ibfk_3;
ALTER TABLE material_usage
  ADD CONSTRAINT material_usage_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
  ADD CONSTRAINT material_usage_ibfk_3 FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE RESTRICT;

ALTER TABLE stock_counts DROP FOREIGN KEY stock_counts_ibfk_1, DROP FOREIGN KEY stock_counts_ibfk_2;
ALTER TABLE stock_counts
  ADD CONSTRAINT stock_counts_ibfk_1 FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE RESTRICT,
  ADD CONSTRAINT stock_counts_ibfk_2 FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE RESTRICT;
