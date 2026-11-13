<?php
declare(strict_types=1);

/**
 * Diagnóstico de procedencia de las fotografías de una estructura.
 *
 * ## Por qué existe
 *
 * Cuando una estructura se limpia administrativamente y luego se vuelve a
 * inspeccionar, en la carpeta del poste conviven archivos de las dos campañas:
 * `adminClearPost()` marca `imagenes_poste.activo=0` pero NO borra el archivo, y
 * `imagenes-poste.php` sólo borra el anterior cuando la fila que reemplaza
 * estaba activa. A eso se suma el respaldo de Qfile, que `mergeStructureImages()`
 * puede sustituir por el archivo oficial cuando éste falta o es ilegible.
 *
 * El resultado es que "qué foto sale en el PDF" deja de ser evidente. Esto lo
 * responde sin tocar nada.
 *
 * ## Regla de oro
 *
 * ESTE SERVICIO ES DE SOLO LECTURA. No ejecuta INSERT/UPDATE/DELETE, no crea,
 * mueve ni borra archivos del almacén. Lo único que escribe son los temporales
 * que `redimensionarImagenTemporal()` genera para comprobar si una imagen se
 * puede incrustar, y se eliminan en el acto.
 *
 * ## Por qué no reimplementa la resolución
 *
 * Un diagnóstico que calcula las rutas por su cuenta puede mentir en cuanto el
 * PDF cambie. Aquí se llama exactamente a lo mismo que llama el reporte:
 *
 *     resolveStructureResources()  -> igual que renderPostReport()
 *     reportImagePath()            -> igual que los paneles de PDFImagenesPoste
 *     redimensionarImagenTemporal()-> igual que el dibujo de cada celda
 *
 * El catálogo de slots (`pdfPhotoSlots()`) vive en `ReportPhotoSourceService.php`
 * y lo comparten el diagnóstico, la pantalla de selección de fotografías y el
 * reporte. Es cosmético: la verdad sobre qué archivo se usa sale de las
 * funciones de arriba, no de ese catálogo.
 */

require_once __DIR__ . '/../config/domain.php';
require_once __DIR__ . '/StructureRecoveryService.php';
// El catalogo de slots (pdfPhotoSlots), photoTypeFromFileName() y humanBytes()
// viven en ReportPhotoSourceService.php: los comparten el diagnostico, la
// pantalla de seleccion de fotografias y el reporte, para que los tres vean
// exactamente la misma fotografia por slot.
require_once __DIR__ . '/ReportPhotoSourceService.php';
// Arrastra PDFImagenesPoste.php (reportImagePath) y optimizador_imagenes.php
// (redimensionarImagenTemporal). Es la misma cadena de includes que usa
// api/ver_pdf_poste.php, de modo que aquí se ve lo mismo que ve el reporte.
require_once __DIR__ . '/PdfReportService.php';

const PHOTO_DIAGNOSTICS_VERSION = '1.0';

/** Techo de archivos inspeccionados por carpeta; evita un barrido patológico. */
const PHOTO_DIAGNOSTICS_MAX_FILES = 400;

/** Por encima de esto no se calcula SHA-256: el diagnóstico no debe tardar. */
const PHOTO_DIAGNOSTICS_MAX_HASH_BYTES = 26214400;

/**
 * Traduce una ruta absoluta del servidor a una etiqueta que se puede enseñar.
 *
 * El frontend nunca recibe `/data/imagenes/...` ni `C:\xampp82\...`: sólo la
 * raíz lógica y el tramo relativo, que es lo que hace falta para entender de
 * dónde salió el archivo.
 *
 * @return array{raiz:string,ruta:string|null}
 */
