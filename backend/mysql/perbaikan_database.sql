-- =====================================================================
-- PERBAIKAN DATABASE — menyesuaikan database yang sudah berjalan dengan versi terbaru.
-- Aman dijalankan berulang kali (hanya mengubah yang belum ada). Data TIDAK dihapus.
-- Cara: phpMyAdmin > pilih database Anda > tab "SQL" > tempel seluruh isi file ini > Kirim.
-- Yang diperbaiki:
--   1. users.role   : menambah peran 'akuntan' (tanpa ini akun Akuntan tidak bisa dibuat / ditolak)
--   2. labor_rates  : kolom 'tim' agar satu proyek bisa punya tarif berbeda per tim
--   3. kasbon_requests : kolom 'tim' (kasbon atas nama tim)
--   4. Pastikan kolom kontrol gaji ada (rev, diverifikasi, disetujui_at/by) dan tabel sesi
-- =====================================================================
SET NAMES utf8mb4;

-- 1. peran Akuntan
SET @s = (SELECT IF(COLUMN_TYPE LIKE '%akuntan%', 'SELECT 1',
  "ALTER TABLE users MODIFY role ENUM('admin','owner','lapangan','akuntan') NOT NULL")
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. tarif per tim (kunci utama menjadi project_id + tim + grade)
SET @s = (SELECT IF(COUNT(*) > 0, 'SELECT 1',
  "ALTER TABLE labor_rates ADD COLUMN tim VARCHAR(80) NOT NULL DEFAULT '' AFTER project_id, ADD INDEX idx_lr_project (project_id), DROP PRIMARY KEY, ADD PRIMARY KEY (project_id, tim, grade)")
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'labor_rates' AND COLUMN_NAME = 'tim');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 3. kasbon atas nama tim
SET @s = (SELECT IF(COUNT(*) > 0, 'SELECT 1',
  "ALTER TABLE kasbon_requests ADD COLUMN tim VARCHAR(80) NOT NULL DEFAULT '' AFTER worker_id")
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'kasbon_requests' AND COLUMN_NAME = 'tim');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 4. kolom kontrol gaji (sudah ada di kebanyakan database; dicek agar pasti)
SET @s = (SELECT IF(COUNT(*) > 0, 'SELECT 1', "ALTER TABLE attendance ADD COLUMN rev INT NOT NULL DEFAULT 0")
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attendance' AND COLUMN_NAME = 'rev');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) > 0, 'SELECT 1',
  "ALTER TABLE workers ADD COLUMN diverifikasi TINYINT(1) NOT NULL DEFAULT 1, ADD COLUMN diverifikasi_by VARCHAR(40) NULL, ADD COLUMN diverifikasi_at DATETIME NULL")
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workers' AND COLUMN_NAME = 'diverifikasi');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) > 0, 'SELECT 1',
  "ALTER TABLE payroll_periods MODIFY status ENUM('draft','diajukan','disetujui','dibayar') NOT NULL DEFAULT 'draft', ADD COLUMN disetujui_at DATETIME NULL, ADD COLUMN disetujui_by VARCHAR(40) NULL")
  FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payroll_periods' AND COLUMN_NAME = 'disetujui_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

CREATE TABLE IF NOT EXISTS php_sessions (
  id            VARCHAR(128) NOT NULL PRIMARY KEY,
  data          MEDIUMBLOB NOT NULL,
  last_activity INT UNSIGNED NOT NULL,
  INDEX idx_sess_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Selesai. Cek hasilnya:
SELECT COLUMN_TYPE AS role_sekarang FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role';
