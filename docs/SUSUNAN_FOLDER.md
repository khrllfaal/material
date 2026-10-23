# Susunan folder di Hostinger (api/ langsung di public_html)

    public_html/
    ├── .htaccess
    ├── index.html            (pengalih ke frontend/)
    ├── frontend/             (aplikasi; backend-config.js berisi '../api')
    ├── api/                  (file .php; config.php diisi sendiri, tidak ikut paket)
    └── backend/
        └── backups/          (hasil backup; dilindungi .htaccess)

- Kode mengenali dua susunan: `backend/api` + `backend/<folder>` (bawaan repo) dan `api` + `backend/<folder>` (di atas).
- `config.php`: `'backup_dir' => ''` = otomatis memakai `backend/backups`.
- File `.sql` tidak perlu ada di server; hapus setelah database terisi.
