<?php
/**
 * Copy this file to config.php and fill in your real values.
 * config.php is gitignored — never commit real database credentials.
 */
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'accv2',
    'db_user' => 'accv2_user',
    'db_pass' => 'CHANGE-ME',
    // Comma-separated origins allowed to call this API when the
    // frontend is NOT served from the same domain (e.g. local dev).
    // Leave empty when frontend + API share one Hostinger domain —
    // same-origin requests don't need CORS at all.
    'cors_origins' => [],

    // Where bin/backup_db.php writes its .sql.gz dumps. On Hostinger,
    // point this OUTSIDE public_html (e.g. one level up) so backups are
    // never reachable over HTTP — see docs/DEPLOY_HOSTINGER.md.
    'backup_dir' => __DIR__ . '/../backups',
    // Dumps older than this are deleted each time the backup script runs.
    'backup_retention_days' => 14,
];
