<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('POST');
requireAuth(['administrador', 'supervisor', 'tecnico']);
$data = requestJson();
foreach (['codigo', 'linea', 'estructura', 'proyecto_id'] as $field) {
    if (trim((string) ($data[$field] ?? '')) === '') {
        respond(['success' => false, 'error' => "Falta el campo: {$field}."], 422);
    }
}
$projectId = filter_var($data['proyecto_id'], FILTER_VALIDATE_INT);
if (!$projectId) {
    respond(['success' => false, 'error' => 'proyecto_id no es válido.'], 422);
}

try {
    $project = $conn->prepare("SELECT 1 FROM proyectos WHERE id = ? AND estado <> 'cancelado'");
    $project->execute([$projectId]);
    if (!$project->fetchColumn()) {
        respond(['success' => false, 'error' => 'El proyecto no existe o está cancelado.'], 404);
    }

    $conn->beginTransaction();
    $statement = $conn->prepare('INSERT INTO postes (codigo, linea, estructura, proyecto_id, ubicaciones) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([
        trim($data['codigo']), trim($data['linea']), trim($data['estructura']), $projectId,
        isset($data['ubicaciones']) ? trim((string) $data['ubicaciones']) : null,
    ]);
    $postId = (int) $conn->lastInsertId();
    $conn->prepare('INSERT INTO poste_datos (poste_id) VALUES (?)')->execute([$postId]);
    $conn->commit();
    respond(['success' => true, 'message' => 'Poste creado.', 'poste_id' => $postId], 201);
} catch (PDOException $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
        respond(['success' => false, 'error' => 'Ya existe ese código de poste dentro del proyecto.'], 409);
    }
    error_log('Post creation failed: ' . $exception->getMessage());
    respond(['success' => false, 'error' => 'No se pudo crear el poste.'], 500);
}
