<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

/**
 * Progress reports don't carry project_id directly (they hang off
 * pekerjaan_id), so they can't use handle_project_scoped_resource()
 * as-is — resolve the project through pekerjaan first, then apply the
 * same scoping rule by hand.
 */
send_cors_headers();
$method = $_SERVER['REQUEST_METHOD'];
// Owner is read-only everywhere: never allowed to write, even here.
$user = ($method !== 'GET') ? require_role(['admin', 'lapangan']) : require_login();
$scope = user_project_ids($user);

function pekerjaan_project_id(string $pekerjaanId): ?string {
    $stmt = db()->prepare('SELECT project_id FROM pekerjaan WHERE id = ?');
    $stmt->execute([$pekerjaanId]);
    $row = $stmt->fetch();
    return $row ? $row['project_id'] : null;
}

if ($method === 'GET') {
    if ($scope !== null) {
        if (!$scope) { json_response([]); return; }
        $placeholders = implode(',', array_fill(0, count($scope), '?'));
        $stmt = db()->prepare(
            "SELECT pp.* FROM progress_pekerjaan pp
             JOIN pekerjaan pk ON pk.id = pp.pekerjaan_id
             WHERE pk.project_id IN ($placeholders) ORDER BY pp.tgl DESC, pp.id DESC"
        );
        $stmt->execute($scope);
    } else {
        $stmt = db()->query('SELECT * FROM progress_pekerjaan ORDER BY tgl DESC, id DESC');
    }
    json_response($stmt->fetchAll());
    exit;
}

if ($method === 'POST') {
    $body = read_json_body();
    $pekerjaanId = (string)($body['pekerjaan_id'] ?? '');
    if ($pekerjaanId === '') json_error('pekerjaan_id wajib diisi.', 422);
    $projectId = pekerjaan_project_id($pekerjaanId);
    if ($projectId === null) json_error('Pekerjaan tidak ditemukan.', 404);
    require_project_access($user, $projectId);

    $id = (string)($body['id'] ?? '');
    if ($id === '') json_error('id wajib diisi', 422);
    $volumeAktual = (float)($body['volume_aktual'] ?? 0);
    if ($volumeAktual < 0) json_error('Volume aktual tidak boleh negatif.', 422);

    $stmt = db()->prepare(
        'INSERT INTO progress_pekerjaan (id, pekerjaan_id, tgl, volume_aktual, ket, created_by)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE tgl=VALUES(tgl), volume_aktual=VALUES(volume_aktual), ket=VALUES(ket)'
    );
    $stmt->execute([$id, $pekerjaanId, $body['tgl'] ?? date('Y-m-d'), $volumeAktual, $body['ket'] ?? '', $user['id']]);

    audit('update', 'progress_pekerjaan', $id);
    json_response(['ok' => true, 'id' => $id]);
    exit;
}

if ($method === 'DELETE') {
    $id = $_GET['id'] ?? '';
    if ($id === '') json_error('id wajib diisi', 422);
    $stmt = db()->prepare('SELECT pekerjaan_id FROM progress_pekerjaan WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) {
        $projectId = pekerjaan_project_id($row['pekerjaan_id']);
        if ($projectId !== null) require_project_access($user, $projectId);
    }
    db()->prepare('DELETE FROM progress_pekerjaan WHERE id = ?')->execute([$id]);
    audit('delete', 'progress_pekerjaan', (string)$id);
    json_response(['ok' => true]);
    exit;
}

json_error('Method not allowed', 405);
