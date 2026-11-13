<?php
declare(strict_types=1);

/**
 * Avance de inspección calculado sobre el respaldo del NAS.
 *
 * ## Para qué
 *
 * El panel de líneas cuenta inspeccionadas, formularios, fotos confirmadas y
 * avance sincronizado con las banderas de la BD, que describen lo que llegó al
 * servidor. Esto responde la misma pregunta mirando ÚNICAMENTE `/data/elpu`:
 * qué hay realmente en el respaldo, sin mezclarlo con lo oficial.
 *
 * ## Sólo lectura
 *
 * No hay INSERT/UPDATE/DELETE, no se escribe nada dentro del respaldo y no se
 * copia ni se recupera ningún archivo. Sólo `scandir`, `stat` y la lectura de
 * los `formulario.json`.
 *
 * ## Por qué un barrido único y una pasada ligera
 *
 * Con 1235 estructuras, medido en local:
 *
 *   barrido único de /data/elpu ............................  0,016 s
 *   inspectMobileBackup() por poste (un BFS cada vez) ......  22,6 s
 *   barrido único + inspección completa por carpeta .........  11,7 s
 *   barrido único + esta pasada ligera ......................   2,1 s
 *
 * El BFS por poste cuesta la mitad del total, así que el índice hace falta;
 * pero lo que domina es `getimagesize()` sobre 32.000 fotografías. Para CONTAR
 * no se decodifica cada imagen: basta con que el archivo exista, no sea
 * temporal, tenga extensión de imagen, pese algo y su nombre corresponda a un
 * slot del catálogo.
 *
 * ## Diferencia declarada con el reporte
 *
 * Por eso mismo, una fotografía presente pero ilegible cuenta aquí y en el PDF
 * saldría como «No tiene foto». Es el precio de que la pantalla cargue: el
 * recuento habla de ARCHIVOS PRESENTES, y el reporte, de archivos que se pueden
 * incrustar. `structurePhotoDiagnostics()` sigue siendo la vista exacta.
 */

require_once __DIR__ . '/../config/domain.php';
require_once __DIR__ . '/StructureRecoveryService.php';

/** Nivel máximo al que se busca `Linea_<x>/Estructura_<y>` bajo la raíz. */
const BACKUP_PROGRESS_MAX_DEPTH = 8;

/** Techo de carpetas visitadas en el barrido; evita un recorrido patológico. */
const BACKUP_PROGRESS_MAX_DIRS = 20000;

/**
 * Índice `linea => estructura => carpeta`, con UN solo barrido de la raíz.
 *
 * Se reconoce por la forma del árbol —una carpeta `Estructura_<x>` cuyo padre
 * es `Linea_<y>`— así que funciona con las carpetas intermedias que organice el
 * share sin conocerlas: `Mantenimiento_LT_60kV/Inspeccion_Piso/Linea_.../...`.
 *
 * Además del índice conserva el inventario físico. Esto evita que dos carpetas
 * normalizadas a la misma estructura se sobrescriban silenciosamente y permite
 * explicar después qué se asoció y qué quedó fuera.
 *
 * @return array{index:array,folders:list<array>,dirs:int,truncated:bool}
 */
