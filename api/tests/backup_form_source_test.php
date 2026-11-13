<?php
declare(strict_types=1);

/**
 * En `photo_source=backup` el informe COMPLETO sale del respaldo.
 *
 * ## La regla que se comprueba
 *
 * Es un cambio deliberado de la anterior. Antes, elegir una fuente cambiaba
 * sólo las fotografías y el formulario venía siempre de la BD. Ahora:
 *
 *   NAS    : formulario y RST de la BD actual   + fotografías del almacén oficial
 *   Backup : formulario y RST de formulario.json + fotografías de Fotos/
 *
 * La identidad y los datos maestros —proyecto, contratista, línea, estructura,
 * código, tramo— siguen saliendo SIEMPRE de la BD: son los que seleccionaron el
 * registro y no deben venir de un archivo.
 *
 * El PDF se compone de verdad y se busca el texto dentro: con el formulario de
 * la BD diciendo `DATO_BD` y el del respaldo `DATO_BACKUP`, cada reporte tiene
 * que contener el suyo y no el del otro. Es la única forma de demostrar que
 * cambia la FUENTE DE DATOS y no sólo las imágenes.
 *
 * Sólo lectura: no toca la base, el almacén, el respaldo ni Flutter.
 *
 *   php api/tests/backup_form_source_test.php
 */

$fixtureRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecoing_form_' . bin2hex(random_bytes(6));
$backupRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'elpu';
$officialRoot = $fixtureRoot . DIRECTORY_SEPARATOR . 'oficial';
mkdir($backupRoot, 0777, true);
mkdir($officialRoot, 0777, true);
// Las raíces se memoizan al cargar la aplicación: se fijan antes del require.
putenv('APP_ENV_FILE=' . $fixtureRoot . DIRECTORY_SEPARATOR . 'sin-env');
putenv('MOBILE_BACKUP_ROOT=' . $backupRoot);
putenv('IMAGE_STORAGE_PATH=' . $officialRoot);
putenv('MOBILE_BACKUP_STABILITY_SECONDS=0');

require_once __DIR__ . '/../services/PdfReportService.php';

$failures = [];
$checks = 0;

