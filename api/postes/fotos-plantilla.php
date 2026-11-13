<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/PlantillaService.php';

/*
 * Fotografías de una estructura cuyo proyecto usa plantilla.
 *
 * Mismo formato multipart que imagenes-poste.php, con dos cambios: el tipo es
 * `foto_clave_i` (una clave de la plantilla) y cada tipo admite varias tomas
 * (`indice_i`, de 1 a la cantidad pedida). Reenviar la misma toma la reemplaza,
 * así que reintentar un lote cortado es seguro.
 */
requireMethod('POST');
requireAuth(['administrador', 'supervisor', 'tecnico']);
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 100 * 1024 * 1024) {
    respond(['success' => false, 'error' => 'La carga completa excede 100 MB.'], 413);
}
$postId = filter_input(INPUT_GET, 'poste_id', FILTER_VALIDATE_INT);
if (!$postId) {
    respond(['success' => false, 'error' => 'poste_id no es válido.'], 422);
}

plantillasAsegurarEsquema($conn);
$q = $conn->prepare(
    "SELECT p.proyecto_id, p.linea
     FROM postes p
     INNER JOIN proyectos pr ON pr.id = p.proyecto_id AND pr.estado = 'activo'
     LEFT JOIN lineas l ON l.proyecto_id = p.proyecto_id AND l.nombre = p.linea
     WHERE p.id = ? AND p.activo = 1 AND COALESCE(l.activa, 1) = 1"
);
$q->execute([$postId]);
$post = $q->fetch();
if (!$post) {
    respond(['success' => false, 'error' => 'Poste no encontrado.'], 404);
}
$plantilla = plantillaDeProyecto($conn, (int) $post['proyecto_id']);
if ($plantilla === null) {
    respond(['success' => false, 'error' => 'El proyecto de esta estructura no usa plantilla.'], 409);
}
$fotosPlantilla = plantillaFotos($plantilla['config']);
$contexto = ['proyecto_id' => (int) $post['proyecto_id'], 'linea' => $post['linea']];

$numeroOVacio = static fn(string $k): ?float =>
    (isset($_POST[$k]) && is_numeric($_POST[$k])) ? (float) $_POST[$k] : null;
$textoCorto = static fn(string $k, int $max): ?string =>
    (isset($_POST[$k]) && trim((string) $_POST[$k]) !== '') ? mb_substr(trim((string) $_POST[$k]), 0, $max) : null;

$resultados = [];
$subidas = 0;
for ($i = 0; $i < 30; $i++) {
    $key = 'imagen_' . $i;
    if (!isset($_FILES[$key])) {
        continue;
    }
    $clave = (string) ($_POST['foto_clave_' . $i] ?? '');
    $indice = (int) ($_POST['indice_' . $i] ?? 1);
    if (!isset($fotosPlantilla[$clave])) {
        $resultados[$key] = ['success' => false, 'error' => 'La plantilla no pide esa fotografía.'];
        continue;
    }
    if ($indice < 1 || $indice > (int) $fotosPlantilla[$clave]['cantidad']) {
        $resultados[$key] = ['success' => false, 'error' => 'Número de toma fuera de rango.'];
        continue;
    }

    $guardada = null;
    try {
        $fecha = normalizeDateTime($_POST['fecha_captura_' . $i] ?? null) ?? date('Y-m-d H:i:s');
        // El índice va en el nombre del archivo para distinguir tomas a simple vista.
        $guardada = saveUploadedImage($_FILES[$key], $postId, $clave . '_' . $indice, $contexto);
        $previa = $conn->prepare('SELECT ruta_archivo FROM imagenes_plantilla WHERE poste_id = ? AND foto_clave = ? AND indice = ?');
        $previa->execute([$postId, $clave, $indice]);
        $rutaPrevia = $previa->fetchColumn();

        $conn->prepare(
            'INSERT INTO imagenes_plantilla
               (poste_id, foto_clave, indice, ruta_archivo, fecha_captura, utm_este, utm_norte, zona, uuid, checksum, fecha_subida, activo)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1)
             ON DUPLICATE KEY UPDATE ruta_archivo = VALUES(ruta_archivo), fecha_captura = VALUES(fecha_captura),
               utm_este = VALUES(utm_este), utm_norte = VALUES(utm_norte), zona = VALUES(zona), uuid = VALUES(uuid),
               checksum = VALUES(checksum), fecha_subida = NOW(), activo = 1, archivado_en = NULL'
        )->execute([
            $postId, $clave, $indice, $guardada['relative'], $fecha,
            $numeroOVacio('utm_este_' . $i), $numeroOVacio('utm_norte_' . $i), $textoCorto('zona_' . $i, 10),
            $textoCorto('uuid_' . $i, 64), $textoCorto('checksum_' . $i, 64),
        ]);
        if (is_string($rutaPrevia) && $rutaPrevia !== '' && $rutaPrevia !== $guardada['relative']) {
            deleteStoredFile($rutaPrevia);
        }
        $subidas++;
        $resultados[$key] = ['success' => true, 'foto_clave' => $clave, 'indice' => $indice, 'bytes' => $guardada['bytes']];
    } catch (Throwable $e) {
        if ($guardada && is_file($guardada['absolute'])) {
            @unlink($guardada['absolute']);
        }
        appLog('sincronizacion', 'Fallo al guardar una foto de plantilla.', ['poste' => $postId, 'foto' => $clave, 'motivo' => $e->getMessage()]);
        $resultados[$key] = ['success' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo guardar la imagen.'];
    }
}

$estado = plantillaRefrescarEstadoPoste($conn, $postId, $plantilla['config']);
if ($subidas > 0) {
    $conn->prepare('UPDATE postes SET fecha_subida = NOW() WHERE id = ?')->execute([$postId]);
    invalidatePostPdfCache($postId);
}
$rechazadas = count(array_filter($resultados, static fn(array $r): bool => !$r['success']));
appLog('sincronizacion', 'Lote de fotos de plantilla procesado.', [
    'poste' => $postId, 'recibidas' => count($resultados), 'confirmadas' => $subidas, 'rechazadas' => $rechazadas,
]);
respond([
    'success' => $subidas > 0 && $rechazadas === 0,
    'poste_id' => $postId,
    'imagenes_procesadas' => $subidas,
    'resultados' => $resultados,
    'estado' => $estado,
], $subidas > 0 ? 200 : 422);