function backupProgressIndex(?string $root = null): array
{
    $root = $root ?? storagePath('mobile_backups');
    $result = ['index' => [], 'folders' => [], 'dirs' => 0, 'truncated' => false];
    if (!is_dir($root)) {
        return $result;
    }

    $frontier = [[$root, 0]];
    $visited = 0;
    while ($frontier !== []) {
        [$directory, $depth] = array_pop($frontier);
        if ($depth >= BACKUP_PROGRESS_MAX_DEPTH) {
            continue;
        }
        foreach (@scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || mobileBackupIsTemporary($entry)) {
                continue;
            }
            if (++$visited > BACKUP_PROGRESS_MAX_DIRS) {
                $result['truncated'] = true;
                $result['dirs'] = $visited;
                return $result;
            }
            $child = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($child)) {
                continue;
            }
            $parent = basename($directory);
            // Prefijos sin distinguir mayúsculas y admitiendo `_`, `-` o espacio
            // como separador: una carpeta `estructura 64` es una estructura, y
            // antes se ignoraba en silencio por no empezar por `Estructura_`.
            $structureName = mobileBackupNameAfterPrefix($entry, 'Estructura');
            $lineName = mobileBackupNameAfterPrefix($parent, 'Linea');
            if ($structureName !== null) {
                // Hoja: no hace falta bajar a Formulario/Fotos. Se conserva
                // incluso cuando el padre no parece una línea, para que el
                // diagnóstico no haga desaparecer una carpeta física.
                $lineKey = $lineName !== null ? mobileBackupCompareKey($lineName) : '';
                $structureKey = mobileBackupCompareKey($structureName);
                $realRoot = realpath($root) ?: rtrim($root, '/\\');
                $realChild = realpath($child) ?: $child;
                $relative = ltrim(substr($realChild, strlen($realRoot)), '/\\');
                $folder = [
                    'ruta' => $realChild,
                    'ruta_relativa' => $relative !== '' ? $relative : basename($realChild),
                    'linea' => $lineName,
                    'linea_key' => $lineKey,
                    'estructura' => $structureName,
                    'estructura_key' => $structureKey,
                    'motivo_no_indexada' => $lineName === null
                        ? 'carpeta_linea_no_reconocida'
                        : ($structureKey === '' ? 'estructura_vacia' : null),
                ];
                $result['folders'][] = $folder;
                if ($lineKey !== '' && $structureKey !== '') {
                    if (!isset($result['index'][$lineKey][$structureKey])) {
                        $result['index'][$lineKey][$structureKey] = $folder + [
                            'candidatas' => [],
                        ];
                    }
                    $result['index'][$lineKey][$structureKey]['candidatas'][] = $folder;
                }
                continue;
            }
            $frontier[] = [$child, $depth + 1];
        }
    }
    $result['dirs'] = $visited;

    return $result;
}

/**
 * Nombres con los que se puede buscar una estructura en el índice.
 *
 * Los mismos candidatos que usa la resolución del reporte: tal cual, con dos
 * dígitos, sin ceros a la izquierda y el código completo.
 *
 * @return list<string>
 */
function backupProgressStructureKeys(array $post): array
{
    $keys = [];
    foreach (mobileBackupStructureNames($post) as $name) {
        $key = mobileBackupCompareKey(substr($name, strlen('Estructura_')));
        if ($key !== '' && !in_array($key, $keys, true)) {
            $keys[] = $key;
        }
    }

    return $keys;
}

/**
 * ¿El manifiesto de esta carpeta es un formulario válido de ESTA estructura?
 *
 * Misma regla que `inspectMobileBackupDirectory()`: nombre exacto
 * `formulario.json` —los `formulario_conflict(N).json` no se leen nunca—, JSON
 * parseable, identidad coincidente y bloque `formulario` con datos.
 *
 * @return array{existe:bool,valido:bool,motivo:string,manifest:?array,
 *               coordenadas:array,fecha_inspeccion:?string}
 */
