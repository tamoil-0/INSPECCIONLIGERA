<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('GET');
requireAuth();
$projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT);
if (!$projectId) {
    respond(['success' => false, 'error' => 'Se requiere proyecto_id válido.'], 422);
}

$sql = 'SELECT id, codigo, linea, estructura, proyecto_id, ubicaciones, fecha_inspeccion, utm_x, utm_y,
               zona, sincronizado, formulario_subido, imagenes_subidas, creado_en
        FROM postes WHERE proyecto_id = :project_id';
if (isset($_GET['inspeccionado'])) {
    $sql .= filter_var($_GET['inspeccionado'], FILTER_VALIDATE_BOOLEAN) ? ' AND fecha_inspeccion IS NOT NULL' : ' AND fecha_inspeccion IS NULL';
}
$sql .= ' ORDER BY linea, codigo';
$statement = $conn->prepare($sql);
$statement->execute(['project_id' => $projectId]);
$posts = $statement->fetchAll();
respond(['success' => true, 'count' => count($posts), 'proyecto_id' => $projectId, 'data' => $posts]);
