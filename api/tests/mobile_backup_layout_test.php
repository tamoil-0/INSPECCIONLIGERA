<?php
declare(strict_types=1);

/**
 * Resolución del respaldo del NAS, sin base de datos y sin tocar el NAS.
 *
 * Reproduce en un directorio temporal el árbol real de producción:
 *
 *     <raiz>/Mantenimiento_LT_60kV/Inspeccion_Piso/Linea_L-6028/Estructura_01/
 *         Formulario/formulario.json
 *         Fotos/01_Panoramica.jpg ... 28_Ubicacion_acceso.jpg
 *
 * con los 26 nombres de archivo reales de esa estructura (faltan el 09 y el 10,
 * igual que en el NAS), y comprueba que:
 *
 *   · la carpeta se encuentra aunque el árbol no tenga `Proyecto_<id>` y meta
 *     dos niveles propios del share antes de la línea;
 *   · el mapeo archivo -> slot lo da el manifiesto, no el orden ni el número;
 *   · una estructura sin respaldo devuelve 0 y no lanza;
 *   · un manifiesto de otra estructura se rechaza;
 *   · el árbol antiguo del móvil (`Proyecto_<id>/...`) sigue resolviendo.
 *
 *   php api/tests/mobile_backup_layout_test.php
 */

require_once __DIR__ . '/../services/StructureRecoveryService.php';

$GLOBALS['app_environment']['MOBILE_BACKUP_STABILITY_SECONDS'] = '0';
$failures = [];
$checks = 0;

function checkBackup(bool $condition, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

/**
 * Los 26 archivos reales de L-6028 / Estructura_01, con el slot al que
 * corresponden según el contrato de fotografías de la aplicación móvil.
 *
 * @return array<string, string> nombre de archivo => slot
 */
function backupRealFiles(): array
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
        // 09_Malla_antiescalamiento y 10_Otros no existen en esta estructura.
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

function backupTestPost(): array
{
    return [
        'id' => 4501,
        'proyecto_id' => 2,
        'codigo' => 'L-6028-01',
        'linea' => 'L-6028',
        // En producción viene con el cero delante, igual que la carpeta.
        'estructura' => '01',
        'ultima_limpieza_admin' => null,
    ];
}

/** JPEG real de 8x8: hace falta que `getimagesize()` lo acepte. */
function backupTestJpeg(int $seed): string
{
    $canvas = imagecreatetruecolor(8, 8);
    imagefilledrectangle($canvas, 0, 0, 8, 8, (int) imagecolorallocate($canvas, $seed % 255, 90, 140));
    ob_start();
    imagejpeg($canvas, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($canvas);

    return $bytes;
}

/**
 * Escribe una estructura de respaldo completa.
 *
 * @param string $suffix ruta relativa a la raíz, sin la carpeta de estructura
 */
function backupWriteStructure(
    string $root,
    string $suffix,
    array $post,
    array $files,
    ?array $identityOverride = null
): string {
    $directory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $suffix);
    $photos = $directory . DIRECTORY_SEPARATOR . 'Fotos';
    $form = $directory . DIRECTORY_SEPARATOR . 'Formulario';
    mkdir($photos, 0777, true);
    mkdir($form, 0777, true);
    mkdir($directory . DIRECTORY_SEPARATOR . 'Resumen', 0777, true);

    $declared = [];
    $seed = 0;
    foreach ($files as $name => $type) {
        $bytes = backupTestJpeg(++$seed * 7);
        file_put_contents($photos . DIRECTORY_SEPARATOR . $name, $bytes);
        $declared[] = [
            'tipo' => $type,
            'etiqueta' => ucfirst(str_replace('_', ' ', $type)),
            'archivo' => $name,
            'estado' => 'sincronizada',
            'fecha_captura' => '2026-08-31 19:40:00',
            'utm_este' => 392541.2,
            'utm_norte' => 8302114.55,
            'zona' => '19L',
            'bytes' => strlen($bytes),
            'checksum_sha256' => hash('sha256', $bytes),
        ];
    }

    // Mismo esquema que escribe la app móvil (servicio_exportacion_inspeccion).
    $manifest = [
        'generado_en' => '2026-08-31T22:54:00-05:00',
        'aplicacion' => 'ECOING Inspección Ligera',
        'estructura' => $identityOverride ?? [
            'poste_id' => $post['id'],
            'codigo' => $post['codigo'],
            'estructura' => $post['estructura'],
            'linea' => $post['linea'],
            'proyecto_id' => $post['proyecto_id'],
            'proyecto' => 'Mantenimiento LT 60 kV',
            'fecha_inspeccion' => '2026-08-31 16:40:00',
        ],
        'fotografias' => [
            'esperadas' => count(photoTypes()),
            'capturadas' => count($declared),
            'omitidas' => [
                ['tipo' => 'atiescalamiento', 'etiqueta' => 'Malla antiescalamiento'],
                ['tipo' => 'otros', 'etiqueta' => 'Otros'],
            ],
            'archivos' => $declared,
        ],
        'formulario' => ['comentarios' => 'respaldo del NAS'],
        'secciones_rst' => [],
    ];
    file_put_contents(
        $form . DIRECTORY_SEPARATOR . 'formulario.json',
        json_encode($manifest, JSON_UNESCAPED_UNICODE)
    );

    return $directory;
}

function backupRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $target = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($target) ? backupRemoveTree($target) : unlink($target);
    }
    rmdir($path);
}

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecoing_nas_' . bin2hex(random_bytes(6));
mkdir($temporary, 0777, true);

