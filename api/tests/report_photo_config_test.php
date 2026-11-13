<?php
declare(strict_types=1);

/**
 * Persistencia de la configuración de impresión, sin base de datos.
 *
 * Se apunta la raíz `report_config` a un directorio temporal y se comprueba lo
 * que garantiza la pantalla «Configurar imágenes»:
 *
 *   · sin configuración se imprime TODO;
 *   · la selección sobrevive a una relectura (que es lo que ocurre al recargar
 *     la página o volver al día siguiente);
 *   · NAS y Backup se guardan por separado y no se pisan;
 *   · sólo se excluye lo que se desmarcó de verdad, así que una fotografía que
 *     llegue después de configurar se sigue imprimiendo;
 *   · nada que venga del cliente puede llegar al disco como ruta.
 *
 *   php api/tests/report_photo_config_test.php
 */

// Las raíces de almacenamiento se memoizan al cargar `config/app.php`, así que
// la de la prueba se declara antes del require. Ver report_photo_pdf_test.php.
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecoing_cfg_' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
putenv('REPORT_CONFIG_STORAGE_PATH=' . $root);

require_once __DIR__ . '/../services/ReportPhotoConfigService.php';

$failures = [];
$checks = 0;

function checkConfig(bool $condition, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

function configRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $target = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($target) ? configRemoveTree($target) : unlink($target);
    }
    rmdir($path);
}

