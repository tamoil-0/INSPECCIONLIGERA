<?php
declare(strict_types=1);

/**
 * Regresión del 500 sin cuerpo en los reportes de fotografías.
 *
 * ## Qué ocurrió
 *
 * `ReportPhotoSourceService.php` y `ReportPhotoConfigService.php` se requerían
 * mutuamente. Con el ciclo, `PdfReportService.php` podía llamar a
 * `reportPhotoApplySavedConfig()` —que vive en Config— sin requerir ese archivo:
 * llegaba de rebote. El día que ese único archivo no estaba en el servidor, el
 * `require_once` fallaba al COMPILAR, antes de cualquier `try/catch`, y con
 * `display_errors=0` la respuesta era un 500 con el cuerpo vacío. Fallaban a la
 * vez `?photo_source=current` y `?photo_source=backup`, porque los dos pasan
 * por ahí.
 *
 * ## Qué vigila esta prueba
 *
 *   1. que cada archivo declare un `require_once` para TODA función propia que
 *      llama —el fallo real, no su síntoma—;
 *   2. que un fatal responda JSON y no un cuerpo vacío;
 *   3. las reglas de fotografías que dependían de ese código.
 *
 * No toca la base, ni el almacén, ni el respaldo.
 *
 *   php api/tests/report_photo_regression_test.php
 */

$raizFixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecoing_reg_' . bin2hex(random_bytes(6));
$backupRoot = $raizFixture . DIRECTORY_SEPARATOR . 'elpu';
$configRoot = $raizFixture . DIRECTORY_SEPARATOR . 'report_config';
mkdir($backupRoot, 0777, true);
mkdir($configRoot, 0777, true);
putenv('MOBILE_BACKUP_ROOT=' . $backupRoot);
putenv('REPORT_CONFIG_STORAGE_PATH=' . $configRoot);
putenv('MOBILE_BACKUP_STABILITY_SECONDS=0');

require_once __DIR__ . '/../services/PdfReportService.php';
require_once __DIR__ . '/../services/ReportPhotoConfigService.php';

// realpath() a propósito: sin él la ruta contiene `/tests/..` y el filtro que
// excluye las pruebas descartaría TODOS los archivos, dejando el guardia sin
// nada que comprobar.
define('API_DIR', str_replace(DIRECTORY_SEPARATOR, '/', (string) realpath(__DIR__ . '/..')));

$failures = [];
$checks = 0;

function checkReg(bool $condition, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. Guardia de dependencias
// ─────────────────────────────────────────────────────────────────────────────

/** Todos los .php del API, sin las pruebas ni el vendor. */
function regApiFiles(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(API_DIR, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if (!str_ends_with($path, '.php')
            || str_contains($path, '/vendor/')
            || str_contains($path, '/tests/')) {
            continue;
        }
        $files[] = $path;
    }
    sort($files);

    return $files;
}

/** `require_once __DIR__ . '/x.php'` -> ruta absoluta normalizada. */
function regRequires(string $file): array
{
    $source = (string) @file_get_contents($file);
    preg_match_all(
        "/require(?:_once)?\s*__DIR__\s*\.\s*'([^']+)'/",
        $source,
        $matches
    );
    $resolved = [];
    foreach ($matches[1] as $relative) {
        $candidate = realpath(dirname($file) . $relative);
        if ($candidate !== false) {
            $resolved[] = str_replace('\\', '/', $candidate);
        }
    }

    return $resolved;
}

/** Cierre transitivo de los require de un archivo, incluido él mismo. */
function regClosure(string $file, array &$seen = []): array
{
    $file = str_replace('\\', '/', (string) (realpath($file) ?: $file));
    if (isset($seen[$file])) {
        return $seen;
    }
    $seen[$file] = true;
    foreach (regRequires($file) as $dependency) {
        regClosure($dependency, $seen);
    }

    return $seen;
}

/**
 * Funciones que declara un archivo y funciones propias que invoca.
 *
 * Se usa `token_get_all()` y no una expresión regular: los nombres de función
 * aparecen constantemente dentro de comentarios y docblocks («lo llama
 * `renderPostReport()`»), y un escáner de texto los contaría como llamadas.
 * El tokenizador los descarta solo, igual que descarta los métodos.
 *
 * @return array{definidas:list<string>,llamadas:list<string>}
 */
function regScan(string $file): array
{
    static $cache = [];
    $key = $file;
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $tokens = @token_get_all((string) @file_get_contents($file));
    $definidas = [];
    $llamadas = [];
    $total = count($tokens);
    for ($i = 0; $i < $total; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_STRING) {
            continue;
        }
        $name = $tokens[$i][1];

        // Token significativo anterior y siguiente, saltando espacios y comentarios.
        $previous = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $previous = $tokens[$j];
            break;
        }
        $next = null;
        for ($j = $i + 1; $j < $total; $j++) {
            if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $next = $tokens[$j];
            break;
        }

        $previousId = is_array($previous) ? $previous[0] : null;
        if ($previousId === T_FUNCTION) {
            $definidas[] = $name;
            continue;
        }
        // Métodos, constantes de clase, `new Clase(`, atributos.
        if (in_array($previousId, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }
        if ($next === '(') {
            $llamadas[] = $name;
        }
    }

    return $cache[$key] = [
        'definidas' => array_values(array_unique($definidas)),
        'llamadas' => array_values(array_unique($llamadas)),
    ];
}

