<?php
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = require __DIR__ . '/config.php';
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_name']);
    $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false, // real prepared statements — SQL injection safe
        // The app runs on Jakarta time everywhere (PHP is set to Asia/Jakarta); make MySQL's NOW()/CURRENT_TIMESTAMP/DATE()
        // agree, so a request made at 02:00 WIB Friday is never counted in Thursday's week by the database.
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+07:00'",
    ]);
    return $pdo;
}
