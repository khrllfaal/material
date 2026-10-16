<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

/**
 * Generic list/upsert/delete handler shared by every simple resource
 * (customers, vendors, projects, coa, transactions, jurnal_umum).
 * Mirrors the frontend's syncUpsert()/syncDelete() contract: POST
 * always upserts (insert-or-update) by primary key, matching how the
 * app already treats every save. Every write is logged to audit_log.
 *
 * $columns: whitelist of writable columns (id must be included).
 * $idColumn: primary key column name, default 'id'.
 * $insertOnlyColumns: columns (e.g. created_by) written on first
 * insert but never overwritten by a later upsert-as-update.
 * $writeRoles: if non-null, only these roles may POST/DELETE (GET still
 * only requires being logged in, unless $readRoles narrows it too).
 * Existing callers that omit it keep today's behaviour — any logged-in
 * role can write.
 * $readRoles: if non-null, only these roles may GET. Used to keep
 * 'lapangan' (field admin) accounts — no business reason to see
 * company-wide financial data — out of the accounting tables entirely,
 * not just blocked from writing to them.
 */
function handle_resource_crud(string $table, array $columns, string $idColumn = 'id', array $insertOnlyColumns = [], ?array $writeRoles = null, ?array $readRoles = null): void {
    // CORS (and the OPTIONS preflight short-circuit) must run before any
    // auth check — a preflight request never carries credentials, so
    // require_login() would 401 it before the browser ever got to see
    // the CORS headers, and silently block the real request that follows.
    send_cors_headers();
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method !== 'GET') {
        // 'owner' is read-only everywhere in this app, no matter what
        // $writeRoles says — it's never meant to be a writer role.
        require_role($writeRoles !== null ? array_diff($writeRoles, ['owner']) : ['admin']);
    } else {
        $readRoles !== null ? require_role($readRoles) : require_login();
    }

    if ($method === 'GET') {
        // Field admins only need their own projects' names, not the
        // company's whole project list.
        if ($table === 'projects') {
            $scope = user_project_ids(current_user());
            if ($scope !== null) {
                if (!$scope) { json_response([]); return; }
                $in = implode(',', array_fill(0, count($scope), '?'));
                $st = db()->prepare("SELECT * FROM projects WHERE id IN ($in) ORDER BY id");
                $st->execute($scope);
                json_response($st->fetchAll());
                return;
            }
        }
        $rows = db()->query("SELECT * FROM `$table` ORDER BY `$idColumn`")->fetchAll();
        json_response($rows);
        return;
    }

    if ($method === 'POST') {
        $body = read_json_body();
        $data = [];
        foreach ($columns as $col) {
            if (array_key_exists($col, $body)) $data[$col] = $body[$col];
        }
        if (empty($data[$idColumn])) json_error('id wajib diisi', 422);

        $cols = array_keys($data);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $colList = implode(',', array_map(fn($c) => "`$c`", $cols));
        $updateCols = array_filter($cols, fn($c) => $c !== $idColumn && !in_array($c, $insertOnlyColumns, true));
        $updateList = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $updateCols));
        $sql = "INSERT INTO `$table` ($colList) VALUES ($placeholders)"
             . ($updateList ? " ON DUPLICATE KEY UPDATE $updateList" : '');
        $stmt = db()->prepare($sql);
        $stmt->execute(array_values($data));

        audit('update', $table, (string)$data[$idColumn]);
        json_response(['ok' => true, 'id' => $data[$idColumn]]);
        return;
    }

    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? '';
        if ($id === '') json_error('id wajib diisi', 422);
        // Deleting a proyek/bahan intentionally removes its transactions
        // too (FK ON DELETE CASCADE) so the data always matches the master
        // list. Count them first so the audit log records what went.
        $detail = '';
        if ($table === 'projects') {
            // A project that has attendance, wage payments or kasbon can still be deleted, but only on purpose:
            // the admin must type the exact project name (a mistaken tap can never wipe money records).
            $cnt = [];
            foreach (['attendance' => 'absen', 'kasbon' => 'kasbon'] as $t => $label) {
                $c = db()->prepare("SELECT COUNT(*) FROM `$t` WHERE project_id = ?"); $c->execute([$id]);
                $n = (int)$c->fetchColumn(); if ($n > 0) $cnt[$label] = $n;
            }
            $c = db()->prepare('SELECT COUNT(*) FROM payroll_payments pp JOIN payroll_periods p ON p.id = pp.period_id WHERE p.project_id = ?'); $c->execute([$id]);
            $n = (int)$c->fetchColumn(); if ($n > 0) $cnt['pembayaran gaji'] = $n;
            if ($cnt) {
                $nm = db()->prepare('SELECT nama FROM projects WHERE id = ?'); $nm->execute([$id]);
                $nama = (string)$nm->fetchColumn();
                if (trim((string)($_GET['konfirmasi'] ?? '')) !== $nama || $nama === '') {
                    json_response(['error' => 'Proyek ini punya data ' . implode(', ', array_keys($cnt)) . '. Ketik nama proyek untuk memastikan penghapusan.',
                                   'butuh_konfirmasi' => true, 'nama' => $nama, 'ringkasan' => $cnt], 409);
                }
            }
        }
        if ($table === 'projects' || $table === 'materials') {
            $col = $table === 'projects' ? 'project_id' : 'material_id';
            $n = [];
            foreach (['material_receipts', 'material_usage'] as $t) {
                $c = db()->prepare("SELECT COUNT(*) FROM `$t` WHERE `$col` = ?");
                $c->execute([$id]);
                $n[] = "$t=" . (int)$c->fetchColumn();
            }
            $detail = 'cascade: ' . implode(', ', $n) . (!empty($cnt) ? ' | DATA GAJI IKUT TERHAPUS: ' . json_encode($cnt, JSON_UNESCAPED_UNICODE) : '');
        }
        $stmt = db()->prepare("DELETE FROM `$table` WHERE `$idColumn` = ?");
        $stmt->execute([$id]);
        audit('delete', $table, (string)$id, $detail);
        json_response(['ok' => true]);
        return;
    }

    json_error('Method not allowed', 405);
}