function logicalStorageLocation(?string $absolute): array
{
    if ($absolute === null || $absolute === '') {
        return ['raiz' => 'sin_archivo', 'ruta' => null];
    }
    $roots = [
        'almacen_imagenes' => 'images',
        'respaldo_movil' => 'mobile_backups',
        'archivo_admin' => 'admin_archive',
        'cache_pdf' => 'pdf',
        'respaldo_bd' => 'backups',
    ];
    $normalized = str_replace('\\', '/', $absolute);
    foreach ($roots as $label => $key) {
        try {
            $root = str_replace('\\', '/', storagePath($key));
        } catch (Throwable) {
            continue;
        }
        if ($root !== '' && stripos($normalized, rtrim($root, '/') . '/') === 0) {
            return ['raiz' => $label, 'ruta' => substr($normalized, strlen(rtrim($root, '/')) + 1)];
        }
    }
    $project = str_replace('\\', '/', projectPath());
    if (stripos($normalized, rtrim($project, '/') . '/') === 0) {
        return ['raiz' => 'arbol_proyecto', 'ruta' => substr($normalized, strlen(rtrim($project, '/')) + 1)];
    }

    // No se devuelve la ruta: está fuera de las raíces conocidas y podría
    // revelar la disposición interna del servidor.
    return ['raiz' => 'fuera_del_almacen', 'ruta' => null];
}

/**
 * Metadatos físicos de un archivo. Sólo lectura: stat, cabecera y hash.
 */
function photoFileFacts(?string $absolute, bool $withHash = true): array
{
    $location = logicalStorageLocation($absolute);
    $facts = [
        'existe' => false,
        'raiz' => $location['raiz'],
        'ruta_logica' => $location['ruta'],
        'nombre' => $absolute !== null ? basename($absolute) : null,
        'extension' => null,
        'bytes' => null,
        'tamano' => null,
        'mime' => null,
        'ancho' => null,
        'alto' => null,
        'modificado_en' => null,
        'sha256_corto' => null,
        'legible_como_imagen' => false,
    ];
    if ($absolute === null || $absolute === '' || !is_file($absolute)) {
        return $facts;
    }

    $bytes = (int) (@filesize($absolute) ?: 0);
    $modified = (int) (@filemtime($absolute) ?: 0);
    $info = @getimagesize($absolute);
    $facts['existe'] = true;
    $facts['extension'] = strtolower((string) pathinfo($absolute, PATHINFO_EXTENSION)) ?: null;
    $facts['bytes'] = $bytes;
    $facts['tamano'] = humanBytes($bytes);
    $facts['modificado_en'] = $modified > 0 ? date('Y-m-d H:i:s', $modified) : null;
    if (is_array($info)) {
        $facts['mime'] = (string) ($info['mime'] ?? '') ?: null;
        $facts['ancho'] = (int) ($info[0] ?? 0) ?: null;
        $facts['alto'] = (int) ($info[1] ?? 0) ?: null;
        $facts['legible_como_imagen'] = true;
    } elseif (function_exists('finfo_open')) {
        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo !== false) {
            $facts['mime'] = @finfo_file($finfo, $absolute) ?: null;
            @finfo_close($finfo);
        }
    }
    if ($withHash && $bytes > 0 && $bytes <= PHOTO_DIAGNOSTICS_MAX_HASH_BYTES) {
        $hash = @hash_file('sha256', $absolute);
        $facts['sha256_corto'] = is_string($hash) ? substr($hash, 0, 16) : null;
    }

    return $facts;
}

/**
 * ¿El archivo se creó antes o después de la última limpieza administrativa?
 *
 * Se responde con la fecha de modificación del archivo, que es el único dato
 * disponible. Si no hay limpieza registrada, o el archivo no existe, la
 * respuesta es "no determinable": no se adivina.
 */
function photoCleanupSide(array $facts, ?int $cleanupTimestamp): string
{
    if ($cleanupTimestamp === null || $cleanupTimestamp <= 0
        || !$facts['existe'] || $facts['modificado_en'] === null) {
        return 'no_determinable';
    }
    $modified = strtotime($facts['modificado_en']) ?: 0;
    if ($modified <= 0) {
        return 'no_determinable';
    }

    return $modified > $cleanupTimestamp ? 'posterior_a_limpieza' : 'anterior_a_limpieza';
}

/**
 * Comprueba si el PDF podría incrustar realmente este archivo.
 *
 * Repite el mismo filtro que el reporte: `getimagesize()` en los paneles y
 * `redimensionarImagenTemporal()` en el dibujo de la celda. El temporal que
 * pueda generar se borra aquí mismo.
 *
 * @return array{usable:bool,motivo:string|null}
 */
