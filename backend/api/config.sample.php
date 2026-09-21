<?php
/**
 * Copy this file to config.php and fill in your real values.
 * config.php is gitignored — never commit real database credentials.
 */
return [
    'db_host' => '127.0.0.1',
    'db_name' => 'material',
    'db_user' => 'material_user',
    'db_pass' => 'CHANGE-ME',
    // Comma-separated origins allowed to call this API when the
    // frontend is NOT served from the same domain (e.g. local dev).
    // Leave empty when frontend + API share one Hostinger domain —
    // same-origin requests don't need CORS at all.
    'cors_origins' => [],
];
