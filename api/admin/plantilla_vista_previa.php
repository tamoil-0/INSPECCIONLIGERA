<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/PlantillaPdfService.php';

/*
 * PDF de muestra para el editor de plantillas.
 *
 * Recibe la configuración que el administrador tiene en pantalla (aún sin
 * guardar) y la dibuja con datos y fotos de ejemplo. No escribe nada.
 */
requireMethod('POST');
requireAuth(['administrador']);

try {
    $data = requestJson();
    $id = (int) filter_var($data['plantilla_id'] ?? 0, FILTER_VALIDATE_INT);
    $guardada = $id > 0 ? plantillaPorId($conn, $id) : null;
    $config = plantillaNormalizar(is_array($data['config'] ?? null) ? $data['config'] : ($guardada['config'] ?? plantillaVacia()));
    $contenido = plantillaPdfDeEjemplo(['id' => $guardada['id'] ?? 0, 'version' => 0, 'config' => $config]);
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="vista_previa_plantilla.pdf"');
    header('Content-Length: ' . strlen($contenido));
    echo $contenido;
    exit;
} catch (InvalidArgumentException $e) {
    respond(['success' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    appLog('administracion', 'Fallo en la vista previa de plantilla.', ['motivo' => $e->getMessage(), 'linea' => $e->getFile() . ':' . $e->getLine()]);
    respond(['success' => false, 'error' => 'No se pudo generar la vista previa.'], 500);
}
