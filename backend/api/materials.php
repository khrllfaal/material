<?php
declare(strict_types=1);
require_once __DIR__ . '/resource_crud.php';
handle_resource_crud('materials', [
    'id', 'kode', 'nama', 'satuan', 'kategori', 'stok_minimum',
], 'id', [], ['admin', 'owner']);
