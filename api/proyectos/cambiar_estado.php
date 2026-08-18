<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('POST', 'PATCH');
requireAuth(['administrador', 'supervisor']);
$data = requestJson();
$id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
$state = (string) ($data['estado'] ?? '');
if (!$id || !in_array($state, ['activo', 'completado', 'cancelado'], true)) {
    respond(['success' => false, 'error' => 'ID o estado no válido.'], 422);
}

$exists = $conn->prepare('SELECT 1 FROM proyectos WHERE id = ?');
$exists->execute([$id]);
if (!$exists->fetchColumn()) {
    respond(['success' => false, 'error' => 'Proyecto no encontrado.'], 404);
}
$conn->prepare('UPDATE proyectos SET estado = ? WHERE id = ?')->execute([$state, $id]);
respond(['success' => true, 'message' => 'Estado del proyecto actualizado.']);
