<?php
declare(strict_types=1);

/**
 * Fuente y selección de las fotografías de un reporte.
 *
 * ## Por qué existe
 *
 * `resolveStructureResources()` decide sola de dónde sale cada fotografía:
 * prefiere el archivo oficial y, cuando falta o es ilegible, lo sustituye por
 * el del respaldo del móvil. Es lo correcto para el día a día, pero deja un
 * caso sin respuesta: una estructura que se usó para pruebas, se limpió
 * administrativamente y se volvió a inspeccionar puede tener en el almacén
 * oficial fotografías mezcladas de las dos campañas, mientras que el respaldo
 * del NAS sí conserva las de la campaña buena.
 *
 * Este servicio permite decir «para este reporte usa la inspección del
 * respaldo» y «no imprimas estas tres fotografías», sin modificar datos.
 *
 * ## Regla de oro
 *
 * SÓLO LECTURA. Aquí no hay INSERT/UPDATE/DELETE, no se crean, copian, mueven
 * ni borran archivos. La elección de fuente vive únicamente durante la
 * generación del reporte: nada se persiste en la base ni en el almacén.
 *
 * ## Qué cambia y qué no
 *
 *   current -> formulario, RST, fecha, coordenadas y fotos actuales.
 *   backup  -> formulario.json y Fotos/ del respaldo, sin fallback al NAS.
 *   auto    -> comportamiento histórico de recuperación automática.
 *
 * La identidad y los datos maestros del padrón permanecen en la BD, porque son
 * los que seleccionan de forma segura la estructura.
 *
 * ## Por qué no reimplementa la resolución
 *
 * Las tres fuentes salen de las mismas funciones que usa el reporte:
 *
 *   auto    -> `resolveStructureResources()['images']`, sin tocar.
 *   current -> las filas de ese mismo resultado con `source === 'official'`.
 *   backup  -> `mergeStructureImages([], [], $backup)`, es decir la misma
 *              función de mezcla sin ninguna fila oficial que pueda ganar.
 *
 * Así el diagnóstico, la pantalla de selección y el PDF ven exactamente la
 * misma fotografía por slot. No hay un segundo resolutor que pueda mentir.
 *
 * ## Nada de mezclas invisibles
 *
 * En `backup` un slot sin archivo utilizable en el respaldo se queda vacío: el
 * PDF dibuja su celda «No tiene foto». NUNCA se cae hacia la fotografía
 * oficial. Si el administrador pidió el respaldo, el reporte sale con lo que
 * haya en el respaldo.
 */

require_once __DIR__ . '/../config/domain.php';
require_once __DIR__ . '/StructureRecoveryService.php';
// `reportImagePath()`: la misma función con la que los paneles del PDF deciden
// qué archivo incrustan.
require_once __DIR__ . '/../clases/PDFImagenesPoste.php';
// Aquí NO se requiere `ReportPhotoConfigService.php`. Este archivo resuelve
// fotografías y no sabe nada de la persistencia de la selección; quien las
// combina es Config, que sí requiere este archivo. La dependencia va en un
// solo sentido a propósito: cuando eran mutuas, `PdfReportService` llamaba a
// `reportPhotoApplySavedConfig()` sin declararla y sólo funcionaba porque el
// ciclo la cargaba de rebote.

/** Comportamiento histórico: oficial y, si falta, respaldo. */
const REPORT_PHOTO_SOURCE_AUTO = 'auto';

/** Sólo fotografías oficiales actuales del servidor/NAS. */
const REPORT_PHOTO_SOURCE_CURRENT = 'current';

/** Sólo fotografías disponibles en el respaldo del móvil (Qfile/NAS). */
const REPORT_PHOTO_SOURCE_BACKUP = 'backup';

/** @return list<string> */
function reportPhotoSources(): array
{
    return [
        REPORT_PHOTO_SOURCE_AUTO,
        REPORT_PHOTO_SOURCE_CURRENT,
        REPORT_PHOTO_SOURCE_BACKUP,
    ];
}

