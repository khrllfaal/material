<?php
declare(strict_types=1);
require_once __DIR__ . '/resource_crud.php';
// Minimal project master for this app — just enough to scope material
// data per project. No financial fields (this isn't the accounting
// system); read is open to every logged-in role (field admins need the
// list to know which project they're entering data for).
handle_resource_crud('projects', ['id', 'nama'], 'id', [], ['admin']);
