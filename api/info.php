<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

// La app usa esta ruta para decidir si puede sincronizar. Verificar también la
// base evita un falso "en línea" cuando Apache responde pero MySQL está caído.
$conn->query('SELECT 1')->fetchColumn();
respond(['success' => true, 'service' => 'ECOING API', 'status' => 'ok']);