function backupProgressFormState(array $post, string $directory): array
{
    $file = $directory . DIRECTORY_SEPARATOR . 'Formulario'
        . DIRECTORY_SEPARATOR . 'formulario.json';
    if (!is_file($file)) {
        return ['existe' => false, 'valido' => false, 'motivo' => RECOVERY_FORM_MISSING,
            'manifest' => null, 'coordenadas' => [], 'fecha_inspeccion' => null];
    }
    $size = (int) (@filesize($file) ?: 0);
    if ($size <= 0 || $size > 5 * 1024 * 1024) {
        return ['existe' => true, 'valido' => false, 'motivo' => RECOVERY_FORM_INVALID,
            'manifest' => null, 'coordenadas' => [], 'fecha_inspeccion' => null];
    }
    $decoded = json_decode((string) @file_get_contents($file), true);
    if (!is_array($decoded)) {
        return ['existe' => true, 'valido' => false, 'motivo' => RECOVERY_FORM_INVALID,
            'manifest' => null, 'coordenadas' => [], 'fecha_inspeccion' => null];
    }
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
        return ['existe' => true, 'valido' => false, 'motivo' => RECOVERY_FORM_MISMATCH,
            'manifest' => $decoded, 'coordenadas' => [], 'fecha_inspeccion' => null];
    }
    if (!is_array($decoded['formulario'] ?? null) || $decoded['formulario'] === []) {
        return ['existe' => true, 'valido' => false, 'motivo' => RECOVERY_FORM_EMPTY,
            'manifest' => $decoded, 'coordenadas' => [], 'fecha_inspeccion' => null];
    }

    $coordinates = is_array($decoded['coordenadas'] ?? null) ? $decoded['coordenadas'] : [];
    return [
        'existe' => true,
        'valido' => true,
        'motivo' => RECOVERY_FORM_OK,
        'manifest' => $decoded,
        'coordenadas' => [
            'utm_x' => is_numeric($coordinates['utm_este'] ?? null)
                ? (string) $coordinates['utm_este'] : null,
            'utm_y' => is_numeric($coordinates['utm_norte'] ?? null)
                ? (string) $coordinates['utm_norte'] : null,
            'zona' => trim((string) ($coordinates['zona'] ?? '')) ?: null,
        ],
        'fecha_inspeccion' => normalizeDateTime($identity['fecha_inspeccion'] ?? null),
    ];
}

/**
 * Fotografías presentes en `Fotos/`, por slot y sin decodificar.
 *
 * Se reutiliza `mobileBackupSlotFromFileName()`, así que el prefijo numérico
 * identifica el slot igual que en el reporte, y un archivo que no mapea a
 * ningún slot no se cuenta. Los `.tmp`, los ocultos y lo que no sea imagen
 * quedan fuera.
 *
 * Se devuelven dos cosas distintas a propósito:
 *
 *   `slots`    los que se pueden atribuir a un slot del catálogo, que es lo
 *              que el reporte podrá imprimir;
 *   `archivos` TODA imagen real presente, aunque su nombre no se reconozca.
 *
 * La distinción importa: una estructura con fotografías nombradas de otra
 * forma tiene evidencia de inspección igualmente, y contarla como «sin fotos»
 * la hacía desaparecer del avance.
 *
 * @return array{slots:list<string>,archivos:int,no_mapeados:int}
 */
function backupProgressPhotoSlots(string $directory): array
{
    $photoRoot = $directory . DIRECTORY_SEPARATOR . 'Fotos';
    $slots = [];
    $archivos = 0;
    $noMapeados = 0;
    $inspected = 0;
    foreach (@scandir($photoRoot) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || mobileBackupIsTemporary($entry)) {
            continue;
        }
        if (++$inspected > MOBILE_BACKUP_MAX_PHOTO_FILES) {
            break;
        }
        if (preg_match('/\.(?:jpe?g|png)$/i', $entry) !== 1) {
            continue;
        }
        if ((int) (@filesize($photoRoot . DIRECTORY_SEPARATOR . $entry) ?: 0) <= 0) {
            continue;
        }
        $archivos++;
        $slot = mobileBackupSlotFromFileName($entry);
        if ($slot === null) {
            $noMapeados++;
            continue;
        }
        if (!in_array($slot, $slots, true)) {
            $slots[] = $slot;
        }
    }

    return ['slots' => $slots, 'archivos' => $archivos, 'no_mapeados' => $noMapeados];
}

/**
 * Estado de una estructura en el respaldo.
 *
 * ## Equivalencia con los indicadores del NAS
 *
 *   NAS                                    Respaldo
 *   ------------------------------------   ---------------------------------
 *   inspeccionada: fecha_inspeccion        existe su carpeta con formulario
 *                  no nula                 o con alguna fotografía
 *   formulario   : formulario_subido = 1   formulario.json válido y suyo
 *   fotos        : fotos_capturadas > 0    hay fotografías, no falta ninguna
 *                  y imagenes_subidas = 1  de las declaradas y la declaración
 *                                          se pudo leer
 *   sincronizada : inspeccion_finalizada   formulario válido Y fotos
 *                  y imagenes_subidas      confirmadas
 *
 * @return array{encontrada:bool,inspeccionada:bool,formulario:bool,fotos:bool,
 *               sincronizada:bool,fotos_presentes:int,form_state:string}
 */
