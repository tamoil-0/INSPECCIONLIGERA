<?php
declare(strict_types=1);

/**
 * Qué fotografías se imprimen en el reporte de una estructura.
 *
 * ## Por qué no es una tabla
 *
 * Esto no es información de inspección: es una preferencia de impresión. No
 * describe lo que se vio en campo, no viaja al móvil, no entra en el Excel y
 * perderla no pierde ningún dato —sólo devuelve el comportamiento por defecto,
 * que es imprimir todo—. Por eso vive en su propio almacén y no toca
 * `imagenes_poste`, `imagenes_poste_estado`, `poste_datos`, `postes` ni el RST:
 * un fallo aquí no puede corromper una inspección.
 *
 * Un archivo JSON por estructura, bajo la raíz `report_config`:
 *
 *     report_config/proyecto_2/poste_1240.json
 *
 * Escritura atómica con `writeFileAtomic()`, la misma que usa la caché de PDF.
 *
 * ## Se guarda lo EXCLUIDO, y sólo lo que se desmarcó de verdad
 *
 * Es la decisión importante del diseño. Guardar la lista de excluidas hace que
 * una estructura sin configuración imprima TODO, sin caso especial, y que
 * añadir un slot nuevo al catálogo no deje de imprimirse en ninguna parte.
 * Guardar las incluidas tendría el efecto contrario: cualquier cosa que no
 * estuviera en la lista dejaría de imprimirse en silencio.
 *
 * Por el mismo motivo la lista que se guarda es EXACTAMENTE la que el
 * administrador desmarcó, no el complemento del catálogo. Si se guardara el
 * complemento, configurar una estructura que hoy tiene 3 de 28 fotografías
 * excluiría los otros 25 slots «vacíos», y la fotografía que llegara mañana
 * para uno de ellos no se imprimiría sin que nadie lo hubiera decidido.
 *
 * ## Por fuente
 *
 * La selección es independiente para cada fuente, porque los archivos de una y
 * otra no son los mismos:
 *
 *     { "fuentes": { "current": { "excluidos": [...] },
 *                    "backup":  { "excluidos": [...] } } }
 *
 * Sólo se guardan claves de slot del catálogo (`photoTypes()`). Nunca rutas,
 * nombres de archivo ni nada que pueda usarse tal cual para leer del disco.
 */

require_once __DIR__ . '/../config/domain.php';
// Config conoce la resolución de fotografías; la resolución no conoce Config.
// Una sola dirección: así ningún archivo llama a una función que no declara.
require_once __DIR__ . '/ReportPhotoSourceService.php';

const REPORT_PHOTO_CONFIG_VERSION = 1;

/** Fuentes que se pueden configurar. `auto` no se configura: es el histórico. */
function reportPhotoConfigurableSources(): array
{
    return [REPORT_PHOTO_SOURCE_CURRENT, REPORT_PHOTO_SOURCE_BACKUP];
}

/** Ruta del archivo de configuración de una estructura. */
function reportPhotoConfigFile(int $postId, int $projectId, bool $ensure = false): string
{
    $directory = storagePath('report_config', $ensure) . DIRECTORY_SEPARATOR
        . 'proyecto_' . max(0, $projectId);
    if ($ensure) {
        ensureDirectory($directory, 0775);
    }

    return $directory . DIRECTORY_SEPARATOR . 'poste_' . max(0, $postId) . '.json';
}

/** Deja una lista de slots en claves del catálogo, sin repetir y ordenadas. */
function reportPhotoConfigCleanSlots(mixed $slots): array
{
    if (!is_array($slots)) {
        return [];
    }
    $catalog = photoTypes();
    $clean = [];
    foreach ($slots as $slot) {
        if (!is_scalar($slot)) {
            continue;
        }
        $slot = trim((string) $slot);
        if ($slot !== '' && in_array($slot, $catalog, true) && !in_array($slot, $clean, true)) {
            $clean[] = $slot;
        }
    }
    sort($clean);

    return $clean;
}