try {
    $post = backupTestPost();
    $files = backupRealFiles();

    // ── A: el árbol real del NAS ────────────────────────────────────────────
    $root = $temporary . DIRECTORY_SEPARATOR . 'elpu';
    mkdir($root, 0777, true);
    // Otras líneas y otra estructura, para que la búsqueda tenga que elegir.
    backupWriteStructure(
        $root,
        'Mantenimiento_LT_60kV/Inspeccion_Piso/Linea_L-6021/Estructura_299',
        ['id' => 9, 'proyecto_id' => 2, 'codigo' => 'L-6021-299', 'linea' => 'L-6021', 'estructura' => '299'],
        ['01_Panoramica.jpg' => 'foto_panoramica']
    );
    $real = backupWriteStructure(
        $root,
        'Mantenimiento_LT_60kV/Inspeccion_Piso/Linea_L-6028/Estructura_01',
        $post,
        $files
    );

    $directories = mobileBackupStructureDirectories($post, $root);
    checkBackup($directories !== [], 'A: no encontró la carpeta de respaldo del árbol real del NAS.');
    checkBackup(
        count($directories) === 1 && (realpath($real) ?: $real) === $directories[0],
        'A: no resolvió exactamente la carpeta de esta estructura.'
    );

    $backup = inspectMobileBackup($post, $root, 0);
    checkBackup($backup['status'] === RECOVERY_BACKUP_AVAILABLE, 'A: el respaldo real no quedó como disponible.');
    checkBackup(count($backup['photos']) === 26, 'A: no detectó las 26 fotografías del respaldo.');
    checkBackup(count($backup['declared_types']) === 26, 'A: no leyó los 26 tipos declarados.');
    checkBackup($backup['missing'] === [], 'A: informó faltantes donde el manifiesto y los archivos coinciden.');

    // ── B: el mapeo archivo -> slot sale del manifiesto ─────────────────────
    foreach ($files as $name => $type) {
        $photo = $backup['photos'][$type] ?? null;
        checkBackup(
            is_array($photo) && basename((string) $photo['absolute']) === $name,
            'B: el slot ' . $type . ' no quedó apuntando a ' . $name . '.'
        );
    }
    // Los dos slots sin archivo no aparecen inventados por el número.
    checkBackup(!isset($backup['photos']['atiescalamiento']), 'B: inventó una foto para el slot 09.');
    checkBackup(!isset($backup['photos']['otros']), 'B: inventó una foto para el slot 10.');

    // ── C: en modo respaldo el PDF ve esas 26 y nada más ────────────────────
    $merged = mergeStructureImages([], [], $backup);
    checkBackup(count($merged['images']) === 26, 'C: la resolución de respaldo no entregó 26 fotografías.');
    checkBackup(
        array_unique(array_column($merged['images'], 'source')) === ['backup_qfile'],
        'C: se colaron fotografías que no vienen del respaldo.'
    );
    $slots = array_column($merged['images'], 'nombre_foto');
    sort($slots);
    $esperados = array_values($files);
    sort($esperados);
    checkBackup($slots === $esperados, 'C: los slots resueltos no son los del manifiesto.');

    // ── D: la estructura puede venir sin el cero delante ────────────────────
    $sinCero = $post;
    $sinCero['estructura'] = '1';
    checkBackup(
        mobileBackupStructureDirectories($sinCero, $root) === $directories,
        'D: `estructura=1` no encontró la carpeta `Estructura_01`.'
    );

    // ── E: estructura sin respaldo -> 0 y sin excepción ─────────────────────
    $ajena = ['id' => 7777, 'proyecto_id' => 2, 'codigo' => 'L-6099-77', 'linea' => 'L-6099', 'estructura' => '77'];
    checkBackup(mobileBackupStructureDirectories($ajena, $root) === [], 'E: encontró respaldo donde no hay.');
    $vacio = inspectMobileBackup($ajena, $root, 0);
    checkBackup($vacio['status'] === RECOVERY_NO_FILES, 'E: no informó "sin archivos".');
    checkBackup($vacio['photos'] === [], 'E: devolvió fotografías de otra estructura.');
    $mergedVacio = mergeStructureImages([], [], $vacio);
    checkBackup($mergedVacio['images'] === [], 'E: la resolución de respaldo inventó fotografías.');

    // ── F: un manifiesto de otra estructura se rechaza ──────────────────────
    $otroRoot = $temporary . DIRECTORY_SEPARATOR . 'ajeno';
    mkdir($otroRoot, 0777, true);
    backupWriteStructure(
        $otroRoot,
        'Mantenimiento_LT_60kV/Inspeccion_Piso/Linea_L-6028/Estructura_01',
        $post,
        $files,
        // Misma carpeta, pero el manifiesto dice que es de otro poste.
        ['poste_id' => 999999, 'codigo' => 'L-6028-01', 'estructura' => '01',
         'linea' => 'L-6028', 'proyecto_id' => 2]
    );
    $suplantado = inspectMobileBackup($post, $otroRoot, 0);
    checkBackup($suplantado['photos'] === [], 'F: aceptó el respaldo de otra estructura.');
    checkBackup(
        in_array('identity_mismatch', $suplantado['warnings'], true),
        'F: no avisó de que la identidad del manifiesto no coincide.'
    );

    // ── G: el árbol antiguo del móvil sigue funcionando ─────────────────────
    $movil = $temporary . DIRECTORY_SEPARATOR . 'moviles';
    mkdir($movil, 0777, true);
    backupWriteStructure(
        $movil,
        'Inspeccion Ligera 2026/Proyecto_2/Linea_L-6028/Estructura_01',
        $post,
        $files
    );
    $legacy = inspectMobileBackup($post, $movil, 0);
    checkBackup(count($legacy['photos']) === 26, 'G: se rompió el árbol antiguo `Proyecto_<id>/...`.');

    // ── G bis: las variantes que cuenta el avance también sirven al PDF ────
    $variantes = $temporary . DIRECTORY_SEPARATOR . 'variantes';
    mkdir($variantes, 0777, true);
    $variantDirectory = backupWriteStructure(
        $variantes,
        'share/linea-l-6028/estructura 001',
        $post,
        ['01_Panoramica.jpg' => 'foto_panoramica']
    );
    $variantResolved = mobileBackupStructureDirectories($post, $variantes);
    checkBackup(
        $variantResolved === [realpath($variantDirectory) ?: $variantDirectory],
        'G bis: el resolvedor del PDF no reconoció guion/espacio ni 001 = 01.'
    );
    checkBackup(
        count(inspectMobileBackup($post, $variantes, 0)['photos']) === 1,
        'G bis: la carpeta normalizada contó en avance pero no aportó su foto al PDF.'
    );

    // ── H: la búsqueda está acotada en profundidad ──────────────────────────
    $profundo = $temporary . DIRECTORY_SEPARATOR . 'profundo';
    mkdir($profundo, 0777, true);
    backupWriteStructure(
        $profundo,
        'a/b/c/d/Linea_L-6028/Estructura_01',
        $post,
        ['01_Panoramica.jpg' => 'foto_panoramica']
    );
    checkBackup(
        mobileBackupStructureDirectories($post, $profundo) === [],
        'H: la búsqueda pasó del límite de profundidad declarado.'
    );

    // ── I: nada de lo devuelto se sale de la raíz ───────────────────────────
    foreach (mobileBackupStructureDirectories($post, $root) as $directory) {
        checkBackup(pathIsInside($directory, $root), 'I: devolvió una carpeta fuera de la raíz del respaldo.');
    }
} finally {
    backupRemoveTree($temporary);
}

echo $failures === []
    ? "OK: {$checks} comprobaciones de la resolucion del respaldo del NAS.\n"
    : "FALLOS ({$checks} comprobaciones):\n - " . implode("\n - ", $failures) . "\n";
exit($failures === [] ? 0 : 1);
