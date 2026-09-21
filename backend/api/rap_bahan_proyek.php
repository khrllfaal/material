<?php
declare(strict_types=1);
require_once __DIR__ . '/resource_crud.php';
handle_resource_crud('rap_bahan_proyek', [
    'id', 'project_id', 'material_id', 'rap_qty',
], 'id', [], ['admin']);