function checkForm(bool $condition, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

/** Los 26 archivos reales de L-6028 / Estructura_01 con su slot. */
function formBackupFiles(): array
{
    $slots = photoTypes();
    $names = [
        1 => '01_Panoramica', 2 => '02_Placa', 3 => '03_Torre_parte_inferior',
        4 => '04_Torre_parte_superior', 5 => '05_Base_de_la_torre', 6 => '06_Mensulas',
        7 => '07_Crucetas', 8 => '08_Perfiles_angulares',
        11 => '11_Aisladores_fase_R_atras', 12 => '12_Aisladores_fase_S_atras',
        13 => '13_Aisladores_fase_T_atras', 14 => '14_Aisladores_fase_R_adelante',
        15 => '15_Aisladores_fase_S_adelante', 16 => '16_Aisladores_fase_T_adelante',
        17 => '17_Ferreteria_fase_R', 18 => '18_Ferreteria_fase_S', 19 => '19_Ferreteria_fase_T',
        20 => '20_Cable_de_guarda', 21 => '21_Ferreteria_del_cable_de_guarda',
        22 => '22_Conductor', 23 => '23_Ferreteria_del_conductor',
        24 => '24_Puesta_a_tierra', 25 => '25_Puesta_a_tierra_2', 26 => '26_Retenida',
        27 => '27_Faja_de_servidumbre', 28 => '28_Ubicacion_acceso',
    ];
    $files = [];
    foreach ($names as $position => $name) {
        $files[$name . '.jpg'] = $slots[$position - 1];
    }

    return $files;
}

function formJpeg(int $px): string
{
    $canvas = imagecreatetruecolor($px, $px);
    imagefilledrectangle($canvas, 0, 0, $px, $px, (int) imagecolorallocate($canvas, 90, 120, 60));
    ob_start();
    imagejpeg($canvas, null, 90);
    $bytes = (string) ob_get_clean();
    imagedestroy($canvas);

    return $bytes;
}

function formPost(): array
{
    return [
        'id' => 1240,
        'proyecto_id' => 2,
        'codigo' => 'L-6028-01',
        'linea' => 'L-6028',
        'estructura' => '01',
        'ubicaciones' => 'Tramo oficial de la BD',
        'utm_x' => '392541.20',
        'utm_y' => '8302114.55',
        'zona' => '19L',
        'fecha_inspeccion' => '2026-09-01 08:15:00',
        'nombre_proyecto' => 'Mantenimiento LT 60 kV',
        'contratista' => 'INTESEL',
        'proyecto_ubicacion' => 'Puno',
        'ultima_limpieza_admin' => null,
    ];
}

/** Escribe el respaldo. `$conFormulario` permite ausencia o JSON roto. */
function formWriteBackup(string $root, string $conFormulario = 'ok'): void
{
    $directory = $root . DIRECTORY_SEPARATOR . 'Mantenimiento_LT_60kV'
        . DIRECTORY_SEPARATOR . 'Inspeccion_Piso'
        . DIRECTORY_SEPARATOR . 'Linea_L-6028'
        . DIRECTORY_SEPARATOR . 'Estructura_01';
    $photos = $directory . DIRECTORY_SEPARATOR . 'Fotos';
    $forms = $directory . DIRECTORY_SEPARATOR . 'Formulario';
    mkdir($photos, 0777, true);
    mkdir($forms, 0777, true);

    $declared = [];
    foreach (formBackupFiles() as $name => $type) {
        $bytes = formJpeg(24);
        file_put_contents($photos . DIRECTORY_SEPARATOR . $name, $bytes);
        $declared[] = [
            'tipo' => $type,
            'archivo' => $name,
            'fecha_captura' => '2026-08-31 19:40:00',
            'bytes' => strlen($bytes),
            'checksum_sha256' => hash('sha256', $bytes),
        ];
    }

    // Siempre presente: no debe elegirse mientras exista el principal.
    file_put_contents(
        $forms . DIRECTORY_SEPARATOR . 'formulario_conflict(1).json',
        json_encode([
            'estructura' => ['poste_id' => 1240, 'codigo' => 'L-6028-01', 'estructura' => '01',
                'linea' => 'L-6028', 'proyecto_id' => 2],
            'fotografias' => ['archivos' => []],
            'formulario' => ['comentarios' => 'DATO_CONFLICTO'],
        ], JSON_UNESCAPED_UNICODE)
    );

    $manifestFile = $forms . DIRECTORY_SEPARATOR . 'formulario.json';
    if ($conFormulario === 'ausente') {
        return;
    }
    if ($conFormulario === 'roto') {
        file_put_contents($manifestFile, '{ esto no es json valido');
        return;
    }
    file_put_contents($manifestFile, json_encode([
        'generado_en' => '2026-08-31T22:54:00-05:00',
        'aplicacion' => 'ECOING Inspección Ligera',
        'estructura' => [
            'poste_id' => 1240, 'codigo' => 'L-6028-01', 'estructura' => '01',
            'linea' => 'L-6028', 'proyecto_id' => 2,
            'proyecto' => 'PROYECTO DEL RESPALDO',
            'fecha_inspeccion' => '2026-08-31 16:40:00',
        ],
        'coordenadas' => ['utm_este' => 111111.25, 'utm_norte' => 8222222.75, 'zona' => '19S'],
        'fotografias' => ['archivos' => $declared],
        // Claves reales del manifiesto: las columnas de `poste_datos`.
        'formulario' => [
            'comentarios' => 'DATO_BACKUP',
            'tipo_torre' => 'angulo',
            'acceso_torre' => 'en_vehiculo',
            'estado_acceso' => 'mal_estado',
            'estado_base' => 'mal_estado',
            'tipo_aislador' => 'porcelana',
            'cadena_aisladores' => 'en_anclaje',
            'criticidad_tala' => 'critico',
            'distancia_acceso' => 45.5,
            'cantidad_pat' => 3,
            'obstaculos_faja' => 'arboles,arbustos',
            // Campo que no está en el catálogo: debe descartarse en silencio.
            'campo_inventado' => 'no debe aparecer',
        ],
        'secciones_rst' => [
            ['seccion' => 'conductores_fase', 'atributo' => 'hebras_rotas', 'fase' => 'T'],
            ['seccion' => 'estado_aisladores', 'atributo' => 'mal_estado', 'fase' => 'R'],
        ],
    ], JSON_UNESCAPED_UNICODE));
}

/** Fila oficial de `imagenes_poste` con su archivo en el almacén. */
function formOfficialImages(string $root): array
{
    $rows = [];
    foreach (['foto_panoramica', 'placa', 'mensulas'] as $index => $type) {
        $file = $root . DIRECTORY_SEPARATOR . $type . '_oficial.jpg';
        file_put_contents($file, formJpeg(40 + $index));
        $rows[] = [
            'nombre_foto' => $type,
            'ruta_archivo' => null,
            'ruta_resuelta' => $file,
            'fecha_captura' => '2026-09-01 08:20:00',
            'utm_este' => 392541.2,
            'utm_norte' => 8302114.55,
            'zona' => '19L',
        ];
    }

    return $rows;
}

function formRemove(string $path): void
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
        formRemove($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

/**
 * Compone el reporte SIN comprimir y devuelve su texto.
 *
 * FPDF comprime los flujos de contenido por defecto, así que en un PDF normal
 * no se puede buscar con `str_contains`. `SetCompression(false)` antes del
 * `Output()` deja el texto legible; sólo afecta a esta prueba.
 *
 * Se anexa el recuento de imágenes incrustadas para poder compararlo sin
 * recomponer el documento.
 */
function formPdfText(PDO $connection, array $resolved, array $options): string
{
    $pdf = buildPostReport($connection, 1240, 2, $resolved, $options);
    $pdf->SetCompression(false);
    $raw = $pdf->Output('S');
    $text = '';
    if (preg_match_all('/\((?:[^()\\\\]|\\\\.)*\)/', $raw, $matches) > 0) {
        $text = implode(' ', $matches[0]);
    }

    return $text . '|IMAGENES:' . preg_match_all('/\/Subtype\s*\/Image/', $raw);
}

// Nunca se consulta: `buildPostReport()` sólo usa la conexión si $resolved es null.
$connection = new PDO('sqlite::memory:');

try {
    formWriteBackup($backupRoot);
    $post = formPost();
    $officialDetails = [
        'comentarios' => 'DATO_BD',
        'tipo_torre' => 'alineamiento',
        'acceso_torre' => 'a_pie',
        'estado_acceso' => 'bueno',
    ];
    $officialRst = [['seccion' => 'conductores_guarda', 'atributo' => 'encanastillado', 'fase' => 'S']];

    $armar = static function (string $root) use ($post, $officialDetails, $officialRst, $officialRoot): array {
        $backup = inspectMobileBackup($post, $root, 0);
        $official = formOfficialImages($officialRoot);
        $merged = mergeStructureImages($official, array_column($official, 'nombre_foto'), $backup);

        return [
            'post' => $post,
            'details' => $officialDetails,
            'rst' => $officialRst,
            'details_official' => $officialDetails,
            'rst_official' => $officialRst,
            'images' => $merged['images'],
            'backup' => $backup,
            'missing' => $merged['missing'],
            'recoverable' => $merged['recoverable'],
            'source' => $merged['source'],
            'data_source' => 'official',
            'official_available' => $merged['official_available'],
            'backup_used' => $merged['backup_used'],
        ];
    };
    $resolved = $armar($backupRoot);

    // ── A: el respaldo se detecta completo ──────────────────────────────────
    checkForm(count($resolved['backup']['photos']) === 26, 'A: no se detectaron las 26 fotografías.');
    checkForm(
        $resolved['backup']['form_state'] === RECOVERY_FORM_OK,
        'A: el formulario del respaldo no quedó como utilizable.'
    );

    // ── B: NAS -> formulario de la BD, fotografías oficiales ────────────────
    $nas = applyReportPhotoOptions($resolved, ['photo_source' => REPORT_PHOTO_SOURCE_CURRENT]);
    checkForm($nas['details'] === $officialDetails, 'B: NAS no usó el formulario de la BD.');
    checkForm($nas['rst'] === $officialRst, 'B: NAS no usó el RST de la BD.');
    checkForm($nas['post']['fecha_inspeccion'] === '2026-09-01 08:15:00', 'B: NAS cambió la fecha.');
    checkForm($nas['post']['zona'] === '19L', 'B: NAS cambió la zona.');
    checkForm($nas['photo_options']['incluidas'] === 3, 'B: NAS no dejó sus 3 fotografías oficiales.');
    checkForm($nas['photo_options']['form_state'] === null, 'B: NAS reportó estado de formulario de respaldo.');

    // ── C: Backup -> formulario del JSON, fotografías del respaldo ──────────
    $bk = applyReportPhotoOptions($resolved, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkForm($bk['details']['comentarios'] === 'DATO_BACKUP', 'C: el formulario no salió del manifiesto.');
    checkForm($bk['details']['tipo_torre'] === 'angulo', 'C: no se trajo tipo_torre del respaldo.');
    checkForm($bk['details']['estado_base'] === 'mal_estado', 'C: no se trajo estado_base del respaldo.');
    checkForm($bk['details']['distancia_acceso'] === 45.5, 'C: no se trajo un numérico del respaldo.');
    checkForm($bk['details']['obstaculos_faja'] === 'arboles,arbustos', 'C: no se trajo la lista de obstáculos.');
    checkForm(!array_key_exists('campo_inventado', $bk['details']), 'C: se colcó un campo fuera del catálogo.');
    checkForm(
        $bk['rst'] === [
            ['seccion' => 'conductores_fase', 'atributo' => 'hebras_rotas', 'fase' => 'T'],
            ['seccion' => 'estado_aisladores', 'atributo' => 'mal_estado', 'fase' => 'R'],
        ],
        'C: el RST no salió del manifiesto del respaldo.'
    );
    checkForm($bk['photo_options']['incluidas'] === 26, 'C: no se imprimieron las 26 del respaldo.');
    checkForm($bk['photo_options']['form_state'] === RECOVERY_FORM_OK, 'C: no informó el estado del formulario.');
    checkForm($bk['data_source'] === 'backup_qfile', 'C: no marcó el origen de datos como respaldo.');

    // Fecha y UTM de la captura respaldada; identidad, de la BD.
    checkForm($bk['post']['fecha_inspeccion'] === '2026-08-31 16:40:00', 'C: no tomó la fecha del respaldo.');
    checkForm($bk['post']['utm_x'] === '111111.25', 'C: no tomó UTM Este del respaldo.');
    checkForm($bk['post']['zona'] === '19S', 'C: no tomó la zona del respaldo.');
    foreach (['codigo' => 'L-6028-01', 'linea' => 'L-6028', 'estructura' => '01',
        'nombre_proyecto' => 'Mantenimiento LT 60 kV', 'contratista' => 'INTESEL',
        'ubicaciones' => 'Tramo oficial de la BD'] as $campo => $esperado) {
        checkForm($bk['post'][$campo] === $esperado, 'C: el respaldo reescribió el dato maestro ' . $campo . '.');
    }

    // ── D: sin fallback de fotografías hacia el NAS ─────────────────────────
    checkForm($bk['official_available'] === 0, 'D: se colaron fotografías del NAS en modo respaldo.');
    foreach ($bk['images'] as $image) {
        checkForm(
            ($image['source'] ?? '') === 'backup_qfile',
            'D: la fotografía ' . $image['nombre_foto'] . ' no vino del respaldo.'
        );
    }

    // ── E: el PDF real contiene el dato de SU fuente y no el del otro ───────
    $textoNas = formPdfText($connection, $resolved, ['photo_source' => REPORT_PHOTO_SOURCE_CURRENT]);
    $textoBk = formPdfText($connection, $resolved, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkForm(str_contains($textoNas, 'DATO_BD'), 'E: el PDF NAS no contiene DATO_BD.');
    checkForm(!str_contains($textoNas, 'DATO_BACKUP'), 'E: el PDF NAS contiene DATO_BACKUP.');
    checkForm(str_contains($textoBk, 'DATO_BACKUP'), 'E: el PDF Backup no contiene DATO_BACKUP.');
    checkForm(!str_contains($textoBk, 'DATO_BD'), 'E: el PDF Backup contiene DATO_BD.');
    checkForm(!str_contains($textoBk, 'DATO_CONFLICTO'), 'E: se usó el formulario_conflict.');
    $incrustadas = static fn(string $texto): int
        => (int) substr($texto, (int) strrpos($texto, '|IMAGENES:') + 10);
    checkForm(
        $incrustadas($textoBk) > $incrustadas($textoNas),
        'E: el PDF Backup no incrustó más fotografías que el NAS.'
    );

    // ── F: la selección guardada excluye fotos, no campos del formulario ────
    $conExclusiones = applyReportPhotoOptions($resolved, [
        'photo_source' => REPORT_PHOTO_SOURCE_BACKUP,
        'excluded_slots' => ['placa', 'retenida', 'conductor'],
    ]);
    checkForm($conExclusiones['photo_options']['incluidas'] === 23, 'F: no se excluyeron 3 fotografías.');
    checkForm($conExclusiones['photo_options']['excluidas'] === 3, 'F: el recuento de excluidas es incorrecto.');
    checkForm($conExclusiones['details'] === $bk['details'], 'F: excluir fotografías recortó el formulario.');
    checkForm($conExclusiones['rst'] === $bk['rst'], 'F: excluir fotografías recortó el RST.');

    // ── G: sin formulario.json NO se disfrazan los datos actuales ───────────
    $sinForm = $fixtureRoot . DIRECTORY_SEPARATOR . 'sin_formulario';
    mkdir($sinForm, 0777, true);
    formWriteBackup($sinForm, 'ausente');
    $resueltoSinForm = $armar($sinForm);
    checkForm(
        count($resueltoSinForm['backup']['photos']) === 26,
        'G: sin manifiesto se perdieron las fotografías, que sí están en disco.'
    );
    checkForm(
        $resueltoSinForm['backup']['form_state'] === RECOVERY_FORM_MISSING,
        'G: no se informó que falta el formulario.'
    );
    $bkSinForm = applyReportPhotoOptions($resueltoSinForm, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkForm($bkSinForm['details'] === [], 'G: se usaron los datos de la BD como si fueran del respaldo.');
    checkForm($bkSinForm['rst'] === [], 'G: se usó el RST de la BD como si fuera del respaldo.');
    checkForm(
        $bkSinForm['photo_options']['form_state'] === RECOVERY_FORM_MISSING,
        'G: el estado del formulario no llega a quien genera el reporte.'
    );
    checkForm($bkSinForm['photo_options']['incluidas'] === 26, 'G: sin formulario se perdieron las fotografías.');
    $textoSinForm = formPdfText($connection, $resueltoSinForm, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkForm(!str_contains($textoSinForm, 'DATO_BD'), 'G: el PDF sin formulario de respaldo mostró el dato de la BD.');

    // ── H: JSON malformado -> mismo trato, sin excepción ───────────────────
    $roto = $fixtureRoot . DIRECTORY_SEPARATOR . 'roto';
    mkdir($roto, 0777, true);
    formWriteBackup($roto, 'roto');
    $resueltoRoto = $armar($roto);
    checkForm(
        $resueltoRoto['backup']['form_state'] === RECOVERY_FORM_INVALID,
        'H: un JSON malformado no se reportó como ilegible.'
    );
    checkForm(
        count($resueltoRoto['backup']['photos']) === 26,
        'H: un JSON malformado se llevó por delante las fotografías.'
    );
    $bkRoto = applyReportPhotoOptions($resueltoRoto, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkForm($bkRoto['details'] === [], 'H: con JSON roto se usaron los datos de la BD.');
    checkForm(
        $bkRoto['photo_options']['form_state'] === RECOVERY_FORM_INVALID,
        'H: no se propagó el estado ilegible.'
    );

    // ── I: la caché distingue fuente y sigue al manifiesto ─────────────────
    $huella = static fn(array $r, array $o): string => postReportFingerprint(
        $connection,
        1240,
        2,
        applyReportPhotoOptions($r, $o)
    );
    $huellaNas = $huella($resolved, ['photo_source' => REPORT_PHOTO_SOURCE_CURRENT]);
    $huellaBk = $huella($resolved, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkForm($huellaNas !== $huellaBk, 'I: NAS y Backup comparten huella de caché.');
    // Editar formulario.json tiene que regenerar el PDF Backup.
    $manifestFile = $resolved['backup']['manifest_file'];
    checkForm(is_string($manifestFile) && is_file($manifestFile), 'I: no se registró la ruta del manifiesto.');
    $original = (string) file_get_contents($manifestFile);
    $modificado = str_replace('DATO_BACKUP', 'DATO_BACKUP_EDITADO', $original);
    file_put_contents($manifestFile, $modificado);
    clearstatcache();
    touch($manifestFile, time() + 5);
    $huellaTrasEditar = $huella($armar($backupRoot), ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkForm(
        $huellaTrasEditar !== $huellaBk,
        'I: editar formulario.json no cambió la huella; se serviría el PDF viejo.'
    );
    file_put_contents($manifestFile, $original);
} finally {
    formRemove($fixtureRoot);
}

echo $failures === []
    ? "OK: {$checks} comprobaciones del formulario del respaldo.\n"
    : "FALLOS ({$checks} comprobaciones):\n - " . implode("\n - ", $failures) . "\n";
exit($failures === [] ? 0 : 1);
