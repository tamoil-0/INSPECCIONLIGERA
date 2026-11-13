<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/domain.php';
require_once __DIR__ . '/../config/photo_status.php';

requireMethod('PUT');
requireAuth(['administrador', 'supervisor', 'tecnico']);
$postId = filter_input(INPUT_GET, 'poste_id', FILTER_VALIDATE_INT);
if (!$postId) {
    respond(['success' => false, 'error' => 'poste_id no es válido.'], 422);
}
$exists = $conn->prepare(
    "SELECT 1 FROM postes p
     INNER JOIN proyectos pr ON pr.id=p.proyecto_id AND pr.estado='activo'
     LEFT JOIN lineas l ON l.proyecto_id=p.proyecto_id AND l.nombre=p.linea
     WHERE p.id=? AND p.activo=1 AND COALESCE(l.activa,1)=1"
);
$exists->execute([$postId]);
if (!$exists->fetchColumn()) {
    respond(['success' => false, 'error' => 'Poste no encontrado.'], 404);
}
$data = requestJson();
if (!isset($data['distancia_acceso']) || !is_numeric($data['distancia_acceso']) || (float) $data['distancia_acceso'] < 0) {
    respond(['success' => false, 'error' => 'distancia_acceso es obligatoria y debe ser un número no negativo.'], 422);
}

$values = ['distancia_acceso' => (float) $data['distancia_acceso']];
foreach (inspectionRules() as $field => $allowed) {
    if (!array_key_exists($field, $data)) {
        continue;
    }
    if ($field === 'obstaculos_faja') {
        if (!is_array($data[$field]) || array_diff($data[$field], $allowed)) {
            respond(['success' => false, 'error' => 'obstaculos_faja contiene valores no permitidos.'], 422);
        }
        $values[$field] = implode(',', array_unique($data[$field]));
        continue;
    }
    if (!in_array($data[$field], $allowed, true)) {
        respond(['success' => false, 'error' => "Valor no permitido para {$field}."], 422);
    }
    $values[$field] = $data[$field];
}
if (array_key_exists('cantidad_pat', $data)) {
    $quantityRaw = $data['cantidad_pat'];
    if ($quantityRaw === null || (is_string($quantityRaw) && trim($quantityRaw) === '')) {
        $values['cantidad_pat'] = null;
    } else {
        $quantity = filter_var($quantityRaw, FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 0) {
            respond(['success' => false, 'error' => 'cantidad_pat debe ser un entero no negativo.'], 422);
        }
        $values['cantidad_pat'] = $quantity;
    }
}

foreach (['distancia_poste_anterior', 'distancia_vertical', 'distancia_horizontal'] as $distanceField) {
    if (!array_key_exists($distanceField, $data)) {
        continue;
    }
    $distance = $data[$distanceField];
    if ($distance === null || (is_string($distance) && trim($distance) === '')) {
        $values[$distanceField] = null;
        continue;
    }
    if (!is_numeric($distance) || !is_finite((float) $distance) || (float) $distance < 0) {
        respond(['success' => false, 'error' => "{$distanceField} debe ser un número no negativo o quedar vacío."], 422);
    }
    $values[$distanceField] = (float) $distance;
}
if (array_key_exists('comentarios', $data)) {
    $values['comentarios'] = mb_substr(trim((string) $data['comentarios']), 0, 5000);
}

$inspectionDate = normalizeDateTime($data['fecha_inspeccion'] ?? null);
if (isset($data['fecha_inspeccion']) && $inspectionDate === null) {
    respond(['success' => false, 'error' => 'fecha_inspeccion no es válida.'], 422);
}

$columns = array_keys($values);
$placeholders = implode(', ', array_fill(0, count($columns), '?'));
$updates = implode(', ', array_map(static fn(string $column): string => "{$column} = VALUES({$column})", $columns));
$sql = 'INSERT INTO poste_datos (poste_id, ' . implode(', ', $columns) . ") VALUES (?, {$placeholders}) ON DUPLICATE KEY UPDATE {$updates}";

try {
    $conn->beginTransaction();
    $conn->prepare($sql)->execute(array_merge([$postId], array_values($values)));
    $postSql = 'UPDATE postes SET formulario_subido = 1, inspeccion_finalizada = 1, fecha_subida = NOW()';
    $postParams = [];
    if ($inspectionDate !== null) {
        $postSql .= ', fecha_inspeccion = ?';
        $postParams[] = $inspectionDate;
    }
    $postSql .= ' WHERE id = ?';
    $postParams[] = $postId;
    $conn->prepare($postSql)->execute($postParams);
    $syncStatus = refreshPostSynchronizationStatus($conn, $postId);
    $conn->commit();
    // El formulario cambió: el PDF cacheado de este poste ya no representa la
    // inspección. Se retira aquí para no dejar archivos huérfanos en el NAS; la
    // huella de contenido de renderPostReport() haría lo correcto igualmente.
    invalidatePostPdfCache($postId);
    respond([
        'success' => true,
        'message' => 'Formulario guardado.',
        'poste_id' => $postId,
        'fecha_inspeccion' => $inspectionDate,
        'fecha_subida' => date('Y-m-d H:i:s'),
        'estado_fotografias' => $syncStatus,
    ]);
} catch (PDOException $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Inspection update failed: ' . $exception->getMessage());
    respond(['success' => false, 'error' => 'No se pudo guardar el formulario.'], 500);
}