function backupProgressEmptyState(): array
{
    return [
        'encontrada' => false,
        'backup_presente' => false,
        'inspeccionada' => false,
        'formulario_existe' => false,
        'formulario' => false,
        'fotos' => false,
        'sincronizada' => false,
        'fotos_presentes' => 0,
        'archivos_presentes' => 0,
        'fotos_sin_mapear' => 0,
        'form_state' => RECOVERY_FORM_MISSING,
        'estado' => 'pendiente',
        'coordenadas' => ['utm_x' => null, 'utm_y' => null, 'zona' => null],
        'fecha_inspeccion' => null,
        'ruta_relativa' => null,
        'carpetas_encontradas' => 0,
        'duplicada' => false,
    ];
}

/** Calcula el estado de una carpeta sin mezclar evidencias de duplicados. */
function backupProgressDirectoryState(array $post, array $folder): array
{
    $state = backupProgressEmptyState();
    $state['encontrada'] = true;
    $state['ruta_relativa'] = $folder['ruta_relativa'] ?? basename((string) $folder['ruta']);
    $form = backupProgressFormState($post, (string) $folder['ruta']);
    $state['formulario_existe'] = $form['existe'];
    $state['form_state'] = $form['motivo'];
    $state['formulario'] = $form['valido'];
    $state['coordenadas'] = $form['coordenadas'];
    $state['fecha_inspeccion'] = $form['fecha_inspeccion'];

    $fotos = backupProgressPhotoSlots((string) $folder['ruta']);
    $slots = $fotos['slots'];
    $state['fotos_presentes'] = count($slots);
    $state['archivos_presentes'] = $fotos['archivos'];
    $state['fotos_sin_mapear'] = $fotos['no_mapeados'];

    $declared = [];
    if (is_array($form['manifest'])) {
        $entries = is_array($form['manifest']['fotografias']['archivos'] ?? null)
            ? $form['manifest']['fotografias']['archivos']
            : [];
        foreach ($entries as $entry) {
            $type = is_array($entry) ? trim((string) ($entry['tipo'] ?? '')) : '';
            if ($type !== '' && in_array($type, photoTypes(), true)) {
                $declared[] = $type;
            }
        }
    }
    $declarationUnreadable = in_array(
        $state['form_state'],
        [RECOVERY_FORM_INVALID, RECOVERY_FORM_MISMATCH],
        true
    );
    $state['fotos'] = $slots !== []
        && !$declarationUnreadable
        && array_diff(array_unique($declared), $slots) === [];
    $state['backup_presente'] = $form['existe'] || $fotos['archivos'] > 0;
    $state['inspeccionada'] = $state['backup_presente'];
    $state['sincronizada'] = $state['formulario'] && $state['fotos'];
    $state['estado'] = $state['sincronizada']
        ? 'sincronizada'
        : ($state['inspeccionada'] ? 'en_proceso' : 'pendiente');

    return $state;
}

