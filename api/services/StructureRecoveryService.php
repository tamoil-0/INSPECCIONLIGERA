<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/domain.php';
require_once __DIR__ . '/../config/photo_status.php';

const RECOVERY_OFFICIAL_COMPLETE = 'OFICIAL_COMPLETO';
const RECOVERY_OFFICIAL_WARNINGS = 'OFICIAL_CON_ADVERTENCIAS';
const RECOVERY_BACKUP_AVAILABLE = 'BACKUP_DISPONIBLE';
const RECOVERY_BACKUP_PARTIAL = 'BACKUP_PARCIAL';
const RECOVERY_NO_FILES = 'SIN_ARCHIVOS';
const RECOVERY_INTEGRITY_ERROR = 'ERROR_DE_INTEGRIDAD';

/**
 * Estado del formulario del respaldo, aparte del de las fotografías.
 *
 * En `photo_source=backup` el informe toma sus datos de inspección del
 * `formulario.json`, así que hace falta distinguir «está y sirve» de «no
 * está», «no se puede leer» y «es de otra estructura». Nunca se rellena con
 * los datos actuales de la BD haciéndolos pasar por datos del respaldo.
 */
const RECOVERY_FORM_OK = 'FORMULARIO_OK';
const RECOVERY_FORM_MISSING = 'FORMULARIO_AUSENTE';
const RECOVERY_FORM_INVALID = 'FORMULARIO_ILEGIBLE';
const RECOVERY_FORM_EMPTY = 'FORMULARIO_SIN_DATOS';
const RECOVERY_FORM_MISMATCH = 'FORMULARIO_DE_OTRA_ESTRUCTURA';

function mobileBackupSegment(mixed $value, string $fallback): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return $fallback;
    }
    $text = preg_replace('/[\/\\\\:*?\x22<>|]/u', '_', $text) ?? '';
    $text = preg_replace('/[[:cntrl:]]/u', '', $text) ?? '';
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
    $text = rtrim($text, '. ');
    return $text === '' ? $fallback : mb_substr($text, 0, 60);
}

function mobileBackupIsTemporary(string $name): bool
{
    return str_starts_with($name, '.')
        || preg_match('/(?:\.tmp|\.part(?:ial)?|\.download|\.crdownload|~)$/i', $name) === 1;
}

function mobileBackupFileIsStable(string $file, int $stabilitySeconds): bool
{
    if (!is_file($file) || mobileBackupIsTemporary(basename($file))) {
        return false;
    }
    $size = @filesize($file);
    $modified = @filemtime($file);
    return is_int($size) && $size > 0 && is_int($modified)
        && ($stabilitySeconds <= 0 || $modified <= time() - $stabilitySeconds);
}

/** Nivel máximo al que se busca la carpeta de la estructura bajo la raíz. */
const MOBILE_BACKUP_MAX_SEARCH_DEPTH = 3;

/** Techo de carpetas visitadas durante la búsqueda; evita un barrido patológico. */
const MOBILE_BACKUP_MAX_SEARCH_DIRS = 250;

/** Techo de archivos inspeccionados dentro de `Fotos/` de una estructura. */
const MOBILE_BACKUP_MAX_PHOTO_FILES = 200;

/**
 * Slot canónico al que corresponde un archivo del respaldo.
 *
 * El nombre lo compone la aplicación móvil como `NN_Etiqueta.jpg`, donde `NN`
 * es la POSICIÓN del tipo en el contrato de fotografías —el mismo orden que
 * `photoTypes()`—. Ese número es el identificador inequívoco: la etiqueta es
 * texto para que una persona reconozca el archivo y puede variar («Mensulas»,
 * «Ménsulas», «Base de la torre» / «Base torre»), pero el prefijo no.
 *
 * Si no hay prefijo utilizable se intenta con el nombre canónico dentro del
 * propio archivo, que es como nombra el almacén oficial (`placa_ab12cd.jpg`).
 * Se devuelve la coincidencia MÁS LARGA para que `puesta_tierra_2` no se
 * confunda con `puesta_tierra`.
 */
function mobileBackupSlotFromFileName(string $fileName): ?string
{
    $types = photoTypes();
    if (preg_match('/^(\d{1,2})[_-]/', $fileName, $matches) === 1) {
        $position = (int) $matches[1];
        if ($position >= 1 && $position <= count($types)) {
            return $types[$position - 1];
        }
    }

    $lower = strtolower($fileName);
    $match = null;
    foreach ($types as $type) {
        if (str_starts_with($lower, strtolower($type))
            && ($match === null || strlen($type) > strlen($match))) {
            $match = $type;
        }
    }

    return $match;
}

/**
 * Clave comparable de un nombre de línea o estructura.
 *
 * El emparejamiento entre el padrón y las carpetas del respaldo era una
 * comparación de texto exacta, y eso deja fuera diferencias que no significan
 * nada: `Estructura_090` frente a un `estructura` = `90` en la base, mayúsculas
 * distintas, o un espacio de más. Aquí se reduce cada lado a la misma forma:
 *
 *   · sin espacios alrededor ni repetidos, en minúsculas;
 *   · si TODO es dígitos, se compara como número, así `090`, `90` y `0090` son
 *     la misma estructura;
 *   · si mezcla dígitos y letras (`64A`, `12-BIS`), se conservan tal cual en
 *     minúsculas: ahí el sufijo distingue de verdad y no se puede tirar.
 */
function mobileBackupCompareKey(mixed $value): string
{
    $text = strtolower(trim((string) $value));
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';
    $text = trim($text, '. ');
    if ($text === '') {
        return '';
    }

    return ctype_digit($text) ? (string) (int) $text : $text;
}

