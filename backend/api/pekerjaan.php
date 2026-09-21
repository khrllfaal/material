<?php
declare(strict_types=1);
require_once __DIR__ . '/resource_crud.php';
handle_resource_crud('pekerjaan', [
    'id', 'project_id', 'nama', 'satuan', 'volume_kontrak',
], 'id', [], ['admin', 'owner']);
