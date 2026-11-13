<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/domain.php';

function adminText(mixed $value, int $max, string $field, bool $required = true): ?string
{
    if (!is_scalar($value) && $value !== null) {
        throw new InvalidArgumentException("{$field} no es válido.");
    }
    $text = trim((string) ($value ?? ''));
    if ($required && $text === '') {
        throw new InvalidArgumentException("{$field} es obligatorio.");
    }
    if ($text === '') return null;
    if (mb_strlen($text) > $max || preg_match('/[\x00-\x1F\x7F]/u', $text)) {
        throw new InvalidArgumentException("{$field} no es válido.");
    }
    return $text;
}

function adminId(mixed $value, string $field = 'ID'): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if ($id === false || $id <= 0) throw new InvalidArgumentException("{$field} no es válido.");
    return (int) $id;
}

function adminCoordinate(mixed $value, string $field): ?string
{
    if ($value === null || trim((string) $value) === '') return null;
    $text = str_replace(',', '.', trim((string) $value));
    if (!preg_match('/^-?\d{1,9}(?:\.\d{1,5})?$/', $text) || !is_finite((float) $text)) {
        throw new InvalidArgumentException("{$field} debe ser numérico y tener hasta cinco decimales.");
    }
    return $text;
}

function adminZone(mixed $value): string
{
    $zone = strtoupper((string) adminText($value ?? '19S', 10, 'Zona'));
    if (!preg_match('/^(?:[1-9]|[1-5][0-9]|60)[C-HJ-NP-X]$/', $zone)) {
        throw new InvalidArgumentException('La zona UTM no es válida.');
    }
    return $zone;
}

function adminPagination(): array
{
    $page = max(1, (int) ($_GET['pagina'] ?? 1));
    $limit = min(100, max(10, (int) ($_GET['limite'] ?? 25)));
    return [$page, $limit, ($page - 1) * $limit];
}

function adminAudit(PDO $db, array $user, string $action, string $entity,
    array $context = [], mixed $before = null, mixed $after = null,
    string $result = 'ok', ?string $detail = null): void
{
    $encode = static fn(mixed $value): ?string => $value === null ? null : json_encode(
        $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    $username = mb_substr((string) ($user['username'] ?? 'administrador'), 0, 100);
    $db->prepare(
        'INSERT INTO admin_auditoria
         (usuario_id, usuario_nombre, accion, entidad_tipo, proyecto_id, linea,
          poste_id, antes_json, despues_json, resultado, detalle)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        isset($user['id']) ? (int) $user['id'] : null, $username, $action, $entity,
        isset($context['proyecto_id']) ? (int) $context['proyecto_id'] : null,
        isset($context['linea']) ? mb_substr((string) $context['linea'], 0, 100) : null,
        isset($context['poste_id']) ? (int) $context['poste_id'] : null,
        $encode($before), $encode($after), $result,
        $detail === null ? null : mb_substr($detail, 0, 500),
    ]);
    appLog('administracion', 'Acción administrativa.', [
        'usuario' => $username, 'accion' => $action, 'entidad' => $entity,
        'proyecto' => $context['proyecto_id'] ?? null,
        'linea' => $context['linea'] ?? null,
        'estructura' => $context['poste_id'] ?? null, 'resultado' => $result,
    ]);
}

function adminFetch(PDO $db, string $table, int $id, bool $lock = false): array
{
    if (!in_array($table, ['proyectos', 'lineas', 'postes'], true)) {
        throw new InvalidArgumentException('Entidad administrativa no válida.');
    }
    $query = $db->prepare("SELECT * FROM {$table} WHERE id = ?" . ($lock ? ' FOR UPDATE' : ''));
    $query->execute([$id]);
    $row = $query->fetch();
    if (!$row) throw new DomainException('Registro no encontrado.');
    return $row;
}

function adminCleanupStats(PDO $db, int $projectId, ?string $line = null, ?int $postId = null): array
{
    $where = ['p.proyecto_id = ?']; $params = [$projectId];
    if ($line !== null) { $where[] = 'p.linea = ?'; $params[] = $line; }
    if ($postId !== null) { $where[] = 'p.id = ?'; $params[] = $postId; }
    $sqlWhere = implode(' AND ', $where);
    $q = $db->prepare(
        "SELECT COUNT(*) AS estructuras,
          SUM(EXISTS(SELECT 1 FROM poste_datos d WHERE d.poste_id=p.id AND d.distancia_acceso IS NOT NULL)) AS formularios,
          SUM((SELECT COUNT(*) FROM imagenes_poste i WHERE i.poste_id=p.id AND i.activo=1)) AS fotos,
          SUM((SELECT COUNT(*) FROM poste_secciones_rst r WHERE r.poste_id=p.id AND r.activo=1)) AS rst
         FROM postes p WHERE {$sqlWhere} AND p.activo=1"
    );
    $q->execute($params); $row = $q->fetch() ?: [];
    return [
        'estructuras' => (int) ($row['estructuras'] ?? 0),
        'formularios' => (int) ($row['formularios'] ?? 0),
        'fotos' => (int) ($row['fotos'] ?? 0),
        'rst' => (int) ($row['rst'] ?? 0),
    ];
}

