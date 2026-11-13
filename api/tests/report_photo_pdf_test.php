<?php
declare(strict_types=1);

/**
 * El PDF que sale de cada fuente y selección, compuesto de verdad.
 *
 * Se llama a `buildPostReport()` con el `$resolved` ya armado, que es el único
 * caso en el que la función no consulta nada: la conexión que recibe es un
 * `sqlite::memory:` que nunca se usa. Así se ejercita la composición real
 * —FPDF, los paneles, la panorámica, las firmas— sin base de datos, sin tocar
 * el almacén y sin escribir en la caché de PDF.
 *
 * Lo que se comprueba es lo que se puede comprobar sobre los bytes del PDF:
 *
 *   · cuántas fotografías quedaron incrustadas (objetos `/Subtype /Image`);
 *   · que el documento tiene el mismo número de páginas en las tres fuentes,
 *     porque el diseño no cambia: una foto excluida usa la celda vacía que ya
 *     existía;
 *   · que la huella de caché del reporte estándar no cambia, para no
 *     invalidar los PDF ya generados, y que dos configuraciones distintas
 *     nunca comparten huella.
 *
 *   php api/tests/report_photo_pdf_test.php
 */

/*
 * Las raíces de almacenamiento se memoizan en `storageRoots()` la primera vez
 * que alguien las pide, y el bootstrap de `config/app.php` las pide al
 * cargarse. Por eso las de la prueba se declaran ANTES del require, y con
 * `putenv()`: `app.php` reinicia `$GLOBALS['app_environment']` al incluirse,
 * pero `envValue()` cae a `getenv()` para lo que no esté en el .env.
 */
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecoing_pdf_' . bin2hex(random_bytes(6));
$officialDirectory = $root . DIRECTORY_SEPARATOR . 'oficial';
$backupDirectory = $root . DIRECTORY_SEPARATOR . 'respaldo';
mkdir($officialDirectory, 0777, true);
mkdir($backupDirectory, 0777, true);
putenv('APP_ENV_FILE=' . $root . DIRECTORY_SEPARATOR . 'sin-env');
putenv('IMAGE_STORAGE_PATH=' . $officialDirectory);
// Usar la clave canónica: un MOBILE_BACKUP_ROOT del .env local tiene prioridad
// sobre el alias histórico y haría que reportImagePath() rechazara el fixture
// por estar fuera de la raíz permitida.
putenv('MOBILE_BACKUP_ROOT=' . $backupDirectory);
putenv('MOBILE_BACKUP_STABILITY_SECONDS=0');

require_once __DIR__ . '/../services/PdfReportService.php';

$failures = [];
$checks = 0;

function checkPdf(bool $condition, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

/** JPEG real de 8x8 con un color plano; sirve para tener fotos distinguibles. */
function pdfTestPhoto(string $file, int $red, int $green, int $blue): void
{
    $canvas = imagecreatetruecolor(8, 8);
    imagefilledrectangle($canvas, 0, 0, 8, 8, (int) imagecolorallocate($canvas, $red, $green, $blue));
    imagejpeg($canvas, $file, 90);
    imagedestroy($canvas);
}

/** Fotografías incrustadas en el documento (cada una es un XObject de imagen). */
function pdfEmbeddedImages(string $pdf): int
{
    return preg_match_all('/\/Subtype\s*\/Image/', $pdf);
}

function pdfPageCount(string $pdf): int
{
    return preg_match_all('/\/Type\s*\/Page[^s]/', $pdf);
}

function pdfRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $target = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($target) ? pdfRemoveTree($target) : unlink($target);
    }
    rmdir($path);
}

// Nunca se consulta: buildPostReport() sólo usa la conexión si $resolved es null.
$connection = new PDO('sqlite::memory:');