/**
 * Texto que sigue a un prefijo de carpeta, o null si no lleva ese prefijo.
 *
 * Acepta `Estructura_01`, `estructura-01` y `Estructura 01`: el separador y las
 * mayúsculas no cambian de qué estructura se habla, y exigirlos hacía que una
 * carpeta con otra forma quedara fuera del índice sin dejar rastro.
 */
function mobileBackupNameAfterPrefix(string $entry, string $prefix): ?string
{
    if (mb_strtolower(mb_substr($entry, 0, mb_strlen($prefix))) !== mb_strtolower($prefix)) {
        return null;
    }
    $rest = mb_substr($entry, mb_strlen($prefix));
    if ($rest === '' || !in_array(mb_substr($rest, 0, 1), ['_', '-', ' '], true)) {
        return null;
    }
    $name = trim(mb_substr($rest, 1));

    return $name === '' ? null : $name;
}

/**
 * Nombres con los que puede aparecer la carpeta de una estructura.
 *
 * `postes.estructura` puede venir como `1` y la carpeta llamarse `Estructura_01`,
 * o al contrario. Se prueban la forma tal cual, la rellenada a dos dígitos, la
 * forma sin ceros a la izquierda y el código completo. Son candidatos derivados
 * de la propia fila: no hay ninguna línea ni estructura fija en el código.
 *
 * @return list<string>
 */
function mobileBackupStructureNames(array $post): array
{
    $values = [];
    $structure = trim((string) ($post['estructura'] ?? ''));
    if ($structure !== '') {
        $values[] = $structure;
        if (ctype_digit($structure)) {
            $values[] = str_pad($structure, 2, '0', STR_PAD_LEFT);
            $values[] = (string) (int) $structure;
        }
    }
    $code = trim((string) ($post['codigo'] ?? ''));
    if ($code !== '') {
        $values[] = $code;
    }
    if ($values === []) {
        $values[] = 'sin_codigo';
    }

    $names = [];
    foreach ($values as $value) {
        $name = 'Estructura_' . mobileBackupSegment($value, 'sin_codigo');
        if (!in_array($name, $names, true)) {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * Carpetas bajo las que puede colgar la ruta de una estructura.
 *
 * La raíz y sus descendientes hasta `MOBILE_BACKUP_MAX_SEARCH_DEPTH`, en
 * anchura y con techo de visitas. Hace falta porque el árbol real del NAS mete
 * niveles propios de la organización del share antes de llegar a la línea:
 *
 *     /data/elpu/Mantenimiento_LT_60kV/Inspeccion_Piso/Linea_L-6028/...
 *
 * Esos nombres no se conocen ni se codifican: se recorren.
 *
 * @return list<string>
 */
function mobileBackupSearchBases(string $root): array
{
    $bases = [$root];
    $frontier = [$root];
    $visited = 0;
    for ($depth = 0; $depth < MOBILE_BACKUP_MAX_SEARCH_DEPTH && $frontier !== []; $depth++) {
        $next = [];
        foreach ($frontier as $directory) {
            foreach (@scandir($directory) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || mobileBackupIsTemporary($entry)) {
                    continue;
                }
                if (++$visited > MOBILE_BACKUP_MAX_SEARCH_DIRS) {
                    return $bases;
                }
                $child = $directory . DIRECTORY_SEPARATOR . $entry;
                if (!is_dir($child)) {
                    continue;
                }
                $bases[] = $child;
                $next[] = $child;
            }
        }
        $frontier = $next;
    }

    return $bases;
}

/**
 * Carpetas de respaldo de una estructura, en cualquiera de los dos árboles.
 *
 * Se aceptan dos formas, y las dos salen de la fila del poste:
 *
 *   Proyecto_<id>/Linea_<linea>/Estructura_<estructura>   copia del móvil
 *   Linea_<linea>/Estructura_<estructura>                 share del NAS
 *
 * La segunda existe porque el respaldo del NAS se organiza por línea, sin
 * carpeta de proyecto. Cualquiera de las dos puede aparecer a varios niveles
 * bajo la raíz, así que se prueban contra todas las bases que devuelve
 * `mobileBackupSearchBases()`.
 *
 * Todo lo encontrado se confina a la raíz con `pathIsInside()`.
 *
 * @return list<string>
 */
function mobileBackupStructureDirectories(array $post, ?string $root = null): array
{
    $root = $root ?? storagePath('mobile_backups');
    if (!is_dir($root)) {
        return [];
    }
    $project = 'Proyecto_' . mobileBackupSegment($post['proyecto_id'] ?? null, 'sin_id');
    $line = 'Linea_' . mobileBackupSegment($post['linea'] ?? null, 'sin_linea');

    $suffixes = [];
    foreach (mobileBackupStructureNames($post) as $structure) {
        $tail = $line . DIRECTORY_SEPARATOR . $structure;
        $suffixes[] = $project . DIRECTORY_SEPARATOR . $tail;
        $suffixes[] = $tail;
    }

    $directories = [];
    $bases = mobileBackupSearchBases($root);
    foreach ($bases as $base) {
        foreach ($suffixes as $suffix) {
            $candidate = $base . DIRECTORY_SEPARATOR . $suffix;
            if (is_dir($candidate) && pathIsInside($candidate, $root)) {
                $real = realpath($candidate) ?: $candidate;
                $directories[$real] = true;
            }
        }
    }

    // Segundo camino: mismo significado con variantes de escritura reales del
    // share (Linea-, Linea espacio, mayúsculas distintas, 090 frente a 90).
    // Se recorren las mismas bases acotadas; no se inicia una búsqueda por cada
    // candidato ni se baja dentro de Fotos/Formulario.
    $lineKey = mobileBackupCompareKey($post['linea'] ?? '');
    $structureKeys = array_map(
        static fn(string $name): string => mobileBackupCompareKey(
            mb_substr($name, mb_strlen('Estructura_'))
        ),
        mobileBackupStructureNames($post)
    );
    foreach ($bases as $base) {
        $lineName = mobileBackupNameAfterPrefix(basename($base), 'Linea');
        if ($lineName === null || mobileBackupCompareKey($lineName) !== $lineKey) {
            continue;
        }
        foreach (@scandir($base) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || mobileBackupIsTemporary($entry)) {
                continue;
            }
            $structureName = mobileBackupNameAfterPrefix($entry, 'Estructura');
            if ($structureName === null
                || !in_array(mobileBackupCompareKey($structureName), $structureKeys, true)) {
                continue;
            }
            $candidate = $base . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($candidate) && pathIsInside($candidate, $root)) {
                $real = realpath($candidate) ?: $candidate;
                $directories[$real] = true;
            }
        }
    }

    return array_keys($directories);
}

