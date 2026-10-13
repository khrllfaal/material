<?php
declare(strict_types=1);
require_once __DIR__ . '/scoped_resource.php';
ensure_stock_counts_table();
handle_project_scoped_resource('stock_counts', [
    'id', 'project_id', 'material_id', 'tgl', 'qty', 'ket',
]);
