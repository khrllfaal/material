-- Run once on an existing database. Fresh installs get this table from schema.sql.
-- Hitungan fisik sisa material di lapangan, dicatat saat ada bahan datang
-- atau dipakai pada hari itu. Dua hitungan berurutan dipakai untuk menghitung
-- pemakaian fisik sebenarnya dan selisih yang tidak tercatat.
CREATE TABLE IF NOT EXISTS stock_counts (
  id          VARCHAR(120) PRIMARY KEY,
  project_id  VARCHAR(40) NOT NULL,
  material_id VARCHAR(40) NOT NULL,
  tgl         DATE NOT NULL,
  qty         DECIMAL(18,3) NOT NULL DEFAULT 0,
  ket         TEXT NULL,
  created_by  VARCHAR(40) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_count_day (project_id, material_id, tgl),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

