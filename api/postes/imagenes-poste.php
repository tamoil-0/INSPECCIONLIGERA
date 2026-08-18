<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/domain.php';

requireMethod('POST');
requireAuth(['administrador', 'supervisor', 'tecnico']);
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 100 * 1024 * 1024) {
    respond(['success' => false, 'error' => 'La carga completa excede 100 MB.'], 413);
}
$postId = filter_input(INPUT_GET, 'poste_id', FILTER_VALIDATE_INT);
if (!$postId) {
    respond(['success' => false, 'error' => 'poste_id no es válido.'], 422);
}
$exists = $conn->prepare('SELECT 1 FROM postes WHERE id = ?');
$exists->execute([$postId]);
if (!$exists->fetchColumn()) {
    respond(['success' => false, 'error' => 'Poste no encontrado.'], 404);
}

$allowedTypes = photoTypes();
$results = [];
$uploaded = 0;
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
        $captureDate = normalizeDateTime($_POST['fecha_captura_' . $index] ?? null) ?? date('Y-m-d H:i:s');
        $stored = saveUploadedImage($_FILES[$key], $postId, $type);
        $previous = $conn->prepare('SELECT ruta_archivo FROM imagenes_poste WHERE poste_id = ? AND nombre_foto = ?');
        $previous->execute([$postId, $type]);
        $previousPath = $previous->fetchColumn() ?: null;

        $statement = $conn->prepare(
            'INSERT INTO imagenes_poste
                (poste_id, nombre_foto, ruta_archivo, fecha_captura, utm_este, utm_norte, zona, sincronizada, fecha_inspeccion, fecha_subida)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
             ON DUPLICATE KEY UPDATE ruta_archivo = VALUES(ruta_archivo), fecha_captura = VALUES(fecha_captura),
                 utm_este = VALUES(utm_este), utm_norte = VALUES(utm_norte), zona = VALUES(zona),
                 sincronizada = 1, fecha_inspeccion = VALUES(fecha_inspeccion), fecha_subida = NOW()'
        );
        $statement->execute([
            $postId, $type, $stored['relative'], $captureDate,
            ($_POST['utm_este_' . $index] ?? '') !== '' ? (float) $_POST['utm_este_' . $index] : null,
            ($_POST['utm_norte_' . $index] ?? '') !== '' ? (float) $_POST['utm_norte_' . $index] : null,
            ($_POST['zona_' . $index] ?? '') !== '' ? trim((string) $_POST['zona_' . $index]) : null,
            $captureDate,
        ]);
        deleteStoredFile($previousPath);
        $uploaded++;
        $results[$key] = ['success' => true, 'nombre_foto' => $type, 'fecha_inspeccion' => $captureDate];
    } catch (Throwable $exception) {
        if ($stored && is_file($stored['absolute'])) {
            unlink($stored['absolute']);
        }
        error_log('Image upload failed: ' . $exception->getMessage());
        $results[$key] = ['success' => false, 'error' => $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo guardar la imagen.'];
    }
}

$count = $conn->prepare('SELECT COUNT(DISTINCT nombre_foto) FROM imagenes_poste WHERE poste_id = ?');
$count->execute([$postId]);
$storedCount = (int) $count->fetchColumn();
$complete = $storedCount === count($allowedTypes);
$conn->prepare('UPDATE postes SET imagenes_subidas = ?, sincronizado = IF(formulario_subido = 1 AND ? = 1, 1, 0), fecha_subida = IF(? > 0, NOW(), fecha_subida) WHERE id = ?')
    ->execute([$complete ? 1 : 0, $complete ? 1 : 0, $uploaded, $postId]);

respond([
    'success' => $uploaded > 0 && !array_filter($results, static fn(array $result): bool => !$result['success']),
    'poste_id' => $postId,
    'imagenes_procesadas' => $uploaded,
    'imagenes_almacenadas' => $storedCount,
    'imagenes_requeridas' => count($allowedTypes),
    'imagenes_completas' => $complete,
    'resultados' => $results,
], $uploaded > 0 ? 200 : 422);
