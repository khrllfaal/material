<?php
declare(strict_types=1);
require_once __DIR__ . '/scoped_resource.php';
handle_project_scoped_resource('material_receipts', [
    'id', 'project_id', 'material_id', 'tgl', 'qty', 'harga_satuan', 'vendor', 'no_referensi', 'ket',
]);
