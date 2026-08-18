<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('POST');
$user = requireAuth(['administrador', 'supervisor']);
$data = requestJson();

foreach (['nombre_proyecto', 'contratista', 'ubicacion'] as $field) {
    if (trim((string) ($data[$field] ?? '')) === '') {
        respond(['success' => false, 'error' => "Falta el campo: {$field}."], 422);
    }
}

try {
    $statement = $conn->prepare(
        "INSERT INTO proyectos (nombre_proyecto, contratista, ubicacion, estado, creado_por)
         VALUES (?, ?, ?, 'activo', ?)"
    );
    $statement->execute([
        trim($data['nombre_proyecto']),
        trim($data['contratista']),
        trim($data['ubicacion']),
        $user['id'],
    ]);
    $id = (int) $conn->lastInsertId();
    $project = $conn->prepare('SELECT id, nombre_proyecto, contratista, ubicacion, fecha_creacion, estado FROM proyectos WHERE id = ?');
    $project->execute([$id]);
    respond(['success' => true, 'message' => 'Proyecto creado.', 'data' => $project->fetch()], 201);
} catch (PDOException $exception) {
    error_log('Project creation failed: ' . $exception->getMessage());
    respond(['success' => false, 'error' => 'No se pudo crear el proyecto.'], 500);
}
