-- Data percobaan Absensi: 2 proyek, tarif per status, dan pekerja.
-- Jalankan SETELAH migrations_2026-10-payroll.sql. Aman diulang (tidak menimpa).
-- Proyek dicari dari nama (mengandung "gudang utara" / "brigif" + "cimahi");
-- kalau belum ada, dibuat baru.
SET NAMES utf8mb4;

SET @gu := (SELECT id FROM projects WHERE LOWER(nama) LIKE '%gudang utara%' ORDER BY nama LIMIT 1);
INSERT INTO projects (id, nama) SELECT 'proj_gudang_utara', 'Gudang Utara' FROM DUAL WHERE @gu IS NULL;
SET @gu := COALESCE(@gu, 'proj_gudang_utara');

SET @bc := (SELECT id FROM projects WHERE LOWER(nama) LIKE '%brigif%' AND LOWER(nama) LIKE '%cimahi%' ORDER BY nama LIMIT 1);
INSERT INTO projects (id, nama) SELECT 'proj_brigif_cimahi', 'Brigif Cimahi' FROM DUAL WHERE @bc IS NULL;
SET @bc := COALESCE(@bc, 'proj_brigif_cimahi');

-- Tarif (lembur per jam = harian / 8, dibulatkan ke bawah per Rp500)
INSERT IGNORE INTO labor_rates (project_id, grade, tarif_harian, tarif_lembur) VALUES
  (@gu, 'TK', 150000, 18500), (@gu, 'LD', 130000, 16000),
  (@bc, 'KTK', 170000, 21000), (@bc, 'TK', 140000, 17500), (@bc, 'LD', 115000, 14000);

-- Gudang Utara (nama & upah dari file pengeluaran 7-8 Okt: Tukang 150.000, Laden 130.000)
INSERT IGNORE INTO workers (id, project_id, nama, grade, tim) VALUES
  ('wrk_gu_tedi',      @gu, 'Tedi',        'TK', 'Tim Utama'),
  ('wrk_gu_andri',     @gu, 'Andriansyah', 'TK', 'Tim Utama'),
  ('wrk_gu_suhen',     @gu, 'Suhen',       'TK', 'Tim Utama'),
  ('wrk_gu_ajat',      @gu, 'Ajat',        'TK', 'Tim Utama'),
  ('wrk_gu_irpan',     @gu, 'Irpan',       'TK', 'Tim Utama'),
  ('wrk_gu_didin',     @gu, 'Didin',       'TK', 'Tim Utama'),
  ('wrk_gu_halim',     @gu, 'Halim',       'TK', 'Tim Utama'),
  ('wrk_gu_sandi',     @gu, 'Sandi',       'TK', 'Tim Utama'),
  ('wrk_gu_abdul',     @gu, 'Abdul',       'LD', 'Tim Utama'),
  ('wrk_gu_fajar',     @gu, 'Fajar',       'LD', 'Tim Utama'),
  ('wrk_gu_miftah',    @gu, 'Miftah',      'LD', 'Tim Utama'),
  ('wrk_gu_irwan',     @gu, 'Irwan',       'LD', 'Tim Utama'),
  ('wrk_gu_fahri',     @gu, 'Fahri',       'LD', 'Tim Utama'),
  ('wrk_gu_ifan',      @gu, 'Ifan',        'LD', 'Tim Utama'),
  ('wrk_gu_ade',       @gu, 'Ade',         'LD', 'Tim Utama'),
  ('wrk_gu_yadi',      @gu, 'Yadi',        'LD', 'Tim Utama'),
  ('wrk_gu_usup',      @gu, 'Usup',        'LD', 'Tim Utama'),
  ('wrk_gu_ramdani',   @gu, 'Ramdani',     'LD', 'Tim Utama');

-- Brigif Cimahi (10 nama dari file Absen Upah rumdis Cimahi)
INSERT IGNORE INTO workers (id, project_id, nama, grade, tim) VALUES
  ('wrk_bc_zek',    @bc, 'Zek',    'KTK', 'Upah Harian'),
  ('wrk_bc_asep',   @bc, 'Asep',   'KTK', 'Upah Harian'),
  ('wrk_bc_atep',   @bc, 'Atep',   'TK',  'Upah Harian'),
  ('wrk_bc_tata',   @bc, 'Tata',   'TK',  'Upah Harian'),
  ('wrk_bc_sopian', @bc, 'Sopian', 'TK',  'Upah Harian'),
  ('wrk_bc_angsit', @bc, 'Angsit', 'TK',  'Upah Harian'),
  ('wrk_bc_iyan',   @bc, 'Iyan',   'TK',  'Upah Harian'),
  ('wrk_bc_dadang', @bc, 'Dadang', 'LD',  'Upah Harian'),
  ('wrk_bc_alan',   @bc, 'Alan',   'LD',  'Upah Harian'),
  ('wrk_bc_iki',    @bc, 'Iki',    'LD',  'Upah Harian');

SELECT p.nama AS proyek, COUNT(w.id) AS pekerja FROM projects p JOIN workers w ON w.project_id = p.id GROUP BY p.id;
