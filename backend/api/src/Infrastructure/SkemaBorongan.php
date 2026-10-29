<?php
declare(strict_types=1);
namespace App\Infrastructure;

/** Skema tabel modul Borongan untuk migrasi otomatis (server tidak wajib membawa file .sql). Harus sama dengan backend/mysql/schema.sql. */
final class SkemaBorongan {
    public const DDL = [
        <<<'SQL'
CREATE TABLE IF NOT EXISTS borongan_kontrak (
  id              VARCHAR(40) PRIMARY KEY,
  no_kontrak      VARCHAR(30) NOT NULL,
  project_id      VARCHAR(40) NOT NULL,
  worker_id       VARCHAR(40) NOT NULL,                  -- tim borongan (workers.borongan = 1)
  nama_pekerjaan  VARCHAR(160) NOT NULL,
  tgl_mulai       DATE NOT NULL,
  nilai           DECIMAL(14,2) NOT NULL,
  retensi_persen  DECIMAL(5,2) NOT NULL DEFAULT 0,
  catatan         VARCHAR(255) NOT NULL DEFAULT '',
  status          ENUM('draft','diajukan','disetujui','selesai','batal') NOT NULL DEFAULT 'draft',
  catatan_kembali VARCHAR(200) NULL,
  created_by      VARCHAR(40) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diajukan_at     DATETIME NULL, diajukan_by VARCHAR(40) NULL,
  disetujui_at    DATETIME NULL, disetujui_by VARCHAR(40) NULL,
  selesai_at      DATETIME NULL, selesai_by VARCHAR(40) NULL,
  batal_at        DATETIME NULL, batal_alasan VARCHAR(200) NULL,
  UNIQUE KEY uq_bk_no (no_kontrak),
  INDEX idx_bk_tim (project_id, worker_id),
  FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS borongan_addendum (
  id          VARCHAR(40) PRIMARY KEY,
  kontrak_id  VARCHAR(40) NOT NULL,
  tgl         DATE NOT NULL,
  jumlah      DECIMAL(14,2) NOT NULL,                    -- positif = tambah nilai, negatif = kurangi
  alasan      VARCHAR(200) NOT NULL DEFAULT '',
  status      ENUM('diajukan','disetujui','ditolak') NOT NULL DEFAULT 'diajukan',
  created_by  VARCHAR(40) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  diproses_by VARCHAR(40) NULL, diproses_at DATETIME NULL,
  INDEX idx_ba_kontrak (kontrak_id),
  FOREIGN KEY (kontrak_id) REFERENCES borongan_kontrak(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
        <<<'SQL'
CREATE TABLE IF NOT EXISTS borongan_termin (
  id                  VARCHAR(40) PRIMARY KEY,
  kontrak_id          VARCHAR(40) NOT NULL,
  no_termin           INT NOT NULL DEFAULT 0,
  jenis               ENUM('termin','lepas_retensi') NOT NULL DEFAULT 'termin',
  tgl                 DATE NOT NULL,
  bruto               DECIMAL(14,2) NOT NULL,
  retensi             DECIMAL(14,2) NOT NULL DEFAULT 0,
  potong_kasbon_aktif TINYINT(1) NOT NULL DEFAULT 1,
  potong_kasbon       DECIMAL(14,2) NOT NULL DEFAULT 0,
  denda               DECIMAL(14,2) NOT NULL DEFAULT 0,
  denda_ket           VARCHAR(160) NOT NULL DEFAULT '',
  bersih              DECIMAL(14,2) NOT NULL DEFAULT 0,
  status              ENUM('diajukan','disetujui','dibayar','ditolak') NOT NULL DEFAULT 'diajukan',
  ket                 VARCHAR(255) NOT NULL DEFAULT '',
  tgl_bayar           DATE NULL,
  metode              ENUM('tunai','transfer') NULL,
  bukti_no            VARCHAR(30) NULL,
  kasbon_id           VARCHAR(40) NULL,
  created_by          VARCHAR(40) NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  disetujui_at        DATETIME NULL, disetujui_by VARCHAR(40) NULL,
  dibayar_at          DATETIME NULL, dibayar_by VARCHAR(40) NULL,
  INDEX idx_bt_kontrak (kontrak_id),
  INDEX idx_bt_bukti (bukti_no),
  FOREIGN KEY (kontrak_id) REFERENCES borongan_kontrak(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL,
    ];
}
