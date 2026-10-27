<?php
declare(strict_types=1);
namespace App\Domain;

/** Aturan bisnis yang dilanggar (mis. periode sudah dibayar). Lapisan HTTP mengubahnya menjadi JSON {error} + kode status. */
final class DomainError extends \RuntimeException {
    public function __construct(string $message, private int $status = 422) { parent::__construct($message); }
    public function status(): int { return $this->status; }
}
