<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('GET');
requireAuth();

$counts = ['activo' => 0, 'completado' => 0, 'cancelado' => 0];
foreach ($conn->query('SELECT estado, COUNT(*) AS total FROM proyectos GROUP BY estado') as $row) {
    $counts[$row['estado']] = (int) $row['total'];
}
respond(['success' => true, 'data' => $counts]);