/**
 * Etiquetas y explicación de cada fuente, tal como se muestran en la interfaz.
 *
 * @return array<string, array{etiqueta:string,descripcion:string}>
 */
function reportPhotoSourceLabels(): array
{
    return [
        // `auto` sigue existiendo para las peticiones sin parámetros, pero no
        // se ofrece en la interfaz: el usuario elige NAS o Backup y sabe
        // exactamente qué está mirando.
        REPORT_PHOTO_SOURCE_AUTO => [
            'etiqueta' => 'Resolución estándar',
            'descripcion' => 'Comportamiento histórico del sistema.',
        ],
        REPORT_PHOTO_SOURCE_CURRENT => [
            'etiqueta' => 'NAS',
            'descripcion' => 'Formulario y fotografías sincronizados actualmente.',
        ],
        REPORT_PHOTO_SOURCE_BACKUP => [
            'etiqueta' => 'Backup',
            'descripcion' => 'Formulario y fotografías encontrados en el respaldo móvil.',
        ],
    ];
}

/**
 * Slots del reporte, en el orden en que el PDF los dibuja.
 *
 * Reproduce `buildPostReport()` + los paneles de `PDFImagenesPoste.php`. Las
 * etiquetas son las que imprime el PDF; donde el panel oculta el rótulo
 * (`ocultarNombres`) se deja el nombre legible del slot para poder nombrarlo.
 *
 * Es el catálogo único que comparten el diagnóstico, la pantalla de selección
 * y el reporte: si alguien lo cambia, los tres cambian a la vez. Vive aquí, y
 * no en `PhotoDiagnosticsService.php`, para que el servicio de reportes pueda
 * usarlo sin incluir el diagnóstico completo.
 *
 * @return list<array{tipo:string,panel:string,etiqueta:string}>
 */
function pdfPhotoSlots(): array
{
    $simple = static fn(string $type): string => ucfirst(str_replace('_', ' ', $type));
    $slots = [];
    $add = static function (string $panel, array $types) use (&$slots): void {
        foreach ($types as $type => $label) {
            $slots[] = ['tipo' => $type, 'panel' => $panel, 'etiqueta' => $label];
        }
    };

    // El panel de estructura rotula con ucfirst(str_replace('_',' ',$tipo)),
    // igual que hace agregarImagenesPoste().
    $estructura = ['placa', 'torre_parte_inferior', 'torre_parte_superior', 'base_torre',
        'mensulas', 'crucetas', 'perfiles_angulares', 'atiescalamiento', 'otros'];

    $add('Formulario (página 1)', ['foto_panoramica' => 'Foto Panorámica']);
    $add('Panel fotográfico de la estructura', array_combine($estructura, array_map($simple, $estructura)));
    $add('Aisladores', [
        'aisladores_fase_r_atras' => 'Aisladores (Fase R) Atrás',
        'aisladores_fase_s_atras' => 'Aisladores (Fase S) Atrás',
        'aisladores_fase_t_atras' => 'Aisladores (Fase T) Atrás',
        'aisladores_fase_r_adelante' => 'Aisladores (Fase R) Adelante',
        'aisladores_fase_s_adelante' => 'Aisladores (Fase S) Adelante',
        'aisladores_fase_t_adelante' => 'Aisladores (Fase T) Adelante',
        'ferreteria_fase_r' => 'Ferretería (Fase R)',
        'ferreteria_fase_s' => 'Ferretería (Fase S)',
        'ferreteria_fase_t' => 'Ferretería (Fase T)',
    ]);
    $add('Cable de guarda y OPGW', [
        'cable_guarda' => 'Cable de Guarda',
        'ferreteria_de_cable_de_guarda' => 'Ferretería de cable de guarda',
    ]);
    $add('Conductores', [
        'conductor' => 'Conductor',
        'ferreteria_de_conductor' => 'Ferretería de conductor',
    ]);
    $add('Puesta a tierra', [
        'puesta_tierra' => 'Puesta a Tierra',
        'puesta_tierra_2' => 'Puesta a Tierra 2',
    ]);
    $add('Faja de servidumbre / Ubicación acceso', [
        'faja_servidumbre' => 'Faja de servidumbre',
        'ubicacion_acceso' => 'Ubicación de acceso',
    ]);
    $add('Retenida', ['retenida' => 'Retenida']);

    // Si alguien añade un tipo a photoTypes() y olvida su panel, el slot se
    // muestra igualmente en vez de desaparecer del diagnóstico sin avisar.
    $known = array_column($slots, 'tipo');
    foreach (photoTypes() as $type) {
        if (!in_array($type, $known, true)) {
            $slots[] = ['tipo' => $type, 'panel' => 'Sin panel asignado', 'etiqueta' => $simple($type)];
        }
    }

    return $slots;
}