function photoIsEmbeddableByPdf(?string $absolute, bool $isPanoramic): array
{
    if ($absolute === null || $absolute === '') {
        return ['usable' => false, 'motivo' => 'sin_archivo_resuelto'];
    }
    if (!is_file($absolute)) {
        return ['usable' => false, 'motivo' => 'archivo_inexistente'];
    }
    // La panorámica la dibuja PDFFormularioPoste, que no pasa por getimagesize;
    // los paneles sí lo exigen antes de meter la foto en su mapa.
    if (!$isPanoramic && @getimagesize($absolute) === false) {
        return ['usable' => false, 'motivo' => 'no_es_una_imagen_legible'];
    }
    $prepared = redimensionarImagenTemporal($absolute, $isPanoramic ? PHP_INT_MAX : 800);
    if ($prepared === null) {
        return ['usable' => false, 'motivo' => 'no_se_puede_decodificar'];
    }
    if ($prepared !== $absolute && is_file($prepared)) {
        @unlink($prepared);
    }

    return ['usable' => true, 'motivo' => null];
}

/**
 * Carpetas físicas donde pueden vivir las fotografías de esta estructura.
 *
 * Se cubren las tres formas que resuelve `resolveStoredImagePath()`: la
 * jerarquía actual, la plana antigua y la carpeta real de cualquier fila ya
 * registrada (que podría apuntar a otro sitio).
 *
 * @return list<string>
 */
function structurePhotoDirectories(array $post, array $referencedPaths): array
{
    $imagesRoot = storagePath('images');
    $candidates = [
        $imagesRoot . DIRECTORY_SEPARATOR . 'proyecto_' . (int) $post['proyecto_id']
            . DIRECTORY_SEPARATOR . 'linea_' . sanitizeStorageSegment($post['linea'] ?? null, 'sin_linea')
            . DIRECTORY_SEPARATOR . 'poste_' . (int) $post['id'],
        $imagesRoot . DIRECTORY_SEPARATOR . (int) $post['id'],
    ];
    foreach ($referencedPaths as $absolute) {
        if (is_string($absolute) && $absolute !== '' && is_file($absolute)) {
            $candidates[] = dirname($absolute);
        }
    }

    $directories = [];
    foreach ($candidates as $directory) {
        if (!is_dir($directory) || !pathIsInside($directory, $imagesRoot)) {
            continue;
        }
        $real = realpath($directory) ?: $directory;
        $directories[$real] = true;
    }

    return array_keys($directories);
}

/**
 * Diagnóstico completo. Sólo lectura.
 *
 * @throws DomainException si la estructura no pertenece al proyecto indicado.
 */