function inspectMobileBackupDirectory(
    array $post,
    string $directory,
    string $root,
    int $stabilitySeconds
): array {
    // Nombre EXACTO: un `formulario_conflict(1).json` que deje la sincronización
    // no se lee nunca, ni aunque el principal falte. Queda para diagnóstico.
    $manifestFile = $directory . DIRECTORY_SEPARATOR . 'Formulario'
        . DIRECTORY_SEPARATOR . 'formulario.json';
    $base = [
        'status' => RECOVERY_INTEGRITY_ERROR,
        'manifest' => null,
        'photos' => [],
        'declared_types' => [],
        'missing' => [],
        'warnings' => [],
        'modified' => 0,
        // Estado del formulario, independiente del de las fotografías: en modo
        // respaldo el informe toma de aquí sus datos de inspección, así que hay
        // que poder decir si están, faltan o no se pueden leer.
        'form_state' => RECOVERY_FORM_MISSING,
        'manifest_file' => null,
        'manifest_modified' => 0,
    ];

    // --- Manifiesto ---------------------------------------------------------
    //
    // Su ausencia o su corrupción NO impiden inventariar las fotografías: los
    // archivos de `Fotos/` son la fuente de verdad de qué fotografías existen.
    // Lo único que veta todo es un manifiesto que dice pertenecer a OTRA
    // estructura: eso sí es peligroso y se rechaza en bloque.
    $decoded = null;
    $declared = [];
    if (!mobileBackupFileIsStable($manifestFile, $stabilitySeconds)) {
        $base['warnings'][] = is_file($manifestFile) ? 'sync_in_progress' : 'manifest_missing';
        $base['form_state'] = is_file($manifestFile)
            ? RECOVERY_FORM_INVALID
            : RECOVERY_FORM_MISSING;
    } else {
        $base['manifest_file'] = realpath($manifestFile) ?: $manifestFile;
        $base['manifest_modified'] = (int) (@filemtime($manifestFile) ?: 0);
        $size = (int) (@filesize($manifestFile) ?: 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            $base['warnings'][] = 'manifest_invalid_size';
            $base['form_state'] = RECOVERY_FORM_INVALID;
        } else {
            try {
                $decoded = json_decode(
                    (string) file_get_contents($manifestFile),
                    true,
                    128,
                    JSON_THROW_ON_ERROR
                );
            } catch (Throwable) {
                $decoded = null;
            }
            if (!is_array($decoded)) {
                $decoded = null;
                $base['warnings'][] = 'json_invalid';
                $base['form_state'] = RECOVERY_FORM_INVALID;
            }
        }
    }

    if ($decoded !== null) {
        $identity = is_array($decoded['estructura'] ?? null) ? $decoded['estructura'] : [];
        $sameProject = (string) ($identity['proyecto_id'] ?? '') === (string) ($post['proyecto_id'] ?? '');
        $samePost = (string) ($identity['poste_id'] ?? '') === (string) ($post['id'] ?? '');
        $sameStructure = mobileBackupCompareKey($identity['estructura'] ?? '')
            === mobileBackupCompareKey($post['estructura'] ?? '');
        $sameCode = mobileBackupCompareKey($identity['codigo'] ?? '')
            === mobileBackupCompareKey($post['codigo'] ?? '');
        $sameLine = mobileBackupCompareKey($identity['linea'] ?? '')
            === mobileBackupCompareKey($post['linea'] ?? '');
        if (!$sameProject || !$samePost || (!$sameStructure && !$sameCode) || !$sameLine) {
            // Un respaldo de otra estructura no aporta ni fotos ni formulario.
            $base['warnings'][] = 'identity_mismatch';
            $base['form_state'] = RECOVERY_FORM_MISMATCH;
            return $base;
        }
        $photoBlock = is_array($decoded['fotografias'] ?? null) ? $decoded['fotografias'] : [];
        $declared = is_array($photoBlock['archivos'] ?? null) ? $photoBlock['archivos'] : [];
        if (!is_array($photoBlock['archivos'] ?? null)) {
            $base['warnings'][] = 'photo_list_missing';
        }
        // El formulario está utilizable si trae respuestas; un manifiesto sin
        // bloque `formulario` no es un error, pero tampoco sirve para el informe.
        $base['form_state'] = is_array($decoded['formulario'] ?? null)
            && $decoded['formulario'] !== []
            ? RECOVERY_FORM_OK
            : RECOVERY_FORM_EMPTY;
    }

    $photoRoot = $directory . DIRECTORY_SEPARATOR . 'Fotos';

    // --- Lo que declara el manifiesto, indexado por nombre de archivo -------
    //
    // El manifiesto ya NO decide qué fotografías existen: aporta metadatos
    // (fecha de captura, UTM, checksum) para los archivos que siguen siendo la
    // versión que él describe. Ver la nota de `inspectMobileBackupDirectory()`.
    $declaredByFile = [];
    $seenTypes = [];
    foreach ($declared as $index => $entry) {
        if (!is_array($entry)) {
            $base['warnings'][] = 'entry_invalid';
            continue;
        }
        $type = trim((string) ($entry['tipo'] ?? ''));
        $filename = trim((string) ($entry['archivo'] ?? ''));
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $type) !== 1 || isset($seenTypes[$type])) {
            $base['warnings'][] = 'type_invalid';
            continue;
        }
        $seenTypes[$type] = true;
        $base['declared_types'][] = $type;
        if ($filename !== '' && basename($filename) === $filename) {
            $declaredByFile[$filename] = ['tipo' => $type] + $entry;
        }
    }

    // --- Inventario FÍSICO de Fotos/ ----------------------------------------
    //
    // Es la fuente de verdad de la disponibilidad: si el archivo está y se
    // puede leer como imagen, la fotografía existe.
    $found = [];
    $entries = @scandir($photoRoot) ?: [];
    $inspected = 0;
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || mobileBackupIsTemporary($entry)) {
            continue;
        }
        if (++$inspected > MOBILE_BACKUP_MAX_PHOTO_FILES) {
            $base['warnings'][] = 'photo_inventory_truncated';
            break;
        }
        if (preg_match('/\.(?:jpe?g|png)$/i', $entry) !== 1) {
            continue;
        }
        $absolute = $photoRoot . DIRECTORY_SEPARATOR . $entry;
        if (!mobileBackupFileIsStable($absolute, $stabilitySeconds)
            || !pathIsInside($absolute, $root)) {
            continue;
        }
        $declaredEntry = $declaredByFile[$entry] ?? null;
        if ($declaredEntry !== null) {
            // El manifiesto es la autoridad sobre SUS propios archivos, y su
            // `tipo` se acepta aunque el catálogo del servidor todavía no lo
            // conozca: una versión nueva del móvil puede declarar un tipo
            // antes de que aquí exista. Ya se validó su forma más arriba.
            $type = (string) $declaredEntry['tipo'];
        } else {
            // Sin declaración hay que DEDUCIR el slot, y ahí sí sólo valen los
            // del catálogo: inventar un tipo nuevo a partir de un nombre de
            // archivo sería adivinar.
            $type = mobileBackupSlotFromFileName($entry);
            if ($type === null) {
                $base['warnings'][] = 'unmapped_photo_file';
                continue;
            }
        }
        $imageInfo = @getimagesize($absolute);
        if (!$imageInfo || !in_array($imageInfo['mime'] ?? '', ['image/jpeg', 'image/png'], true)) {
            continue;
        }
        $bytes = (int) (@filesize($absolute) ?: 0);
        $modified = (int) (@filemtime($absolute) ?: 0);

        // Los metadatos del manifiesto sólo valen si describen ESTE archivo. Si
        // la fotografía se volvió a sincronizar después de generarlo, su tamaño
        // ya no coincide: entonces se toma el archivo tal cual y se deja el
        // checksum vacío, que es lo que significa «sin verificar».
        $declaredBytes = $declaredEntry !== null
            ? filter_var($declaredEntry['bytes'] ?? null, FILTER_VALIDATE_INT)
            : false;
        $describesFile = $declaredEntry !== null
            && ($declaredBytes === false || $declaredBytes <= 0 || $declaredBytes === $bytes);
        $checksum = '';
        if ($describesFile) {
            $candidate = strtolower(trim((string) ($declaredEntry['checksum_sha256'] ?? '')));
            if (preg_match('/^[a-f0-9]{64}$/', $candidate) === 1) {
                $checksum = $candidate;
            }
        } elseif ($declaredEntry !== null) {
            $base['warnings'][] = 'manifest_outdated';
        }

        $candidate = [
            'type' => $type,
            'absolute' => realpath($absolute) ?: $absolute,
            'root' => $root,
            'bytes' => $bytes,
            'checksum' => $checksum,
            'fecha_captura' => $describesFile
                ? ($declaredEntry['fecha_captura'] ?? null)
                : ($modified > 0 ? date('Y-m-d H:i:s', $modified) : null),
            'utm_este' => $describesFile ? ($declaredEntry['utm_este'] ?? null) : null,
            'utm_norte' => $describesFile ? ($declaredEntry['utm_norte'] ?? null) : null,
            'zona' => $describesFile ? ($declaredEntry['zona'] ?? null) : null,
        ];
        // Dos archivos para el mismo slot: gana el que el manifiesto describe y,
        // en su defecto, el más reciente. Determinista, nunca al azar.
        $previous = $found[$type] ?? null;
        if ($previous === null
            || ($describesFile && !$previous['_declarado'])
            || (!$previous['_declarado'] && !$describesFile && $modified > $previous['_mtime'])) {
            $candidate['_declarado'] = $describesFile;
            $candidate['_mtime'] = $modified;
            $found[$type] = $candidate;
        }
    }

    foreach ($found as $type => $photo) {
        unset($photo['_declarado'], $photo['_mtime']);
        $base['photos'][$type] = $photo;
    }

    // Un slot detectado físicamente cuenta como declarado: si no, la mezcla de
    // `mergeStructureImages()` lo descartaría por no estar en lo esperado.
    $base['declared_types'] = array_values(array_unique(array_merge(
        $base['declared_types'],
        array_keys($base['photos'])
    )));

    // Faltan las declaradas que no aparecieron en disco.
    foreach (array_keys($seenTypes) as $type) {
        if (!isset($base['photos'][$type])) {
            $base['missing'][] = ['type' => $type, 'reason' => 'file_missing_or_unreadable'];
        }
    }
    $base['warnings'] = array_values(array_unique($base['warnings']));

    $base['manifest'] = $decoded;
    $generated = $decoded !== null
        ? (strtotime((string) ($decoded['generado_en'] ?? '')) ?: 0)
        : 0;
    // Sin manifiesto la antigüedad la marca la fotografía más reciente: hace
    // falta para comparar el respaldo con una limpieza administrativa.
    $newestPhoto = 0;
    foreach ($base['photos'] as $photo) {
        $newestPhoto = max($newestPhoto, (int) (@filemtime((string) $photo['absolute']) ?: 0));
    }
    $base['modified'] = max($generated, $base['manifest_modified'], $newestPhoto);
    if ($base['photos'] === [] && $decoded === null) {
        // Sin nada utilizable, el estado distingue POR QUÉ, igual que antes de
        // desacoplar el formulario: un manifiesto corrupto no es lo mismo que
        // una carpeta vacía, ni que una sincronización a medias.
        if (in_array('manifest_invalid_size', $base['warnings'], true)
            || in_array('json_invalid', $base['warnings'], true)) {
            $base['status'] = RECOVERY_INTEGRITY_ERROR;
        } elseif (in_array('sync_in_progress', $base['warnings'], true)) {
            $base['status'] = RECOVERY_BACKUP_PARTIAL;
        } else {
            $base['status'] = RECOVERY_NO_FILES;
        }
    } else {
        $base['status'] = $base['missing'] === []
            ? RECOVERY_BACKUP_AVAILABLE
            : RECOVERY_BACKUP_PARTIAL;
    }
    return $base;
}

