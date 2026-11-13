<?php
declare(strict_types=1);

/**
 * Fecha del informe Backup y avance por evidencia.
 *
 * ## Los dos problemas que cubre
 *
 * 1. El PDF Backup decía «Sin fecha» aunque el formulario venía del respaldo:
 *    sólo se miraba `estructura.fecha_inspeccion`, que el móvil deja NULL
 *    cuando no había sellado la fecha al exportar. Ahora se recorren, por orden,
 *    las marcas de tiempo REALES del manifiesto y, si no hay ninguna, se sigue
 *    diciendo que no hay fecha.
 *
 * 2. El avance en modo Backup usaba `sincronizadas` —formulario válido Y
 *    fotografías completas—, que es el estado exigente de cada fila y como
 *    medida de avance se queda corta. Ahora usa `respaldadas`: estructuras con
 *    evidencia real, completas o no.
 *
 * Sólo lectura: no toca la base, el almacén ni el respaldo.
 *
 *   php api/tests/backup_fecha_y_avance_test.php
 */

$fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecoing_fa_' . bin2hex(random_bytes(6));
$backupRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'elpu';
mkdir($backupRoot, 0777, true);
putenv('APP_ENV_FILE=' . $fixtureRoot . DIRECTORY_SEPARATOR . 'sin-env');
putenv('MOBILE_BACKUP_ROOT=' . $backupRoot);
putenv('MOBILE_BACKUP_STABILITY_SECONDS=0');

require_once __DIR__ . '/../services/PdfReportService.php';
require_once __DIR__ . '/../services/BackupProgressService.php';

$failures = [];
$checks = 0;

