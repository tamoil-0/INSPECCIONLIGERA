<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('GET');
requireAuth();
$postId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$postId) {
    respond(['success' => false, 'error' => 'ID de poste no válido.'], 422);
}

$statement = $conn->prepare('SELECT * FROM postes WHERE id = ?');
$statement->execute([$postId]);
$post = $statement->fetch();
if (!$post) {
    respond(['success' => false, 'error' => 'Poste no encontrado.'], 404);
}
$details = $conn->prepare('SELECT * FROM poste_datos WHERE poste_id = ?');
$details->execute([$postId]);
$rst = $conn->prepare('SELECT seccion, fase, atributo FROM poste_secciones_rst WHERE poste_id = ? ORDER BY seccion, fase');
$rst->execute([$postId]);
$sections = [];
foreach ($rst->fetchAll() as $row) {
    $sections[$row['seccion']][] = ['fase' => $row['fase'], 'atributo' => $row['atributo']];
}
respond([
    'success' => true,
    'data' => ['poste' => $post, 'secciones_rst' => $sections, 'datos_adicionales' => $details->fetch() ?: null],
    'meta' => ['total_secciones' => array_sum(array_map('count', $sections)), 'timestamp' => date('Y-m-d H:i:s')],
]);