/**
 * Configuración guardada de una estructura.
 *
 * Nunca lanza: si el archivo no existe, está corrupto o el almacén no se puede
 * leer, se devuelve la configuración vacía, que significa «imprimir todo». Una
 * preferencia de impresión no puede impedir que salga un reporte.
 *
 * @return array{fuentes:array<string,array{excluidos:list<string>}>,actualizado_en:string|null,actualizado_por:string|null,existe:bool}
 */
function reportPhotoConfigRead(int $postId, int $projectId): array
{
    $empty = ['fuentes' => [], 'actualizado_en' => null, 'actualizado_por' => null, 'existe' => false];
    foreach (reportPhotoConfigurableSources() as $source) {
        $empty['fuentes'][$source] = ['excluidos' => []];
    }

    try {
        $file = reportPhotoConfigFile($postId, $projectId);
    } catch (Throwable) {
        return $empty;
    }
    if (!is_file($file)) {
        return $empty;
    }
    $raw = @file_get_contents($file);
    if (!is_string($raw) || $raw === '') {
        return $empty;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        appLog('almacenamiento', 'Configuracion de impresion ilegible; se imprime todo.', [
            'structure' => $postId,
            'project' => $projectId,
        ]);

        return $empty;
    }

    $result = $empty;
    $result['existe'] = true;
    $sources = is_array($decoded['fuentes'] ?? null) ? $decoded['fuentes'] : [];
    foreach (reportPhotoConfigurableSources() as $source) {
        $entry = is_array($sources[$source] ?? null) ? $sources[$source] : [];
        $result['fuentes'][$source]['excluidos'] = reportPhotoConfigCleanSlots($entry['excluidos'] ?? null);
    }
    $result['actualizado_en'] = is_scalar($decoded['actualizado_en'] ?? null)
        ? (string) $decoded['actualizado_en']
        : null;
    $result['actualizado_por'] = is_scalar($decoded['actualizado_por'] ?? null)
        ? mb_substr((string) $decoded['actualizado_por'], 0, 80)
        : null;

    return $result;
}

/**
 * Mete la configuración guardada en las opciones del reporte.
 *
 * Es el único puente entre la persistencia y la generación del PDF: lo llama
 * `renderPostReport()` justo antes de resolver las fotografías. Si quien pide
 * el reporte ya trajo una lista de exclusiones propia, se respeta y no se lee
 * nada del disco.
 *
 * @return array{photo_source:string,included_slots:list<string>|null,excluded_slots:list<string>}
 */
function reportPhotoApplySavedConfig(mixed $options, int $postId, int $projectId): array
{
    $normalized = normalizeReportPhotoOptions($options);
    if ($normalized['excluded_slots'] !== []) {
        return $normalized;
    }
    $normalized['excluded_slots'] = reportPhotoConfigExcluded(
        $postId,
        $projectId,
        $normalized['photo_source']
    );

    return $normalized;
}

/**
 * Slots que NO se imprimen para una fuente concreta.
 *
 * Es lo único que consulta la generación del PDF. Para `auto` devuelve una
 * lista vacía: esa fuente conserva el comportamiento histórico sin tocar.
 *
 * @return list<string>
 */
function reportPhotoConfigExcluded(int $postId, int $projectId, string $source): array
{
    if (!in_array($source, reportPhotoConfigurableSources(), true)) {
        return [];
    }

    return reportPhotoConfigRead($postId, $projectId)['fuentes'][$source]['excluidos'] ?? [];
}

/**
 * Guarda la selección de una fuente y deja la otra como estaba.
 *
 * Se reciben los slots que el administrador desmarcó «Imprimir» teniéndolos
 * delante. Nada más: un slot que no aparece en esta lista se imprime, hoy y
 * cuando llegue su fotografía.
 *
 * No borra fotografías, no toca `imagenes_poste` y no modifica ningún archivo
 * de imagen: sólo escribe este JSON.
 *
 * @param list<string> $excludedSlots slots desmarcados en la pantalla
 * @throws RuntimeException si el almacén de configuración no se puede escribir
 */
