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
  email           VARCHAR(190) NOT NULL UNIQUE,
  password_hash   VARCHAR(255) NOT NULL,
  nama            VARCHAR(190) NOT NULL,
  role            ENUM('admin','owner','lapangan') NOT NULL,
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
  user_email  VARCHAR(190) NULL,
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
