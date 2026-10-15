-- OPSIONAL: aplikasi menambahkan kolom/tabel ini sendiri saat pertama dipakai
-- (butuh hak ALTER). Jalankan file ini SEKALI bila hosting membatasi hak itu.
-- Bila muncul "Duplicate column name", berarti sudah terpasang — abaikan.
ALTER TABLE workers
  ADD COLUMN diverifikasi TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN diverifikasi_by VARCHAR(40) NULL,
  ADD COLUMN diverifikasi_at DATETIME NULL;
ALTER TABLE payroll_periods
  MODIFY status ENUM('draft','diajukan','disetujui','dibayar') NOT NULL DEFAULT 'draft',
  ADD COLUMN disetujui_at DATETIME NULL,
  ADD COLUMN disetujui_by VARCHAR(40) NULL;
ALTER TABLE attendance ADD COLUMN rev INT NOT NULL DEFAULT 0;