function checkFa(bool $condition, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

function faJpeg(): string
{
    $canvas = imagecreatetruecolor(8, 8);
    imagefilledrectangle($canvas, 0, 0, 8, 8, (int) imagecolorallocate($canvas, 70, 120, 90));
    ob_start();
    imagejpeg($canvas, null, 88);
    $bytes = (string) ob_get_clean();
    imagedestroy($canvas);

    return $bytes;
}

function faPost(string $structure = '01', int $id = 1240): array
{
    return [
        'id' => $id,
        'proyecto_id' => 2,
        'codigo' => 'L-6021-' . $structure,
        'linea' => 'L-6021',
        'estructura' => $structure,
        'ubicaciones' => 'Tramo de la BD',
        'utm_x' => '1', 'utm_y' => '2', 'zona' => '19L',
        'fecha_inspeccion' => '2026-09-01 08:15:00',
        'nombre_proyecto' => 'Mantenimiento LT 60 kV',
        'contratista' => 'INTESEL',
        'proyecto_ubicacion' => 'Puno',
        'ultima_limpieza_admin' => null,
    ];
}

/**
 * Escribe una estructura de respaldo.
 *
 * @param string      $evidencia formulario|fotos|ambas|nada
 * @param string|null $fecha     valor de `estructura.fecha_inspeccion`
 * @param list<string> $capturas valores de `fecha_captura`
 */
function faWrite(
    string $root,
    array $post,
    string $evidencia = 'ambas',
    ?string $fecha = '2026-08-31 16:40:00',
    array $capturas = ['2026-08-31 19:40:00'],
    ?string $actualizado = null,
    bool $formularioRoto = false
): void {
    $directory = $root . DIRECTORY_SEPARATOR . 'Mantenimiento_LT_60kV'
        . DIRECTORY_SEPARATOR . 'Inspeccion_Piso'
        . DIRECTORY_SEPARATOR . 'Linea_' . $post['linea']
        . DIRECTORY_SEPARATOR . 'Estructura_' . $post['estructura'];
    mkdir($directory . DIRECTORY_SEPARATOR . 'Fotos', 0777, true);
    mkdir($directory . DIRECTORY_SEPARATOR . 'Formulario', 0777, true);

    $slots = photoTypes();
    $declared = [];
    if (in_array($evidencia, ['fotos', 'ambas'], true)) {
        foreach ($capturas as $indice => $captura) {
            $name = str_pad((string) ($indice + 1), 2, '0', STR_PAD_LEFT) . '_Foto.jpg';
            $bytes = faJpeg();
            file_put_contents(
                $directory . DIRECTORY_SEPARATOR . 'Fotos' . DIRECTORY_SEPARATOR . $name,
                $bytes
            );
            $declared[] = [
                'tipo' => $slots[$indice],
                'archivo' => $name,
                'fecha_captura' => $captura,
                'bytes' => strlen($bytes),
                'checksum_sha256' => hash('sha256', $bytes),
            ];
        }
    }
    if (!in_array($evidencia, ['formulario', 'ambas'], true)) {
        return;
    }

    $file = $directory . DIRECTORY_SEPARATOR . 'Formulario' . DIRECTORY_SEPARATOR . 'formulario.json';
    if ($formularioRoto) {
        file_put_contents($file, '{ roto');
        return;
    }
    $manifest = [
        'generado_en' => '2026-09-05T02:00:00Z',
        'estructura' => [
            'poste_id' => $post['id'], 'codigo' => $post['codigo'],
            'estructura' => $post['estructura'], 'linea' => $post['linea'],
            'proyecto_id' => 2, 'fecha_inspeccion' => $fecha,
        ],
        'estado' => $actualizado !== null ? ['actualizado_en' => $actualizado] : [],
        'fotografias' => ['archivos' => $declared],
        'formulario' => ['comentarios' => 'DEL_RESPALDO'],
        'secciones_rst' => [],
    ];
    file_put_contents($file, json_encode($manifest, JSON_UNESCAPED_UNICODE));
}

function faRemove(string $path): void
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
        faRemove($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

/** Texto de un PDF compuesto sin comprimir. */
function faPdfText(PDO $connection, array $resolved, array $options): string
{
    $pdf = buildPostReport($connection, 1240, 2, $resolved, $options);
    $pdf->SetCompression(false);
    $raw = $pdf->Output('S');
    if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/', $raw, $matches) > 0) {
        return implode(' ', $matches[0]);
    }

    return '';
}

/** `$resolved` a mano, como el que entrega `resolveStructureResources()`. */
function faResolved(array $post, string $root): array
{
    $backup = inspectMobileBackup($post, $root, 0);
    $merged = mergeStructureImages([], [], $backup);

    return [
        'post' => $post,
        'details' => ['comentarios' => 'DE_LA_BD'],
        'rst' => [],
        'details_official' => ['comentarios' => 'DE_LA_BD'],
        'rst_official' => [],
        'images' => $merged['images'],
        'backup' => $backup,
        'missing' => $merged['missing'],
        'recoverable' => $merged['recoverable'],
        'source' => $merged['source'],
        'data_source' => 'official',
        'official_available' => $merged['official_available'],
        'backup_used' => $merged['backup_used'],
    ];
}

$connection = new PDO('sqlite::memory:');

try {
    // ═══ PROBLEMA 1: fecha y hora del formulario del respaldo ═══════════════

    // ── 8: el PDF Backup toma la fecha real del JSON ────────────────────────
    $raizFecha = $backupRoot . DIRECTORY_SEPARATOR . 'con_fecha';
    mkdir($raizFecha, 0777, true);
    faWrite($raizFecha, faPost(), 'ambas', '2026-08-31 16:40:00', ['2026-08-31 19:40:00']);
    $conFecha = faResolved(faPost(), $raizFecha);
    $bk = applyReportPhotoOptions($conFecha, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkFa(
        $bk['post']['fecha_inspeccion'] === '2026-08-31 16:40:00',
        '8: no se tomó `estructura.fecha_inspeccion` del respaldo.'
    );
    $texto = faPdfText($connection, $conFecha, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkFa(str_contains($texto, '31/08/2026'), '8: el PDF Backup no imprime la fecha del respaldo.');
    checkFa(str_contains($texto, '16:40:00'), '8: el PDF Backup no imprime la hora del respaldo.');
    checkFa(!str_contains($texto, 'Sin fecha'), '8: el PDF Backup sigue diciendo «Sin fecha».');

    // ── 9: sin `fecha_inspeccion`, la primera captura es la fecha real ──────
    $raizCaptura = $backupRoot . DIRECTORY_SEPARATOR . 'sin_fecha_con_capturas';
    mkdir($raizCaptura, 0777, true);
    faWrite($raizCaptura, faPost(), 'ambas', null, [
        '2026-08-30 07:15:00', '2026-08-30 09:45:00', '2026-08-30 11:20:00',
    ]);
    $sinFecha = faResolved(faPost(), $raizCaptura);
    $bkCaptura = applyReportPhotoOptions($sinFecha, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkFa(
        $bkCaptura['post']['fecha_inspeccion'] === '2026-08-30 07:15:00',
        '9: sin fecha explícita no se usó la primera captura (dio '
            . var_export($bkCaptura['post']['fecha_inspeccion'], true) . ').'
    );
    $fechas = reportPhotoBackupTimestamps($sinFecha['backup']['manifest']);
    checkFa($fechas['origen'] === 'primera_captura', '9: no informó de dónde salió la fecha.');
    checkFa($fechas['primera_captura'] === '2026-08-30 07:15:00', '9: primera captura incorrecta.');
    checkFa($fechas['ultima_captura'] === '2026-08-30 11:20:00', '9: última captura incorrecta.');
    checkFa($fechas['capturas'] === 3, '9: no contó las capturas.');
    $textoCaptura = faPdfText($connection, $sinFecha, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkFa(str_contains($textoCaptura, '30/08/2026'), '9: el PDF no imprime la fecha de la captura.');
    checkFa(!str_contains($textoCaptura, 'Sin fecha'), '9: el PDF dice «Sin fecha» habiendo capturas.');

    // Tercer nivel: sólo `estado.actualizado_en`.
    $raizGuardado = $backupRoot . DIRECTORY_SEPARATOR . 'solo_actualizado';
    mkdir($raizGuardado, 0777, true);
    faWrite($raizGuardado, faPost(), 'formulario', null, [], '2026-08-29T21:05:00-05:00');
    $soloGuardado = faResolved(faPost(), $raizGuardado);
    $bkGuardado = applyReportPhotoOptions($soloGuardado, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkFa(
        $bkGuardado['post']['fecha_inspeccion'] === '2026-08-29 21:05:00',
        '9: no se usó `estado.actualizado_en` como último recurso.'
    );

    // ── 10: sin ninguna fecha real NO se inventa nada ───────────────────────
    $raizNada = $backupRoot . DIRECTORY_SEPARATOR . 'sin_ninguna_fecha';
    mkdir($raizNada, 0777, true);
    faWrite($raizNada, faPost(), 'formulario', null, []);
    $nada = faResolved(faPost(), $raizNada);
    $bkNada = applyReportPhotoOptions($nada, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkFa(
        !array_key_exists('fecha_inspeccion', $bkNada['photo_options'] ?? [])
            && $bkNada['post']['fecha_inspeccion'] === faPost()['fecha_inspeccion'],
        '10: se sobrescribió la fecha sin tener ninguna del respaldo.'
    );
    $fechasNada = reportPhotoBackupTimestamps($nada['backup']['manifest']);
    checkFa($fechasNada['fecha_inspeccion'] === null, '10: inventó una fecha.');
    checkFa($fechasNada['origen'] === null, '10: inventó el origen de la fecha.');
    // `generado_en` existe en ese manifiesto y NO debe usarse: dice cuándo se
    // copió el respaldo, no cuándo se inspeccionó.
    checkFa(
        ($nada['backup']['manifest']['generado_en'] ?? '') !== ''
            && $fechasNada['fecha_inspeccion'] === null,
        '10: se usó `generado_en` como fecha de inspección.'
    );
    checkFa(
        $fechasNada['fecha_inspeccion'] !== date('Y-m-d H:i:s'),
        '10: usó la fecha de hoy.'
    );

    // ── 11: NAS no cambia ───────────────────────────────────────────────────
    $nas = applyReportPhotoOptions($conFecha, ['photo_source' => REPORT_PHOTO_SOURCE_CURRENT]);
    checkFa(
        $nas['post']['fecha_inspeccion'] === '2026-09-01 08:15:00',
        '11: NAS tomó la fecha del respaldo.'
    );
    checkFa($nas['details'] === ['comentarios' => 'DE_LA_BD'], '11: NAS tomó el formulario del respaldo.');
    $textoNas = faPdfText($connection, $conFecha, ['photo_source' => REPORT_PHOTO_SOURCE_CURRENT]);
    checkFa(str_contains($textoNas, '01/09/2026'), '11: el PDF NAS no imprime la fecha de la BD.');
    checkFa(str_contains($textoNas, 'DE_LA_BD'), '11: el PDF NAS no trae el formulario de la BD.');
    checkFa(!str_contains($textoNas, 'DEL_RESPALDO'), '11: el PDF NAS trajo el formulario del respaldo.');

    // ── 12: sin fallback Backup -> NAS ──────────────────────────────────────
    checkFa(str_contains($texto, 'DEL_RESPALDO'), '12: el PDF Backup no trae el formulario del respaldo.');
    checkFa(!str_contains($texto, 'DE_LA_BD'), '12: el PDF Backup mostró datos de la BD.');
    checkFa($bk['official_available'] === 0, '12: se colaron fotografías del NAS en modo Backup.');

    // ═══ PROBLEMA 2: avance por evidencia ═══════════════════════════════════

    $raizAvance = $backupRoot . DIRECTORY_SEPARATOR . 'avance';
    mkdir($raizAvance, 0777, true);
    // 1 completa, 2 sólo formulario, 3 sólo fotos, 4 formulario roto con fotos,
    // 5 imagen real con nombre no mapeado, 6 sin nada.
    faWrite($raizAvance, faPost('01', 1), 'ambas', '2026-08-31 16:40:00', ['2026-08-31 19:40:00']);
    faWrite($raizAvance, faPost('02', 2), 'formulario', '2026-08-31 16:40:00', []);
    faWrite($raizAvance, faPost('03', 3), 'fotos', null, ['2026-08-31 19:40:00']);
    faWrite($raizAvance, faPost('04', 4), 'ambas', null, ['2026-08-31 19:40:00'], null, true);
    $dir05 = $raizAvance . '/Mantenimiento_LT_60kV/Inspeccion_Piso/Linea_L-6021/Estructura_05/Fotos';
    mkdir($dir05, 0777, true);
    file_put_contents($dir05 . DIRECTORY_SEPARATOR . 'IMG_9999.jpg', faJpeg());

    $index = backupProgressIndex($raizAvance)['index'];
    $estados = [];
    foreach (['01', '02', '03', '04', '05', '06'] as $indice => $estructura) {
        $estados[$estructura] = backupProgressStructureState(
            faPost($estructura, $indice + 1),
            $index
        );
    }

    // 1: formulario + fotos -> sincronizada y respaldada
    checkFa($estados['01']['estado'] === 'sincronizada', '1: con formulario y fotos no quedó sincronizada.');
    checkFa($estados['01']['inspeccionada'], '1: con formulario y fotos no cuenta como respaldada.');
    // 2: sólo formulario -> en proceso y respaldada
    checkFa($estados['02']['estado'] === 'en_proceso', '2: sólo formulario no quedó en proceso.');
    checkFa($estados['02']['inspeccionada'], '2: sólo formulario no cuenta como respaldada.');
    // 3: sólo fotos -> en proceso y respaldada
    checkFa($estados['03']['estado'] === 'en_proceso', '3: sólo fotos no quedó en proceso.');
    checkFa($estados['03']['inspeccionada'], '3: sólo fotos no cuenta como respaldada.');
    // 4: sin evidencia -> pendiente
    checkFa($estados['06']['estado'] === 'pendiente', '4: sin evidencia no quedó pendiente.');
    checkFa(!$estados['06']['inspeccionada'], '4: sin evidencia cuenta como respaldada.');
    // 5: formulario corrupto con fotos -> respaldada, en proceso, NUNCA sincronizada
    checkFa($estados['04']['inspeccionada'], '5: formulario corrupto con fotos no cuenta como respaldada.');
    checkFa($estados['04']['estado'] === 'en_proceso', '5: formulario corrupto no quedó en proceso.');
    checkFa(!$estados['04']['sincronizada'], '5: un formulario corrupto llegó a sincronizada.');
    // 6: imagen real con nombre no mapeado -> evidencia física
    checkFa($estados['05']['inspeccionada'], '6: una imagen real no mapeada no cuenta como evidencia.');
    checkFa($estados['05']['archivos_presentes'] === 1, '6: no contó el archivo de imagen real.');
    checkFa($estados['05']['fotos_presentes'] === 0, '6: atribuyó un slot a un nombre que no lo tiene.');
    checkFa($estados['05']['estado'] === 'en_proceso', '6: evidencia física suelta no quedó en proceso.');

    // ── La identidad de los contadores ──────────────────────────────────────
    $respaldadas = 0;
    $sincronizadas = 0;
    $enProceso = 0;
    $pendientes = 0;
    foreach ($estados as $estado) {
        $respaldadas += $estado['inspeccionada'] ? 1 : 0;
        $sincronizadas += $estado['estado'] === 'sincronizada' ? 1 : 0;
        $enProceso += $estado['estado'] === 'en_proceso' ? 1 : 0;
        $pendientes += $estado['estado'] === 'pendiente' ? 1 : 0;
    }
    checkFa($respaldadas === 5, 'avance: se esperaban 5 respaldadas (dio ' . $respaldadas . ').');
    checkFa($sincronizadas === 1, 'avance: se esperaba 1 sincronizada (dio ' . $sincronizadas . ').');
    checkFa($enProceso === 4, 'avance: se esperaban 4 en proceso (dio ' . $enProceso . ').');
    checkFa($pendientes === 1, 'avance: se esperaba 1 pendiente (dio ' . $pendientes . ').');
    checkFa(
        $respaldadas === $sincronizadas + $enProceso,
        'avance: respaldadas != sincronizadas + en_proceso.'
    );
    checkFa(
        $respaldadas + $pendientes === count($estados),
        'avance: respaldadas + pendientes != total del padrón.'
    );
    checkFa($respaldadas <= count($estados), 'avance: respaldadas supera el padrón.');
    // Y el avance por evidencia es MAYOR que el exigente: es el punto del cambio.
    checkFa(
        $respaldadas > $sincronizadas,
        'avance: el avance por evidencia no supera al de sincronización completa.'
    );
} finally {
    faRemove($fixtureRoot);
}

echo $failures === []
    ? "OK: {$checks} comprobaciones de fecha del respaldo y avance por evidencia.\n"
    : "FALLOS ({$checks} comprobaciones):\n - " . implode("\n - ", $failures) . "\n";
exit($failures === [] ? 0 : 1);
