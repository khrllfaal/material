<?php
declare(strict_types=1);
namespace App\Infrastructure;

/** Batas percobaan masuk yang gagal per alamat IP (melengkapi kunci per akun, yang tidak menahan satu IP yang mencoba banyak akun). */
final class LoginThrottle {
    public const BATAS = 20;       // gagal
    public const MENIT = 10;       // dalam rentang ini

    public function __construct(private \PDO $db) {
        $db->exec('CREATE TABLE IF NOT EXISTS login_attempts (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ip VARCHAR(45) NOT NULL, at DATETIME NOT NULL,
            INDEX idx_login_ip_at (ip, at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public function terlalumBanyak(string $ip): bool {
        $q = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND at > (NOW() - INTERVAL ' . self::MENIT . ' MINUTE)');
        $q->execute([$ip]);
        return (int)$q->fetchColumn() >= self::BATAS;
    }

    public function catatGagal(string $ip): void {
        $this->db->prepare('INSERT INTO login_attempts (ip, at) VALUES (?, NOW())')->execute([$ip]);
        if (random_int(1, 50) === 1) $this->db->exec('DELETE FROM login_attempts WHERE at < (NOW() - INTERVAL 1 DAY)'); // bersih-bersih sesekali
    }
}
