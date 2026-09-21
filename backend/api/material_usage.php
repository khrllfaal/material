<?php
declare(strict_types=1);
require_once __DIR__ . '/scoped_resource.php';
handle_project_scoped_resource('material_usage', [
    'id', 'project_id', 'pekerjaan_id', 'material_id', 'tgl', 'qty', 'ket',
]);
