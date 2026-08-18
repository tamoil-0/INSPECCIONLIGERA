<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('GET');
requireAuth();
$postId = filter_input(INPUT_GET, 'poste_id', FILTER_VALIDATE_INT);
if (!$postId) {
    respond(['success' => false, 'error' => 'poste_id no es válido.'], 422);
}
$statement = $conn->prepare('SELECT formulario_subido, imagenes_subidas FROM postes WHERE id = ?');
$statement->execute([$postId]);
$post = $statement->fetch();
if (!$post) {
    respond(['success' => false, 'error' => 'Poste no encontrado.'], 404);
}
respond(['success' => true, 'poste_id' => $postId, 'formulario_subido' => (bool) $post['formulario_subido'], 'imagenes_subidas' => (bool) $post['imagenes_subidas']]);
