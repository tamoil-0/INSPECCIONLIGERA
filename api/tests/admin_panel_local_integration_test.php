<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../services/AdminCatalogService.php';
require_once __DIR__ . '/../services/StructureRecoveryService.php';

const DEV_LINE = 'DEV-PANEL-20260824';
const PROJECT_ID = 2;

if ((string) $conn->query('SELECT DATABASE()')->fetchColumn() !== 'ecoing_inspeccion_recuperada') {
    throw new RuntimeException('Prueba bloqueada fuera de ecoing_inspeccion_recuperada.');
}
$token = (string) getenv('TEST_ADMIN_JWT');
if ($token === '' || (validateJWT($token)['rol'] ?? null) !== 'administrador') {
    throw new RuntimeException('Se requiere un JWT administrativo temporal valido.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$api = static function (string $method, string $path, ?array $payload = null, int $expected = 200) use ($token): array {
    $headers = "Accept: application/json\r\nAuthorization: Bearer {$token}\r\n";
    $body = '';
    if ($payload !== null) {
        $headers .= "Content-Type: application/json\r\n";
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => $headers, 'content' => $body,
        'ignore_errors' => true, 'timeout' => 20,
    ]]);
    $raw = @file_get_contents(rtrim((string) envValue('APP_URL'), '/') . '/api/' . ltrim($path, '/'), false, $context);
    $line = $http_response_header[0] ?? '';
    $status = preg_match('/\s(\d{3})\s/', $line, $match) ? (int) $match[1] : 0;
    $decoded = json_decode(is_string($raw) ? $raw : '', true);
    if ($status !== $expected) {
        throw new RuntimeException("{$method} {$path}: HTTP {$status}, esperado {$expected}. Respuesta: " . substr((string) $raw, 0, 500));
    }
    return is_array($decoded) ? $decoded : [];
};
$fetchOne = static function (PDO $db, string $sql, array $params = []): array {
    $q = $db->prepare($sql); $q->execute($params); return $q->fetch() ?: [];
};
$seedInspection = static function (PDO $db, int $postId, string $comment): void {
    $db->prepare(
        'INSERT INTO poste_datos(poste_id,distancia_acceso,comentarios) VALUES(?,?,?)
         ON DUPLICATE KEY UPDATE distancia_acceso=VALUES(distancia_acceso),comentarios=VALUES(comentarios)'
    )->execute([$postId, 12.75, $comment]);
    $db->prepare(
        "INSERT INTO poste_secciones_rst(poste_id,seccion,atributo,fase,activo,archivado_en)
         VALUES(?,'conductores_fase','hebras_rotas','R',1,NULL)
         ON DUPLICATE KEY UPDATE atributo=VALUES(atributo),activo=1,archivado_en=NULL"
    )->execute([$postId]);
    $db->prepare(
        'UPDATE postes SET formulario_subido=1,inspeccion_finalizada=1,imagenes_subidas=1,
         sincronizado=1,fecha_inspeccion=NOW(),fecha_subida=NOW() WHERE id=?'
    )->execute([$postId]);
};
$seedPhoto = static function (PDO $db, array $post, string $type): string {
    if (!function_exists('imagecreatetruecolor')) throw new RuntimeException('GD no esta disponible.');
    $relative = buildImageRelativePath((int) $post['proyecto_id'], $post['linea'], (int) $post['id'], $type);
    $absolute = imageAbsoluteTarget($relative);
    ensureDirectory(dirname($absolute), 0775);
    $image = imagecreatetruecolor(640, 480);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 100, 75));
    imagejpeg($image, $absolute, 85); imagedestroy($image);
    $db->prepare(
        'INSERT INTO imagenes_poste(poste_id,nombre_foto,ruta_archivo,fecha_captura,sincronizada,fecha_inspeccion,fecha_subida,activo,archivado_en)
         VALUES(?,?,?,NOW(),1,NOW(),NOW(),1,NULL)
         ON DUPLICATE KEY UPDATE ruta_archivo=VALUES(ruta_archivo),sincronizada=1,activo=1,archivado_en=NULL'
    )->execute([(int) $post['id'], $type, $relative]);
    $db->prepare(
        'INSERT INTO imagenes_poste_estado(poste_id,nombre_foto,sincronizada,activo,archivado_en)
         VALUES(?,?,1,1,NULL)
         ON DUPLICATE KEY UPDATE sincronizada=1,activo=1,archivado_en=NULL'
    )->execute([(int) $post['id'], $type]);
    return $absolute;
};

