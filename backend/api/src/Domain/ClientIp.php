<?php
declare(strict_types=1);
namespace App\Domain;

/**
 * Alamat IP pengunjung yang sebenarnya. Di belakang Cloudflare, REMOTE_ADDR berisi alamat server Cloudflare; alamat asli ada di
 * header CF-Connecting-IP. Header itu hanya dipercaya bila sambungan memang datang dari rentang IP Cloudflare, karena siapa pun
 * bisa mengirim header palsu langsung ke server.
 */
final class ClientIp {
    /** Rentang IP Cloudflare (https://www.cloudflare.com/ips/). Periksa daftar resminya sesekali. */
    public const CLOUDFLARE = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20',
        '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public static function resolve(array $server, array $rentang = self::CLOUDFLARE): string {
        $remote = (string)($server['REMOTE_ADDR'] ?? '');
        $cf = trim((string)($server['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && filter_var($remote, FILTER_VALIDATE_IP) && self::dalamRentang($remote, $rentang)) return $cf;
        return $remote;
    }

    public static function dalamRentang(string $ip, array $rentang): bool {
        $bin = @inet_pton($ip);
        if ($bin === false) return false;
        foreach ($rentang as $cidr) {
            [$net, $bits] = explode('/', $cidr) + [1 => ''];
            $nb = @inet_pton($net);
            if ($nb === false || strlen($nb) !== strlen($bin)) continue; // IPv4 tidak dibandingkan dengan IPv6
            $bits = (int)$bits; $bytes = intdiv($bits, 8); $rem = $bits % 8;
            if (substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) continue;
            if ($rem === 0) return true;
            $mask = (0xFF << (8 - $rem)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($nb[$bytes]) & $mask)) return true;
        }
        return false;
    }
}
