<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/StructureRecoveryService.php';

requireMethod('POST');
requireAuth(['administrador', 'supervisor']);
$body = requestJson();
$postId = filter_var($body['poste_id'] ?? null, FILTER_VALIDATE_INT);
$projectId = filter_var($body['proyecto_id'] ?? null, FILTER_VALIDATE_INT);
if (!$postId || !$projectId) {
    respond([
        'success' => false,
        'error' => 'Los identificadores de proyecto y estructura son obligatorios.',
    ], 422);
}
try {
    $result = recoverMissingStructureFiles($conn, $postId, $projectId);
    $resolved = resolveStructureResources($conn, $postId, $projectId);
    $count = count($result['recovered']);
    respond([
        'success' => true,
        'message' => $count > 0
            ? 'Los archivos faltantes se recuperaron correctamente.'
            : 'No había archivos faltantes que se pudieran recuperar.',
        'recovered_files' => $count,
        'data' => publicStructureRecoveryStatus($resolved),
    ]);
} catch (DomainException $exception) {
    respond(['success' => false, 'error' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    error_log('Backup recovery failed: ' . $exception->getMessage());
    respond([
        'success' => false,
        'error' => 'No pudimos recuperar los archivos. No se reemplazó información existente.',
    ], 500);
}