try {
    $post = 1240;
    $project = 2;

    // ── A: sin configuración se imprime todo ────────────────────────────────
    checkConfig(
        reportPhotoConfigExcluded($post, $project, REPORT_PHOTO_SOURCE_CURRENT) === [],
        'A: una estructura sin configurar ya excluye fotografías.'
    );
    checkConfig(
        reportPhotoConfigRead($post, $project)['existe'] === false,
        'A: dice que hay configuración donde no hay archivo.'
    );
    checkConfig(
        reportPhotoOptionsAreDefault(reportPhotoApplySavedConfig([], $post, $project)),
        'A: sin configuración el reporte deja de ser el estándar.'
    );

    // ── B: guardar NAS excluye exactamente lo desmarcado ────────────────────
    reportPhotoConfigWrite($post, $project, REPORT_PHOTO_SOURCE_CURRENT, [
        'aisladores_fase_r_adelante',
        'ferreteria_fase_t',
    ], 'admin01');
    $excluidosNas = reportPhotoConfigExcluded($post, $project, REPORT_PHOTO_SOURCE_CURRENT);
    checkConfig(
        $excluidosNas === ['aisladores_fase_r_adelante', 'ferreteria_fase_t'],
        'B: no guardó exactamente los dos slots desmarcados.'
    );

    // ── C: Backup no se ve afectado por lo guardado en NAS ──────────────────
    checkConfig(
        reportPhotoConfigExcluded($post, $project, REPORT_PHOTO_SOURCE_BACKUP) === [],
        'C: guardar NAS cambió la selección de Backup.'
    );
    reportPhotoConfigWrite($post, $project, REPORT_PHOTO_SOURCE_BACKUP, ['placa'], 'admin01');
    checkConfig(
        reportPhotoConfigExcluded($post, $project, REPORT_PHOTO_SOURCE_BACKUP) === ['placa'],
        'C: no guardó la selección de Backup.'
    );
    checkConfig(
        reportPhotoConfigExcluded($post, $project, REPORT_PHOTO_SOURCE_CURRENT) === $excluidosNas,
        'C: guardar Backup pisó la selección de NAS.'
    );

    // ── D: la selección persiste; es lo que se ve al recargar ───────────────
    $releido = reportPhotoConfigRead($post, $project);
    checkConfig($releido['existe'] === true, 'D: no detecta el archivo ya escrito.');
    checkConfig(
        $releido['fuentes'][REPORT_PHOTO_SOURCE_CURRENT]['excluidos'] === $excluidosNas
            && $releido['fuentes'][REPORT_PHOTO_SOURCE_BACKUP]['excluidos'] === ['placa'],
        'D: la configuración no sobrevivió a la relectura.'
    );
    checkConfig($releido['actualizado_por'] === 'admin01', 'D: no registró quién configuró.');
    checkConfig(is_string($releido['actualizado_en']), 'D: no registró cuándo se configuró.');

    // ── E: una fotografía que llegue después se imprime ─────────────────────
    // Se guarda lo desmarcado, nunca el complemento del catálogo: los slots que
    // hoy no tienen archivo no aparecen en la lista y por tanto se imprimirán.
    $nuncaExcluidos = array_diff(photoTypes(), $excluidosNas);
    checkConfig(
        in_array('base_torre', $nuncaExcluidos, true)
            && in_array('foto_panoramica', $nuncaExcluidos, true),
        'E: quedó excluido un slot que nadie desmarcó.'
    );
    checkConfig(
        count($excluidosNas) === 2,
        'E: guardó más exclusiones de las que se pidieron.'
    );

    // ── F: la configuración llega al reporte por sí sola ────────────────────
    $opciones = reportPhotoApplySavedConfig(
        ['photo_source' => REPORT_PHOTO_SOURCE_CURRENT],
        $post,
        $project
    );
    checkConfig($opciones['excluded_slots'] === $excluidosNas, 'F: el reporte NAS no aplica su configuración.');
    checkConfig($opciones['included_slots'] === null, 'F: inventó una lista de incluidas.');
    $opcionesBackup = reportPhotoApplySavedConfig(
        ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP],
        $post,
        $project
    );
    checkConfig($opcionesBackup['excluded_slots'] === ['placa'], 'F: el reporte Backup no aplica su configuración.');

    // Una exclusión explícita del llamador gana y no se lee el disco.
    $impuesta = reportPhotoApplySavedConfig(
        ['photo_source' => REPORT_PHOTO_SOURCE_CURRENT, 'excluded_slots' => ['retenida']],
        $post,
        $project
    );
    checkConfig($impuesta['excluded_slots'] === ['retenida'], 'F: una exclusión explícita no gana sobre lo guardado.');

    // ── G: `auto` conserva el comportamiento histórico ──────────────────────
    checkConfig(
        reportPhotoConfigExcluded($post, $project, REPORT_PHOTO_SOURCE_AUTO) === [],
        'G: la resolución estándar quedó afectada por la configuración.'
    );
    $autoOptions = reportPhotoApplySavedConfig([], $post, $project);
    checkConfig(
        reportPhotoOptionsAreDefault($autoOptions),
        'G: una peticion sin parametros dejo de ser el reporte estandar.'
    );
    $lanzo = false;
    try {
        reportPhotoConfigWrite($post, $project, REPORT_PHOTO_SOURCE_AUTO, [], 'admin01');
    } catch (InvalidArgumentException) {
        $lanzo = true;
    }
    checkConfig($lanzo, 'G: dejó configurar la resolución estándar.');

    // ── H: seguridad. Nada del cliente puede llegar al disco como ruta ──────
    reportPhotoConfigWrite($post, $project, REPORT_PHOTO_SOURCE_CURRENT, [
        '../../etc/passwd',
        '/share/CACHEDEV1_DATA/foto.jpg',
        'C:\\Windows\\win.ini',
        'placa',
        'placa',
        'slot_que_no_existe',
    ], 'admin01');
    checkConfig(
        reportPhotoConfigExcluded($post, $project, REPORT_PHOTO_SOURCE_CURRENT) === ['placa'],
        'H: aceptó un slot que no está en el catálogo.'
    );
    $file = reportPhotoConfigFile($post, $project);
    checkConfig(
        str_contains(str_replace('\\', '/', $file), '/proyecto_2/poste_1240.json'),
        'H: el archivo de configuración no está donde debe.'
    );
    checkConfig(
        !str_contains((string) @file_get_contents($file), 'passwd'),
        'H: se escribió en disco algo que vino del cliente sin validar.'
    );

    // ── I: un archivo corrupto no impide imprimir ───────────────────────────
    file_put_contents($file, '{esto no es json');
    checkConfig(
        reportPhotoConfigExcluded($post, $project, REPORT_PHOTO_SOURCE_CURRENT) === [],
        'I: una configuración corrupta dejaría el reporte sin fotografías.'
    );

    // ── J: quitar todas es una decisión válida y se respeta ─────────────────
    reportPhotoConfigWrite($post, $project, REPORT_PHOTO_SOURCE_BACKUP, photoTypes(), 'admin01');
    checkConfig(
        count(reportPhotoConfigExcluded($post, $project, REPORT_PHOTO_SOURCE_BACKUP)) === count(photoTypes()),
        'J: no se pudo excluir todo el catálogo.'
    );
} finally {
    configRemoveTree($root);
}

echo $failures === []
    ? "OK: {$checks} comprobaciones de la configuracion de impresion.\n"
    : "FALLOS ({$checks} comprobaciones):\n - " . implode("\n - ", $failures) . "\n";
exit($failures === [] ? 0 : 1);
