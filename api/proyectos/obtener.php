<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('GET');
requireAuth();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    respond(['success' => false, 'error' => 'ID de proyecto no válido.'], 422);
}

$statement = $conn->prepare('SELECT id, nombre_proyecto, contratista, ubicacion, fecha_creacion, estado FROM proyectos WHERE id = ?');
$statement->execute([$id]);
$project = $statement->fetch();
if (!$project) {
    respond(['success' => false, 'error' => 'Proyecto no encontrado.'], 404);
}
respond(['success' => true, 'data' => $project]);