function backupProgressStructureState(array $post, array $index): array
{
    $empty = backupProgressEmptyState();
    $byStructure = $index[mobileBackupCompareKey($post['linea'] ?? '')] ?? null;
    if (!is_array($byStructure)) {
        return $empty;
    }

    $folders = [];
    foreach (backupProgressStructureKeys($post) as $key) {
        if (!isset($byStructure[$key])) {
            continue;
        }
        $entry = $byStructure[$key];
        $candidates = is_array($entry['candidatas'] ?? null) && $entry['candidatas'] !== []
            ? $entry['candidatas']
            : [$entry];
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && isset($candidate['ruta'])) {
                $folders[(string) $candidate['ruta']] = $candidate;
            }
        }
    }
    if ($folders === []) {
        return $empty;
    }

    // Los duplicados no se fusionan: formulario y fotos deben coexistir en la
    // misma carpeta. Gana la autosuficiente más completa y luego la más nueva.
    $best = null;
    $bestScore = null;
    foreach ($folders as $folder) {
        $candidate = backupProgressDirectoryState($post, $folder);
        $score = [
            $candidate['sincronizada'] ? 1 : 0,
            $candidate['inspeccionada'] ? 1 : 0,
            $candidate['formulario'] ? 1 : 0,
            $candidate['archivos_presentes'],
            (int) (@filemtime((string) $folder['ruta']) ?: 0),
        ];
        if ($best === null || $score > $bestScore) {
            $best = $candidate;
            $bestScore = $score;
        }
    }
    $best['carpetas_encontradas'] = count($folders);
    $best['duplicada'] = count($folders) > 1;

    return $best;
}

/**
 * Avance por línea de un proyecto, calculado sobre el respaldo.
 *
 * El TOTAL de estructuras sale siempre del padrón de la BD: es el universo
 * oficial del proyecto y no depende de lo que haya en el respaldo. Todo lo
 * demás se cuenta sobre `/data/elpu`.
 *
 * @param string|null $line sólo esa línea, o null para todo el proyecto
 * @throws DomainException si el proyecto no existe o no está activo
 */
