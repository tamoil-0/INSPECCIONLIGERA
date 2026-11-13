<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/photo_status.php';

requireMethod('GET');
requireAuth();
$structure = trim((string) ($_GET['estructura'] ?? ''));
$line = trim((string) ($_GET['linea'] ?? ''));
$projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT) ?: null;
if ($structure === '' || $line === '') {
    respond(['success' => false, 'error' => 'estructura y linea son obligatorios.'], 422);
}

$sql = 'SELECT p.*, ' . photoStatusSelectSql() . '
        FROM postes p
        INNER JOIN proyectos pr ON pr.id = p.proyecto_id AND pr.estado = \'activo\'
        LEFT JOIN lineas l ON l.proyecto_id = p.proyecto_id AND l.nombre = p.linea'
        . photoStatusJoinSql() .
        ' WHERE p.estructura = ? AND p.linea = ? AND p.activo = 1 AND COALESCE(l.activa, 1) = 1';
$parameters = [$structure, $line];
if ($projectId) {
    $sql .= ' AND p.proyecto_id = ?';
    $parameters[] = $projectId;
}
$sql .= ' ORDER BY p.proyecto_id, p.codigo';
$statement = $conn->prepare($sql);
$statement->execute($parameters);
$posts = $statement->fetchAll();
if (!$posts) {
    respond(['success' => false, 'error' => 'No se encontraron postes con esos criterios.'], 404);
}
respond(['success' => true, 'estructura_buscada' => $structure, 'linea_buscada' => $line, 'total' => count($posts), 'data' => $posts]);
