<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('PUT', 'PATCH');
$user = requireAuth(['administrador', 'supervisor']);
$data = requestJson();
$id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id) {
    respond(['success' => false, 'error' => 'ID de proyecto no válido.'], 422);
}

$current = $conn->prepare('SELECT id, nombre_proyecto, contratista, ubicacion, creado_por FROM proyectos WHERE id = ?');
$current->execute([$id]);
$project = $current->fetch();
if (!$project) {
    respond(['success' => false, 'error' => 'Proyecto no encontrado.'], 404);
}
if (($user['rol'] ?? '') !== 'administrador' && (int) $project['creado_por'] !== (int) $user['id']) {
    respond(['success' => false, 'error' => 'Solo puede editar proyectos creados por usted.'], 403);
}

$name = trim((string) ($data['nombre_proyecto'] ?? $project['nombre_proyecto']));
$contractor = trim((string) ($data['contratista'] ?? $project['contratista']));
$location = trim((string) ($data['ubicacion'] ?? $project['ubicacion']));
if ($name === '' || $contractor === '' || $location === '') {
    respond(['success' => false, 'error' => 'Nombre, contratista y ubicación no pueden estar vacíos.'], 422);
}

$statement = $conn->prepare('UPDATE proyectos SET nombre_proyecto = ?, contratista = ?, ubicacion = ? WHERE id = ?');
$statement->execute([$name, $contractor, $location, $id]);
respond(['success' => true, 'message' => 'Proyecto actualizado.']);
