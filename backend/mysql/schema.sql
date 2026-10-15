-- =====================================================================
-- Material & Stok — standalone material in/out + RAP tracking system
-- MySQL 8+/MariaDB (Hostinger or any shared host with phpMyAdmin).
-- Run once against a fresh database:
--   mysql -u <user> -p <database> < schema.sql
-- Safe to re-run (CREATE TABLE IF NOT EXISTS).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. users — three roles:
--   admin    — full read/write everywhere, manages other users
--   owner    — read-only everywhere (every write endpoint rejects it)
--   lapangan — field admin; can only write Bahan Masuk/Pemakaian for
--              the project(s) assigned in user_projects, and can't
--              read any project outside that scope.
-- No public signup — accounts are created by admin (via the "Kelola
-- User" screen) or bin/create_user.php on hosts with SSH access.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id              VARCHAR(40) PRIMARY KEY,
  username        VARCHAR(60) NOT NULL UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,
  nama            VARCHAR(190) NOT NULL,
  role            ENUM('admin','owner','lapangan','akuntan') NOT NULL,
  failed_attempts INT NOT NULL DEFAULT 0,
  locked_until    DATETIME NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Project master — minimal on purpose (no contract/budget fields);
-- this app only needs a project to scope material data to.
CREATE TABLE IF NOT EXISTS projects (
  id          VARCHAR(40) PRIMARY KEY,
  nama        VARCHAR(255) NOT NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which project(s) a 'lapangan' user may see/write.
CREATE TABLE IF NOT EXISTS user_projects (
  user_id     VARCHAR(40) NOT NULL,
  project_id  VARCHAR(40) NOT NULL,
  PRIMARY KEY (user_id, project_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Master bahan (material catalogue) — shared across all projects.
CREATE TABLE IF NOT EXISTS materials (
  id                  VARCHAR(40) PRIMARY KEY,
  kode                VARCHAR(40) NOT NULL,
  nama                VARCHAR(255) NOT NULL,
  satuan              VARCHAR(40) NOT NULL DEFAULT '',
  kategori            VARCHAR(120) NOT NULL DEFAULT '',
  stok_minimum        DECIMAL(18,3) NOT NULL DEFAULT 0, -- reorder point (minimarket-style)
  updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- Master pekerjaan (BOQ work items) per project — the unit that RAP
-- material coefficients and progress % are tied to.
CREATE TABLE IF NOT EXISTS pekerjaan (
  id              VARCHAR(40) PRIMARY KEY,
  project_id      VARCHAR(40) NOT NULL,
  nama            VARCHAR(255) NOT NULL,
  satuan          VARCHAR(40) NOT NULL DEFAULT '',
  volume_kontrak  DECIMAL(18,3) NOT NULL DEFAULT 0,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pekerjaan_project (project_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- RAP config: how much material is *ideally* needed per unit volume of
-- a pekerjaan (koefisien). target_material_ideal for a given progress
-- = koefisien * aktual_volume (see progress_pekerjaan below).
CREATE TABLE IF NOT EXISTS rap_material (
  id            VARCHAR(40) PRIMARY KEY,
  pekerjaan_id  VARCHAR(40) NOT NULL,
  material_id   VARCHAR(40) NOT NULL,
  koefisien     DECIMAL(18,6) NOT NULL DEFAULT 0,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_rap_pekerjaan_material (pekerjaan_id, material_id),
  FOREIGN KEY (pekerjaan_id) REFERENCES pekerjaan(id) ON DELETE CASCADE,
  FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Simple RAP: one total budget qty per project+material, no BOQ/
-- pekerjaan breakdown needed. This is the one pusat should fill in for
-- every project (fast, always fillable); rap_material above stays
-- available for a deeper per-pekerjaan breakdown on projects worth the
-- extra setup effort.
CREATE TABLE IF NOT EXISTS rap_bahan_proyek (
  id            VARCHAR(40) PRIMARY KEY,
  project_id    VARCHAR(40) NOT NULL,
  material_id   VARCHAR(40) NOT NULL,
  rap_qty       DECIMAL(18,3) NOT NULL DEFAULT 0,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_rap_project_material (project_id, material_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Latest cumulative % progress per pekerjaan, reported from the field.
CREATE TABLE IF NOT EXISTS progress_pekerjaan (
  id             VARCHAR(40) PRIMARY KEY,
  pekerjaan_id   VARCHAR(40) NOT NULL,
  tgl            DATE NOT NULL,
  volume_aktual  DECIMAL(18,3) NOT NULL DEFAULT 0, -- cumulative to-date, not incremental
  ket            TEXT NULL,
  created_by     VARCHAR(40) NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_progress_pekerjaan (pekerjaan_id, tgl),
  FOREIGN KEY (pekerjaan_id) REFERENCES pekerjaan(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bahan Masuk (material receipts / stock IN), input by field admins.
-- vendor is plain text (no vendor master in this standalone app).
CREATE TABLE IF NOT EXISTS material_receipts (
  id             VARCHAR(40) PRIMARY KEY,
  project_id     VARCHAR(40) NOT NULL,
  material_id    VARCHAR(40) NOT NULL,
  tgl            DATE NOT NULL,
  qty            DECIMAL(18,3) NOT NULL DEFAULT 0,
  harga_satuan   DECIMAL(18,2) NOT NULL DEFAULT 0,
  vendor         VARCHAR(255) NOT NULL DEFAULT '',
  no_referensi   VARCHAR(80) NOT NULL DEFAULT '', -- no. surat jalan / nota
  ket            TEXT NULL,
  created_by     VARCHAR(40) NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_receipts_project_material (project_id, material_id),
  INDEX idx_receipts_tgl (tgl),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pemakaian (material usage / stock OUT), input by field admins.
-- pekerjaan_id is optional (usage isn't always tied to one BOQ item)
-- but is required for RAP deviation analysis to work for that row.
CREATE TABLE IF NOT EXISTS material_usage (
  id             VARCHAR(40) PRIMARY KEY,
  project_id     VARCHAR(40) NOT NULL,
  pekerjaan_id   VARCHAR(40) NULL,
  material_id    VARCHAR(40) NOT NULL,
  tgl            DATE NOT NULL,
  qty            DECIMAL(18,3) NOT NULL DEFAULT 0,
  ket            TEXT NULL,
  created_by     VARCHAR(40) NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_usage_project_material (project_id, material_id),
  INDEX idx_usage_pekerjaan (pekerjaan_id),
  INDEX idx_usage_tgl (tgl),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (pekerjaan_id) REFERENCES pekerjaan(id) ON DELETE SET NULL,
  FOREIGN KEY (material_id) REFERENCES materials(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit log — who changed what, when (append-only, never edited).
CREATE TABLE IF NOT EXISTS audit_log (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id     VARCHAR(40) NULL,
  user_username VARCHAR(60) NULL,
  action      ENUM('create','update','delete') NOT NULL,
  entity      VARCHAR(40) NOT NULL,
  entity_id   VARCHAR(40) NOT NULL,
  detail      TEXT NULL,
  ip_address  VARCHAR(45) NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_entity (entity, entity_id),
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Absensi & Rekap Gaji pekerja proyek
-- ============================================================

-- Tarif per proyek per status (Kepala Tukang, Tukang, Laden, ...). Nilai ini
-- disalin (snapshot) ke setiap baris absen saat disimpan, jadi kenaikan tarif
-- tidak mengubah gaji yang sudah lewat.
CREATE TABLE IF NOT EXISTS labor_rates (
  project_id    VARCHAR(40) NOT NULL,
  grade         VARCHAR(10) NOT NULL,
  tarif_harian  DECIMAL(14,2) NOT NULL DEFAULT 0,
  tarif_lembur  DECIMAL(14,2) NOT NULL DEFAULT 0, -- per jam
  updated_by    VARCHAR(40) NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id, grade),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workers (
  id            VARCHAR(40) PRIMARY KEY,
  project_id    VARCHAR(40) NOT NULL,
  nama          VARCHAR(120) NOT NULL,
  grade         VARCHAR(10) NOT NULL,           -- KTK, TK, LD, OBK, SPR, OMX, LAIN
  tim           VARCHAR(80) NOT NULL DEFAULT '',
  tarif_harian  DECIMAL(14,2) NULL,             -- tarif khusus orang ini (NULL = ikut tarif status)
  tarif_lembur  DECIMAL(14,2) NULL,
  no_rek        VARCHAR(80) NOT NULL DEFAULT '', -- rekening / e-wallet, opsional
  aktif         TINYINT(1) NOT NULL DEFAULT 1,
  diverifikasi  TINYINT(1) NOT NULL DEFAULT 1,   -- pekerja baru dari lapangan = 0 sampai diperiksa pusat
  diverifikasi_by VARCHAR(40) NULL,
  diverifikasi_at DATETIME NULL,
  created_by    VARCHAR(40) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_workers_project (project_id, aktif),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Satu baris = satu pekerja, satu proyek, satu tanggal. hari: 0 / 0.5 / 1.
CREATE TABLE IF NOT EXISTS attendance (
  worker_id     VARCHAR(40) NOT NULL,
  project_id    VARCHAR(40) NOT NULL,
  tgl           DATE NOT NULL,
  hari          DECIMAL(3,1) NOT NULL DEFAULT 0,
  lembur_jam    DECIMAL(4,1) NOT NULL DEFAULT 0,
  grade         VARCHAR(10) NOT NULL DEFAULT '',     -- snapshot
  tim           VARCHAR(80) NOT NULL DEFAULT '',     -- snapshot
  tarif_harian  DECIMAL(14,2) NOT NULL DEFAULT 0,    -- snapshot
  tarif_lembur  DECIMAL(14,2) NOT NULL DEFAULT 0,    -- snapshot
  ket           VARCHAR(255) NOT NULL DEFAULT '',
  rev           INT NOT NULL DEFAULT 0,               -- bertambah tiap diubah: mencegah dua perangkat saling menimpa
  created_by    VARCHAR(40) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (worker_id, project_id, tgl),
  INDEX idx_att_project_tgl (project_id, tgl),
  INDEX idx_att_tgl (tgl),
  FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Periode gaji mingguan (Jumat–Kamis) per proyek: draft -> diajukan -> dibayar.
CREATE TABLE IF NOT EXISTS payroll_periods (
  id            VARCHAR(80) PRIMARY KEY,
  project_id    VARCHAR(40) NOT NULL,
  tgl_mulai     DATE NOT NULL,
  tgl_akhir     DATE NOT NULL,
  status        ENUM('draft','diajukan','disetujui','dibayar') NOT NULL DEFAULT 'draft',
  pj_nama       VARCHAR(120) NOT NULL DEFAULT '',  -- penanggung jawab
  grup_nama     VARCHAR(120) NOT NULL DEFAULT '',
  catatan       VARCHAR(255) NOT NULL DEFAULT '',
  diajukan_at   DATETIME NULL, diajukan_by VARCHAR(40) NULL,
  disetujui_at  DATETIME NULL, disetujui_by VARCHAR(40) NULL,   -- diperiksa & disetujui Admin Pusat
  dibayar_at    DATETIME NULL, dibayar_by  VARCHAR(40) NULL,
  UNIQUE KEY uniq_period (project_id, tgl_mulai),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tambahan / potongan pada satu periode. worker_id NULL = untuk seluruh grup
-- (mis. "air galon" +378.000 atau "kasbon" -7.500.000 pada lembar pengajuan).
CREATE TABLE IF NOT EXISTS payroll_adjustments (
  id            VARCHAR(40) PRIMARY KEY,
  period_id     VARCHAR(80) NOT NULL,
  worker_id     VARCHAR(40) NULL,
  jenis         ENUM('tambah','potong') NOT NULL,
  kategori      VARCHAR(20) NOT NULL DEFAULT 'lainnya', -- kasbon, potongan, bonus, tambahan
  label         VARCHAR(160) NOT NULL,
  qty           DECIMAL(12,3) NULL,
  harga         DECIMAL(14,2) NULL,
  jumlah        DECIMAL(14,2) NOT NULL,
  created_by    VARCHAR(40) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_adj_period (period_id),
  FOREIGN KEY (period_id) REFERENCES payroll_periods(id) ON DELETE CASCADE,
  FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pembayaran gaji. worker_id NULL = baris penyesuaian grup.
CREATE TABLE IF NOT EXISTS payroll_payments (
  id            VARCHAR(40) PRIMARY KEY,
  period_id     VARCHAR(80) NOT NULL,
  worker_id     VARCHAR(40) NULL,
  jumlah        DECIMAL(14,2) NOT NULL,
  tgl_bayar     DATE NOT NULL,
  metode        ENUM('tunai','transfer') NOT NULL DEFAULT 'tunai',
  ket           VARCHAR(255) NOT NULL DEFAULT '',
  created_by    VARCHAR(40) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pay_period (period_id),
  FOREIGN KEY (period_id) REFERENCES payroll_periods(id) ON DELETE CASCADE,
  FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kasbon yang diberikan. Saldo = kasbon diberikan - potongan kasbon pada gaji.
-- worker_id NULL = kasbon atas nama grup/penanggung jawab.
CREATE TABLE IF NOT EXISTS kasbon (
  id            VARCHAR(40) PRIMARY KEY,
  project_id    VARCHAR(40) NOT NULL,
  worker_id     VARCHAR(40) NULL,
  tgl           DATE NOT NULL,
  jumlah        DECIMAL(14,2) NOT NULL,
  ket           VARCHAR(255) NOT NULL DEFAULT '',
  created_by    VARCHAR(40) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_kasbon_pw (project_id, worker_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pengajuan kasbon dari lapangan: lapangan mengajukan, Akuntan/Admin Pusat memeriksa
-- lalu menyetujui (otomatis tercatat di buku kasbon) atau menolak.
CREATE TABLE IF NOT EXISTS kasbon_requests (
  id            VARCHAR(40) PRIMARY KEY,
  project_id    VARCHAR(40) NOT NULL,
  worker_id     VARCHAR(40) NULL,                 -- NULL = atas nama grup / penanggung jawab
  jumlah        DECIMAL(14,2) NOT NULL,
  ket           VARCHAR(255) NOT NULL DEFAULT '',
  status        ENUM('diajukan','disetujui','ditolak') NOT NULL DEFAULT 'diajukan',
  catatan       VARCHAR(255) NOT NULL DEFAULT '',  -- alasan penolakan / catatan akuntan
  kasbon_id     VARCHAR(40) NULL,                  -- baris buku kasbon yang dibuat saat disetujui
  created_by    VARCHAR(40) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diproses_by   VARCHAR(40) NULL,
  diproses_at   DATETIME NULL,
  INDEX idx_kreq_status (status, created_at),
  INDEX idx_kreq_project (project_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

