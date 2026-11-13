<?php
declare(strict_types=1);

/**
 * Visor de una fotografía concreta de una estructura, para el diagnóstico.
 *
 * Sólo lectura y sólo para administradores. Permite comparar visualmente el
 * archivo que el reporte usaría con los que quedaron sueltos en la carpeta.
 *
 * ## Por qué no acepta rutas
 *
 * El cliente sólo manda un NOMBRE de archivo. El servidor construye por su
 * cuenta la lista de archivos que esta estructura puede servir
 * (`structurePhotoServableFiles()`) y comprueba que el nombre esté en ella. No
 * hay ninguna ruta que venga del navegador, así que no hay path traversal que
 * defender: un nombre que no esté en la lista simplemente no existe.
 *
 * GET api/admin/ver_archivo_poste.php?poste_id=1240&proyecto_id=2&archivo=placa_ab12.jpg[&miniatura=1]
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/PhotoDiagnosticsService.php';

requireMethod('GET');
requireAuth(['administrador']);

const VIEWER_THUMBNAIL_MAX_WIDTH = 520;

$postId = filter_input(INPUT_GET, 'poste_id', FILTER_VALIDATE_INT);
$projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT);
$name = trim((string) filter_input(INPUT_GET, 'archivo'));
$thumbnail = filter_input(INPUT_GET, 'miniatura', FILTER_VALIDATE_BOOLEAN);
if (!$postId || !$projectId || $name === '' || preg_match('/^[A-Za-z0-9._-]{1,120}$/', $name) !== 1) {
    respond([
        'success' => false,
        'error' => 'Los identificadores y el nombre del archivo son obligatorios.',
    ], 422);
}

try {
    $servable = structurePhotoServableFiles($conn, $postId, $projectId);
    $absolute = $servable[$name] ?? null;
    if ($absolute === null || !is_file($absolute)) {
        respond(['success' => false, 'error' => 'El archivo no pertenece a esta estructura.'], 404);
    }

    $info = @getimagesize($absolute);
    if (!is_array($info) || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
        respond(['success' => false, 'error' => 'El archivo no es una imagen que se pueda mostrar.'], 415);
    }

    if ($thumbnail && (int) $info[0] > VIEWER_THUMBNAIL_MAX_WIDTH) {
        // Se reduce en memoria para no mandar 3 MB por miniatura. El archivo
        // original no se toca en ningún momento.
        $source = decodificarImagenReporte($absolute, (int) $info[2]);
        if ($source !== null) {
            $width = VIEWER_THUMBNAIL_MAX_WIDTH;
            $height = max(1, (int) ((int) $info[1] * ($width / (int) $info[0])));
            $canvas = imagecreatetruecolor($width, $height);
            imagefilledrectangle($canvas, 0, 0, $width, $height, (int) imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, (int) $info[0], (int) $info[1]);
            imagedestroy($source);
            header_remove('Content-Type');
            header('Content-Type: image/jpeg');
            header('Content-Disposition: inline; filename="' . $name . '"');
            imagejpeg($canvas, null, 78);
            imagedestroy($canvas);
            exit;
        }
    }

    $bytes = (int) (@filesize($absolute) ?: 0);
    header_remove('Content-Type');
    header('Content-Type: ' . $info['mime']);
    header('Content-Disposition: inline; filename="' . $name . '"');
    if ($bytes > 0) {
        header('Content-Length: ' . $bytes);
    }
    readfile($absolute);
    exit;
} catch (DomainException $exception) {
    respond(['success' => false, 'error' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    error_log(sprintf(
        'Photo viewer failed (poste %d, proyecto %d): %s: %s en %s:%d',
        $postId,
        $projectId,
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
    respond(['success' => false, 'error' => 'No se pudo mostrar el archivo.'], 500);
}
