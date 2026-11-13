<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/photo_status.php';

requireMethod('GET');
requireAuth();
$line = trim((string) ($_GET['linea'] ?? ''));
$projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT) ?: null;
if ($line === '') {
    respond(['success' => false, 'error' => 'linea es obligatoria.'], 422);
}

$sql = 'SELECT p.*, ' . photoStatusSelectSql() . '
        FROM postes p
        INNER JOIN proyectos pr ON pr.id = p.proyecto_id AND pr.estado = \'activo\'
        LEFT JOIN lineas l ON l.proyecto_id = p.proyecto_id AND l.nombre = p.linea'
        . photoStatusJoinSql() .
        ' WHERE p.linea = ? AND p.activo = 1 AND COALESCE(l.activa, 1) = 1';
$parameters = [$line];
if ($projectId) {
    $sql .= ' AND p.proyecto_id = ?';
    $parameters[] = $projectId;
}
$sql .= ' ORDER BY p.codigo';
$statement = $conn->prepare($sql);
$statement->execute($parameters);
$posts = $statement->fetchAll();
if (!$posts) {
    respond(['success' => false, 'error' => 'No se encontraron postes para esa línea.'], 404);
}
respond(['success' => true, 'linea_buscada' => $line, 'total' => count($posts), 'data' => $posts]);
