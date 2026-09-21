<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
send_cors_headers();

$u = current_user();
if ($u && $u['role'] === 'lapangan') $u['projectIds'] = user_project_ids($u);
json_response(['user' => $u]);