function inspectMobileBackup(
    array $post,
    ?string $root = null,
    ?int $stabilitySeconds = null
): array {
    $root = $root ?? storagePath('mobile_backups');
    $stabilitySeconds ??= max(0, (int) envValue('MOBILE_BACKUP_STABILITY_SECONDS', 10));
    $empty = [
        'status' => RECOVERY_NO_FILES,
        'manifest' => null,
        'photos' => [],
        'declared_types' => [],
        'missing' => [],
        'warnings' => [],
        'modified' => 0,
        'form_state' => RECOVERY_FORM_MISSING,
        'manifest_file' => null,
        'manifest_modified' => 0,
    ];
    if (!is_dir($root)) {
        $empty['warnings'][] = 'backup_not_configured';
        return $empty;
    }
    $directories = mobileBackupStructureDirectories($post, $root);
    if ($directories === []) {
        return $empty;
    }
    $inspected = [];
    foreach ($directories as $directory) {
        $inspected[] = inspectMobileBackupDirectory(
            $post,
            $directory,
            $root,
            $stabilitySeconds
        );
    }
    usort($inspected, static fn(array $a, array $b): int =>
        ($b['modified'] ?? 0) <=> ($a['modified'] ?? 0));
    foreach ($inspected as $candidate) {
        // Sirve el que aporte algo: fotografías, manifiesto o los dos. Antes se
        // exigía manifiesto, y una carpeta con fotografías pero sin él quedaba
        // descartada aunque las fotografías fueran perfectamente válidas.
        if (in_array($candidate['status'], [RECOVERY_BACKUP_AVAILABLE, RECOVERY_BACKUP_PARTIAL], true)
            && ($candidate['photos'] !== [] || is_array($candidate['manifest']))) {
            return $candidate;
        }
    }
    return $inspected[0];
}

