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
$statement = $conn->prepare('SELECT * FROM postes WHERE id = ?');
$statement->execute([$postId]);
$post = $statement->fetch();
if (!$post) {
    respond(['success' => false, 'error' => 'Poste no encontrado.'], 404);
}
$details = $conn->prepare('SELECT * FROM poste_datos WHERE poste_id = ?');
$details->execute([$postId]);
$rst = $conn->prepare('SELECT seccion, atributo, fase FROM poste_secciones_rst WHERE poste_id = ? ORDER BY seccion, fase');
$rst->execute([$postId]);
$images = $conn->prepare('SELECT nombre_foto, ruta_archivo, fecha_captura, utm_este, utm_norte, zona FROM imagenes_poste WHERE poste_id = ? ORDER BY nombre_foto');
$images->execute([$postId]);
respond(['success' => true, 'poste' => $post, 'datos' => $details->fetch() ?: null, 'rst' => $rst->fetchAll(), 'imagenes' => $images->fetchAll()]);
