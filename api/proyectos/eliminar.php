<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('DELETE');
requireAuth(['administrador']);
$data = requestJson();
$id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
if (!$id) {
    respond(['success' => false, 'error' => 'ID de proyecto no válido.'], 422);
}

$exists = $conn->prepare('SELECT 1 FROM proyectos WHERE id = ?');
$exists->execute([$id]);
if (!$exists->fetchColumn()) {
    respond(['success' => false, 'error' => 'Proyecto no encontrado.'], 404);
}
$conn->prepare("UPDATE proyectos SET estado = 'cancelado' WHERE id = ?")->execute([$id]);
respond(['success' => true, 'message' => 'Proyecto cancelado.']);
