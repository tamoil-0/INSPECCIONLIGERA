<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('POST', 'PUT');
requireAuth(['administrador', 'supervisor', 'tecnico']);
$postId = filter_input(INPUT_GET, 'poste_id', FILTER_VALIDATE_INT);
if (!$postId) {
    respond(['success' => false, 'error' => 'poste_id no es válido.'], 422);
}
$data = requestJson();
$records = isset($data['registros']) ? $data['registros'] : [$data];
if (!is_array($records) || $records === []) {
    respond(['success' => false, 'error' => 'No se enviaron registros RST.'], 422);
}

$sections = ['conductores_fase', 'conductores_cuellos', 'conductores_guarda', 'estado_aisladores'];
$phases = ['R', 'S', 'T'];
$conductorAttributes = ['hebras_rotas', 'encanastillado', 'empalme_deformado', 'objetos_extranos'];
$insulatorAttributes = ['buen_estado', 'rotos_suspension', 'rotos_anclaje_adelante', 'rotos_anclaje_atras', 'mal_estado'];
foreach ($records as $index => $record) {
    if (!is_array($record)) {
        respond(['success' => false, 'error' => "Registro RST #{$index} no válido."], 422);
    }
    $section = $record['seccion'] ?? '';
    $phase = $record['fase'] ?? '';
    $attribute = $record['atributo'] ?? '';
    $allowed = $section === 'estado_aisladores' ? $insulatorAttributes : $conductorAttributes;
    if (!in_array($section, $sections, true) || !in_array($phase, $phases, true) || !in_array($attribute, $allowed, true)) {
        respond(['success' => false, 'error' => "Registro RST #{$index} contiene valores no permitidos."], 422);
    }
}

$exists = $conn->prepare('SELECT 1 FROM postes WHERE id = ?');
$exists->execute([$postId]);
if (!$exists->fetchColumn()) {
    respond(['success' => false, 'error' => 'Poste no encontrado.'], 404);
}

try {
    $conn->beginTransaction();
    $statement = $conn->prepare(
        'INSERT INTO poste_secciones_rst (poste_id, seccion, atributo, fase) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE atributo = VALUES(atributo)'
    );
    foreach ($records as $record) {
        $statement->execute([$postId, $record['seccion'], $record['atributo'], $record['fase']]);
    }
    $conn->commit();
    respond(['success' => true, 'message' => 'Registros RST guardados.', 'procesados' => count($records)]);
} catch (PDOException $exception) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('RST update failed: ' . $exception->getMessage());
    respond(['success' => false, 'error' => 'No se pudieron guardar los registros RST.'], 500);
}
