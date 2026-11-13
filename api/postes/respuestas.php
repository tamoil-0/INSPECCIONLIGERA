<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/PlantillaService.php';

/*
 * Respuestas del formulario de una estructura cuyo proyecto usa plantilla.
 *
 *   GET  → respuestas guardadas y fotos ya recibidas (para reabrir en la app).
 *   PUT  → guarda las respuestas validadas contra la plantilla vigente.
 *
 * El equivalente del flujo fijo es actualiza-datos.php; este endpoint rechaza
 * las estructuras de proyectos sin plantilla para que nunca se mezclen.
 */
requireMethod('GET', 'PUT');
$user = requireAuth(['administrador', 'supervisor', 'tecnico']);
$postId = filter_input(INPUT_GET, 'poste_id', FILTER_VALIDATE_INT);
if (!$postId) {
    respond(['success' => false, 'error' => 'poste_id no es válido.'], 422);
}

try {
    plantillasAsegurarEsquema($conn);
    $q = $conn->prepare(
        "SELECT p.id, p.proyecto_id, pr.estado AS proyecto_estado
         FROM postes p
         INNER JOIN proyectos pr ON pr.id = p.proyecto_id
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

    if (strtoupper($_SERVER['REQUEST_METHOD']) === 'GET') {
        $v = $conn->prepare('SELECT plantilla_version FROM poste_respuestas WHERE poste_id = ?');
        $v->execute([$postId]);
        $fotos = array_map(static fn(array $f): array => [
            'foto_clave' => $f['foto_clave'], 'indice' => (int) $f['indice'], 'uuid' => $f['uuid'],
            'checksum' => $f['checksum'], 'fecha_captura' => $f['fecha_captura'],
        ], plantillaFotosDePoste($conn, $postId));
        respond(['success' => true, 'data' => [
            'respuestas' => (object) plantillaRespuestasDePoste($conn, $postId),
            'plantilla_version' => ($ver = $v->fetchColumn()) !== false ? (int) $ver : null,
            'fotos' => $fotos,
        ]]);
    }

    if ($post['proyecto_estado'] !== 'activo') {
        respond(['success' => false, 'error' => 'El proyecto no está activo.'], 409);
    }
    $data = requestJson();
    if (!is_array($data['respuestas'] ?? null)) {
        respond(['success' => false, 'error' => 'Faltan las respuestas.'], 422);
    }
    // Se valida contra la versión vigente. Si el teléfono llenó el formulario
    // con una versión anterior (estaba sin señal cuando se cambió la
    // plantilla), no se rechaza: se guarda lo que sigue siendo válido, sin
    // exigir campos que el inspector nunca vio, y se devuelven avisos.
    $versionTelefono = is_numeric($data['plantilla_version'] ?? null) ? (int) $data['plantilla_version'] : null;
    $desactualizado = $versionTelefono !== null && $versionTelefono < (int) $plantilla['version'];
    $avisos = [];
    $respuestas = plantillaValidarRespuestas($plantilla['config'], $data['respuestas'], !$desactualizado, $desactualizado, $avisos);
    $fecha = normalizeDateTime($data['fecha_inspeccion'] ?? null);
    if (isset($data['fecha_inspeccion']) && $data['fecha_inspeccion'] !== null && $fecha === null) {
        respond(['success' => false, 'error' => 'fecha_inspeccion no es válida.'], 422);
    }

    $conn->beginTransaction();
    $conn->prepare(
        'INSERT INTO poste_respuestas (poste_id, plantilla_id, plantilla_version, respuestas, actualizado_por)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE plantilla_id = VALUES(plantilla_id), plantilla_version = VALUES(plantilla_version),
             respuestas = VALUES(respuestas), actualizado_por = VALUES(actualizado_por)'
    )->execute([
        $postId, $plantilla['id'], $plantilla['version'],
        json_encode((object) $respuestas, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $user['id'] ?? null,
    ]);
    $sql = 'UPDATE postes SET formulario_subido = 1, inspeccion_finalizada = 1, fecha_subida = NOW()'
        . ($fecha !== null ? ', fecha_inspeccion = ?' : '') . ' WHERE id = ?';
    $conn->prepare($sql)->execute($fecha !== null ? [$fecha, $postId] : [$postId]);
    $estado = plantillaRefrescarEstadoPoste($conn, $postId, $plantilla['config']);
    $conn->commit();
    invalidatePostPdfCache($postId);

    respond([
        'success' => true,
        'message' => 'Formulario guardado.',
        'poste_id' => $postId,
        'plantilla_version' => $plantilla['version'],
        'fecha_inspeccion' => $fecha,
        'estado' => $estado,
        'avisos' => $avisos,
    ]);
} catch (InvalidArgumentException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    respond(['success' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    appLog('sincronizacion', 'Fallo al guardar respuestas de plantilla.', ['poste' => $postId, 'motivo' => $e->getMessage()]);
    respond(['success' => false, 'error' => 'No se pudo guardar el formulario.'], 500);
}