function regDefines(string $file): array
{
    return regScan($file)['definidas'];
}

/** Llamadas a funciones propias del proyecto que hace un archivo. */
function regCalls(string $file, array $ownFunctions): array
{
    $scan = regScan($file);

    return array_values(array_filter(
        $scan['llamadas'],
        static fn(string $name): bool => in_array($name, $ownFunctions, true)
            && !in_array($name, $scan['definidas'], true)
    ));
}

$archivos = regApiFiles();
$definidaEn = [];
foreach ($archivos as $archivo) {
    foreach (regDefines($archivo) as $nombre) {
        $definidaEn[$nombre] = $archivo;
    }
}
$propias = array_keys($definidaEn);

$sinDeclarar = [];
foreach ($archivos as $archivo) {
    $seen = [];
    $cierre = array_keys(regClosure($archivo, $seen));
    $disponibles = [];
    foreach ($cierre as $incluido) {
        foreach (regDefines($incluido) as $nombre) {
            $disponibles[$nombre] = true;
        }
    }
    foreach (regCalls($archivo, $propias) as $llamada) {
        if (!isset($disponibles[$llamada])) {
            $sinDeclarar[] = basename($archivo) . ' llama a ' . $llamada . '() sin requerir '
                . basename($definidaEn[$llamada]);
        }
    }
}
checkReg(
    $sinDeclarar === [],
    "1: hay funciones llamadas sin declarar su require:\n     " . implode("\n     ", $sinDeclarar)
);

