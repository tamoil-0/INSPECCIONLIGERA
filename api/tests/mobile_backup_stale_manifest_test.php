<?php
declare(strict_types=1);

/**
 * Regresión: el respaldo se quedaba en 2 fotografías de 26.
 *
 * ## Qué pasaba
 *
 * `inspectMobileBackupDirectory()` recorría ÚNICAMENTE
 * `formulario.json → fotografias.archivos`, y descartaba toda entrada cuyo
 * `bytes` no coincidiera con el archivo (`size_mismatch`). En L-6028 /
 * Estructura_01 el manifiesto se generó a las 16:5x y después 24 fotografías se
 * volvieron a sincronizar con otro tamaño; sólo `06_Mensulas.jpg` y
 * `25_Puesta_a_tierra_2.jpg` conservaban sus bytes originales. Resultado:
 *
 *     26 JPG en disco  ->  status=BACKUP_PARCIAL, photos=2, declared_types=21
 *
 * Además, los 5 archivos que el manifiesto no declaraba eran invisibles,
 * porque nunca se miraba la carpeta.
 *
 * ## Qué se comprueba aquí
 *
 * Que el inventario FÍSICO de `Fotos/` manda para decidir qué fotografías
 * existen, que el prefijo numérico basta para mapear el slot, que los
 * metadatos del manifiesto sólo se usan cuando describen el archivo que hay, y
 * que el modo respaldo sigue sin caer al NAS.
 *
 * Sólo lectura sobre un árbol temporal. No toca la base, el almacén ni el NAS.
 *
 *   php api/tests/mobile_backup_stale_manifest_test.php
 */

require_once __DIR__ . '/../services/StructureRecoveryService.php';

$GLOBALS['app_environment']['MOBILE_BACKUP_STABILITY_SECONDS'] = '0';
$failures = [];
$checks = 0;