try {
    $post = [
        'id' => 4242,
        'proyecto_id' => 7,
        'codigo' => 'L-6021-025',
        'linea' => 'L-6021',
        'estructura' => '025',
        'ubicaciones' => 'Tramo de prueba local',
        'utm_x' => '392541.20',
        'utm_y' => '8302114.55',
        'zona' => '19L',
        'fecha_inspeccion' => '2026-09-01 08:15:00',
        'nombre_proyecto' => 'Mantenimiento LT 60 kV',
        'contratista' => 'INTESEL',
        'proyecto_ubicacion' => 'Puno',
        'ultima_limpieza_admin' => null,
    ];
    $details = [
        'tipo_torre' => 'alineamiento',
        'acceso_torre' => 'a_pie',
        'estado_acceso' => 'bueno',
        'distancia_acceso' => 12.5,
        'comentarios' => 'HUELLA-FORMULARIO-BD-ACTUAL',
    ];
    $rst = [
        ['seccion' => 'conductores_fase', 'atributo' => 'hebras_rotas', 'fase' => 'R'],
        ['seccion' => 'estado_aisladores', 'atributo' => 'buen_estado', 'fase' => 'S'],
    ];

    // Cuatro slots reales: la panorámica (que va en la página 1) y tres del
    // panel fotográfico, para poder excluir y notarlo.
    $slots = ['foto_panoramica', 'placa', 'mensulas', 'retenida'];

    $imageRow = static function (string $slot, string $file, string $source): array {
        return [
            'nombre_foto' => $slot,
            'ruta_archivo' => null,
            'ruta_resuelta' => $file,
            'fecha_captura' => '2026-09-01 08:20:00',
            'utm_este' => 392541.2,
            'utm_norte' => 8302114.55,
            'zona' => '19L',
            'source' => $source,
            'disponible' => true,
        ];
    };

    // Oficial: tiene 3 de los 4 slots (le falta `retenida`).
    $officialImages = [];
    foreach (['foto_panoramica', 'placa', 'mensulas'] as $index => $slot) {
        $file = $officialDirectory . DIRECTORY_SEPARATOR . $slot . '.jpg';
        pdfTestPhoto($file, 200, 40 + ($index * 20), 40);
        $officialImages[] = $imageRow($slot, $file, 'official');
    }
    // Respaldo: tiene los 4, con archivos distintos de los oficiales.
    $backupPhotos = [];
    foreach ($slots as $index => $slot) {
        $file = $backupDirectory . DIRECTORY_SEPARATOR
            . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . '_' . $slot . '.jpg';
        pdfTestPhoto($file, 30, 60, 180 + $index * 15);
        $backupPhotos[$slot] = [
            'type' => $slot,
            'absolute' => $file,
            'root' => $backupDirectory,
            'bytes' => (int) filesize($file),
            'checksum' => '',
            'fecha_captura' => '2026-08-24 09:30:00',
            'utm_este' => null,
            'utm_norte' => null,
            'zona' => null,
        ];
    }

    $resolved = [
        'post' => $post,
        'details' => $details,
        'rst' => $rst,
        'images' => $officialImages,
        'status' => RECOVERY_OFFICIAL_WARNINGS,
        'source' => 'official',
        'data_source' => 'official',
        'backup' => [
            'status' => RECOVERY_BACKUP_AVAILABLE,
            'manifest' => [
                'formulario' => ['comentarios' => 'HUELLA-FORMULARIO-RESPALDO'],
                'secciones_rst' => [
                    ['seccion' => 'estado_aisladores', 'atributo' => 'buen_estado', 'fase' => 'T'],
                ],
                'coordenadas' => ['utm_este' => 111111.5, 'utm_norte' => 8222222.5, 'zona' => '19S'],
                'estructura' => ['fecha_inspeccion' => '2026-08-24 09:15:00'],
            ],
            'form_state' => RECOVERY_FORM_OK,
            'photos' => $backupPhotos,
            'declared_types' => $slots,
            'missing' => [],
            'warnings' => [],
            'modified' => strtotime('2026-08-24 10:00:00'),
        ],
        'official_missing' => ['retenida'],
        'missing' => ['retenida'],
        'recoverable' => [],
        'official_available' => 3,
        'backup_used' => 0,
    ];

    $render = static function (array $options) use ($connection, $resolved): string {
        // Copia por valor: cada render parte del mismo $resolved sin aplicar.
        return buildPostReport($connection, 4242, 7, $resolved, $options)->Output('S');
    };

    // Marca del PDF sin fotografías del poste: las dos del encabezado y la
    // firma. Sirve de línea base para contar sólo las fotos de la estructura.
    $chrome = pdfEmbeddedImages($render(['slots' => []]));

    // ── A: fuente actual + todas -> el reporte de siempre ────────────────────
    $a = $render([]);
    checkPdf(strlen($a) > 20000, 'A: el reporte estándar salió sospechosamente pequeño.');
    checkPdf(pdfEmbeddedImages($a) - $chrome === 3, 'A: no incrustó las 3 fotografías oficiales.');
    // Sin opciones y con opciones estándar explícitas debe salir el mismo PDF.
    $aExplicito = $render(['photo_source' => 'auto', 'slots' => photoTypes()]);
    checkPdf(
        pdfEmbeddedImages($aExplicito) === pdfEmbeddedImages($a)
            && pdfPageCount($aExplicito) === pdfPageCount($a),
        'A: enumerar los 28 slots cambió el reporte estándar.'
    );

    // ── B: fuente respaldo + todas -> mismos datos, otras fotos ──────────────
    $b = $render(['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkPdf(pdfEmbeddedImages($b) - $chrome === 4, 'B: el respaldo no aportó sus 4 fotografías.');
    checkPdf(pdfPageCount($b) === pdfPageCount($a), 'B: cambió el número de páginas al cambiar de fuente.');
    checkPdf($b !== $a, 'B: el reporte del respaldo salió idéntico al oficial.');
    // En Backup el informe COMPLETO es el de la campaña respaldada: formulario,
    // RST, fecha de inspección y coordenadas salen del manifiesto.
    $resolvedB = applyReportPhotoOptions($resolved, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkPdf(
        $resolvedB['details']['comentarios'] === 'HUELLA-FORMULARIO-RESPALDO',
        'B: el formulario del informe no salió del manifiesto del respaldo.'
    );
    checkPdf(
        $resolvedB['rst'] === [['seccion' => 'estado_aisladores', 'atributo' => 'buen_estado', 'fase' => 'T']],
        'B: el RST no salió del manifiesto del respaldo.'
    );
    checkPdf($resolvedB['post']['fecha_inspeccion'] === '2026-08-24 09:15:00', 'B: no tomó la fecha del respaldo.');
    checkPdf($resolvedB['post']['zona'] === '19S', 'B: no tomó la zona UTM del respaldo.');
    // Pero la identidad sigue viniendo de la BD.
    checkPdf($resolvedB['post']['codigo'] === 'L-6021-025', 'B: el respaldo reescribió el código.');
    checkPdf($resolvedB['post']['nombre_proyecto'] === 'Mantenimiento LT 60 kV', 'B: el respaldo reescribió el proyecto.');
    // Y en NAS el informe sigue siendo el de la BD, intacto.
    $resolvedNas = applyReportPhotoOptions($resolved, ['photo_source' => REPORT_PHOTO_SOURCE_CURRENT]);
    checkPdf($resolvedNas['details']['comentarios'] === 'HUELLA-FORMULARIO-BD-ACTUAL', 'B: NAS trajo el formulario del respaldo.');
    checkPdf($resolvedNas['rst'] === $rst, 'B: NAS trajo el RST del respaldo.');
    checkPdf($resolvedNas['post']['fecha_inspeccion'] === '2026-09-01 08:15:00', 'B: NAS cambió la fecha de inspección.');

    // ── C: fuente respaldo excluyendo 3 fotografías ──────────────────────────
    $c = $render([
        'photo_source' => REPORT_PHOTO_SOURCE_BACKUP,
        'slots' => ['foto_panoramica'],
    ]);
    checkPdf(pdfEmbeddedImages($c) - $chrome === 1, 'C: las fotografías excluidas siguen en el PDF.');
    checkPdf(pdfPageCount($c) === pdfPageCount($a), 'C: excluir fotografías reorganizó las páginas.');

    // Excluir la panorámica también funciona: la página 1 se dibuja sin ella.
    $cSinPanoramica = $render([
        'photo_source' => REPORT_PHOTO_SOURCE_BACKUP,
        'slots' => ['placa', 'mensulas', 'retenida'],
    ]);
    checkPdf(pdfEmbeddedImages($cSinPanoramica) - $chrome === 3, 'C: excluir la panorámica no la quitó del PDF.');
    checkPdf(pdfPageCount($cSinPanoramica) === pdfPageCount($a), 'C: quitar la panorámica cambió las páginas.');

    // ── B bis: SIN configuración guardada se imprime TODO lo disponible ─────
    // Es la regla que comparten la pantalla de configuración y el reporte: una
    // configuración inexistente nunca puede leerse como «ninguna».
    $sinConfig = $render(['photo_source' => REPORT_PHOTO_SOURCE_BACKUP, 'excluded_slots' => []]);
    checkPdf(
        pdfEmbeddedImages($sinConfig) - $chrome === 4,
        'B: sin configuración guardada dejó de imprimir fotografías disponibles.'
    );
    $sinConfigNas = $render(['photo_source' => REPORT_PHOTO_SOURCE_CURRENT, 'excluded_slots' => []]);
    checkPdf(
        pdfEmbeddedImages($sinConfigNas) - $chrome === 3,
        'B: sin configuración guardada NAS dejó de imprimir sus fotografías.'
    );

    // ── C bis: la configuración guardada entra como `excluded_slots` ────────
    // Es exactamente por donde llega la selección del panel admin.
    $cGuardada = $render([
        'photo_source' => REPORT_PHOTO_SOURCE_BACKUP,
        'excluded_slots' => ['placa', 'mensulas'],
    ]);
    checkPdf(pdfEmbeddedImages($cGuardada) - $chrome === 2, 'C: no aplicó los slots excluidos guardados.');
    checkPdf(pdfPageCount($cGuardada) === pdfPageCount($a), 'C: los slots excluidos cambiaron las páginas.');

    // ── E: un slot ausente del respaldo NO cae a la fotografía actual ────────
    $resolvedSinPlaca = $resolved;
    unset($resolvedSinPlaca['backup']['photos']['placa']);
    $e = buildPostReport($connection, 4242, 7, $resolvedSinPlaca, [
        'photo_source' => REPORT_PHOTO_SOURCE_BACKUP,
    ])->Output('S');
    checkPdf(pdfEmbeddedImages($e) - $chrome === 3, 'E: hubo fallback silencioso a la fotografía oficial.');

    // ── I: la caché no puede confundir dos configuraciones ───────────────────
    $huella = static fn(array $options): string => postReportFingerprint(
        $connection,
        4242,
        7,
        applyReportPhotoOptions($resolved, $options)
    );
    $huellaEstandar = postReportFingerprint($connection, 4242, 7, $resolved);
    checkPdf($huella([]) === $huellaEstandar, 'I: la huella del reporte estándar cambió; invalidaría los PDF ya cacheados.');
    checkPdf($huella(['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]) !== $huellaEstandar, 'I: el respaldo comparte huella con el estándar.');
    checkPdf(
        $huella(['photo_source' => REPORT_PHOTO_SOURCE_BACKUP])
            !== $huella(['photo_source' => REPORT_PHOTO_SOURCE_BACKUP, 'slots' => ['placa']]),
        'I: dos selecciones distintas comparten huella.'
    );
    checkPdf(
        $huella(['photo_source' => REPORT_PHOTO_SOURCE_CURRENT]) !== $huella(['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]),
        'I: las fuentes oficial y respaldo comparten huella.'
    );

    // ── J: supervisor de obra y caché ───────────────────────────────────────
    checkPdf(PDF_SIGNER_OBRA_NAME === 'EDUARDO PANCORBO SALAS',
        'J: el supervisor de obra no tiene el nombre solicitado.');
    $signaturePdf = buildPostReport($connection, 4242, 7, $resolved, [
        'photo_source' => REPORT_PHOTO_SOURCE_CURRENT,
    ]);
    $signaturePdf->SetCompression(false);
    $signatureBytes = $signaturePdf->Output('S');
    checkPdf(str_contains($signatureBytes, 'EDUARDO PANCORBO SALAS'),
        'J: el nombre del supervisor no aparece en el PDF compuesto.');
    $oldSupervisor = implode(' ', ['Hector', 'Hitler', 'Condori', 'Yaguno']);
    checkPdf(!str_contains($signatureBytes, $oldSupervisor),
        'J: el PDF todavía contiene el supervisor anterior.');
    // Editar el formulario del respaldo tiene que regenerar el PDF Backup.
    $huellaBackup = $huella(['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    $resolvedOtroForm = $resolved;
    $resolvedOtroForm['backup']['manifest']['formulario']['comentarios'] = 'OTRO-COMENTARIO';
    checkPdf(
        postReportFingerprint($connection, 4242, 7, applyReportPhotoOptions(
            $resolvedOtroForm,
            ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]
        )) !== $huellaBackup,
        'I: cambiar el formulario del respaldo no cambió la huella del PDF Backup.'
    );

    // ── K: nada de lo anterior escribió en la caché de PDF ───────────────────
    $cacheDirectory = pdfCacheDirectory(4242);
    checkPdf(!is_dir($cacheDirectory) || (glob($cacheDirectory . DIRECTORY_SEPARATOR . '*.pdf') ?: []) === [],
        'K: se escribió un PDF en la caché durante la prueba.');
} finally {
    pdfRemoveTree($root);
}

echo $failures === []
    ? "OK: {$checks} comprobaciones del PDF por fuente y selección.\n"
    : "FALLOS ({$checks} comprobaciones):\n - " . implode("\n - ", $failures) . "\n";
exit($failures === [] ? 0 : 1);
