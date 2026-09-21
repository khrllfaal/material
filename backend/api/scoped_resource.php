<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

/**
 * Like handle_resource_crud(), but for tables that carry a project_id
 * column and must be scoped to the logged-in user's assigned projects
 * when they're role 'lapangan' (field admin). admin/owner see and
 * write everything, same as the plain resource endpoints.
 *
 * created_by is always taken from the session, never the client body.
 */
function handle_project_scoped_resource(string $table, array $columns, string $projectColumn = 'project_id'): void {
    send_cors_headers();
    $method = $_SERVER['REQUEST_METHOD'];
    // Owner is read-only everywhere: never allowed to write, even here.
    $user = ($method !== 'GET') ? require_role(['admin', 'lapangan']) : require_login();
    $scope = user_project_ids($user); // null = unrestricted (admin/owner)

    if ($method === 'GET') {
        if ($scope !== null) {
            if (!$scope) { json_response([]); return; }
            $placeholders = implode(',', array_fill(0, count($scope), '?'));
            $stmt = db()->prepare("SELECT * FROM `$table` WHERE `$projectColumn` IN ($placeholders) ORDER BY tgl DESC, id DESC");
            $stmt->execute($scope);
        } else {
            $stmt = db()->query("SELECT * FROM `$table` ORDER BY tgl DESC, id DESC");
        }
        json_response($stmt->fetchAll());
        return;
    }

    if ($method === 'POST') {
        $body = read_json_body();
        $projectId = (string)($body[$projectColumn] ?? '');
        if ($projectId === '') json_error("$projectColumn wajib diisi.", 422);
        require_project_access($user, $projectId);

        $data = [];
        foreach ($columns as $col) {
            if (array_key_exists($col, $body)) $data[$col] = $body[$col];
        }
        $data['created_by'] = $user['id'];
        if (empty($data['id'])) json_error('id wajib diisi', 422);

        // A 'lapangan' user editing an existing row must still own its
        // (possibly unchanged) project — re-check against the stored row
        // so they can't move it into scope by relabelling on the way in.
        if ($scope !== null) {
            $existing = db()->prepare("SELECT `$projectColumn` FROM `$table` WHERE id = ?");
            $existing->execute([$data['id']]);
            $row = $existing->fetch();
            if ($row) require_project_access($user, $row[$projectColumn]);
        }

        $cols = array_keys($data);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $colList = implode(',', array_map(fn($c) => "`$c`", $cols));
        $updateCols = array_filter($cols, fn($c) => $c !== 'id' && $c !== 'created_by');
        $updateList = implode(',', array_map(fn($c) => "`$c`=VALUES(`$c`)", $updateCols));
        $sql = "INSERT INTO `$table` ($colList) VALUES ($placeholders)"
             . ($updateList ? " ON DUPLICATE KEY UPDATE $updateList" : '');
        $stmt = db()->prepare($sql);
        $stmt->execute(array_values($data));

        audit('update', $table, (string)$data['id']);
        json_response(['ok' => true, 'id' => $data['id']]);
        return;
    }

    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? '';
        if ($id === '') json_error('id wajib diisi', 422);
        if ($scope !== null) {
            $existing = db()->prepare("SELECT `$projectColumn` FROM `$table` WHERE id = ?");
            $existing->execute([$id]);
            $row = $existing->fetch();
            if ($row) require_project_access($user, $row[$projectColumn]);
        }
        $stmt = db()->prepare("DELETE FROM `$table` WHERE id = ?");
        $stmt->execute([$id]);
        audit('delete', $table, (string)$id);
        json_response(['ok' => true]);
        return;
    }

    json_error('Method not allowed', 405);
}