function backupPhotoIsUsable(array $photo, bool $verifyChecksum = true): bool
{
    $absolute = (string) ($photo['absolute'] ?? '');
    $root = (string) ($photo['root'] ?? '');
    $stability = max(0, (int) envValue('MOBILE_BACKUP_STABILITY_SECONDS', 10));
    if ($absolute === '' || $root === ''
        || !mobileBackupFileIsStable($absolute, $stability)
        || !pathIsInside($absolute, $root)) {
        return false;
    }
    $info = @getimagesize($absolute);
    if (!$info || !in_array($info['mime'] ?? '', ['image/jpeg', 'image/png'], true)) {
        return false;
    }
    $checksum = (string) ($photo['checksum'] ?? '');
    return !$verifyChecksum || $checksum === ''
        || hash_equals($checksum, hash_file('sha256', $absolute));
}

/** Mezcla por tipo; un archivo oficial v�lido siempre gana. */
function mergeStructureImages(array $officialRows, array $registeredTypes, array $backup): array
{
    $officialByType = [];
    foreach ($officialRows as $row) {
        $type = (string) ($row['nombre_foto'] ?? '');
        if ($type === '') {
            continue;
        }
        $absolute = $row['ruta_resuelta'] ?? resolveStoredImagePath($row['ruta_archivo'] ?? null);
        $row['ruta_resuelta'] = $absolute;
        $row['source'] = 'official';
        $row['disponible'] = is_string($absolute) && $absolute !== ''
            && is_file($absolute) && (int) (@filesize($absolute) ?: 0) > 0
            && @getimagesize($absolute) !== false;
        $officialByType[$type] = $row;
    }
    $expected = [];
    foreach (array_merge($registeredTypes, $backup['declared_types'] ?? []) as $type) {
        if ((string) $type !== '') {
            $expected[(string) $type] = true;
        }
    }
    foreach (array_keys($officialByType) as $type) {
        $expected[$type] = true;
    }

    $images = [];
    $missing = [];
    $recoverable = [];
    $officialAvailable = 0;
    $backupUsed = 0;
    foreach (array_keys($expected) as $type) {
        $official = $officialByType[$type] ?? null;
        if ($official !== null && $official['disponible']) {
            $images[] = $official;
            $officialAvailable++;
            continue;
        }
        $photo = $backup['photos'][$type] ?? null;
        // En lectura/PDF se valida tamaño, MIME, estabilidad y confinamiento.
        // El SHA completo se reserva para la copia explícita al almacén oficial.
        if (is_array($photo) && backupPhotoIsUsable($photo, false)) {
            $images[] = [
                'nombre_foto' => $type,
                'ruta_archivo' => null,
                'ruta_resuelta' => $photo['absolute'],
                'fecha_captura' => $photo['fecha_captura'],
                'utm_este' => $photo['utm_este'],
                'utm_norte' => $photo['utm_norte'],
                'zona' => $photo['zona'],
                'source' => 'backup_qfile',
                'disponible' => true,
            ];
            $recoverable[] = $type;
            $backupUsed++;
            continue;
        }
        $missing[] = $type;
    }
    $source = $backupUsed > 0
        ? ($officialAvailable > 0 ? 'mixed' : 'backup_qfile')
        : 'official';
    return [
        'images' => $images,
        'missing' => $missing,
        'recoverable' => $recoverable,
        'source' => $source,
        'official_available' => $officialAvailable,
        'backup_used' => $backupUsed,
    ];
}

