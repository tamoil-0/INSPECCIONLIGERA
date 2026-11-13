<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/PlantillaService.php';

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
} else {
    // Contrato móvil: sin filtro explícito solo se entrega trabajo disponible.
    $conditions[] = "estado = 'activo'";
}
if ($search !== '') {
    $conditions[] = '(nombre_proyecto LIKE :search OR contratista LIKE :search OR ubicacion LIKE :search)';
    $parameters['search'] = '%' . $search . '%';
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

try {
    // `plantilla_id` NULL = flujo fijo de siempre; con valor, la app pide el
    // formulario y las fotos a plantillas/obtener.php. `plantilla_version` le
    // dice si su copia offline quedó vieja.
    plantillasAsegurarEsquema($conn);
    $statement = $conn->prepare(
        'SELECT proyectos.id, nombre_proyecto, contratista, ubicacion, fecha_creacion, estado, plantilla_id,
                (SELECT t.version FROM plantillas t WHERE t.id = proyectos.plantilla_id) AS plantilla_version
         FROM proyectos' . $where . ' ORDER BY fecha_creacion DESC, proyectos.id DESC'
    );
    $statement->execute($parameters);
    $projects = $statement->fetchAll();
    respond(['success' => true, 'count' => count($projects), 'data' => $projects]);
} catch (PDOException $exception) {
    error_log('Project list failed: ' . $exception->getMessage());
    respond(['success' => false, 'error' => 'No se pudieron obtener los proyectos.'], 500);
}