/**
 * Deduce el slot a partir del nombre del archivo.
 *
 * `buildImageRelativePath()` nombra `{tipo}_{24 hex}.jpg`, y varios tipos
 * llevan guiones bajos (`aisladores_fase_r_atras`), así que hay que quedarse
 * con el prefijo MÁS LARGO que coincida, no con el primer tramo.
 */
function photoTypeFromFileName(string $fileName): ?string
{
    $lower = strtolower($fileName);
    $match = null;
    foreach (photoTypes() as $type) {
        if (str_starts_with($lower, strtolower($type) . '_')
            && ($match === null || strlen($type) > strlen($match))) {
            $match = $type;
        }
    }

    return $match;
}

function humanBytes(?int $bytes): ?string
{
    if ($bytes === null || $bytes < 0) {
        return null;
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB'];
    $value = $bytes / 1024;
    foreach ($units as $index => $unit) {
        if ($value < 1024 || $index === count($units) - 1) {
            return number_format($value, $value < 10 ? 1 : 0, '.', '') . ' ' . $unit;
        }
        $value /= 1024;
    }

    return $bytes . ' B';
}

/**
 * Normaliza lo que llega del cliente a opciones de confianza.
 *
 * El frontend NUNCA manda rutas: sólo una clave de fuente y nombres de slot.
 * Un slot que no esté en `photoTypes()` se descarta, así que no hay ninguna
 * cadena del cliente que pueda terminar formando parte de una ruta.
 *
 * `included_slots === null` significa «todas»: es el valor por defecto. Una
 * lista vacía significa «ninguna», que es distinto de «todas» y por eso se
 * respeta tal cual.
 *
 * `excluded_slots` es por dónde entra la configuración guardada en el panel
 * admin (`ReportPhotoConfigService`). Se guarda lo excluido y no lo incluido
 * para que una estructura sin configurar imprima todo y para que una
 * fotografía que llegue más tarde se imprima por defecto.
 *
 * @return array{photo_source:string,included_slots:list<string>|null,excluded_slots:list<string>}
 */
function normalizeReportPhotoOptions(mixed $raw): array
{
    $raw = is_array($raw) ? $raw : [];
    $source = strtolower(trim((string) (
        $raw['photo_source'] ?? $raw['photoSource'] ?? REPORT_PHOTO_SOURCE_AUTO
    )));
    if (!in_array($source, reportPhotoSources(), true)) {
        $source = REPORT_PHOTO_SOURCE_AUTO;
    }

    $slots = null;
    foreach (['slots', 'included_slots', 'includedSlots'] as $key) {
        if (array_key_exists($key, $raw)) {
            $slots = $raw[$key];
            break;
        }
    }
    if (is_string($slots)) {
        $slots = array_values(array_filter(
            array_map('trim', explode(',', $slots)),
            static fn(string $value): bool => $value !== ''
        ));
    }

    $included = null;
    if (is_array($slots)) {
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
        // Una lista que cubre el catálogo entero equivale a "todas". Se
        // normaliza a null para que el reporte estándar siga siendo cacheable
        // aunque el cliente enumere los 28 slots.
        $included = count($clean) === count($catalog) ? null : $clean;
    }

    $excluded = [];
    foreach (['excluded_slots', 'excludedSlots', 'excluidos'] as $key) {
        if (!array_key_exists($key, $raw)) {
            continue;
        }
        $value = $raw[$key];
        if (is_string($value)) {
            $value = array_values(array_filter(
                array_map('trim', explode(',', $value)),
                static fn(string $item): bool => $item !== ''
            ));
        }
        if (is_array($value)) {
            $catalog = photoTypes();
            foreach ($value as $slot) {
                if (!is_scalar($slot)) {
                    continue;
                }
                $slot = trim((string) $slot);
                if ($slot !== '' && in_array($slot, $catalog, true) && !in_array($slot, $excluded, true)) {
                    $excluded[] = $slot;
                }
            }
        }
        break;
    }
    sort($excluded);

    return [
        'photo_source' => $source,
        'included_slots' => $included,
        'excluded_slots' => $excluded,
    ];
}

/** ¿Son las opciones exactamente el comportamiento histórico? */
function reportPhotoOptionsAreDefault(mixed $options): bool
{
    $normalized = normalizeReportPhotoOptions($options);

    return $normalized['photo_source'] === REPORT_PHOTO_SOURCE_AUTO
        && $normalized['included_slots'] === null
        && $normalized['excluded_slots'] === [];
}

/**
 * Inspección del respaldo cuando el administrador la pide explícitamente.
 *
 * `resolveStructureResources()` vacía el respaldo cuando es anterior a la
 * última limpieza administrativa. Ese filtro protege la RECUPERACIÓN
 * automática: nadie quiere que una limpieza se deshaga sola. Aquí no hay nada
 * automático —un administrador ha elegido «Backup NAS» a mano— así que se
 * vuelve a inspeccionar sin ese filtro y se marca `prior_to_admin_clear` para
 * poder advertirlo en la interfaz. Sigue siendo sólo lectura: no se copia, no
 * se reactiva y no se modifica ninguna referencia.
 *
 * @return array{status:string,photos:array<string,array>,declared_types:list<string>,warnings:list<string>,modified:int}
 */
function reportPhotoBackupInspection(array $resolved): array
{
    $backup = is_array($resolved['backup'] ?? null) ? $resolved['backup'] : [];
    $warnings = is_array($backup['warnings'] ?? null) ? $backup['warnings'] : [];
    if (in_array('blocked_by_admin_clear', $warnings, true) && is_array($resolved['post'] ?? null)) {
        $reinspected = inspectMobileBackup($resolved['post']);
        $reinspected['warnings'] = array_values(array_unique(array_merge(
            is_array($reinspected['warnings'] ?? null) ? $reinspected['warnings'] : [],
            ['prior_to_admin_clear']
        )));

        return $reinspected;
    }

    return $backup + [
        'status' => RECOVERY_NO_FILES,
        'photos' => [],
        'declared_types' => [],
        'warnings' => [],
        'modified' => 0,
    ];
}

/**
 * Datos de inspección del respaldo, con la forma que espera el PDF.
 *
 * ## Qué traduce
 *
 * El bloque `formulario` del manifiesto lo escribe la app móvil con las MISMAS
 * claves que las columnas de `poste_datos` (es el borrador del técnico o un
 * espejo de esa fila), y `secciones_rst` con `seccion`/`atributo`/`fase`. Por
 * eso el adaptador no inventó ningún mapeo: reutiliza `sanitizeBackupForm()` y
 * `sanitizeBackupRst()`, que ya validan cada campo contra `inspectionRules()` y
 * cubren los 26 campos que imprime `PDFFormularioPoste`.
 *
 * ## Qué viene del respaldo y qué de la BD
 *
 *   del respaldo : formulario completo, secciones RST, fecha de inspección y
 *                  coordenadas UTM de la captura;
 *   de la BD     : identidad y datos maestros —proyecto, contratista, línea,
 *                  estructura, código, tramo/ubicación—, que son los que
 *                  seleccionaron el registro y no deben salir de un archivo.
 *
 * ## Nunca disfraza
 *
 * Si el formulario del respaldo no está, no se puede leer o es de otra
 * estructura, se devuelve `usable => false` y NO se rellena con los datos
 * actuales: el informe saldría diciendo «respaldo» con datos de la BD.
 *
 * @return array{usable:bool,estado:string,details:array,rst:array,post:array}
 */
function reportPhotoBackupFormData(array $backup, array $post): array
{
    $estado = (string) ($backup['form_state'] ?? RECOVERY_FORM_MISSING);
    $manifest = is_array($backup['manifest'] ?? null) ? $backup['manifest'] : null;
    $vacio = ['usable' => false, 'estado' => $estado, 'details' => [], 'rst' => [],
        'post' => [], 'fechas' => ['fecha_inspeccion' => null, 'origen' => null,
            'primera_captura' => null, 'ultima_captura' => null, 'capturas' => 0]];
    if ($manifest === null || $estado !== RECOVERY_FORM_OK) {
        return $vacio;
    }

    $details = is_array($manifest['formulario'] ?? null)
        ? sanitizeBackupForm($manifest['formulario'])
        : [];
    if ($details === []) {
        // El bloque existía pero no sobrevivió la validación: sin datos válidos
        // no hay formulario de respaldo que imprimir.
        return ['usable' => false, 'estado' => RECOVERY_FORM_EMPTY,
            'details' => [], 'rst' => [], 'post' => [], 'fechas' => $vacio['fechas']];
    }
    $rst = is_array($manifest['secciones_rst'] ?? null)
        ? sanitizeBackupRst($manifest['secciones_rst'])
        : [];

    // Datos de la inspección, no de identidad: fecha y coordenadas de captura.
    $overrides = [];
    $identity = is_array($manifest['estructura'] ?? null) ? $manifest['estructura'] : [];
    $fechas = reportPhotoBackupTimestamps($manifest);
    if ($fechas['fecha_inspeccion'] !== null) {
        $overrides['fecha_inspeccion'] = $fechas['fecha_inspeccion'];
    }
    $coordinates = is_array($manifest['coordenadas'] ?? null) ? $manifest['coordenadas'] : [];
    foreach (['utm_este' => 'utm_x', 'utm_norte' => 'utm_y'] as $origen => $destino) {
        $valor = $coordinates[$origen] ?? null;
        if (is_numeric($valor) && is_finite((float) $valor)) {
            $overrides[$destino] = (string) $valor;
        }
    }
    $zona = trim((string) ($coordinates['zona'] ?? ''));
    if ($zona !== '') {
        $overrides['zona'] = mb_substr($zona, 0, 10);
    }

    return [
        'usable' => true,
        'estado' => RECOVERY_FORM_OK,
        'details' => $details,
        'rst' => $rst,
        'post' => $overrides,
        'fechas' => $fechas,
    ];
}

/**
 * Marcas de tiempo REALES que trae el manifiesto del respaldo.
 *
 * ## Qué hay y qué no
 *
 * El manifiesto que escribe la app móvil sólo guarda estas fechas:
 *
 *   estructura.fecha_inspeccion        de `postes.fecha_inspeccion` del móvil;
 *                                      puede venir NULL si el teléfono no la
 *                                      había sellado al exportar;
 *   fotografias.archivos[].fecha_captura  cuándo se tomó cada fotografía;
 *   estado.actualizado_en              cuándo se guardó el formulario;
 *   generado_en                        cuándo se hizo la copia.
 *
 * NO existe `hora_inicio` ni `hora_fin` en ninguna parte —ni en el manifiesto,
 * ni en el esquema, ni en la app—, así que no se inventan: el rango real de la
 * jornada son la primera y la última captura.
 *
 * ## Orden de preferencia para «Fecha de Inspección»
 *
 *   1. `estructura.fecha_inspeccion`: es el dato explícito, manda si está.
 *   2. la PRIMERA captura: es cuando se hizo el trabajo de campo.
 *   3. `estado.actualizado_en`: cuándo se guardó el formulario, que puede ser
 *      más tarde y en otro sitio, pero sigue siendo un dato de la inspección.
 *
 * `generado_en` queda FUERA a propósito: dice cuándo se copió el respaldo, no
 * cuándo se inspeccionó, y presentarlo como fecha de inspección sería falsear.
 * Si no hay ninguna de las tres, se devuelve null y el reporte sigue diciendo
 * «Sin fecha». Nunca se usa la fecha de hoy ni el mtime del archivo.
 *
 * @return array{fecha_inspeccion:string|null,origen:string|null,
 *               primera_captura:string|null,ultima_captura:string|null,
 *               capturas:int}
 */
function reportPhotoBackupTimestamps(array $manifest): array
{
    $capturas = [];
    $archivos = is_array($manifest['fotografias']['archivos'] ?? null)
        ? $manifest['fotografias']['archivos']
        : [];
    foreach ($archivos as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $captura = normalizeDateTime($entry['fecha_captura'] ?? null);
        if ($captura !== null) {
            $capturas[] = $captura;
        }
    }
    sort($capturas);
    $primera = $capturas !== [] ? $capturas[0] : null;
    $ultima = $capturas !== [] ? $capturas[count($capturas) - 1] : null;

    $identity = is_array($manifest['estructura'] ?? null) ? $manifest['estructura'] : [];
    $estado = is_array($manifest['estado'] ?? null) ? $manifest['estado'] : [];
    $candidatos = [
        'estructura.fecha_inspeccion' => normalizeDateTime($identity['fecha_inspeccion'] ?? null),
        'primera_captura' => $primera,
        'estado.actualizado_en' => normalizeDateTime($estado['actualizado_en'] ?? null),
    ];
    $fecha = null;
    $origen = null;
    foreach ($candidatos as $nombre => $valor) {
        if ($valor !== null) {
            $fecha = $valor;
            $origen = $nombre;
            break;
        }
    }

    return [
        'fecha_inspeccion' => $fecha,
        'origen' => $origen,
        'primera_captura' => $primera,
        'ultima_captura' => $ultima,
        'capturas' => count($capturas),
    ];
}

/**
 * Slots que esta estructura debería tener.
 *
 * Misma unión que usa `publicStructureRecoveryStatus()`: lo que hay, lo que
 * falta y lo que declara el respaldo. Sirve para no informar como «faltante»
 * un slot que esta inspección nunca registró.
 *
 * @return list<string>
 */
function reportPhotoExpectedSlots(array $resolved, array $backup): array
{
    $expected = array_merge(
        array_column(is_array($resolved['images'] ?? null) ? $resolved['images'] : [], 'nombre_foto'),
        is_array($resolved['missing'] ?? null) ? $resolved['missing'] : [],
        is_array($backup['declared_types'] ?? null) ? $backup['declared_types'] : []
    );
    $catalog = photoTypes();

    return array_values(array_unique(array_filter(
        array_map('strval', $expected),
        static fn(string $slot): bool => in_array($slot, $catalog, true)
    )));
}

/**
 * Fotografías disponibles para una fuente concreta, indexadas por slot.
 *
 * @return array<string, array>
 */
function reportPhotoImagesForSource(array $resolved, string $source, array $backup): array
{
    $images = is_array($resolved['images'] ?? null) ? $resolved['images'] : [];
    if ($source === REPORT_PHOTO_SOURCE_CURRENT) {
        // `mergeStructureImages()` nunca sustituye una fila oficial válida, así
        // que filtrar por `source` deja exactamente las fotografías oficiales.
        $images = array_filter(
            $images,
            static fn(array $image): bool => ($image['source'] ?? 'official') === 'official'
        );
    } elseif ($source === REPORT_PHOTO_SOURCE_BACKUP) {
        // Misma función de mezcla que el flujo normal, sin ninguna fila oficial
        // que pueda ganar: el resultado sólo puede venir del respaldo.
        $images = mergeStructureImages([], [], $backup)['images'];
    }

    $bySlot = [];
    foreach ($images as $image) {
        $slot = (string) ($image['nombre_foto'] ?? '');
        if ($slot !== '') {
            $bySlot[$slot] = $image;
        }
    }

    return $bySlot;
}

/**
 * Aplica fuente y selección al resultado de `resolveStructureResources()`.
 *
 * Devuelve una copia con la inspección de la fuente elegida y `photo_options`
 * con el recuento de lo incluido y lo excluido. En Backup también reemplaza
 * formulario, RST, fecha y coordenadas; si formulario.json no sirve, quedan
 * vacíos deliberadamente en vez de caer a la BD.
 *
 * Es idempotente: si ya se aplicó, se devuelve tal cual.
 */
function applyReportPhotoOptions(array $resolved, mixed $options): array
{
    if (isset($resolved['photo_options'])) {
        return $resolved;
    }
    $options = normalizeReportPhotoOptions($options);
    $source = $options['photo_source'];
    $included = $options['included_slots'];
    $excluded = $options['excluded_slots'];

    $backup = $source === REPORT_PHOTO_SOURCE_BACKUP
        ? reportPhotoBackupInspection($resolved)
        : (is_array($resolved['backup'] ?? null) ? $resolved['backup'] : []);
    $expected = reportPhotoExpectedSlots($resolved, $backup);
    $available = reportPhotoImagesForSource($resolved, $source, $backup);

    $images = [];
    $skipped = [];
    foreach ($available as $slot => $image) {
        $printable = ($included === null || in_array($slot, $included, true))
            && !in_array($slot, $excluded, true);
        if ($printable) {
            $images[] = $image;
            continue;
        }
        // Excluir es sólo «no imprimir»: la fila, el archivo y el estado de la
        // fotografía quedan exactamente como estaban.
        $skipped[] = $slot;
    }

    $officialUsed = 0;
    $backupUsed = 0;
    foreach ($images as $image) {
        if (($image['source'] ?? 'official') === 'backup_qfile') {
            $backupUsed++;
        } else {
            $officialUsed++;
        }
    }
    $missing = array_values(array_diff($expected, array_keys($available)));

    $formState = null;
    if ($source === REPORT_PHOTO_SOURCE_CURRENT) {
        // NAS: el informe es el de la BD actual, punto. Se fija aquí porque
        // `resolveStructureResources()` recurre al manifiesto del respaldo
        // cuando `poste_datos` está vacío —su comportamiento histórico, que
        // `auto` conserva— y elegir NAS no puede traer datos del respaldo.
        if (array_key_exists('details_official', $resolved)) {
            $resolved['details'] = $resolved['details_official'];
        }
        if (array_key_exists('rst_official', $resolved)) {
            $resolved['rst'] = $resolved['rst_official'];
        }
        $resolved['data_source'] = 'official';
    } elseif ($source === REPORT_PHOTO_SOURCE_BACKUP) {
        // Backup: el informe ENTERO es el de la campaña respaldada —formulario,
        // RST, fecha y coordenadas de captura—, no sólo las fotografías. La
        // identidad y los datos maestros siguen saliendo de la BD.
        $form = reportPhotoBackupFormData($backup, $resolved['post']);
        $formState = $form['estado'];
        if ($form['usable']) {
            $resolved['details'] = $form['details'];
            $resolved['rst'] = $form['rst'];
            $resolved['post'] = array_merge($resolved['post'], $form['post']);
            $resolved['data_source'] = 'backup_qfile';
        } else {
            // Sin formulario de respaldo NO se cae a la BD: el informe saldría
            // rotulado como respaldo con datos actuales. Se deja vacío y quien
            // llama avisa con `photo_options.form_state`.
            $resolved['details'] = [];
            $resolved['rst'] = [];
            $resolved['data_source'] = 'backup_qfile';
        }
    }


    $resolved['images'] = $images;
    $resolved['photo_options'] = [
        'photo_source' => $source,
        'included_slots' => $included,
        'excluded_slots' => $excluded,
        'slots_catalogo' => count(pdfPhotoSlots()),
        'slots_esperados' => count($expected),
        'disponibles' => count($available),
        'incluidas' => count($images),
        'excluidas' => count($skipped),
        'excluidos_slots' => $skipped,
        'no_disponibles' => count($missing),
        // Estado del formulario del respaldo: `null` cuando la fuente no lo usa.
        'form_state' => $formState,
        'respaldo_anterior_a_limpieza' => in_array(
            'prior_to_admin_clear',
            is_array($backup['warnings'] ?? null) ? $backup['warnings'] : [],
            true
        ),
    ];

    if ($source !== REPORT_PHOTO_SOURCE_AUTO) {
        // Los contadores que leen los endpoints y los logs tienen que hablar
        // del reporte que realmente se va a imprimir, no del que habría salido
        // con la resolución automática.
        $resolved['backup'] = $backup;
        $resolved['missing'] = $missing;
        $resolved['official_available'] = $officialUsed;
        $resolved['backup_used'] = $backupUsed;
        $resolved['recoverable'] = [];
        $resolved['source'] = $backupUsed > 0
            ? ($officialUsed > 0 ? 'mixed' : 'backup_qfile')
            : 'official';
    }

    return $resolved;
}

/**
 * Datos físicos que la pantalla de selección necesita para una fotografía.
 *
 * Sólo `stat`: ni hash ni decodificación, porque esta pantalla se abre una vez
 * por estructura y no debe costar lo que cuesta el diagnóstico completo. Se
 * devuelve el NOMBRE del archivo para que el visor autenticado
 * (`admin/ver_archivo_poste.php`) pueda mostrarlo; el cliente nunca ve rutas.
 *
 * @return array{archivo:string|null,bytes:int|null,tamano:string|null,modificado_en:string|null}
 */
function reportPhotoFileSummary(?string $absolute): array
{
    if ($absolute === null || $absolute === '' || !is_file($absolute)) {
        return ['archivo' => null, 'bytes' => null, 'tamano' => null, 'modificado_en' => null];
    }
    $bytes = (int) (@filesize($absolute) ?: 0);
    $modified = (int) (@filemtime($absolute) ?: 0);

    return [
        'archivo' => basename($absolute),
        'bytes' => $bytes,
        'tamano' => humanBytes($bytes),
        'modificado_en' => $modified > 0 ? date('Y-m-d H:i:s', $modified) : null,
    ];
}

/**
 * Cuántos archivos podrían confundirse con el de cada slot.
 *
 * Se cuenta lo único que explica el caso real —filas archivadas de una campaña
 * anterior sobre el mismo slot— con una consulta agregada. El inventario
 * completo de la carpeta, con hash y detalle de cada candidato, ya lo hace
 * `structurePhotoDiagnostics()`; aquí sólo hace falta poder avisar.
 *
 * @return array<string, int>
 */
function reportPhotoArchivedCounts(PDO $connection, int $postId): array
{
    $statement = $connection->prepare(
        'SELECT nombre_foto, COUNT(*) AS total FROM imagenes_poste
         WHERE poste_id = ? AND activo = 0 GROUP BY nombre_foto'
    );
    $statement->execute([$postId]);
    $counts = [];
    foreach ($statement->fetchAll() as $row) {
        $counts[(string) $row['nombre_foto']] = (int) $row['total'];
    }

    return $counts;
}