function reportPhotoConfigWrite(
    int $postId,
    int $projectId,
    string $source,
    array $excludedSlots,
    ?string $user = null
): array {
    if (!in_array($source, reportPhotoConfigurableSources(), true)) {
        throw new InvalidArgumentException('La fuente de fotografías no se puede configurar.');
    }

    $excluded = reportPhotoConfigCleanSlots($excludedSlots);

    $current = reportPhotoConfigRead($postId, $projectId);
    $sources = [];
    foreach (reportPhotoConfigurableSources() as $key) {
        $sources[$key] = [
            'excluidos' => $key === $source
                ? $excluded
                : ($current['fuentes'][$key]['excluidos'] ?? []),
        ];
    }

    $document = [
        'version' => REPORT_PHOTO_CONFIG_VERSION,
        'poste_id' => $postId,
        'proyecto_id' => $projectId,
        'actualizado_en' => date('Y-m-d H:i:s'),
        'actualizado_por' => $user !== null ? mb_substr($user, 0, 80) : null,
        'fuentes' => $sources,
    ];
    $encoded = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if (!is_string($encoded)) {
        throw new RuntimeException('No se pudo serializar la configuración de impresión.');
    }
    if (!writeFileAtomic(reportPhotoConfigFile($postId, $projectId, true), $encoded)) {
        throw new RuntimeException('No se pudo guardar la configuración de impresión.');
    }

    // El PDF cacheado de esta estructura corresponde a la selección anterior.
    // Su huella ya incluye la selección efectiva, así que no se podría servir
    // por error; se retira igualmente para no dejar archivos que nadie va a
    // volver a pedir. Sólo borra PDF generados: ninguna fotografía.
    invalidatePostPdfCache($postId);

    return [
        'fuentes' => $sources,
        'actualizado_en' => $document['actualizado_en'],
        'actualizado_por' => $document['actualizado_por'],
        'existe' => true,
    ];
}

/**
 * Todo lo que la pantalla «Seleccionar fotografías del reporte» necesita.
 *
 * Devuelve las tres fuentes resueltas de una vez para que cambiar de fuente en
 * la interfaz no dispare otra petición y no pueda quedar desincronizada con lo
 * que imprimirá el PDF. Cada slot del catálogo aparece siempre, disponible o
 * no, para que «No disponible en el respaldo» se vea en lugar de desaparecer.
 *
 * Sólo lectura.
 *
 * @throws DomainException si la estructura no pertenece al proyecto indicado.
 */
