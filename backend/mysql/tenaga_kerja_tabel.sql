-- Tabel Absensi, Tarif, Rekap Gaji & Kasbon (Tenaga Kerja). Aman dijalankan berulang (IF NOT EXISTS).
-- Butuh tabel users & projects yang sudah ada. Jalankan lewat phpMyAdmin > SQL / Import.
SET NAMES utf8mb4;
-- ============================================================
-- Absensi & Rekap Gaji pekerja proyek
-- ============================================================

-- Tarif per proyek per status (Kepala Tukang, Tukang, Laden, ...). Nilai ini
-- disalin (snapshot) ke setiap baris absen saat disimpan, jadi kenaikan tarif
-- tidak mengubah gaji yang sudah lewat.
CREATE TABLE IF NOT EXISTS labor_rates (
  project_id    VARCHAR(40) NOT NULL,
  tim           VARCHAR(80) NOT NULL DEFAULT '',   -- '' = tarif standar proyek; isi = tarif khusus tim tsb
  grade         VARCHAR(10) NOT NULL,
  tarif_harian  DECIMAL(14,2) NOT NULL DEFAULT 0,
  tarif_lembur  DECIMAL(14,2) NOT NULL DEFAULT 0, -- per jam
  updated_by    VARCHAR(40) NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id, tim, grade),
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
  diverifikasi  TINYINT(1) NOT NULL DEFAULT 1,   -- selalu 1 (verifikasi pekerja baru dinonaktifkan)
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
  tim           VARCHAR(80) NOT NULL DEFAULT '',   -- baris milik satu tim (mis. potong kasbon tim)
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
  tim           VARCHAR(80) NOT NULL DEFAULT '',   -- kasbon atas nama tim (worker_id kosong)
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
  worker_id     VARCHAR(40) NULL,                 -- NULL = atas nama tim / grup / penanggung jawab
  tim           VARCHAR(80) NOT NULL DEFAULT '',   -- nama tim bila atas nama tim
  batch_id      VARCHAR(40) NULL,                  -- satu pengajuan berisi beberapa tim/pekerja
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