function checkStale(bool $condition, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

/** Los 26 archivos reales de L-6028 / Estructura_01 y su slot canónico. */
function staleFiles(): array
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

/** JPEG cuadrado de `$px` px, para tener dos tamaños distinguibles. */
function staleJpeg(int $px): string
{
    $canvas = imagecreatetruecolor($px, $px);
    imagefilledrectangle($canvas, 0, 0, $px, $px, (int) imagecolorallocate($canvas, 120, 90, 40));
    ob_start();
    imagejpeg($canvas, null, 92);
    $bytes = (string) ob_get_clean();
    imagedestroy($canvas);

    return $bytes;
}

function stalePost(): array
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

function staleRemove(string $path): void
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
        staleRemove($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecoing_stale_' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);

try {
    // Árbol real de producción, con las carpetas intermedias del share.
    $directory = $root . DIRECTORY_SEPARATOR . 'Mantenimiento_LT_60kV'
        . DIRECTORY_SEPARATOR . 'Inspeccion_Piso'
        . DIRECTORY_SEPARATOR . 'Linea_L-6028'
        . DIRECTORY_SEPARATOR . 'Estructura_01';
    $photos = $directory . DIRECTORY_SEPARATOR . 'Fotos';
    mkdir($photos, 0777, true);
    mkdir($directory . DIRECTORY_SEPARATOR . 'Formulario', 0777, true);
    mkdir($directory . DIRECTORY_SEPARATOR . 'Resumen', 0777, true);

    $vieja = staleJpeg(16);
    $nueva = staleJpeg(48);
    // Las dos que en producción conservaban su versión original.
    $conservadas = ['06_Mensulas.jpg', '25_Puesta_a_tierra_2.jpg'];
    // Y las cinco que el manifiesto no llegó a declarar.
    $sinDeclarar = [
        '22_Conductor.jpg',
        '23_Ferreteria_del_conductor.jpg',
        '26_Retenida.jpg',
        '27_Faja_de_servidumbre.jpg',
        '28_Ubicacion_acceso.jpg',
    ];

    $files = staleFiles();
    $declared = [];
    foreach ($files as $name => $type) {
        $keeps = in_array($name, $conservadas, true);
        file_put_contents($photos . DIRECTORY_SEPARATOR . $name, $keeps ? $vieja : $nueva);
        if (in_array($name, $sinDeclarar, true)) {
            continue;
        }
        $declared[] = [
            'tipo' => $type,
            'archivo' => $name,
            'fecha_captura' => '2026-08-31 16:50:00',
            'utm_este' => 392541.2,
            'utm_norte' => 8302114.55,
            'zona' => '19L',
            // Tamaño de la versión vieja: sólo cuadra en las conservadas.
            'bytes' => strlen($vieja),
            'checksum_sha256' => hash('sha256', 'version-vieja-' . $name),
        ];
    }
    file_put_contents(
        $directory . DIRECTORY_SEPARATOR . 'Formulario' . DIRECTORY_SEPARATOR . 'formulario.json',
        json_encode([
            'generado_en' => '2026-08-31T16:53:00-05:00',
            'estructura' => [
                'poste_id' => 1240, 'codigo' => 'L-6028-01', 'estructura' => '01',
                'linea' => 'L-6028', 'proyecto_id' => 2,
            ],
            'fotografias' => ['archivos' => $declared],
            'formulario' => ['comentarios' => 'FORMULARIO DEL RESPALDO'],
            'secciones_rst' => [['seccion' => 'conductores_fase', 'atributo' => 'hebras_rotas', 'fase' => 'R']],
        ], JSON_UNESCAPED_UNICODE)
    );

    checkStale(count(glob($photos . DIRECTORY_SEPARATOR . '*.jpg') ?: []) === 26, 'A: el fixture no tiene 26 JPG.');
    checkStale(count($declared) === 21, 'A: el manifiesto de prueba no declara 21 tipos.');

    // ── A: las 26 se detectan aunque el manifiesto esté desfasado ───────────
    $backup = inspectMobileBackup(stalePost(), $root, 0);
    checkStale(
        count($backup['photos']) === 26,
        'A: se detectaron ' . count($backup['photos']) . ' de 26 con el manifiesto desfasado.'
    );
    checkStale($backup['status'] === RECOVERY_BACKUP_AVAILABLE, 'A: el respaldo no quedó disponible.');
    checkStale($backup['missing'] === [], 'A: informó faltantes con los 26 archivos en disco.');
    checkStale(
        count($backup['declared_types']) === 26,
        'A: los slots vistos en disco no entraron en los declarados; la mezcla los descartaría.'
    );
    foreach ($files as $name => $type) {
        checkStale(
            isset($backup['photos'][$type])
                && basename((string) $backup['photos'][$type]['absolute']) === $name,
            'A: el slot ' . $type . ' no quedó apuntando a ' . $name . '.'
        );
    }

    // ── B: 09 y 10 no existen y no se inventan ─────────────────────────────
    checkStale(!isset($backup['photos']['atiescalamiento']), 'B: se inventó el slot 09.');
    checkStale(!isset($backup['photos']['otros']), 'B: se inventó el slot 10.');

    // ── C: los 5 no declarados salen por el prefijo numérico ───────────────
    foreach ($sinDeclarar as $name) {
        checkStale(
            isset($backup['photos'][$files[$name]]),
            'C: el archivo no declarado ' . $name . ' no se mapeó a su slot.'
        );
    }

    // ── D: el contrato de cada entrada no cambia ───────────────────────────
    foreach (['type', 'absolute', 'root', 'bytes', 'checksum',
        'fecha_captura', 'utm_este', 'utm_norte', 'zona'] as $campo) {
        checkStale(
            array_key_exists($campo, $backup['photos']['placa']),
            'D: la entrada perdió el campo ' . $campo . '.'
        );
    }
    foreach (['_declarado', '_mtime'] as $interno) {
        checkStale(
            !array_key_exists($interno, $backup['photos']['placa']),
            'D: se filtró el campo interno ' . $interno . '.'
        );
    }
    checkStale(
        $backup['photos']['placa']['bytes'] === strlen($nueva),
        'D: el tamaño no es el del archivo que hay en disco.'
    );

    // ── E: metadatos sólo cuando el manifiesto describe ESE archivo ────────
    checkStale(
        ($backup['photos']['mensulas']['checksum'] ?? '') !== '',
        'E: se perdió el checksum de la fotografía que el manifiesto sí describe.'
    );
    checkStale(
        $backup['photos']['mensulas']['fecha_captura'] === '2026-08-31 16:50:00',
        'E: no se usó la fecha de captura del manifiesto donde era válida.'
    );
    checkStale(
        ($backup['photos']['placa']['checksum'] ?? null) === '',
        'E: se arrastró el checksum de una versión que ya no es el archivo.'
    );
    checkStale(
        $backup['photos']['placa']['utm_este'] === null,
        'E: se arrastraron coordenadas de una versión que ya no es el archivo.'
    );
    checkStale(
        in_array('manifest_outdated', $backup['warnings'], true),
        'E: no se avisó de que el manifiesto está desfasado.'
    );
    // `backupPhotoIsUsable()` con verificación estricta no debe aceptar un
    // archivo cuyo checksum no se conoce... y sí el que sigue verificado.
    checkStale(
        backupPhotoIsUsable($backup['photos']['placa'], false),
        'E: una fotografía presente y legible no se considera utilizable.'
    );

    // ── F: en modo respaldo el PDF ve esas 26 y nada del NAS ───────────────
    $merged = mergeStructureImages([], [], $backup);
    checkStale(count($merged['images']) === 26, 'F: la resolución de respaldo no entregó 26.');
    checkStale(
        array_unique(array_column($merged['images'], 'source')) === ['backup_qfile'],
        'F: se colaron fotografías que no vienen del respaldo.'
    );
    // Con una fila oficial válida para `placa`, el modo respaldo sigue usando
    // la del respaldo: nunca completa desde el NAS.
    $officialDir = $root . DIRECTORY_SEPARATOR . 'oficial';
    mkdir($officialDir, 0777, true);
    $officialFile = $officialDir . DIRECTORY_SEPARATOR . 'placa_oficial.jpg';
    file_put_contents($officialFile, staleJpeg(32));
    $conOficial = mergeStructureImages([[
        'nombre_foto' => 'placa',
        'ruta_archivo' => null,
        'ruta_resuelta' => $officialFile,
        'fecha_captura' => '2026-09-01 08:00:00',
    ]], ['placa'], $backup);
    checkStale(
        count($conOficial['images']) === 26 && $conOficial['official_available'] === 1,
        'F: la mezcla normal dejó de preferir la fila oficial válida.'
    );
    $soloRespaldo = mergeStructureImages([], [], $backup);
    $rutaPlaca = null;
    foreach ($soloRespaldo['images'] as $image) {
        if ($image['nombre_foto'] === 'placa') {
            $rutaPlaca = str_replace('\\', '/', (string) $image['ruta_resuelta']);
        }
    }
    checkStale(
        $rutaPlaca !== null && str_ends_with($rutaPlaca, '/Fotos/02_Placa.jpg'),
        'F: en modo respaldo la placa no salió del respaldo.'
    );

    // ── G: el prefijo numérico manda sobre la etiqueta ─────────────────────
    checkStale(mobileBackupSlotFromFileName('01_Panoramica.jpg') === 'foto_panoramica', 'G: 01 mal mapeado.');
    checkStale(mobileBackupSlotFromFileName('05_Base_torre.jpg') === 'base_torre', 'G: etiqueta distinta en 05.');
    checkStale(mobileBackupSlotFromFileName('09_Malla_antiescalamiento.jpg') === 'atiescalamiento', 'G: 09 mal mapeado.');
    checkStale(mobileBackupSlotFromFileName('25_Puesta_a_tierra_2.jpg') === 'puesta_tierra_2', 'G: 25 mal mapeado.');
    checkStale(mobileBackupSlotFromFileName('28_Ubicacion_acceso.jpg') === 'ubicacion_acceso', 'G: 28 mal mapeado.');
    checkStale(mobileBackupSlotFromFileName('99_Loquesea.jpg') === null, 'G: aceptó un prefijo fuera del catálogo.');
    checkStale(mobileBackupSlotFromFileName('Sin_prefijo.jpg') === null, 'G: dedujo un slot de un nombre cualquiera.');
    // Sin prefijo vale el nombre canónico del almacén oficial, con la
    // coincidencia más larga para no confundir las dos puestas a tierra.
    checkStale(mobileBackupSlotFromFileName('puesta_tierra_2_ab12.jpg') === 'puesta_tierra_2', 'G: canónico 2 mal mapeado.');
    checkStale(mobileBackupSlotFromFileName('puesta_tierra_ab12.jpg') === 'puesta_tierra', 'G: confundió las dos puestas a tierra.');

    // ── H: basura en la carpeta no se cuenta ni rompe nada ────────────────
    file_put_contents($photos . DIRECTORY_SEPARATOR . 'Thumbs.db', 'no es una imagen');
    file_put_contents($photos . DIRECTORY_SEPARATOR . '30_Inexistente.jpg', $nueva);
    file_put_contents($photos . DIRECTORY_SEPARATOR . '.parcial.jpg', $nueva);
    file_put_contents($photos . DIRECTORY_SEPARATOR . '02_Placa.jpg.tmp', $nueva);
    file_put_contents($photos . DIRECTORY_SEPARATOR . '07_Crucetas.txt', 'texto');
    $conBasura = inspectMobileBackup(stalePost(), $root, 0);
    checkStale(
        count($conBasura['photos']) === 26,
        'H: la basura de la carpeta alteró el recuento (' . count($conBasura['photos']) . ').'
    );
    checkStale($conBasura['status'] === RECOVERY_BACKUP_AVAILABLE, 'H: la basura cambió el estado.');

    // ── I: un manifiesto de otra estructura sigue rechazándose ────────────
    $ajeno = $root . DIRECTORY_SEPARATOR . 'ajeno';
    $ajenoDir = $ajeno . DIRECTORY_SEPARATOR . 'Linea_L-6028' . DIRECTORY_SEPARATOR . 'Estructura_01';
    mkdir($ajenoDir . DIRECTORY_SEPARATOR . 'Fotos', 0777, true);
    mkdir($ajenoDir . DIRECTORY_SEPARATOR . 'Formulario', 0777, true);
    file_put_contents($ajenoDir . DIRECTORY_SEPARATOR . 'Fotos' . DIRECTORY_SEPARATOR . '01_Panoramica.jpg', $nueva);
    file_put_contents(
        $ajenoDir . DIRECTORY_SEPARATOR . 'Formulario' . DIRECTORY_SEPARATOR . 'formulario.json',
        json_encode([
            'generado_en' => '2026-08-31T16:53:00-05:00',
            'estructura' => [
                'poste_id' => 999999, 'codigo' => 'L-6028-01', 'estructura' => '01',
                'linea' => 'L-6028', 'proyecto_id' => 2,
            ],
            'fotografias' => ['archivos' => []],
        ], JSON_UNESCAPED_UNICODE)
    );
    $suplantado = inspectMobileBackup(stalePost(), $ajeno, 0);
    checkStale($suplantado['photos'] === [], 'I: se aceptó el respaldo de otra estructura.');
    checkStale(
        in_array('identity_mismatch', $suplantado['warnings'], true),
        'I: no se avisó de la identidad que no coincide.'
    );
} finally {
    staleRemove($root);
}

echo $failures === []
    ? "OK: {$checks} comprobaciones del inventario fisico del respaldo.\n"
    : "FALLOS ({$checks} comprobaciones):\n - " . implode("\n - ", $failures) . "\n";
exit($failures === [] ? 0 : 1);