function sanitizeBackupForm(array $form): array
{
    $clean = [];
    foreach (inspectionRules() as $field => $allowed) {
        if (!array_key_exists($field, $form)) {
            continue;
        }
        $value = $form[$field];
        if ($field === 'obstaculos_faja') {
            $items = is_array($value)
                ? $value
                : array_filter(array_map('trim', explode(',', (string) $value)));
            if ($items !== [] && array_diff($items, $allowed) === []) {
                $clean[$field] = implode(',', array_unique($items));
            }
        } elseif (is_scalar($value) && in_array((string) $value, $allowed, true)) {
            $clean[$field] = (string) $value;
        }
    }
    foreach ([
        'distancia_acceso',
        'cantidad_pat',
        'distancia_poste_anterior',
        'distancia_vertical',
        'distancia_horizontal',
    ] as $field) {
        $value = $form[$field] ?? null;
        if (is_numeric($value) && is_finite((float) $value) && (float) $value >= 0) {
            $clean[$field] = (float) $value;
        }
    }
    if (isset($form['comentarios']) && is_scalar($form['comentarios'])) {
        $clean['comentarios'] = mb_substr(trim((string) $form['comentarios']), 0, 5000);
    }
    return $clean;
}

function sanitizeBackupRst(array $records): array
{
    $sections = ['conductores_fase', 'conductores_cuellos', 'conductores_guarda', 'estado_aisladores'];
    $phases = ['R', 'S', 'T'];
    $conductors = ['hebras_rotas', 'encanastillado', 'empalme_deformado', 'objetos_extranos'];
    $insulators = ['buen_estado', 'rotos_suspension', 'rotos_anclaje_adelante', 'rotos_anclaje_atras', 'mal_estado'];
    $clean = [];
    foreach ($records as $record) {
        if (!is_array($record)) {
            continue;
        }
        $section = (string) ($record['seccion'] ?? '');
        $phase = (string) ($record['fase'] ?? '');
        $attribute = (string) ($record['atributo'] ?? '');
        $allowed = $section === 'estado_aisladores' ? $insulators : $conductors;
        if (in_array($section, $sections, true)
            && in_array($phase, $phases, true)
            && in_array($attribute, $allowed, true)) {
            // Mismo orden de claves que proyecta la consulta de
            // `poste_secciones_rst`: así una fila del respaldo y una de la BD son
            // indistinguibles para quien las compare, no sólo para quien las lea.
            $clean[] = [
                'seccion' => $section,
                'atributo' => $attribute,
                'fase' => $phase,
            ];
        }
    }
    return $clean;
}

