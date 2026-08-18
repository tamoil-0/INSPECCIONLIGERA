<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('GET');
requireAuth();
$structure = trim((string) ($_GET['estructura'] ?? ''));
$line = trim((string) ($_GET['linea'] ?? ''));
$projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT) ?: null;
if ($structure === '' || $line === '') {
    respond(['success' => false, 'error' => 'estructura y linea son obligatorios.'], 422);
}

$sql = 'SELECT * FROM postes WHERE estructura = ? AND linea = ?';
$parameters = [$structure, $line];
if ($projectId) {
    $sql .= ' AND proyecto_id = ?';
    $parameters[] = $projectId;
}
$sql .= ' ORDER BY proyecto_id, codigo';
$statement = $conn->prepare($sql);
$statement->execute($parameters);
$posts = $statement->fetchAll();
if (!$posts) {
    respond(['success' => false, 'error' => 'No se encontraron postes con esos criterios.'], 404);
}
respond(['success' => true, 'estructura_buscada' => $structure, 'linea_buscada' => $line, 'total' => count($posts), 'data' => $posts]);
