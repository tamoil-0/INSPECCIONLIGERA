<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/StructureRecoveryService.php';

requireMethod('GET');
requireAuth();
$postId = filter_input(INPUT_GET, 'poste_id', FILTER_VALIDATE_INT);
$projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT);
if (!$postId || !$projectId) {
    respond([
        'success' => false,
        'error' => 'Los identificadores de proyecto y estructura son obligatorios.',
    ], 422);
}
try {
    $resolved = resolveStructureResources($conn, $postId, $projectId);
    respond([
        'success' => true,
        'data' => publicStructureRecoveryStatus($resolved),
    ]);
} catch (DomainException $exception) {
    respond(['success' => false, 'error' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    error_log('Backup status failed: ' . $exception->getMessage());
    respond([
        'success' => false,
        'error' => 'No pudimos comprobar el respaldo en este momento.',
    ], 500);
}
