<?php
declare(strict_types=1);
require_once __DIR__ . '/resource_crud.php';
handle_resource_crud('rap_material', [
    'id', 'pekerjaan_id', 'material_id', 'koefisien',
], 'id', [], ['admin', 'owner']);