function reportPhotoSelection(PDO $connection, int $postId, int $projectId): array
{
    $started = hrtime(true);
    // La misma llamada que hace renderPostReport(): lo que se ofrece elegir es
    // lo que el reporte va a ver.
    $resolved = resolveStructureResources($connection, $postId, $projectId);
    $post = $resolved['post'];
    $archived = reportPhotoArchivedCounts($connection, $postId);
    $catalog = pdfPhotoSlots();
    $labels = reportPhotoSourceLabels();
    // Lo que el administrador dejó guardado para cada fuente. Vacío = todo se
    // imprime, que es el estado por defecto de una estructura sin configurar.
    $saved = reportPhotoConfigRead($postId, $projectId);

    $sources = [];
    foreach (reportPhotoConfigurableSources() as $source) {
        $excludedSlots = $saved['fuentes'][$source]['excluidos'] ?? [];
        $backup = $source === REPORT_PHOTO_SOURCE_BACKUP
            ? reportPhotoBackupInspection($resolved)
            : (is_array($resolved['backup'] ?? null) ? $resolved['backup'] : []);
        $expected = reportPhotoExpectedSlots($resolved, $backup);
        $available = reportPhotoImagesForSource($resolved, $source, $backup);

        $slots = [];
        foreach ($catalog as $slot) {
            $type = $slot['tipo'];
            $image = $available[$type] ?? null;
            $summary = reportPhotoFileSummary($image !== null ? reportImagePath($image) : null);
            $isExpected = in_array($type, $expected, true);
            $slots[] = [
                'slot' => $type,
                'panel' => $slot['panel'],
                'etiqueta' => $slot['etiqueta'],
                'esperada' => $isExpected,
                'disponible' => $summary['archivo'] !== null,
                // Lo que la pantalla debe mostrar marcado en «Imprimir».
                'imprimir' => !in_array($type, $excludedSlots, true),
                'origen' => $image !== null ? (string) ($image['source'] ?? 'official') : null,
                'fecha_captura' => $image !== null ? ($image['fecha_captura'] ?? null) : null,
                'archivo' => $summary['archivo'],
                'tamano' => $summary['tamano'],
                'modificado_en' => $summary['modificado_en'],
                // Aviso informativo. La elección entre candidatos concretos no
                // se ofrece aquí: el detalle está en «Ver origen de archivos».
                'candidatos' => ($image !== null ? 1 : 0) + ($archived[$type] ?? 0),
                'motivo' => $summary['archivo'] !== null
                    ? null
                    : ($source === REPORT_PHOTO_SOURCE_BACKUP
                        ? 'no_disponible_en_respaldo'
                        : ($isExpected ? 'sin_archivo_oficial' : 'no_registrada')),
            ];
        }

        $entry = [
            'clave' => $source,
            'etiqueta' => $labels[$source]['etiqueta'],
            'descripcion' => $labels[$source]['descripcion'],
            'disponibles' => count(array_filter($slots, static fn(array $row): bool => $row['disponible'])),
            'esperadas' => count($expected),
            'no_disponibles' => count(array_diff($expected, array_keys($available))),
            'excluidos_guardados' => $excludedSlots,
            'imprimibles' => count(array_filter(
                $slots,
                static fn(array $row): bool => $row['disponible'] && $row['imprimir']
            )),
            'slots' => $slots,
        ];
        if ($source === REPORT_PHOTO_SOURCE_BACKUP) {
            $warnings = is_array($backup['warnings'] ?? null) ? $backup['warnings'] : [];
            $entry['respaldo'] = [
                'estado' => $backup['status'] ?? null,
                'actualizado_en' => ($backup['modified'] ?? 0) > 0
                    ? date('Y-m-d H:i:s', (int) $backup['modified'])
                    : null,
                'tipos_declarados' => count($backup['declared_types'] ?? []),
                'anterior_a_limpieza' => in_array('prior_to_admin_clear', $warnings, true),
                'avisos' => array_values($warnings),
            ];
        }
        $sources[] = $entry;
    }

    return [
        'generado_en' => date('Y-m-d H:i:s'),
        'ms' => elapsedMs($started),
        'poste' => [
            'id' => (int) $post['id'],
            'proyecto_id' => (int) $post['proyecto_id'],
            'codigo' => $post['codigo'] ?? null,
            'linea' => $post['linea'] ?? null,
            'estructura' => $post['estructura'] ?? null,
        ],
        'total_slots' => count($catalog),
        // La interfaz sólo ofrece NAS y Backup: «automático» no dice qué se
        // está revisando, y aquí lo importante es saberlo con exactitud.
        'fuente_predeterminada' => REPORT_PHOTO_SOURCE_CURRENT,
        'configuracion' => [
            'existe' => $saved['existe'],
            'actualizado_en' => $saved['actualizado_en'],
            'actualizado_por' => $saved['actualizado_por'],
        ],
        'ultima_limpieza_admin' => trim((string) ($post['ultima_limpieza_admin'] ?? '')) !== ''
            ? $post['ultima_limpieza_admin']
            : null,
        'fuentes' => $sources,
    ];
}
