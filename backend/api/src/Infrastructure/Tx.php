<?php
declare(strict_types=1);
namespace App\Infrastructure;

/** Transaksi: berhasil => commit; gagal/ditolak (exception) => batal semua. Opsional diulang bila database memilih transaksi ini sebagai korban deadlock. */
final class Tx {
    public static function jalankan(\PDO $db, callable $fn, int $percobaan = 1): mixed {
        for ($i = 1; ; $i++) {
            $db->beginTransaction();
            try { $r = $fn(); $db->commit(); return $r; }
            catch (\PDOException $ex) {
                if ($db->inTransaction()) $db->rollBack();
                $kode = (int)($ex->errorInfo[1] ?? 0);
                if (($kode === 1213 || $kode === 1205) && $i < $percobaan) { usleep(random_int(30, 120) * 1000 * $i); continue; } // belum ada yang tertulis: aman diulang
                throw $ex;
            } catch (\Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
        }
    }
}
