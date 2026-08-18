<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('GET');
requireAuth();

$state = trim((string) ($_GET['estado'] ?? ''));
$search = trim((string) ($_GET['busqueda'] ?? ''));
$conditions = [];
$parameters = [];
if ($state !== '') {
    if (!in_array($state, ['activo', 'completado', 'cancelado'], true)) {
        respond(['success' => false, 'error' => 'Estado no válido.'], 422);
    }
    $conditions[] = 'estado = :estado';
    $parameters['estado'] = $state;
}
if ($search !== '') {
    $conditions[] = '(nombre_proyecto LIKE :search OR contratista LIKE :search OR ubicacion LIKE :search)';
    $parameters['search'] = '%' . $search . '%';
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

try {
    $statement = $conn->prepare(
        'SELECT id, nombre_proyecto, contratista, ubicacion, fecha_creacion, estado FROM proyectos' . $where . ' ORDER BY fecha_creacion DESC, id DESC'
    );
    $statement->execute($parameters);
    $projects = $statement->fetchAll();
    respond(['success' => true, 'count' => count($projects), 'data' => $projects]);
} catch (PDOException $exception) {
    error_log('Project list failed: ' . $exception->getMessage());
    respond(['success' => false, 'error' => 'No se pudieron obtener los proyectos.'], 500);
}