/** Punto central de lectura para web, PDF y recuperaci�n. */
function resolveStructureResources(PDO $connection, int $postId, int $projectId, bool $allowBackupBeforeAdminClear = false): array
{
    $statement = $connection->prepare(
        'SELECT p.*, pr.nombre_proyecto, pr.contratista,
                pr.ubicacion AS proyecto_ubicacion
         FROM postes p
         INNER JOIN proyectos pr ON pr.id = p.proyecto_id
         WHERE p.id = ? AND p.proyecto_id = ?'
    );
    $statement->execute([$postId, $projectId]);
    $post = $statement->fetch();
    if (!$post) {
        throw new DomainException('La estructura no existe dentro del proyecto indicado.');
    }
    $query = $connection->prepare('SELECT * FROM poste_datos WHERE poste_id = ?');
    $query->execute([$postId]);
    $officialDetails = $query->fetch() ?: [];
    $query = $connection->prepare(
        'SELECT seccion, atributo, fase FROM poste_secciones_rst
         WHERE poste_id = ? AND activo = 1 ORDER BY seccion, fase'
    );
    $query->execute([$postId]);
    $officialRst = $query->fetchAll();
    $query = $connection->prepare(
        'SELECT nombre_foto, ruta_archivo, fecha_captura, utm_este,
                utm_norte, zona, sincronizada, fecha_subida
         FROM imagenes_poste WHERE poste_id = ? AND activo = 1 ORDER BY nombre_foto'
    );
    $query->execute([$postId]);
    $officialImages = $query->fetchAll();
    $query = $connection->prepare(
        'SELECT nombre_foto FROM imagenes_poste_estado
         WHERE poste_id = ? AND activo = 1 ORDER BY nombre_foto'
    );
    $query->execute([$postId]);
    $registeredTypes = array_column($query->fetchAll(), 'nombre_foto');
    if ($registeredTypes === []) {
        $registeredTypes = array_column($officialImages, 'nombre_foto');
    }
    $backup = inspectMobileBackup($post);
    $clearAt = strtotime((string) ($post['ultima_limpieza_admin'] ?? '')) ?: 0;
    if (!$allowBackupBeforeAdminClear && $clearAt > 0
        && (int) ($backup['modified'] ?? 0) <= $clearAt) {
        $backup = [
            'status' => RECOVERY_NO_FILES, 'manifest' => null, 'photos' => [],
            'declared_types' => [], 'missing' => [],
            'warnings' => ['blocked_by_admin_clear'], 'modified' => 0,
            'form_state' => RECOVERY_FORM_MISSING,
            'manifest_file' => null, 'manifest_modified' => 0,
        ];
    }
    $merged = mergeStructureImages($officialImages, $registeredTypes, $backup);
    $manifest = is_array($backup['manifest'] ?? null) ? $backup['manifest'] : [];

    $details = $officialDetails;
    $dataSource = 'official';
    if ($details === [] && is_array($manifest['formulario'] ?? null)) {
        $details = sanitizeBackupForm($manifest['formulario']);
        if ($details !== []) {
            $dataSource = 'backup_qfile';
        }
    }
    $rst = $officialRst;
    if ($rst === [] && is_array($manifest['secciones_rst'] ?? null)) {
        $rst = sanitizeBackupRst($manifest['secciones_rst']);
        if ($rst !== []) {
            $dataSource = $dataSource === 'official' ? 'mixed' : 'backup_qfile';
        }
    }
    $source = $merged['source'];
    if ($dataSource !== 'official' && $source === 'official') {
        $source = 'mixed';
    }

    $officialByType = [];
    foreach ($officialImages as $row) {
        $officialByType[(string) $row['nombre_foto']]
            = resolveStoredImagePath($row['ruta_archivo'] ?? null);
    }
    $officialMissing = [];
    $declaredTypes = $backup['declared_types'] ?? [];
    foreach (array_unique(array_merge($registeredTypes, $declaredTypes)) as $type) {
        $absolute = $officialByType[(string) $type] ?? null;
        if (!is_string($absolute) || $absolute === '' || !is_file($absolute)
            || (int) (@filesize($absolute) ?: 0) <= 0
            || @getimagesize($absolute) === false) {
            $officialMissing[] = (string) $type;
        }
    }
    if ($merged['missing'] !== []) {
        $status = $backup['status'] === RECOVERY_INTEGRITY_ERROR
            ? RECOVERY_INTEGRITY_ERROR
            : ($backup['status'] === RECOVERY_BACKUP_PARTIAL
                ? RECOVERY_BACKUP_PARTIAL
                : RECOVERY_OFFICIAL_WARNINGS);
    } elseif ($merged['backup_used'] > 0) {
        $status = RECOVERY_BACKUP_AVAILABLE;
    } elseif ($merged['images'] !== [] || $details !== []) {
        $status = RECOVERY_OFFICIAL_COMPLETE;
    } else {
        $status = RECOVERY_NO_FILES;
    }
    return [
        'post' => $post,
        'details' => $details,
        'rst' => $rst,
        // Lo que hay en la BD, antes del recurso al manifiesto del respaldo.
        // `applyReportPhotoOptions()` lo necesita para garantizar que elegir
        // NAS o Backup no cambia el contenido no fotográfico del reporte.
        'details_official' => $officialDetails,
        'rst_official' => $officialRst,
        'images' => $merged['images'],
        'status' => $status,
        'source' => $source,
        'data_source' => $dataSource,
        'backup' => $backup,
        'official_missing' => array_values(array_unique($officialMissing)),
        'missing' => $merged['missing'],
        'recoverable' => $merged['recoverable'],
        'official_available' => $merged['official_available'],
        'backup_used' => $merged['backup_used'],
    ];
}

/** Respuesta sin rutas ni detalles técnicos para la interfaz. */
function publicStructureRecoveryStatus(array $resolved): array
{
    $missing = count($resolved['missing']);
    $recoverable = count($resolved['recoverable']);
    $backup = $resolved['backup'];
    if ($missing > 0) {
        $title = 'Faltan algunos archivos';
        $message = 'No encontramos algunos archivos ni en el almacenamiento principal ni en el respaldo.';
        $tone = 'danger';
    } elseif ($recoverable > 0) {
        $title = 'Respaldo disponible';
        $message = 'Encontramos una copia segura de archivos que aún no aparecen en el almacenamiento principal.';
        $tone = 'warning';
    } elseif (($backup['status'] ?? '') === RECOVERY_BACKUP_PARTIAL) {
        $title = 'Sincronización pendiente';
        $message = 'Algunos archivos del respaldo todavía están terminando de sincronizarse.';
        $tone = 'warning';
    } else {
        $title = 'Información sincronizada';
        $message = 'La información y los archivos disponibles de esta estructura están listos.';
        $tone = 'success';
    }
    $allTypes = array_unique(array_merge(
        $backup['declared_types'] ?? [],
        array_column($resolved['images'], 'nombre_foto'),
        $resolved['missing']
    ));
    return [
        'estado' => $resolved['status'],
        'titulo' => $title,
        'mensaje' => $message,
        'tono' => $tone,
        'source' => $resolved['source'],
        'archivos_registrados' => count($allTypes),
        'archivos_disponibles' => count($resolved['images']),
        'archivos_recuperables' => $recoverable,
        'archivos_faltantes' => $missing,
        'faltantes' => array_values($resolved['missing']),
        'puede_recuperar' => $recoverable > 0,
        'respaldo' => [
            'disponible' => in_array(
                $backup['status'] ?? '',
                [RECOVERY_BACKUP_AVAILABLE, RECOVERY_BACKUP_PARTIAL],
                true
            ),
            'completo' => ($backup['status'] ?? '') === RECOVERY_BACKUP_AVAILABLE,
        ],
    ];
}

