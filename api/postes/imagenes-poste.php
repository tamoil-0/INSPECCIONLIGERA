<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/domain.php';
require_once __DIR__ . '/../config/photo_status.php';

requireMethod('POST');
requireAuth(['administrador', 'supervisor', 'tecnico']);
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 100 * 1024 * 1024) {
    respond(['success' => false, 'error' => 'La carga completa excede 100 MB.'], 413);
}
$postId = filter_input(INPUT_GET, 'poste_id', FILTER_VALIDATE_INT);
if (!$postId) {
    respond(['success' => false, 'error' => 'poste_id no es válido.'], 422);
}
// Se leen proyecto y línea, no solo la existencia: la jerarquía de carpetas del
// almacén es `proyecto_{id}/linea_{linea}/poste_{id}/`.
$exists = $conn->prepare(
    "SELECT p.proyecto_id, p.linea
     FROM postes p
     INNER JOIN proyectos pr ON pr.id=p.proyecto_id AND pr.estado='activo'
     LEFT JOIN lineas l ON l.proyecto_id=p.proyecto_id AND l.nombre=p.linea
     WHERE p.id=? AND p.activo=1 AND COALESCE(l.activa,1)=1"
);
$exists->execute([$postId]);
$post = $exists->fetch();
if (!$post) {
    respond(['success' => false, 'error' => 'Poste no encontrado.'], 404);
}
$storageContext = ['proyecto_id' => (int) $post['proyecto_id'], 'linea' => $post['linea']];

$requestStarted = hrtime(true);
$allowedTypes = photoTypes();
$results = [];
$uploaded = 0;
$processedBytes = 0;
$reprocessedCount = 0;
for ($index = 0; $index < 30; $index++) {
    $key = 'imagen_' . $index;
    if (!isset($_FILES[$key])) {
        continue;
    }
    $type = (string) ($_POST['nombre_foto_' . $index] ?? '');
    if (!in_array($type, $allowedTypes, true)) {
        $results[$key] = ['success' => false, 'error' => 'Tipo de foto no válido.'];
        continue;
    }

    $stored = null;
    try {
        // La fila representa una foto realmente capturada/intentada, nunca una
        // omitida. En un reemplazo solo este tipo vuelve a quedar pendiente.
        markPhotoPending($conn, $postId, $type);
        $captureDate = normalizeDateTime($_POST['fecha_captura_' . $index] ?? null) ?? date('Y-m-d H:i:s');
        $stored = saveUploadedImage($_FILES[$key], $postId, $type, $storageContext);
        $dbStarted = hrtime(true);
        $previous = $conn->prepare('SELECT ruta_archivo, activo FROM imagenes_poste WHERE poste_id = ? AND nombre_foto = ?');
        $previous->execute([$postId, $type]);
        $previousRow = $previous->fetch() ?: null;
        $previousPath = is_array($previousRow) ? ($previousRow['ruta_archivo'] ?? null) : null;
        $previousWasActive = is_array($previousRow) && (int) ($previousRow['activo'] ?? 0) === 1;

        $statement = $conn->prepare(
            'INSERT INTO imagenes_poste
                (poste_id, nombre_foto, ruta_archivo, fecha_captura, utm_este, utm_norte, zona, sincronizada, fecha_inspeccion, fecha_subida, activo)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), 1)
             ON DUPLICATE KEY UPDATE ruta_archivo = VALUES(ruta_archivo), fecha_captura = VALUES(fecha_captura),
                 utm_este = VALUES(utm_este), utm_norte = VALUES(utm_norte), zona = VALUES(zona),
                 sincronizada = 1, fecha_inspeccion = VALUES(fecha_inspeccion), fecha_subida = NOW(),
                 activo = 1, archivado_en = NULL'
        );
        $statement->execute([
            $postId, $type, $stored['relative'], $captureDate,
            ($_POST['utm_este_' . $index] ?? '') !== '' ? (float) $_POST['utm_este_' . $index] : null,
            ($_POST['utm_norte_' . $index] ?? '') !== '' ? (float) $_POST['utm_norte_' . $index] : null,
            ($_POST['zona_' . $index] ?? '') !== '' ? trim((string) $_POST['zona_' . $index]) : null,
            $captureDate,
        ]);
        markPhotoSynced($conn, $postId, $type);
        if ($previousWasActive && $previousPath !== null && $previousPath !== $stored['relative']) {
            deleteStoredFile($previousPath);
        }
        $dbMs = elapsedMs($dbStarted);
        $uploaded++;
        $processedBytes += (int) $stored['bytes'];
        $reprocessedCount += $stored['reprocesada'] ? 1 : 0;

        // Instrumentación del objetivo 12: se separa el coste de disco/imagen
        // del coste de base de datos, que es lo único que permite saber dónde
        // se va el tiempo cuando una estructura tarda de más.
        appMetric('imagen almacenada', [
            'poste' => $postId,
            'tipo' => $type,
            'kb' => (int) round($stored['bytes'] / 1024),
            'px' => $stored['ancho'] . 'x' . $stored['alto'],
            'reprocesada' => $stored['reprocesada'],
            'ms_imagen' => $stored['ms'],
            'ms_bd' => $dbMs,
        ]);

        $results[$key] = [
            'success' => true,
            'nombre_foto' => $type,
            'fecha_inspeccion' => $captureDate,
            // Datos de diagnóstico. La app los ignora si no los conoce; sirven
            // para medir desde el teléfono sin abrir los logs del NAS.
            'bytes' => $stored['bytes'],
            'reprocesada' => $stored['reprocesada'],
            'ms' => $stored['ms'],
        ];
    } catch (Throwable $exception) {
        if ($stored && is_file($stored['absolute'])) {
            unlink($stored['absolute']);
        }
        error_log('Image upload failed: ' . $exception->getMessage());
        appLog('sincronizacion', 'Fallo al guardar una fotografía.', [
            'poste' => $postId,
            'tipo' => $type,
            'motivo' => $exception->getMessage(),
        ]);
        $results[$key] = ['success' => false, 'error' => $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo guardar la imagen.'];
    }
}

