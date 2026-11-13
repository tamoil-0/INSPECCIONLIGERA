<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/photo_status.php';

requireMethod('GET');
requireAuth();
$postId = filter_input(INPUT_GET, 'poste_id', FILTER_VALIDATE_INT);
if (!$postId) {
    respond(['success' => false, 'error' => 'poste_id no es válido.'], 422);
}
try {
    $status = refreshPostSynchronizationStatus($conn, $postId);
} catch (RuntimeException) {
    respond(['success' => false, 'error' => 'Poste no encontrado.'], 404);
}
respond([
    'success' => true,
    'poste_id' => $postId,
    'formulario_subido' => $status['inspeccion_finalizada'],
    'imagenes_subidas' => $status['imagenes_subidas'],
    'sincronizado' => $status['sincronizado'],
    'fotos_esperadas' => $status['fotos_esperadas'],
    'fotos_capturadas' => $status['fotos_capturadas'],
    'fotos_sincronizadas' => $status['fotos_sincronizadas'],
    'fotos_omitidas' => $status['fotos_omitidas'],
    'inspeccion_finalizada' => $status['inspeccion_finalizada'],
]);
