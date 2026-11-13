<?php
declare(strict_types=1);

/**
 * Configuración de impresión de las fotografías de una estructura.
 *
 * Sólo para administradores. Es la que alimenta la pantalla
 * «Configurar imágenes» del panel admin.
 *
 *   GET  -> qué fotografías hay en cada fuente (NAS / Backup) y cuáles están
 *           marcadas para imprimir según lo guardado.
 *   POST -> guarda la selección de UNA fuente. La otra queda como estaba.
 *           Se envían los slots DESMARCADOS: un slot que no venga en la
 *           lista se imprime, hoy y cuando llegue su fotografía.
 *
 * No borra, no mueve y no modifica ninguna fotografía, ninguna fila de
 * inspección ni ningún archivo del almacén de imágenes: sólo el JSON de
 * configuración de impresión de esta estructura.
 *
 *   GET  api/admin/opciones_reporte_poste.php?poste_id=1240&proyecto_id=2
 *   POST api/admin/opciones_reporte_poste.php
 *        {"poste_id":1240,"proyecto_id":2,"photo_source":"current",
 *         "excluidos":["aisladores_fase_r_adelante"]}
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/ReportPhotoSourceService.php';
require_once __DIR__ . '/../services/ReportPhotoConfigService.php';

requireMethod('GET', 'POST');
$user = requireAuth(['administrador']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = requestJson();
    $postId = filter_var($payload['poste_id'] ?? null, FILTER_VALIDATE_INT);
    $projectId = filter_var($payload['proyecto_id'] ?? null, FILTER_VALIDATE_INT);
    $source = strtolower(trim((string) ($payload['photo_source'] ?? '')));
    if (!$postId || !$projectId) {
        respond([
            'success' => false,
            'error' => 'Los identificadores de proyecto y estructura son obligatorios.',
        ], 422);
    }
    if (!in_array($source, reportPhotoConfigurableSources(), true)) {
        respond(['success' => false, 'error' => 'La fuente de fotografías no es válida.'], 422);
    }
    $excluded = $payload['excluidos'] ?? $payload['excluded_slots'] ?? null;
    if (!is_array($excluded)) {
        respond([
            'success' => false,
            'error' => 'Falta la lista de fotografías que no se deben imprimir.',
        ], 422);
    }

    try {
        // La estructura tiene que pertenecer al proyecto indicado; se comprueba
        // contra la base antes de escribir nada.
        $statement = $conn->prepare('SELECT codigo FROM postes WHERE id = ? AND proyecto_id = ?');
        $statement->execute([$postId, $projectId]);
        $code = $statement->fetchColumn();
        if ($code === false) {
            respond([
                'success' => false,
                'error' => 'La estructura no existe dentro del proyecto indicado.',
            ], 404);
        }

        $saved = reportPhotoConfigWrite(
            $postId,
            $projectId,
            $source,
            $excluded,
            (string) ($user['username'] ?? $user['nombre_usuario'] ?? '')
        );
        appLog('administracion', 'Configuracion de impresion de fotografias guardada.', [
            'project' => $projectId,
            'structure' => $postId,
            'action' => 'report_photo_config_saved',
            'photo_source' => $source,
            'photos_excluded' => count($saved['fuentes'][$source]['excluidos']),
            'usuario' => $user['username'] ?? null,
        ]);

        respond([
            'success' => true,
            'message' => 'Configuración de impresión guardada.',
            'data' => $saved,
        ]);
    } catch (InvalidArgumentException $exception) {
        respond(['success' => false, 'error' => $exception->getMessage()], 400);
    } catch (Throwable $exception) {
        error_log(sprintf(
            'Report photo config save failed (poste %d, proyecto %d): %s: %s en %s:%d',
            $postId,
            $projectId,
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        ));
        respond([
            'success' => false,
            'error' => 'No se pudo guardar la configuración. No se modificó ninguna fotografía.',
        ], 500);
    }
}

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
        'data' => reportPhotoSelection($conn, $postId, $projectId),
    ]);
} catch (DomainException $exception) {
    respond(['success' => false, 'error' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    error_log(sprintf(
        'Report photo options failed (poste %d, proyecto %d): %s: %s en %s:%d',
        $postId,
        $projectId,
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
    respond([
        'success' => false,
        'error' => 'No se pudieron preparar las opciones del reporte.',
    ], 500);
}