function structurePhotoDiagnostics(PDO $connection, int $postId, int $projectId): array
{
    $started = hrtime(true);

    // Exactamente la misma llamada que hace renderPostReport(): lo que se
    // diagnostica es lo que el reporte va a ver.
    $resolved = resolveStructureResources($connection, $postId, $projectId);
    $post = $resolved['post'];

    $cleanupAt = trim((string) ($post['ultima_limpieza_admin'] ?? ''));
    $cleanupTimestamp = $cleanupAt !== '' ? (strtotime($cleanupAt) ?: null) : null;

    // --- Filas de base de datos, activas y archivadas -----------------------
    $query = $connection->prepare(
        'SELECT id, nombre_foto, ruta_archivo, fecha_captura, utm_este, utm_norte, zona,
                sincronizada, fecha_inspeccion, fecha_subida, activo, archivado_en
         FROM imagenes_poste WHERE poste_id = ? ORDER BY nombre_foto, id'
    );
    $query->execute([$postId]);
    $allRows = $query->fetchAll();

    $query = $connection->prepare(
        'SELECT nombre_foto, sincronizada, activo, archivado_en, actualizado_en
         FROM imagenes_poste_estado WHERE poste_id = ? ORDER BY nombre_foto'
    );
    $query->execute([$postId]);
    $stateRows = [];
    foreach ($query->fetchAll() as $row) {
        $stateRows[(string) $row['nombre_foto']] = $row;
    }

    $activeByType = [];
    $archivedByType = [];
    $referencedActive = [];
    $referencedAny = [];
    foreach ($allRows as $row) {
        $type = (string) $row['nombre_foto'];
        $absolute = resolveStoredImagePath($row['ruta_archivo'] ?? null);
        $row['_absolute'] = $absolute;
        if ($absolute !== null) {
            $referencedAny[strtolower($absolute)] = (int) $row['id'];
        }
        if ((int) $row['activo'] === 1) {
            $activeByType[$type] = $row;
            if ($absolute !== null) {
                $referencedActive[strtolower($absolute)] = (int) $row['id'];
            }
        } else {
            $archivedByType[$type][] = $row;
        }
    }

    // --- Inventario físico de las carpetas de la estructura -----------------
    $directories = structurePhotoDirectories($post, array_column($allRows, '_absolute'));
    $physical = [];
    $truncated = false;
    foreach ($directories as $directory) {
        foreach (@scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $absolute = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($absolute)) {
                continue;
            }
            if (count($physical) >= PHOTO_DIAGNOSTICS_MAX_FILES) {
                $truncated = true;
                break 2;
            }
            $physical[strtolower(realpath($absolute) ?: $absolute)] = realpath($absolute) ?: $absolute;
        }
    }

    // --- Fotos que el PDF tiene delante ------------------------------------
    $mergedByType = [];
    foreach ($resolved['images'] as $image) {
        $mergedByType[(string) $image['nombre_foto']] = $image;
    }

    $slots = [];
    $conflicts = [];
    $usedByPdf = 0;
    $found = 0;
    foreach (pdfPhotoSlots() as $slot) {
        $type = $slot['tipo'];
        $activeRow = $activeByType[$type] ?? null;
        $merged = $mergedByType[$type] ?? null;

        // Ruta que usaría el reporte, calculada con su propia función.
        $pdfPath = $merged !== null ? reportImagePath($merged) : null;
        $facts = photoFileFacts($pdfPath);
        $embeddable = photoIsEmbeddableByPdf($pdfPath, $type === 'foto_panoramica');
        if ($facts['existe']) {
            $found++;
        }
        if ($embeddable['usable']) {
            $usedByPdf++;
        }

        // Candidatos: todo archivo que podría confundirse con el de este slot.
        $candidates = [];
        $seen = [];
        $pushCandidate = static function (?string $absolute, string $origin, ?int $dbId) use (&$candidates, &$seen, $cleanupTimestamp): void {
            if ($absolute === null || $absolute === '' || !is_file($absolute)) {
                return;
            }
            $key = strtolower(realpath($absolute) ?: $absolute);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $facts = photoFileFacts($absolute);
            $candidates[] = array_merge($facts, [
                'origen' => $origin,
                'db_id' => $dbId,
                'respecto_a_limpieza' => photoCleanupSide($facts, $cleanupTimestamp),
            ]);
        };

        $pushCandidate($pdfPath, $merged['source'] ?? 'official', $activeRow !== null ? (int) $activeRow['id'] : null);
        $pushCandidate($activeRow['_absolute'] ?? null, 'fila_activa', $activeRow !== null ? (int) $activeRow['id'] : null);
        foreach ($archivedByType[$type] ?? [] as $archived) {
            $pushCandidate($archived['_absolute'], 'fila_archivada', (int) $archived['id']);
        }
        $backupPhoto = $resolved['backup']['photos'][$type] ?? null;
        if (is_array($backupPhoto)) {
            $pushCandidate($backupPhoto['absolute'] ?? null, 'respaldo_qfile', null);
        }
        // Archivos sueltos cuyo nombre delata el tipo: buildImageRelativePath()
        // los nombra `{tipo}_{24 hex}.jpg`. Se compara por coincidencia más
        // larga; si no, un archivo de `puesta_tierra_2` entraría también como
        // candidato de `puesta_tierra`.
        foreach ($physical as $absolute) {
            if (photoTypeFromFileName(basename($absolute)) === $type) {
                $isReferenced = isset($referencedAny[strtolower($absolute)]);
                $pushCandidate($absolute, $isReferenced ? 'fila_archivada' : 'archivo_suelto', $referencedAny[strtolower($absolute)] ?? null);
            }
        }

        $hasConflict = count($candidates) > 1;
        if ($hasConflict) {
            $conflicts[] = ['slot' => $type, 'etiqueta' => $slot['etiqueta'], 'candidatos' => count($candidates)];
        }

        $slots[] = [
            'slot' => $type,
            'panel' => $slot['panel'],
            'etiqueta' => $slot['etiqueta'],
            'db' => $activeRow === null ? null : [
                'id' => (int) $activeRow['id'],
                'ruta_registrada' => (string) $activeRow['ruta_archivo'],
                'fecha_captura' => $activeRow['fecha_captura'],
                'fecha_subida' => $activeRow['fecha_subida'],
                'sincronizada' => (int) $activeRow['sincronizada'] === 1,
                'utm_este' => $activeRow['utm_este'],
                'utm_norte' => $activeRow['utm_norte'],
                'zona' => $activeRow['zona'],
            ],
            'db_archivadas' => array_map(static fn(array $row): array => [
                'id' => (int) $row['id'],
                'ruta_registrada' => (string) $row['ruta_archivo'],
                'archivado_en' => $row['archivado_en'],
            ], $archivedByType[$type] ?? []),
            'estado_registrado' => isset($stateRows[$type]) ? [
                'activo' => (int) $stateRows[$type]['activo'] === 1,
                'sincronizada' => (int) $stateRows[$type]['sincronizada'] === 1,
                'actualizado_en' => $stateRows[$type]['actualizado_en'],
                'archivado_en' => $stateRows[$type]['archivado_en'],
            ] : null,
            'origen' => $merged['source'] ?? ($activeRow !== null ? 'official' : null),
            'archivo' => $facts,
            'respecto_a_limpieza' => photoCleanupSide($facts, $cleanupTimestamp),
            'usada_por_pdf' => $embeddable['usable'],
            'motivo_no_usada' => $embeddable['motivo'],
            'conflicto' => $hasConflict,
            'candidatos' => $candidates,
        ];
    }

    // --- Referencias en BD sin archivo --------------------------------------
    $broken = [];
    foreach ($activeByType as $type => $row) {
        $absolute = $row['_absolute'];
        if ($absolute !== null && is_file($absolute) && @getimagesize($absolute) !== false) {
            continue;
        }
        $broken[] = [
            'db_id' => (int) $row['id'],
            'slot' => $type,
            'ruta_registrada' => (string) $row['ruta_archivo'],
            'motivo' => $absolute === null
                ? 'la_ruta_no_resuelve_a_ningun_archivo'
                : (is_file($absolute) ? 'el_archivo_no_es_una_imagen_legible' : 'el_archivo_no_existe'),
            'sustituida_por_respaldo' => ($mergedByType[$type]['source'] ?? null) === 'backup_qfile',
        ];
    }

    // --- Archivos físicos sin referencia activa ------------------------------
    $orphans = [];
    foreach ($physical as $key => $absolute) {
        if (isset($referencedActive[$key])) {
            continue;
        }
        $facts = photoFileFacts($absolute);
        $archivedId = $referencedAny[$key] ?? null;
        $guessed = photoTypeFromFileName(basename($absolute));
        $orphans[] = array_merge($facts, [
            'motivo' => $archivedId !== null ? 'solo_referencia_archivada' : 'sin_referencia_en_bd',
            'db_id_archivada' => $archivedId,
            'slot_deducido' => $guessed,
            'respecto_a_limpieza' => photoCleanupSide($facts, $cleanupTimestamp),
        ]);
    }

    // --- Limpieza administrativa --------------------------------------------
    $audit = [];
    try {
        $query = $connection->prepare(
            "SELECT accion, usuario_nombre, resultado, creado_en
             FROM admin_auditoria
             WHERE poste_id = ? AND accion IN ('structure_cleared','line_cleared')
             ORDER BY creado_en DESC, id DESC LIMIT 5"
        );
        $query->execute([$postId]);
        $audit = $query->fetchAll();
    } catch (Throwable) {
        // La tabla de auditoría puede no existir en instalaciones antiguas.
        $audit = [];
    }
    $archiveSnapshots = [];
    try {
        $archiveRoot = storagePath('admin_archive');
        $suffix = 'proyecto_' . (int) $post['proyecto_id']
            . DIRECTORY_SEPARATOR . 'linea_' . sanitizeStorageSegment($post['linea'] ?? null, 'sin_linea')
            . DIRECTORY_SEPARATOR . 'poste_' . (int) $post['id'];
        foreach (@scandir($archiveRoot) ?: [] as $year) {
            $directory = $archiveRoot . DIRECTORY_SEPARATOR . $year . DIRECTORY_SEPARATOR . $suffix;
            if ($year === '.' || $year === '..' || !is_dir($directory)) {
                continue;
            }
            foreach (@scandir($directory) ?: [] as $stamp) {
                $snapshot = $directory . DIRECTORY_SEPARATOR . $stamp;
                if ($stamp === '.' || $stamp === '..' || !is_dir($snapshot)) {
                    continue;
                }
                $images = glob($snapshot . DIRECTORY_SEPARATOR . 'imagenes' . DIRECTORY_SEPARATOR . '*') ?: [];
                $modified = (int) (@filemtime($snapshot) ?: 0);
                $archiveSnapshots[] = [
                    'marca' => $stamp,
                    'anio' => $year,
                    'archivos_conservados' => count($images),
                    'creado_en' => $modified > 0 ? date('Y-m-d H:i:s', $modified) : null,
                ];
            }
        }
    } catch (Throwable) {
        $archiveSnapshots = [];
    }

    // --- Caché de PDF --------------------------------------------------------
    $cache = ['habilitada' => envValue('PDF_CACHE_ENABLED', true) === true, 'archivos' => [], 'huella_vigente' => null];
    try {
        $fingerprint = postReportFingerprint($connection, $postId, $projectId, $resolved);
        $cache['huella_vigente'] = substr($fingerprint, 0, 16);
        $current = pdfCacheFile($postId, $fingerprint);
        $directory = pdfCacheDirectory($postId);
        foreach (is_dir($directory) ? (glob($directory . DIRECTORY_SEPARATOR . '*.pdf') ?: []) : [] as $file) {
            $modified = (int) (@filemtime($file) ?: 0);
            $cache['archivos'][] = [
                'nombre' => basename($file),
                'bytes' => (int) (@filesize($file) ?: 0),
                'tamano' => humanBytes((int) (@filesize($file) ?: 0)),
                'modificado_en' => $modified > 0 ? date('Y-m-d H:i:s', $modified) : null,
                'corresponde_al_contenido_actual' => strcasecmp($file, $current) === 0,
            ];
        }
    } catch (Throwable $exception) {
        $cache['error'] = 'No se pudo calcular la huella del reporte.';
    }

    $registered = count(array_filter($slots, static fn(array $slot): bool => $slot['db'] !== null));

    return [
        'version' => PHOTO_DIAGNOSTICS_VERSION,
        'generado_en' => date('Y-m-d H:i:s'),
        'ms' => elapsedMs($started),
        'poste' => [
            'id' => (int) $post['id'],
            'proyecto_id' => (int) $post['proyecto_id'],
            'proyecto' => $post['nombre_proyecto'] ?? null,
            'linea' => $post['linea'] ?? null,
            'estructura' => $post['estructura'] ?? null,
            'codigo' => $post['codigo'] ?? null,
            'ubicaciones' => $post['ubicaciones'] ?? null,
        ],
        'estado' => [
            'formulario_subido' => (int) ($post['formulario_subido'] ?? 0) === 1,
            'inspeccion_finalizada' => (int) ($post['inspeccion_finalizada'] ?? 0) === 1,
            'sincronizado' => (int) ($post['sincronizado'] ?? 0) === 1,
            'imagenes_subidas' => (int) ($post['imagenes_subidas'] ?? 0) === 1,
            'fecha_inspeccion' => $post['fecha_inspeccion'] ?? null,
            'fecha_subida' => $post['fecha_subida'] ?? null,
            'estado_recuperacion' => $resolved['status'],
            'origen_predominante' => $resolved['source'],
            'origen_datos' => $resolved['data_source'],
        ],
        'limpieza' => [
            'ultima_limpieza_admin' => $cleanupAt !== '' ? $cleanupAt : null,
            'auditoria' => $audit,
            'instantaneas_archivadas' => $archiveSnapshots,
        ],
        'respaldo_movil' => [
            'estado' => $resolved['backup']['status'] ?? null,
            'avisos' => array_values($resolved['backup']['warnings'] ?? []),
            'tipos_declarados' => count($resolved['backup']['declared_types'] ?? []),
            'fotos_utilizables' => count($resolved['backup']['photos'] ?? []),
            'actualizado_en' => ($resolved['backup']['modified'] ?? 0) > 0
                ? date('Y-m-d H:i:s', (int) $resolved['backup']['modified'])
                : null,
        ],
        'resumen' => [
            'slots' => count($slots),
            'referenciadas_en_bd' => $registered,
            'archivos_encontrados' => $found,
            'usadas_por_pdf' => $usedByPdf,
            'referencias_rotas' => count($broken),
            'huerfanos' => count($orphans),
            'conflictos' => count($conflicts),
            'recuperadas_de_respaldo' => (int) $resolved['backup_used'],
            'inventario_truncado' => $truncated,
        ],
        'slots_detalle' => $slots,
        'referencias_rotas' => $broken,
        'huerfanos' => $orphans,
        'conflictos' => $conflicts,
        'cache_pdf' => $cache,
        'carpetas_inspeccionadas' => array_map(
            static fn(string $directory): array => logicalStorageLocation($directory),
            $directories
        ),
    ];
}