function backupProgressByLine(
    PDO $connection,
    int $projectId,
    ?string $line = null,
    ?string $root = null,
    bool $includeDiagnostic = false
): array {
    $started = hrtime(true);
    $root = $root ?? storagePath('mobile_backups');

    $statement = $connection->prepare(
        "SELECT 1 FROM proyectos WHERE id = ? AND estado = 'activo'"
    );
    $statement->execute([$projectId]);
    if ($statement->fetchColumn() === false) {
        throw new DomainException('El proyecto no existe o no está activo.');
    }

    // Mismo universo que `postes/listar.php`: padrón activo de líneas activas.
    $sql = 'SELECT p.id, p.codigo, p.linea, p.estructura, p.proyecto_id
            FROM postes p
            LEFT JOIN lineas l ON l.proyecto_id = p.proyecto_id AND l.nombre = p.linea
            WHERE p.proyecto_id = :project AND p.activo = 1 AND COALESCE(l.activa, 1) = 1';
    $parameters = ['project' => $projectId];
    if ($line !== null && $line !== '') {
        $sql .= ' AND p.linea = :line';
        $parameters['line'] = $line;
    }
    $sql .= ' ORDER BY p.linea, p.codigo';
    $query = $connection->prepare($sql);
    $query->execute($parameters);
    $posts = $query->fetchAll();

    // UN solo barrido de la raíz para todas las estructuras de la petición.
    $index = backupProgressIndex($root);

    $lines = [];
    $structures = [];
    foreach ($posts as $post) {
        $name = (string) $post['linea'];
        $lines[$name] ??= [
            'linea' => $name,
            'total' => 0,
            'encontradas' => 0,
            'presentes' => 0,
            'inspeccionadas' => 0,
            'formularios' => 0,
            'fotos_confirmadas' => 0,
            'sincronizadas' => 0,
            // Avance que se muestra en modo Backup: toda estructura con
            // evidencia real, completa o no. `sincronizadas` es exigente
            // (formulario válido Y fotografías completas) y como medida de
            // avance del respaldo se queda corta.
            'respaldadas' => 0,
            'en_proceso' => 0,
            'pendientes' => 0,
            'fotos_presentes' => 0,
        ];
        $lines[$name]['total']++;
        $state = backupProgressStructureState($post, $index['index']);
        $lines[$name]['encontradas'] += $state['encontrada'] ? 1 : 0;
        $lines[$name]['presentes'] += $state['backup_presente'] ? 1 : 0;
        $lines[$name]['inspeccionadas'] += $state['inspeccionada'] ? 1 : 0;
        $lines[$name]['formularios'] += $state['formulario'] ? 1 : 0;
        $lines[$name]['fotos_confirmadas'] += $state['fotos'] ? 1 : 0;
        $lines[$name]['sincronizadas'] += $state['sincronizada'] ? 1 : 0;
        $lines[$name]['fotos_presentes'] += $state['fotos_presentes'];
        $lines[$name]['respaldadas'] += $state['inspeccionada'] ? 1 : 0;
        $lines[$name]['en_proceso'] += $state['estado'] === 'en_proceso' ? 1 : 0;
        $lines[$name]['pendientes'] += $state['estado'] === 'pendiente' ? 1 : 0;
        // Estado por poste, para que el listado pinte cada fila sin pedir una
        // consulta por estructura.
        $structures[] = [
            'poste_id' => (int) $post['id'],
            'codigo' => (string) $post['codigo'],
            'linea' => $name,
            'estructura' => (string) $post['estructura'],
            'carpeta_encontrada' => $state['encontrada'],
            'backup_presente' => $state['backup_presente'],
            'carpetas_encontradas' => $state['carpetas_encontradas'],
            'duplicada' => $state['duplicada'],
            'carpeta_usada' => $state['ruta_relativa'],
            'formulario' => [
                'existe' => $state['formulario_existe'],
                'valido' => $state['formulario'],
                'estado' => $state['form_state'],
            ],
            'fotos' => [
                'cantidad' => $state['fotos_presentes'],
                'archivos' => $state['archivos_presentes'],
                'sin_mapear' => $state['fotos_sin_mapear'],
                'confirmadas' => $state['fotos'],
            ],
            'inspeccionada' => $state['inspeccionada'],
            'sincronizada' => $state['sincronizada'],
            'estado' => $state['estado'],
            'coordenadas' => $state['coordenadas'],
            'fecha_inspeccion' => $state['fecha_inspeccion'],
        ];
    }
    ksort($lines, SORT_NATURAL);

    $totals = ['total' => 0, 'encontradas' => 0, 'presentes' => 0, 'inspeccionadas' => 0,
        'formularios' => 0, 'fotos_confirmadas' => 0, 'sincronizadas' => 0,
        'respaldadas' => 0, 'en_proceso' => 0, 'pendientes' => 0];
    foreach ($lines as $entry) {
        foreach (array_keys($totals) as $key) {
            $totals[$key] += $entry[$key];
        }
    }

    $response = [
        'fuente' => 'backup',
        'proyecto_id' => $projectId,
        'respaldo_configurado' => is_dir($root),
        'lineas' => array_values($lines),
        'estructuras' => $structures,
        'totales' => $totals,
        'carpetas_indexadas' => $index['dirs'],
        'indice_truncado' => $index['truncated'],
        'ms' => elapsedMs($started),
    ];
    if ($includeDiagnostic) {
        $response['diagnostico'] = backupProgressDiagnostic(
            $posts,
            $structures,
            $index,
            $line
        );
    }

    return $response;
}

/**
 * Explica el emparejamiento carpeta física -> estructura oficial.
 *
 * No vuelve a recorrer el disco: trabaja con el inventario producido por el
 * mismo barrido que calculó el avance.
 */
