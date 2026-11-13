<?php
declare(strict_types=1);

/**
 * Diagnóstico de procedencia de las fotografías de una estructura.
 *
 * Sólo lectura y sólo para administradores. Responde de dónde saldría cada
 * fotografía del reporte, qué archivos físicos hay en la carpeta de la
 * estructura y cuáles ya no tienen referencia activa.
 *
 * GET api/admin/diagnostico_archivos_poste.php?poste_id=1240&proyecto_id=2
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/PhotoDiagnosticsService.php';

requireMethod('GET');
requireAuth(['administrador']);

$postId = filter_input(INPUT_GET, 'poste_id', FILTER_VALIDATE_INT);
$projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT);
if (!$postId || !$projectId) {
    respond([
        'success' => false,
        'error' => 'Los identificadores de proyecto y estructura son obligatorios.',
    ], 422);
}

try {
    respond([
        'success' => true,
        'data' => structurePhotoDiagnostics($conn, $postId, $projectId),
    ]);
} catch (DomainException $exception) {
    respond(['success' => false, 'error' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    error_log(sprintf(
        'Photo diagnostics failed (poste %d, proyecto %d): %s: %s en %s:%d',
        $postId,
        $projectId,
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
    respond([
        'success' => false,
        'error' => 'No se pudo completar el diagnóstico de archivos.',
    ], 500);
}