/** Copia únicamente archivos ausentes; nunca reemplaza uno oficial válido. */
function recoverMissingStructureFiles(PDO $connection, int $postId, int $projectId): array
{
    $resolved = resolveStructureResources($connection, $postId, $projectId, true);
    $requested = array_values(array_intersect($resolved['recoverable'], photoTypes()));
    $result = ['recovered' => [], 'skipped' => [], 'failed' => []];
    if ($requested === []) {
        return $result;
    }
    $created = [];
    $connection->beginTransaction();
    try {
        foreach ($requested as $type) {
            $current = $connection->prepare(
                'SELECT ruta_archivo FROM imagenes_poste
                 WHERE poste_id = ? AND nombre_foto = ? FOR UPDATE'
            );
            $current->execute([$postId, $type]);
            $currentPath = $current->fetchColumn() ?: null;
            $currentAbsolute = resolveStoredImagePath(
                is_string($currentPath) ? $currentPath : null
            );
            if ($currentAbsolute !== null
                && (int) (@filesize($currentAbsolute) ?: 0) > 0
                && @getimagesize($currentAbsolute) !== false) {
                $result['skipped'][] = $type;
                continue;
            }
            $photo = $resolved['backup']['photos'][$type] ?? null;
            if (!is_array($photo) || !backupPhotoIsUsable($photo, true)) {
                $result['failed'][] = $type;
                continue;
            }
            $stored = saveUploadedImage([
                'error' => UPLOAD_ERR_OK,
                'size' => (int) $photo['bytes'],
                'tmp_name' => (string) $photo['absolute'],
            ], $postId, $type, [
                'proyecto_id' => $projectId,
                'linea' => $resolved['post']['linea'],
            ]);
            $created[] = $stored['relative'];
            $captureDate = normalizeDateTime($photo['fecha_captura'] ?? null)
                ?? normalizeDateTime($resolved['post']['fecha_inspeccion'] ?? null)
                ?? date('Y-m-d H:i:s');
            $upsert = $connection->prepare(
                'INSERT INTO imagenes_poste
                    (poste_id, nombre_foto, ruta_archivo, fecha_captura,
                     utm_este, utm_norte, zona, sincronizada,
                     fecha_inspeccion, fecha_subida, activo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), 1)
                 ON DUPLICATE KEY UPDATE ruta_archivo = VALUES(ruta_archivo),
                    fecha_captura = VALUES(fecha_captura),
                    utm_este = VALUES(utm_este), utm_norte = VALUES(utm_norte),
                    zona = VALUES(zona), sincronizada = 1,
                    fecha_inspeccion = VALUES(fecha_inspeccion),
                    fecha_subida = NOW(), activo = 1, archivado_en = NULL'
            );
            $upsert->execute([
                $postId,
                $type,
                $stored['relative'],
                $captureDate,
                ($photo['utm_este'] ?? '') !== '' ? (float) $photo['utm_este'] : null,
                ($photo['utm_norte'] ?? '') !== '' ? (float) $photo['utm_norte'] : null,
                ($photo['zona'] ?? '') !== '' ? trim((string) $photo['zona']) : null,
                $captureDate,
            ]);
            $state = $connection->prepare(
                'INSERT INTO imagenes_poste_estado
                    (poste_id, nombre_foto, sincronizada, activo)
                 VALUES (?, ?, 1, 1)
                 ON DUPLICATE KEY UPDATE sincronizada = 1, activo = 1, archivado_en = NULL,
                    actualizado_en = CURRENT_TIMESTAMP'
            );
            $state->execute([$postId, $type]);
            $result['recovered'][] = $type;
        }
        if ($result['recovered'] !== []) {
            refreshPostSynchronizationStatus($connection, $postId);
            $connection->prepare(
                'UPDATE postes SET fecha_subida = NOW() WHERE id = ?'
            )->execute([$postId]);
        }
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        foreach ($created as $relative) {
            deleteStoredFile($relative);
        }
        appLog('almacenamiento', 'No se pudieron recuperar archivos del respaldo.', [
            'project' => $projectId,
            'structure' => $postId,
            'motivo' => $exception->getMessage(),
        ]);
        throw $exception;
    }
    if ($result['recovered'] !== []) {
        invalidatePostPdfCache($postId);
    }
    appLog('sincronizacion', 'Recuperación de archivos finalizada.', [
        'project' => $projectId,
        'structure' => $postId,
        'action' => 'recover_missing_files',
        'source' => 'backup_qfile',
        'recovered_files' => count($result['recovered']),
        'skipped_files' => count($result['skipped']),
        'failed_files' => count($result['failed']),
    ]);
    return $result;
}