function backupProgressDiagnostic(
    array $posts,
    array $structures,
    array $scan,
    ?string $line
): array {
    $targetLineKey = mobileBackupCompareKey($line ?? '');
    $official = [];
    foreach ($posts as $post) {
        $lineKey = mobileBackupCompareKey($post['linea'] ?? '');
        foreach (backupProgressStructureKeys($post) as $structureKey) {
            $official[$lineKey][$structureKey] ??= [
                'poste_id' => (int) $post['id'],
                'codigo' => (string) $post['codigo'],
                'estructura' => (string) $post['estructura'],
            ];
        }
    }

    $mappings = [];
    $outsideLayout = [];
    $associatedPerPost = [];
    foreach ($scan['folders'] as $folder) {
        $folderLineKey = (string) ($folder['linea_key'] ?? '');
        if ($folderLineKey === '') {
            $outsideLayout[] = [
                'carpeta' => (string) $folder['ruta_relativa'],
                'estructura_carpeta' => (string) $folder['estructura'],
                'razon' => (string) ($folder['motivo_no_indexada'] ?? 'carpeta_linea_no_reconocida'),
            ];
            continue;
        }
        if ($targetLineKey !== '' && $folderLineKey !== $targetLineKey) {
            continue;
        }

        $match = $official[$folderLineKey][(string) $folder['estructura_key']] ?? null;
        if ($match === null) {
            $mappings[] = [
                'carpeta' => (string) $folder['ruta_relativa'],
                'linea_carpeta' => (string) $folder['linea'],
                'estructura_carpeta' => (string) $folder['estructura'],
                'asociada' => false,
                'poste_id' => null,
                'estructura_asociada' => null,
                'razon' => 'estructura_no_existe_en_padron',
                'duplicada' => false,
            ];
            continue;
        }
        $postId = (int) $match['poste_id'];
        $associatedPerPost[$postId] = ($associatedPerPost[$postId] ?? 0) + 1;
        $mappings[] = [
            'carpeta' => (string) $folder['ruta_relativa'],
            'linea_carpeta' => (string) $folder['linea'],
            'estructura_carpeta' => (string) $folder['estructura'],
            'asociada' => true,
            'poste_id' => $postId,
            'estructura_asociada' => $match['estructura'],
            'codigo_asociado' => $match['codigo'],
            'razon' => null,
            'duplicada' => false,
        ];
    }
    foreach ($mappings as &$mapping) {
        if ($mapping['asociada']) {
            $mapping['duplicada'] = ($associatedPerPost[(int) $mapping['poste_id']] ?? 0) > 1;
        }
    }
    unset($mapping);

    $summary = [
        'total_padron' => count($structures),
        'carpetas_fisicas' => count($mappings),
        'carpetas_asociadas' => count(array_filter(
            $mappings,
            static fn(array $item): bool => $item['asociada']
        )),
        'estructuras_asociadas' => count($associatedPerPost),
        'no_asociadas' => count(array_filter(
            $mappings,
            static fn(array $item): bool => !$item['asociada']
        )),
        'duplicadas' => array_sum(array_map(
            static fn(int $count): int => max(0, $count - 1),
            $associatedPerPost
        )),
        'con_formulario' => 0,
        'formulario_valido' => 0,
        'formulario_invalido' => 0,
        'sin_formulario' => 0,
        'con_fotos' => 0,
        'sin_fotos' => 0,
        'sincronizadas' => 0,
        'en_proceso' => 0,
        'pendientes' => 0,
        'fuera_de_layout' => count($outsideLayout),
    ];
    foreach ($structures as $structure) {
        $formExists = (bool) ($structure['formulario']['existe'] ?? false);
        $formValid = (bool) ($structure['formulario']['valido'] ?? false);
        $summary['con_formulario'] += $formExists ? 1 : 0;
        $summary['formulario_valido'] += $formValid ? 1 : 0;
        $summary['formulario_invalido'] += $formExists && !$formValid ? 1 : 0;
        $summary['sin_formulario'] += !$formExists ? 1 : 0;
        $hasPhotos = (int) ($structure['fotos']['archivos'] ?? 0) > 0;
        $summary['con_fotos'] += $hasPhotos ? 1 : 0;
        $summary['sin_fotos'] += $hasPhotos ? 0 : 1;
        $status = (string) ($structure['estado'] ?? 'pendiente');
        if ($status === 'sincronizada') {
            $summary['sincronizadas']++;
        } elseif ($status === 'en_proceso') {
            $summary['en_proceso']++;
        } else {
            $summary['pendientes']++;
        }
    }

    return [
        'solo_lectura' => true,
        'linea' => $line,
        'resumen' => $summary,
        'mapeo_carpetas' => $mappings,
        'fuera_de_layout' => $outsideLayout,
        'indice_truncado' => (bool) $scan['truncated'],
        'carpetas_visitadas' => (int) $scan['dirs'],
    ];
}
