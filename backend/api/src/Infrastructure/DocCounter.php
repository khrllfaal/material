<?php
declare(strict_types=1);
namespace App\Infrastructure;

use App\Domain\NomorBukti;

/** Penghitung nomor dokumen berurut per bulan. Harus dipanggil di dalam transaksi: kenaikan ikut batal bila transaksi batal (tanpa lubang, tanpa ganda). */
final class DocCounter {
    public function __construct(private \PDO $db) {}

    public function berikutnya(string $kode, string $tgl): string {
        $bulan = NomorBukti::bulan($tgl);
        $this->db->prepare('INSERT INTO doc_counters (kode, periode, terakhir) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE terakhir = terakhir + 1')->execute([$kode, $bulan]);
        $q = $this->db->prepare('SELECT terakhir FROM doc_counters WHERE kode = ? AND periode = ?');
        $q->execute([$kode, $bulan]);
        return NomorBukti::format($kode, $tgl, (int)$q->fetchColumn());
    }
}