$syncStatus = refreshPostSynchronizationStatus($conn, $postId);
if ($uploaded > 0) {
    $conn->prepare('UPDATE postes SET fecha_subida = NOW() WHERE id = ?')->execute([$postId]);
}
$storedCount = $syncStatus['fotos_sincronizadas'];
$complete = $syncStatus['imagenes_subidas'];

// Si alguna fotografía se reemplazó, el PDF de este poste dejó de ser válido.
// Se invalida ahora, no al pedir el reporte: así el primer usuario que lo abra
// no paga la regeneración de un archivo que ya sabíamos obsoleto.
if ($uploaded > 0) {
    invalidatePostPdfCache($postId);
}

$totalMs = elapsedMs($requestStarted);
$rejected = count(array_filter($results, static fn(array $result): bool => !$result['success']));

appLog('sincronizacion', 'Lote de fotografías procesado.', [
    'poste' => $postId,
    'recibidas' => count($results),
    'confirmadas' => $uploaded,
    'rechazadas' => $rejected,
    'reprocesadas_gd' => $reprocessedCount,
    'kb_total' => (int) round($processedBytes / 1024),
    'ms_total' => $totalMs,
    'almacenadas_en_bd' => $storedCount,
    'completo' => $complete,
]);

respond([
    'success' => $uploaded > 0 && $rejected === 0,
    'poste_id' => $postId,
    'imagenes_procesadas' => $uploaded,
    'imagenes_almacenadas' => $storedCount,
    'imagenes_requeridas' => $syncStatus['fotos_esperadas'],
    'fotos_esperadas' => $syncStatus['fotos_esperadas'],
    'fotos_capturadas' => $syncStatus['fotos_capturadas'],
    'fotos_sincronizadas' => $syncStatus['fotos_sincronizadas'],
    'fotos_omitidas' => $syncStatus['fotos_omitidas'],
    'inspeccion_finalizada' => $syncStatus['inspeccion_finalizada'],
    'imagenes_completas' => $complete,
    'sincronizado' => $syncStatus['sincronizado'],
    'duracion_ms' => $totalMs,
    'resultados' => $results,
], $uploaded > 0 ? 200 : 422);