// El ciclo que ocultaba el problema no debe volver.
$source = str_replace('\\', '/', (string) realpath(API_DIR . '/services/ReportPhotoSourceService.php'));
$config = str_replace('\\', '/', (string) realpath(API_DIR . '/services/ReportPhotoConfigService.php'));
checkReg(
    !in_array($config, regRequires($source), true),
    '1: ReportPhotoSourceService volvió a requerir a Config (ciclo).'
);
checkReg(
    in_array($source, regRequires($config), true),
    '1: ReportPhotoConfigService dejó de requerir la resolución de fotografías.'
);
checkReg(
    in_array($config, regRequires(str_replace('\\', '/', (string) realpath(API_DIR . '/services/PdfReportService.php'))), true),
    '1: PdfReportService no declara el servicio de configuración que usa.'
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Un fatal responde JSON, nunca un cuerpo vacío
// ─────────────────────────────────────────────────────────────────────────────

$script = $raizFixture . DIRECTORY_SEPARATOR . 'fatal.php';
file_put_contents($script, "<?php\nrequire '"
    . str_replace('\\', '/', API_DIR . '/config/app.php')
    . "';\nfuncionQueNoExiste();\n");
$salida = (string) @shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1');
$json = json_decode($salida, true);
if (!is_array($json)) {
    // Con display_errors activo el aviso precede al JSON; se toma la última línea.
    $lineas = array_values(array_filter(array_map('trim', explode("\n", $salida))));
    $json = json_decode((string) end($lineas), true);
}
checkReg(is_array($json), '2: un fatal no produjo JSON (cuerpo vacío o texto suelto).');
checkReg(
    is_array($json) && ($json['success'] ?? null) === false && ($json['error'] ?? '') !== '',
    '2: el JSON de un fatal no trae success=false con mensaje.'
);
checkReg(
    !str_contains($salida, 'Stack trace'),
    '2: se filtró la traza al cuerpo de la respuesta.'
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Las reglas de fotografías que dependían de ese código
// ─────────────────────────────────────────────────────────────────────────────

/** Los 26 archivos reales de L-6028 / Estructura_01 con su slot. */
function regBackupFiles(): array
{
    return [
        '01_Panoramica.jpg' => 'foto_panoramica',
        '02_Placa.jpg' => 'placa',
        '03_Torre_parte_inferior.jpg' => 'torre_parte_inferior',
        '04_Torre_parte_superior.jpg' => 'torre_parte_superior',
        '05_Base_de_la_torre.jpg' => 'base_torre',
        '06_Mensulas.jpg' => 'mensulas',
        '07_Crucetas.jpg' => 'crucetas',
        '08_Perfiles_angulares.jpg' => 'perfiles_angulares',
        '11_Aisladores_fase_R_atras.jpg' => 'aisladores_fase_r_atras',
        '12_Aisladores_fase_S_atras.jpg' => 'aisladores_fase_s_atras',
        '13_Aisladores_fase_T_atras.jpg' => 'aisladores_fase_t_atras',
        '14_Aisladores_fase_R_adelante.jpg' => 'aisladores_fase_r_adelante',
        '15_Aisladores_fase_S_adelante.jpg' => 'aisladores_fase_s_adelante',
        '16_Aisladores_fase_T_adelante.jpg' => 'aisladores_fase_t_adelante',
        '17_Ferreteria_fase_R.jpg' => 'ferreteria_fase_r',
        '18_Ferreteria_fase_S.jpg' => 'ferreteria_fase_s',
        '19_Ferreteria_fase_T.jpg' => 'ferreteria_fase_t',
        '20_Cable_de_guarda.jpg' => 'cable_guarda',
        '21_Ferreteria_del_cable_de_guarda.jpg' => 'ferreteria_de_cable_de_guarda',
        '22_Conductor.jpg' => 'conductor',
        '23_Ferreteria_del_conductor.jpg' => 'ferreteria_de_conductor',
        '24_Puesta_a_tierra.jpg' => 'puesta_tierra',
        '25_Puesta_a_tierra_2.jpg' => 'puesta_tierra_2',
        '26_Retenida.jpg' => 'retenida',
        '27_Faja_de_servidumbre.jpg' => 'faja_servidumbre',
        '28_Ubicacion_acceso.jpg' => 'ubicacion_acceso',
    ];
}

function regPost(): array
{
    return [
        'id' => 1240,
        'proyecto_id' => 2,
        'codigo' => 'L-6028-01',
        'linea' => 'L-6028',
        'estructura' => '01',
        'ultima_limpieza_admin' => null,
    ];
}

function regJpeg(int $seed): string
{
    $canvas = imagecreatetruecolor(8, 8);
    imagefilledrectangle($canvas, 0, 0, 8, 8, (int) imagecolorallocate($canvas, $seed % 255, 70, 150));
    ob_start();
    imagejpeg($canvas, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($canvas);

    return $bytes;
}

function regRemove(string $path): void
{
    if (!is_dir($path)) {
        if (is_file($path)) {
            @unlink($path);
        }
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        regRemove($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

try {
    // Árbol real: <raiz>/<lo que organice el share>/Linea_<L>/Estructura_<E>
    $estructura = $backupRoot . '/Mantenimiento_LT_60kV/Inspeccion_Piso/Linea_L-6028/Estructura_01';
    mkdir($estructura . '/Fotos', 0777, true);
    mkdir($estructura . '/Formulario', 0777, true);
    $declarados = [];
    $seed = 0;
    foreach (regBackupFiles() as $nombre => $tipo) {
        $bytes = regJpeg(++$seed * 11);
        file_put_contents($estructura . '/Fotos/' . $nombre, $bytes);
        $declarados[] = [
            'tipo' => $tipo,
            'archivo' => $nombre,
            'fecha_captura' => '2026-08-31 19:40:00',
            'bytes' => strlen($bytes),
            'checksum_sha256' => hash('sha256', $bytes),
        ];
    }
    file_put_contents($estructura . '/Formulario/formulario.json', json_encode([
        'generado_en' => '2026-08-31T22:54:00-05:00',
        'estructura' => [
            'poste_id' => 1240, 'codigo' => 'L-6028-01', 'estructura' => '01',
            'linea' => 'L-6028', 'proyecto_id' => 2,
        ],
        'fotografias' => ['archivos' => $declarados],
        'formulario' => ['comentarios' => 'FORMULARIO DEL RESPALDO'],
        'secciones_rst' => [['seccion' => 'conductores_fase', 'atributo' => 'hebras_rotas', 'fase' => 'R']],
    ], JSON_UNESCAPED_UNICODE));

    $backup = inspectMobileBackup(regPost(), $backupRoot, 0);

    // BACKUP_26_PHOTOS
    checkReg($backup['status'] === RECOVERY_BACKUP_AVAILABLE, '3: el respaldo real no quedó disponible.');
    checkReg(count($backup['photos']) === 26, '3: BACKUP_26_PHOTOS: no se detectaron 26 fotografías.');
    checkReg(!isset($backup['photos']['atiescalamiento']), '3: se inventó el slot 09.');
    checkReg(!isset($backup['photos']['otros']), '3: se inventó el slot 10.');

    // La fila oficial existe para un slot que el respaldo también tiene.
    $officialDir = $raizFixture . DIRECTORY_SEPARATOR . 'oficial';
    mkdir($officialDir, 0777, true);
    $officialFile = $officialDir . DIRECTORY_SEPARATOR . 'placa_oficial.jpg';
    file_put_contents($officialFile, regJpeg(200));
    $officialRow = [
        'nombre_foto' => 'placa',
        'ruta_archivo' => null,
        'ruta_resuelta' => $officialFile,
        'fecha_captura' => '2026-09-01 08:00:00',
        'source' => 'official',
        'disponible' => true,
    ];
    $merged = mergeStructureImages([$officialRow], ['placa'], $backup);
    $resolved = [
        'post' => regPost(),
        'details' => ['comentarios' => 'FORMULARIO DEL RESPALDO'],
        'rst' => [['seccion' => 'conductores_fase', 'atributo' => 'hebras_rotas', 'fase' => 'R']],
        'details_official' => ['comentarios' => 'FORMULARIO DE LA BD'],
        'rst_official' => [['seccion' => 'estado_aisladores', 'atributo' => 'buen_estado', 'fase' => 'S']],
        'images' => $merged['images'],
        'backup' => $backup,
        'missing' => $merged['missing'],
        'recoverable' => $merged['recoverable'],
        'source' => $merged['source'],
        'official_available' => $merged['official_available'],
        'backup_used' => $merged['backup_used'],
    ];

    // BACKUP_NO_NAS_FALLBACK
    $bk = applyReportPhotoOptions($resolved, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkReg($bk['photo_options']['incluidas'] === 26, '3: BACKUP_PDF: el respaldo no aportó sus 26.');
    checkReg($bk['official_available'] === 0, '3: BACKUP_NO_NAS_FALLBACK: se colaron fotos del NAS.');
    $rutaPlaca = null;
    foreach ($bk['images'] as $imagen) {
        if ($imagen['nombre_foto'] === 'placa') {
            $rutaPlaca = (string) $imagen['ruta_resuelta'];
        }
    }
    checkReg(
        $rutaPlaca !== null && str_contains(str_replace('\\', '/', $rutaPlaca), '/Fotos/02_Placa.jpg'),
        '3: BACKUP_NO_NAS_FALLBACK: la placa salió del NAS teniendo respaldo.'
    );

    // CURRENT: sólo lo oficial
    $nas = applyReportPhotoOptions($resolved, ['photo_source' => REPORT_PHOTO_SOURCE_CURRENT]);
    checkReg($nas['photo_options']['incluidas'] === 1, '3: CURRENT_PDF: la fuente NAS no dejó su única foto oficial.');
    checkReg($nas['backup_used'] === 0, '3: la fuente NAS usó el respaldo.');

    // NAS: el informe es el de la BD actual.
    $nasForm = applyReportPhotoOptions($resolved, ['photo_source' => REPORT_PHOTO_SOURCE_CURRENT]);
    checkReg(
        $nasForm['details'] === ['comentarios' => 'FORMULARIO DE LA BD'],
        '3: NAS: el formulario salió del respaldo.'
    );
    checkReg(
        $nasForm['rst'] === [['seccion' => 'estado_aisladores', 'atributo' => 'buen_estado', 'fase' => 'S']],
        '3: NAS: el RST salió del respaldo.'
    );
    checkReg($nasForm['post'] === regPost(), '3: NAS: cambiaron los datos del poste.');

    // Backup: el informe es el de la campaña respaldada, formulario incluido.
    $bkForm = applyReportPhotoOptions($resolved, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkReg(
        $bkForm['details'] === ['comentarios' => 'FORMULARIO DEL RESPALDO'],
        '3: Backup: el formulario no salió del manifiesto del respaldo.'
    );
    checkReg(
        $bkForm['rst'] === [['seccion' => 'conductores_fase', 'atributo' => 'hebras_rotas', 'fase' => 'R']],
        '3: Backup: el RST no salió del manifiesto del respaldo.'
    );
    // La identidad no la reescribe el respaldo.
    foreach (['id', 'proyecto_id', 'codigo', 'linea', 'estructura'] as $campo) {
        checkReg(
            $bkForm['post'][$campo] === regPost()[$campo],
            '3: Backup: el respaldo alteró el dato maestro ' . $campo . '.'
        );
    }

    // NO_CONFIG_PRINTS_ALL
    checkReg(
        reportPhotoConfigExcluded(1240, 2, REPORT_PHOTO_SOURCE_BACKUP) === [],
        '3: NO_CONFIG_PRINTS_ALL: sin configuración ya se excluye algo.'
    );
    $sinConfig = applyReportPhotoOptions(
        $resolved,
        reportPhotoApplySavedConfig(['photo_source' => REPORT_PHOTO_SOURCE_BACKUP], 1240, 2)
    );
    checkReg(
        $sinConfig['photo_options']['incluidas'] === 26,
        '3: NO_CONFIG_PRINTS_ALL: sin configuración no se imprimieron las 26.'
    );

    // SAVED_CONFIG_RESPECTED, y por fuente
    reportPhotoConfigWrite(1240, 2, REPORT_PHOTO_SOURCE_BACKUP, ['placa', 'retenida'], 'test');
    $conConfig = applyReportPhotoOptions(
        $resolved,
        reportPhotoApplySavedConfig(['photo_source' => REPORT_PHOTO_SOURCE_BACKUP], 1240, 2)
    );
    checkReg(
        $conConfig['photo_options']['incluidas'] === 24,
        '3: SAVED_CONFIG_RESPECTED: no se aplicaron las 2 exclusiones guardadas.'
    );
    checkReg(
        reportPhotoConfigExcluded(1240, 2, REPORT_PHOTO_SOURCE_CURRENT) === [],
        '3: SAVED_CONFIG_RESPECTED: guardar Backup afectó a NAS.'
    );
} finally {
    regRemove($raizFixture);
}

echo $failures === []
    ? "OK: {$checks} comprobaciones de la regresion del 500 en reportes.\n"
    : "FALLOS ({$checks} comprobaciones):\n - " . implode("\n - ", $failures) . "\n";
exit($failures === [] ? 0 : 1);