function adminInspectionSnapshot(PDO $db, array $post): array
{
    $id = (int) $post['id'];
    $one = static function (PDO $db, string $sql, int $id): array {
        $q = $db->prepare($sql); $q->execute([$id]); return $q->fetchAll();
    };
    return [
        'poste' => $post,
        'datos' => $one($db, 'SELECT * FROM poste_datos WHERE poste_id=?', $id),
        'rst' => $one($db, 'SELECT * FROM poste_secciones_rst WHERE poste_id=? AND activo=1', $id),
        'imagenes' => $one($db, 'SELECT * FROM imagenes_poste WHERE poste_id=? AND activo=1', $id),
        'estados_foto' => $one($db, 'SELECT * FROM imagenes_poste_estado WHERE poste_id=? AND activo=1', $id),
    ];
}

function adminArchiveInspectionFiles(array $snapshot, string $stamp): int
{
    $post = $snapshot['poste'];
    $base = date('Y') . '/proyecto_' . (int) $post['proyecto_id']
        . '/linea_' . sanitizeStorageSegment($post['linea'])
        . '/poste_' . (int) $post['id'] . '/' . $stamp;
    $root = storagePath('admin_archive', true); $copied = 0;
    $copy = static function (string $source, string $relative) use ($root, &$copied): void {
        $safe = normalizeStorageRelativePath($relative);
        if ($safe === null || !is_file($source)) return;
        $target = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $safe);
        ensureDirectory(dirname($target), 0775);
        if (!@copy($source, $target) || @filesize($source) !== @filesize($target)) {
            @unlink($target);
            throw new RuntimeException('No se pudo conservar un archivo antes de limpiar.');
        }
        $copied++;
    };
    foreach ($snapshot['imagenes'] as $image) {
        $source = resolveStoredImagePath($image['ruta_archivo'] ?? null);
        if ($source !== null) {
            $copy($source, $base . '/imagenes/' . sanitizeStorageSegment($image['nombre_foto']) . '_' . basename($source));
        }
    }
    $pdfDir = pdfCacheDirectory((int) $post['id']);
    foreach (is_dir($pdfDir) ? (glob($pdfDir . DIRECTORY_SEPARATOR . '*.pdf') ?: []) : [] as $pdf) {
        if (pathIsInside($pdf, storagePath('pdf'))) $copy($pdf, $base . '/pdf/' . basename($pdf));
    }
    $manifest = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $base . '/inspeccion.json');
    ensureDirectory(dirname($manifest), 0775);
    if (!writeFileAtomic($manifest, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
        throw new RuntimeException('No se pudo conservar el manifiesto administrativo.');
    }
    return $copied;
}

function adminClearPost(PDO $db, array $post): void
{
    $id = (int) $post['id'];
    $columns = [
        'obstaculos_faja','estado_cuencas','marcado_arboles','criticidad_tala','criticidad_contacto',
        'notificacion_propietario','tipo_torre','ubicacion','acceso_torre','estado_acceso',
        'estado_placas_torre','estado_placas_linea','estado_placas_fases','peligro_cerco','peligro_torre',
        'puesta_tierra','retenida','estado_base','limpiar_base','crucetas_mensuales','perfiles_angulares',
        'malla_antiescalamiento','oxidos_base','cadena_aisladores','tipo_aislador','conductor_bajada_pat',
        'conductor_guarda','comentarios','distancia_acceso','distancia_poste_anterior','distancia_vertical',
        'distancia_horizontal'
    ];
    $set = implode(', ', array_map(static fn(string $c): string => "{$c}=NULL", $columns));
    $db->prepare("UPDATE poste_datos SET {$set}, cantidad_pat=0 WHERE poste_id=?")->execute([$id]);
    $db->prepare('UPDATE poste_secciones_rst SET activo=0, archivado_en=NOW() WHERE poste_id=? AND activo=1')->execute([$id]);
    $db->prepare('UPDATE imagenes_poste SET activo=0, archivado_en=NOW() WHERE poste_id=? AND activo=1')->execute([$id]);
    $db->prepare('UPDATE imagenes_poste_estado SET activo=0, archivado_en=NOW() WHERE poste_id=? AND activo=1')->execute([$id]);
    $db->prepare('UPDATE postes SET fecha_inspeccion=NULL, fecha_subida=NULL, sincronizado=0,
        formulario_subido=0, inspeccion_finalizada=0, imagenes_subidas=0, ultima_limpieza_admin=NOW()
        WHERE id=?')->execute([$id]);
}
