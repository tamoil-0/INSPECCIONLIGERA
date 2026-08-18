<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('GET');
requireAuth();
$line = trim((string) ($_GET['linea'] ?? ''));
$projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT) ?: null;
if ($line === '') {
    respond(['success' => false, 'error' => 'linea es obligatoria.'], 422);
}

$sql = 'SELECT * FROM postes WHERE linea = ?';
$parameters = [$line];
if ($projectId) {
    $sql .= ' AND proyecto_id = ?';
    $parameters[] = $projectId;
}
$sql .= ' ORDER BY codigo';
$statement = $conn->prepare($sql);
$statement->execute($parameters);
$posts = $statement->fetchAll();
if (!$posts) {
    respond(['success' => false, 'error' => 'No se encontraron postes para esa línea.'], 404);
}
respond(['success' => true, 'linea_buscada' => $line, 'total' => count($posts), 'data' => $posts]);