$projectBefore = adminFetch($conn, 'proyectos', PROJECT_ID);
$lineBefore = $fetchOne($conn, 'SELECT * FROM lineas WHERE proyecto_id=? AND nombre=?', [PROJECT_ID, 'L-6024']);
$assert($lineBefore !== [], 'No existe L-6024.');
$results = [];
$auditBefore = $fetchOne($conn, "SELECT COUNT(*) n FROM admin_auditoria WHERE usuario_nombre='admin_dev' AND accion IN ('structure_cleared','line_cleared')", []);

try {
    $listed = $api('GET', 'admin/proyectos.php?limite=10');
    $assert((int) ($listed['total'] ?? 0) >= 1, 'No se listo el proyecto recuperado.');
    $results['proyecto_listar'] = 'ok';

    $changedLocation = (string) $projectBefore['ubicacion'] . ' [PRUEBA ADMIN]';
    $api('PATCH', 'admin/proyectos.php', [
        'id' => PROJECT_ID, 'accion' => 'actualizar',
        'nombre_proyecto' => $projectBefore['nombre_proyecto'],
        'contratista' => $projectBefore['contratista'], 'ubicacion' => $changedLocation,
    ]);
    $assert((string) adminFetch($conn, 'proyectos', PROJECT_ID)['ubicacion'] === $changedLocation, 'No se edito el proyecto.');
    $api('PATCH', 'admin/proyectos.php', [
        'id' => PROJECT_ID, 'accion' => 'actualizar',
        'nombre_proyecto' => $projectBefore['nombre_proyecto'],
        'contratista' => $projectBefore['contratista'], 'ubicacion' => $projectBefore['ubicacion'],
    ]);
    $api('PATCH', 'admin/proyectos.php', ['id' => PROJECT_ID, 'accion' => 'desactivar']);
    $assert(adminFetch($conn, 'proyectos', PROJECT_ID)['estado'] === 'cancelado', 'No se archivo el proyecto.');
    $api('PATCH', 'admin/proyectos.php', ['id' => PROJECT_ID, 'accion' => 'reactivar']);
    $assert(adminFetch($conn, 'proyectos', PROJECT_ID)['estado'] === 'activo', 'No se reactivo el proyecto.');
    $results['proyecto_editar_archivar_reactivar'] = 'ok';

    $lines = $api('GET', 'admin/lineas.php?proyecto_id=2&busqueda=L-6024&limite=10');
    $assert((int) ($lines['total'] ?? 0) === 1, 'No se listo L-6024.');
    $api('PATCH', 'admin/lineas.php', ['id' => (int) $lineBefore['id'], 'accion' => 'desactivar']);
    $assert((int) adminFetch($conn, 'lineas', (int) $lineBefore['id'])['activa'] === 0, 'No se archivo L-6024.');
    $assert((int) $conn->query("SELECT COUNT(*) FROM postes WHERE proyecto_id=2 AND linea='L-6024'")->fetchColumn() === 118, 'Archivar la linea altero sus postes.');
    $api('PATCH', 'admin/lineas.php', ['id' => (int) $lineBefore['id'], 'accion' => 'reactivar']);
    $assert((int) adminFetch($conn, 'lineas', (int) $lineBefore['id'])['activa'] === 1, 'No se reactivo L-6024.');
    $results['linea_real_listar_archivar_reactivar'] = 'ok';

    $existingDev = $fetchOne($conn, 'SELECT * FROM lineas WHERE proyecto_id=? AND nombre=?', [PROJECT_ID, DEV_LINE]);
    if ($existingDev === []) {
        $devLine = $api('POST', 'admin/lineas.php', ['proyecto_id' => PROJECT_ID, 'nombre' => DEV_LINE, 'ubicacion' => 'ENTORNO LOCAL'], 201);
        $devLineId = (int) $devLine['data']['id'];
        $posts = [];
    } else {
        $devLineId = (int) $existingDev['id'];
        $existingPosts = $conn->prepare('SELECT * FROM postes WHERE proyecto_id=? AND linea=? ORDER BY id');
        $existingPosts->execute([PROJECT_ID, DEV_LINE]);
        $posts = $existingPosts->fetchAll();
        $assert(count($posts) === 0 || count($posts) === 3, 'La linea DEV debe estar vacia o contener exactamente sus tres postes de prueba.');
    }
    if ($posts === []) {
        foreach ([1, 2, 3] as $number) {
            $suffix = str_pad((string) $number, 2, '0', STR_PAD_LEFT);
            $created = $api('POST', 'admin/estructuras.php', [
                'proyecto_id' => PROJECT_ID, 'linea' => DEV_LINE, 'estructura' => $suffix,
                'codigo' => DEV_LINE . '-' . $suffix, 'utm_x' => '390000.12345',
                'utm_y' => '8249000.54321', 'zona' => '19S', 'ubicaciones' => 'LOCAL-PRUEBA',
            ], 201);
            $posts[] = adminFetch($conn, 'postes', (int) $created['data']['id']);
        }
    }
    $results['linea_y_estructuras_dev_creadas'] = count($posts);

    $masterBefore = $posts[0];
    $api('PATCH', 'admin/estructuras.php', [
        'id' => (int) $masterBefore['id'], 'accion' => 'actualizar', 'linea' => DEV_LINE,
        'estructura' => $masterBefore['estructura'], 'codigo' => $masterBefore['codigo'],
        'utm_x' => '390001.54321', 'utm_y' => $masterBefore['utm_y'], 'zona' => '19S',
        'ubicaciones' => $masterBefore['ubicaciones'],
    ]);
    $edited = adminFetch($conn, 'postes', (int) $masterBefore['id']);
    $assert((string) $edited['utm_x'] === '390001.54321', 'No se conservo el decimal UTM editado.');
    $api('PATCH', 'admin/estructuras.php', ['id' => (int) $edited['id'], 'accion' => 'desactivar']);
    $assert((int) adminFetch($conn, 'postes', (int) $edited['id'])['activo'] === 0, 'No se archivo la estructura DEV.');
    $api('PATCH', 'admin/estructuras.php', ['id' => (int) $edited['id'], 'accion' => 'reactivar']);
    $assert((int) adminFetch($conn, 'postes', (int) $edited['id'])['activo'] === 1, 'No se reactivo la estructura DEV.');
    $results['estructura_editar_utm_archivar_reactivar'] = 'ok';

    $post1 = adminFetch($conn, 'postes', (int) $posts[0]['id']);
    $seedInspection($conn, (int) $post1['id'], 'FICTICIO ESTRUCTURA');
    $photo1 = $seedPhoto($conn, $post1, 'foto_panoramica');
    $backupRoot = storagePath('mobile_backups', true);
    $backupDir = $backupRoot . DIRECTORY_SEPARATOR . 'Proyecto_2' . DIRECTORY_SEPARATOR
        . 'Linea_' . mobileBackupSegment(DEV_LINE, 'sin_linea') . DIRECTORY_SEPARATOR
        . 'Estructura_' . mobileBackupSegment($post1['estructura'], 'sin_codigo');
    $manifestPath = $backupDir . DIRECTORY_SEPARATOR . 'Formulario' . DIRECTORY_SEPARATOR . 'formulario.json';
    ensureDirectory(dirname($manifestPath), 0775);
    $manifest = [
        'generado_en' => date('c', time() - 3600),
        'estructura' => ['proyecto_id' => 2, 'poste_id' => (int) $post1['id'], 'estructura' => $post1['estructura'], 'codigo' => $post1['codigo'], 'linea' => DEV_LINE],
        'formulario' => ['distancia_acceso' => 999, 'comentarios' => 'BACKUP ANTIGUO'],
        'fotografias' => ['archivos' => []], 'secciones_rst' => [],
    ];
    $assert(writeFileAtomic($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 'No se creo el manifiesto Qfile de prueba.');
    touch($manifestPath, time() - 3600);
    $snapshotMaster = array_intersect_key(adminFetch($conn, 'postes', (int) $post1['id']), array_flip(['id','proyecto_id','linea','codigo','estructura','utm_x','utm_y','zona','ubicaciones']));
    $preview = $api('POST', 'admin/limpiar.php', ['alcance' => 'estructura', 'poste_id' => (int) $post1['id'], 'solo_resumen' => true]);
    $assert((int) $preview['data']['formularios'] === 1 && (int) $preview['data']['fotos'] === 1 && (int) $preview['data']['rst'] === 1, 'El resumen de estructura no coincide.');
    $cleared = $api('POST', 'admin/limpiar.php', ['alcance' => 'estructura', 'poste_id' => (int) $post1['id'], 'solo_resumen' => false, 'confirmacion' => $post1['codigo']]);
    $afterMaster = array_intersect_key(adminFetch($conn, 'postes', (int) $post1['id']), $snapshotMaster);
    $assert($afterMaster === $snapshotMaster, 'La limpieza cambio datos maestros de la estructura.');
    $assert((int) $fetchOne($conn, 'SELECT COUNT(*) n FROM poste_datos WHERE poste_id=? AND distancia_acceso IS NOT NULL', [(int) $post1['id']])['n'] === 0, 'El formulario no quedo limpio.');
    $assert((int) $fetchOne($conn, 'SELECT COUNT(*) n FROM poste_secciones_rst WHERE poste_id=? AND activo=1', [(int) $post1['id']])['n'] === 0, 'RST sigue activo.');
    $assert((int) $fetchOne($conn, 'SELECT COUNT(*) n FROM imagenes_poste WHERE poste_id=? AND activo=1', [(int) $post1['id']])['n'] === 0, 'La foto sigue oficial.');
    $assert(is_file($photo1), 'La foto original fue eliminada fisicamente.');
    $stamp = (string) $cleared['data']['archivo'];
    $archiveManifest = storagePath('admin_archive') . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR
        . 'proyecto_2' . DIRECTORY_SEPARATOR . 'linea_' . sanitizeStorageSegment(DEV_LINE)
        . DIRECTORY_SEPARATOR . 'poste_' . (int) $post1['id'] . DIRECTORY_SEPARATOR . $stamp . DIRECTORY_SEPARATOR . 'inspeccion.json';
    $assert(is_file($archiveManifest), 'No existe el manifiesto del archivo administrativo.');
    $resolved = resolveStructureResources($conn, (int) $post1['id'], PROJECT_ID);
    $assert(in_array('blocked_by_admin_clear', $resolved['backup']['warnings'] ?? [], true), 'Qfile antiguo no quedo bloqueado.');
    $results['limpieza_estructura_archivo_qfile'] = 'ok';

    $api('PUT', 'postes/actualiza-datos.php?poste_id=' . (int) $post1['id'], ['distancia_acceso' => 8.5, 'comentarios' => 'REENVIO MOVIL DEV', 'fecha_inspeccion' => date('Y-m-d H:i:s')]);
    $assert((int) adminFetch($conn, 'postes', (int) $post1['id'])['formulario_subido'] === 1, 'El backend no acepto el reenvio movil.');
    $seedInspection($conn, (int) $posts[1]['id'], 'FICTICIO LINEA');
    $post2 = adminFetch($conn, 'postes', (int) $posts[1]['id']);
    $photo2 = $seedPhoto($conn, $post2, 'placa');
    $seedInspection($conn, (int) $posts[2]['id'], 'FICTICIO ROLLBACK');

    $conn->beginTransaction();
    adminClearPost($conn, adminFetch($conn, 'postes', (int) $posts[2]['id'], true));
    $conn->rollBack();
    $rollbackValue = $fetchOne($conn, 'SELECT distancia_acceso FROM poste_datos WHERE poste_id=?', [(int) $posts[2]['id']]);
    $assert((float) $rollbackValue['distancia_acceso'] === 12.75, 'ROLLBACK no restauro el formulario ficticio.');
    $results['rollback_transaccional'] = 'ok';

    $lineMasterBefore = [];
    foreach ($posts as $post) {
        $current = adminFetch($conn, 'postes', (int) $post['id']);
        $lineMasterBefore[(int) $post['id']] = array_intersect_key($current, array_flip(['id','proyecto_id','linea','codigo','estructura','utm_x','utm_y','zona','ubicaciones']));
    }
    $linePreview = $api('POST', 'admin/limpiar.php', ['alcance' => 'linea', 'proyecto_id' => PROJECT_ID, 'linea' => DEV_LINE, 'solo_resumen' => true]);
    $assert((int) $linePreview['data']['estructuras'] === 3, 'El resumen de linea no contiene tres estructuras.');
    $api('POST', 'admin/limpiar.php', ['alcance' => 'linea', 'proyecto_id' => PROJECT_ID, 'linea' => DEV_LINE, 'solo_resumen' => false, 'confirmacion' => 'CONFIRMACION-INCORRECTA'], 422);
    $assert((int) $fetchOne($conn, 'SELECT COUNT(*) n FROM poste_datos d JOIN postes p ON p.id=d.poste_id WHERE p.linea=? AND d.distancia_acceso IS NOT NULL', [DEV_LINE])['n'] === 3, 'La confirmacion incorrecta altero datos.');
    $lineClear = $api('POST', 'admin/limpiar.php', ['alcance' => 'linea', 'proyecto_id' => PROJECT_ID, 'linea' => DEV_LINE, 'solo_resumen' => false, 'confirmacion' => DEV_LINE]);
    $assert((int) $lineClear['data']['estructuras'] === 3, 'La limpieza de linea no proceso tres estructuras.');
    foreach ($lineMasterBefore as $postId => $master) {
        $after = array_intersect_key(adminFetch($conn, 'postes', $postId), $master);
        $assert($after === $master, "La limpieza de linea cambio datos maestros de {$postId}.");
    }
    $pending = $fetchOne($conn, "SELECT COUNT(*) n FROM postes WHERE proyecto_id=? AND linea=? AND formulario_subido=0 AND inspeccion_finalizada=0 AND sincronizado=0", [PROJECT_ID, DEV_LINE]);
    $assert((int) $pending['n'] === 3, 'No todas las estructuras quedaron pendientes.');
    $assert(is_file($photo2), 'La limpieza de linea elimino fisicamente una foto.');
    $results['limpieza_linea_controlada'] = 'ok';

    $audit = $fetchOne($conn, "SELECT COUNT(*) n FROM admin_auditoria WHERE usuario_nombre='admin_dev' AND accion IN ('structure_cleared','line_cleared')", []);
    $auditDelta = (int) $audit['n'] - (int) $auditBefore['n'];
    $assert($auditDelta === 2, 'No se registraron ambas limpiezas en auditoria.');
    $results['auditoria_limpiezas'] = $auditDelta;
} finally {
    $conn->prepare('UPDATE proyectos SET nombre_proyecto=?,contratista=?,ubicacion=?,estado=? WHERE id=?')->execute([
        $projectBefore['nombre_proyecto'], $projectBefore['contratista'], $projectBefore['ubicacion'], $projectBefore['estado'], PROJECT_ID,
    ]);
    $conn->prepare('UPDATE lineas SET activa=?,desactivada_en=? WHERE id=?')->execute([
        $lineBefore['activa'], $lineBefore['desactivada_en'], $lineBefore['id'],
    ]);
}

echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
