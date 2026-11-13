<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/photo_status.php';

requireMethod('GET');
requireAuth();
$postId = filter_input(INPUT_GET, 'poste_id', FILTER_VALIDATE_INT);
if (!$postId) {
    respond(['success' => false, 'error' => 'poste_id no es válido.'], 422);
}
$statement = $conn->prepare(
    'SELECT p.*, ' . photoStatusSelectSql() . '
     FROM postes p' . photoStatusJoinSql() . '
     WHERE p.id = ?'
);
$statement->execute([$postId]);
$post = $statement->fetch();
if (!$post) {
    respond(['success' => false, 'error' => 'Poste no encontrado.'], 404);
}
$details = $conn->prepare('SELECT * FROM poste_datos WHERE poste_id = ?');
$details->execute([$postId]);
$rst = $conn->prepare('SELECT seccion, atributo, fase FROM poste_secciones_rst WHERE poste_id = ? AND activo = 1 ORDER BY seccion, fase');
$rst->execute([$postId]);
$images = $conn->prepare(
    'SELECT e.nombre_foto, e.sincronizada, i.ruta_archivo, i.fecha_captura,
            i.utm_este, i.utm_norte, i.zona
     FROM imagenes_poste_estado e
     LEFT JOIN imagenes_poste i
       ON i.poste_id = e.poste_id AND i.nombre_foto = e.nombre_foto
     WHERE e.poste_id = ? AND e.activo = 1 AND (i.id IS NULL OR i.activo = 1)
     ORDER BY e.nombre_foto'
);
$images->execute([$postId]);
respond(['success' => true, 'poste' => $post, 'datos' => $details->fetch() ?: null, 'rst' => $rst->fetchAll(), 'imagenes' => $images->fetchAll()]);
