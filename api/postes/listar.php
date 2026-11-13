<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/photo_status.php';

requireMethod('GET');
requireAuth();
$projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT);
if (!$projectId) {
    respond(['success' => false, 'error' => 'Se requiere proyecto_id válido.'], 422);
}

$sql = 'SELECT p.id, p.codigo, p.linea, p.estructura, p.proyecto_id, p.ubicaciones, p.fecha_inspeccion, p.utm_x, p.utm_y,
               p.zona, p.sincronizado, p.formulario_subido, p.imagenes_subidas, p.creado_en, '
        . photoStatusSelectSql() . '
        FROM postes p
        INNER JOIN proyectos pr ON pr.id = p.proyecto_id AND pr.estado = \'activo\'
        LEFT JOIN lineas l ON l.proyecto_id = p.proyecto_id AND l.nombre = p.linea'
        . photoStatusJoinSql() .
        ' WHERE p.proyecto_id = :project_id AND p.activo = 1 AND COALESCE(l.activa, 1) = 1';
if (isset($_GET['inspeccionado'])) {
    $sql .= filter_var($_GET['inspeccionado'], FILTER_VALIDATE_BOOLEAN) ? ' AND fecha_inspeccion IS NOT NULL' : ' AND fecha_inspeccion IS NULL';
}
$sql .= ' ORDER BY p.linea, p.codigo';
$statement = $conn->prepare($sql);
$statement->execute(['project_id' => $projectId]);
$posts = $statement->fetchAll();
respond(['success' => true, 'count' => count($posts), 'proyecto_id' => $projectId, 'data' => $posts]);
