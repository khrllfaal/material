<?php
declare(strict_types=1);
namespace App\Domain;

/** Aturan bisnis yang dilanggar (mis. periode sudah dibayar). Lapisan HTTP mengubahnya menjadi JSON {error, ...extra} + kode status. */
final class DomainError extends \RuntimeException {
    public function __construct(string $message, private int $status = 422, private array $extra = []) { parent::__construct($message); }
    public function status(): int { return $this->status; }
    /** Data tambahan untuk klien (mis. id pekerja nonaktif yang bisa diaktifkan kembali). */
    public function extra(): array { return $this->extra; }
}