/**
 * Inventario reducido para el visor de imágenes: sólo las rutas absolutas que
 * este poste puede legítimamente servir, indexadas por su nombre de archivo.
 *
 * Se calcula aparte —sin decodificar imágenes ni hashear— porque el visor lo
 * necesita en cada miniatura y el diagnóstico completo es caro. La lista la
 * construye el servidor: el cliente sólo puede pedir un nombre de archivo, y si
 * no está aquí, no se sirve.
 *
 * @return array<string, string> nombre de archivo => ruta absoluta
 */
function structurePhotoServableFiles(PDO $connection, int $postId, int $projectId): array
{
    $resolved = resolveStructureResources($connection, $postId, $projectId);
    $post = $resolved['post'];

    $query = $connection->prepare('SELECT ruta_archivo FROM imagenes_poste WHERE poste_id = ?');
    $query->execute([$postId]);
    $referenced = [];
    foreach ($query->fetchAll() as $row) {
        $absolute = resolveStoredImagePath($row['ruta_archivo'] ?? null);
        if ($absolute !== null) {
            $referenced[] = $absolute;
        }
    }

    $servable = [];
    $add = static function (?string $absolute) use (&$servable): void {
        if ($absolute === null || $absolute === '' || !is_file($absolute)) {
            return;
        }
        $servable[basename($absolute)] = realpath($absolute) ?: $absolute;
    };

    foreach ($referenced as $absolute) {
        $add($absolute);
    }
    foreach ($resolved['images'] as $image) {
        $add(reportImagePath($image));
    }
    // Se usa la misma inspección que la pantalla de selección: si el respaldo es
    // anterior a una limpieza administrativa, `resolveStructureResources()` lo
    // vacía y sus fotografías no serían visibles aunque el administrador sí
    // pueda pedirlas para el reporte. Sigue siendo sólo lectura y sigue
    // confinado a la carpeta de respaldo de esta estructura.
    foreach (reportPhotoBackupInspection($resolved)['photos'] ?? [] as $photo) {
        $add($photo['absolute'] ?? null);
    }
    $count = 0;
    foreach (structurePhotoDirectories($post, $referenced) as $directory) {
        foreach (@scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || ++$count > PHOTO_DIAGNOSTICS_MAX_FILES) {
                continue;
            }
            $add($directory . DIRECTORY_SEPARATOR . $entry);
        }
    }

    return $servable;
}
