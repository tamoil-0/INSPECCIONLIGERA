<?php
declare(strict_types=1);

/**
 * Fuente y selección de fotografías del reporte, sin base de datos.
 *
 * Se monta un respaldo del móvil real en un directorio temporal y se comprueba
 * `applyReportPhotoOptions()` sobre un `$resolved` armado a mano, que es lo que
 * `resolveStructureResources()` entrega a `renderPostReport()`.
 *
 * No toca la base, ni el almacén oficial, ni la caché de PDF.
 *
 *   php api/tests/report_photo_source_test.php
 */

require_once __DIR__ . '/../services/ReportPhotoSourceService.php';

$GLOBALS['app_environment']['MOBILE_BACKUP_STABILITY_SECONDS'] = '0';
$failures = [];
$checks = 0;

function checkPhotos(bool $condition, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

function photosTestImage(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
        . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
    );
}

function photosTestPost(): array
{
    return [
        'id' => 25,
        'proyecto_id' => 7,
        'codigo' => 'L-6021-025',
        'linea' => 'L-6021',
        'estructura' => '025',
        'ultima_limpieza_admin' => null,
    ];
}

/** Respaldo con `Formulario/formulario.json` y sus fotos, como el del NAS. */
function photosTestBackup(string $root, array $types): void
{
    $directory = $root . DIRECTORY_SEPARATOR . 'Proyecto_7'
        . DIRECTORY_SEPARATOR . 'Linea_L-6021' . DIRECTORY_SEPARATOR . 'Estructura_025';
    $photos = $directory . DIRECTORY_SEPARATOR . 'Fotos';
    $form = $directory . DIRECTORY_SEPARATOR . 'Formulario';
    mkdir($photos, 0777, true);
    mkdir($form, 0777, true);
    $image = photosTestImage();
    $files = [];
    foreach ($types as $index => $type) {
        // Nombre humano numerado, como en el File Station del QNAP. El mapeo
        // archivo -> slot lo da el manifiesto (`tipo`), nunca el orden.
        $name = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) . '_' . $type . '.png';
        file_put_contents($photos . DIRECTORY_SEPARATOR . $name, $image);
        $files[] = [
            'tipo' => $type,
            'archivo' => $name,
            'bytes' => strlen($image),
            'checksum_sha256' => hash('sha256', $image),
            'fecha_captura' => '2026-08-24 09:30:00',
        ];
    }
    file_put_contents($form . DIRECTORY_SEPARATOR . 'formulario.json', json_encode([
        'generado_en' => '2026-08-24T10:00:00-05:00',
        'estructura' => [
            'poste_id' => 25,
            'codigo' => 'L-6021-025',
            'estructura' => '025',
            'linea' => 'L-6021',
            'proyecto_id' => '7',
        ],
        'fotografias' => ['archivos' => $files],
        'formulario' => ['comentarios' => 'FORMULARIO-DEL-RESPALDO'],
        'secciones_rst' => [],
    ], JSON_UNESCAPED_UNICODE));
}

function photosTestOfficialRow(string $directory, string $type): array
{
    $file = $directory . DIRECTORY_SEPARATOR . 'official_' . $type . '.png';
    file_put_contents($file, photosTestImage());

    return [
        'nombre_foto' => $type,
        'ruta_archivo' => null,
        'ruta_resuelta' => $file,
        'fecha_captura' => '2026-09-01 08:00:00',
        'source' => 'official',
        'disponible' => true,
    ];
}

function photosRemoveTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $target = $path . DIRECTORY_SEPARATOR . $item;
        is_dir($target) ? photosRemoveTree($target) : unlink($target);
    }
    rmdir($path);
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecoing_fotos_' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);

try {
    $backupRoot = $root . DIRECTORY_SEPARATOR . 'respaldo';
    mkdir($backupRoot, 0777, true);
    photosTestBackup($backupRoot, ['placa', 'mensulas', 'retenida']);
    $backup = inspectMobileBackup(photosTestPost(), $backupRoot, 0);
    checkPhotos(count($backup['photos']) === 3, 'El respaldo de prueba no tiene sus 3 fotografías.');

    // `resolveStructureResources()` mezcla: placa oficial gana, mensulas viene
    // del respaldo porque no hay oficial, retenida sólo existe en el respaldo.
    $officialDirectory = $root . DIRECTORY_SEPARATOR . 'oficial';
    mkdir($officialDirectory, 0777, true);
    $merged = mergeStructureImages(
        [photosTestOfficialRow($officialDirectory, 'placa')],
        ['placa', 'mensulas', 'crucetas'],
        $backup
    );
    $rstOficial = [['seccion' => 'conductores_fase', 'atributo' => 'hebras_rotas', 'fase' => 'R']];
    $resolved = [
        'post' => photosTestPost(),
        'details' => ['comentarios' => 'formulario de la BD actual'],
        'rst' => $rstOficial,
        'details_official' => ['comentarios' => 'formulario de la BD actual'],
        'rst_official' => $rstOficial,
        'images' => $merged['images'],
        'backup' => $backup,
        'missing' => $merged['missing'],
        'recoverable' => $merged['recoverable'],
        'source' => $merged['source'],
        'official_available' => $merged['official_available'],
        'backup_used' => $merged['backup_used'],
    ];
    $slotsOf = static fn(array $result): array => array_column($result['images'], 'nombre_foto');
    $sourceOf = static function (array $result, string $slot): ?string {
        foreach ($result['images'] as $image) {
            if ($image['nombre_foto'] === $slot) {
                return (string) $image['source'];
            }
        }
        return null;
    };

    // A: sin opciones, el comportamiento es exactamente el de siempre.
    $a = applyReportPhotoOptions($resolved, []);
    checkPhotos($slotsOf($a) === $slotsOf($resolved), 'A: las opciones vacías cambiaron la lista de fotografías.');
    checkPhotos($a['photo_options']['photo_source'] === REPORT_PHOTO_SOURCE_AUTO, 'A: la fuente no quedó en automático.');
    checkPhotos($a['photo_options']['excluidas'] === 0, 'A: excluyó fotografías sin que se lo pidieran.');
    checkPhotos(reportPhotoOptionsAreDefault([]), 'A: unas opciones vacías no se reconocen como estándar.');

    // B: `auto` y NAS mantienen el informe de la BD actual.
    foreach ([REPORT_PHOTO_SOURCE_AUTO, REPORT_PHOTO_SOURCE_CURRENT] as $source) {
        $result = applyReportPhotoOptions($resolved, ['photo_source' => $source]);
        checkPhotos($result['details'] === $resolved['details'], "B/$source: cambió el formulario.");
        checkPhotos($result['rst'] === $resolved['rst'], "B/$source: cambiaron las secciones RST.");
        checkPhotos($result['post'] === $resolved['post'], "B/$source: cambiaron los datos del poste.");
    }

    // B ter: Backup cambia el informe COMPLETO, no sólo las fotografías.
    //
    // Es la regla nueva: al elegir el respaldo, el formulario y el RST salen de
    // `Formulario/formulario.json` de esa misma estructura.
    $backupForm = applyReportPhotoOptions($resolved, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkPhotos(
        $backupForm['details'] === ['comentarios' => 'FORMULARIO-DEL-RESPALDO'],
        'B ter: el formulario del informe no salió del manifiesto del respaldo.'
    );
    checkPhotos($backupForm['rst'] === [], 'B ter: el RST no salió del manifiesto.');
    checkPhotos($backupForm['data_source'] === 'backup_qfile', 'B ter: no marcó el origen de datos.');
    checkPhotos(
        $backupForm['photo_options']['form_state'] === RECOVERY_FORM_OK,
        'B ter: no informó que el formulario del respaldo es utilizable.'
    );
    // La identidad sigue siendo la de la BD: el respaldo no la reescribe.
    foreach (['codigo', 'linea', 'estructura', 'proyecto_id', 'id'] as $campo) {
        checkPhotos(
            ($backupForm['post'][$campo] ?? null) === ($resolved['post'][$campo] ?? null),
            'B ter: el respaldo alteró el dato maestro ' . $campo . '.'
        );
    }

    // B bis: en NAS el formulario sale de la BD, no del manifiesto.
    //
    // `resolveStructureResources()` recurre al manifiesto cuando `poste_datos`
    // está vacío. Es su comportamiento histórico, así que en `auto` se respeta;
    // pero elegir NAS no puede traer datos del respaldo.
    $sinFormulario = $resolved;
    $sinFormulario['details'] = ['comentarios' => 'formulario del RESPALDO'];
    $sinFormulario['rst'] = [['seccion' => 'estado_aisladores', 'atributo' => 'buen_estado', 'fase' => 'T']];
    $sinFormulario['details_official'] = [];
    $sinFormulario['rst_official'] = [];
    $result = applyReportPhotoOptions($sinFormulario, ['photo_source' => REPORT_PHOTO_SOURCE_CURRENT]);
    checkPhotos($result['details'] === [], 'B bis/NAS: el formulario salió del respaldo.');
    checkPhotos($result['rst'] === [], 'B bis/NAS: el RST salió del respaldo.');
    $auto = applyReportPhotoOptions($sinFormulario, []);
    checkPhotos(
        $auto['details'] === ['comentarios' => 'formulario del RESPALDO'],
        'B bis/auto: se alteró el comportamiento histórico de la resolución estándar.'
    );

    // C: fuente "actuales" -> sólo lo oficial, sin nada del respaldo.
    $c = applyReportPhotoOptions($resolved, ['photo_source' => REPORT_PHOTO_SOURCE_CURRENT]);
    checkPhotos($slotsOf($c) === ['placa'], 'C: la fuente oficial no dejó únicamente la fotografía oficial.');
    checkPhotos($c['backup_used'] === 0, 'C: la fuente oficial usó el respaldo.');

    // D: fuente "backup" -> sólo el respaldo; la placa oficial NO aparece.
    $d = applyReportPhotoOptions($resolved, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    $slotsD = $slotsOf($d);
    sort($slotsD);
    checkPhotos($slotsD === ['mensulas', 'placa', 'retenida'], 'D: el respaldo no aportó sus 3 fotografías.');
    checkPhotos($sourceOf($d, 'placa') === 'backup_qfile', 'D: la placa salió del almacén oficial, no del respaldo.');
    checkPhotos($d['official_available'] === 0, 'D: se colaron fotografías oficiales en el modo respaldo.');

    // E: un slot que el respaldo no tiene se queda vacío. Sin fallback.
    $backupParcial = inspectMobileBackup(photosTestPost(), $backupRoot, 0);
    unset($backupParcial['photos']['placa']);
    $resolvedParcial = $resolved;
    $resolvedParcial['backup'] = $backupParcial;
    $e = applyReportPhotoOptions($resolvedParcial, ['photo_source' => REPORT_PHOTO_SOURCE_BACKUP]);
    checkPhotos(!in_array('placa', $slotsOf($e), true), 'E: hubo fallback silencioso a la fotografía actual.');
    checkPhotos(in_array('placa', $e['missing'], true), 'E: no informó la placa como no disponible.');

    // F: excluir slots concretos.
    $f = applyReportPhotoOptions($resolved, [
        'photo_source' => REPORT_PHOTO_SOURCE_BACKUP,
        'slots' => ['placa', 'retenida'],
    ]);
    $slotsF = $slotsOf($f);
    sort($slotsF);
    checkPhotos($slotsF === ['placa', 'retenida'], 'F: no respetó la lista de fotografías incluidas.');
    checkPhotos($f['photo_options']['excluidas'] === 1, 'F: el recuento de excluidas es incorrecto.');
    checkPhotos($f['photo_options']['excluidos_slots'] === ['mensulas'], 'F: no informó qué slot se excluyó.');

    // G: lista vacía significa "ninguna"; ausente significa "todas".
    $g = applyReportPhotoOptions($resolved, ['slots' => []]);
    checkPhotos($g['images'] === [], 'G: una lista vacía no dejó el reporte sin fotografías.');
    checkPhotos(!reportPhotoOptionsAreDefault(['slots' => []]), 'G: "ninguna" se confundió con el reporte estándar.');

    // H: enumerar el catálogo completo equivale a "todas" y sigue siendo
    //    cacheable; así el reporte estándar no pierde la caché.
    $todos = normalizeReportPhotoOptions(['slots' => photoTypes()]);
    checkPhotos($todos['included_slots'] === null, 'H: enumerar los 28 slots no se normalizó a "todas".');
    checkPhotos(reportPhotoOptionsAreDefault(['slots' => photoTypes()]), 'H: enumerar los 28 slots perdió la caché.');

    // I: seguridad. Nada que venga del cliente puede formar parte de una ruta.
    $sucio = normalizeReportPhotoOptions([
        'photo_source' => '../../etc/passwd',
        'slots' => ['placa', '../../secreto', '/share/CACHEDEV1_DATA/x', 'C:\\Windows\\win.ini', 'placa'],
    ]);
    checkPhotos($sucio['photo_source'] === REPORT_PHOTO_SOURCE_AUTO, 'I: aceptó una fuente inventada.');
    checkPhotos($sucio['included_slots'] === ['placa'], 'I: aceptó un slot que no está en el catálogo.');

    // J: idempotencia. Aplicar dos veces no vuelve a filtrar.
    $j = applyReportPhotoOptions($f, ['photo_source' => REPORT_PHOTO_SOURCE_CURRENT]);
    checkPhotos($slotsOf($j) === $slotsOf($f), 'J: una segunda aplicación volvió a cambiar la selección.');

    // K: el respaldo anterior a una limpieza administrativa se ofrece sólo
    //    cuando se pide de forma explícita, y queda marcado.
    $bloqueado = $resolved;
    $bloqueado['backup'] = [
        'status' => RECOVERY_NO_FILES, 'manifest' => null, 'photos' => [],
        'declared_types' => [], 'missing' => [],
        'warnings' => ['blocked_by_admin_clear'], 'modified' => 0,
    ];
    $GLOBALS['app_environment']['MOBILE_BACKUP_STORAGE_PATH'] = $backupRoot;
    // storageRoots() memoiza; si ya se resolvió, esta comprobación no aplica.
    $reinspected = reportPhotoBackupInspection($bloqueado);
    if (($reinspected['warnings'] ?? []) !== ['blocked_by_admin_clear']) {
        checkPhotos(
            in_array('prior_to_admin_clear', $reinspected['warnings'] ?? [], true),
            'K: no marcó el respaldo como anterior a la limpieza administrativa.'
        );
    }
    $k = applyReportPhotoOptions($bloqueado, ['photo_source' => REPORT_PHOTO_SOURCE_CURRENT]);
    checkPhotos($slotsOf($k) === ['placa'], 'K: la fuente oficial se vio afectada por el respaldo bloqueado.');

    // L: el catálogo de slots es único y cubre photoTypes().
    $catalogo = array_column(pdfPhotoSlots(), 'tipo');
    checkPhotos(count($catalogo) === count(array_unique($catalogo)), 'L: el catálogo de slots tiene duplicados.');
    checkPhotos(array_diff(photoTypes(), $catalogo) === [], 'L: hay tipos de foto sin slot en el catálogo.');
    checkPhotos($catalogo[0] === 'foto_panoramica', 'L: la panorámica ya no es el primer slot.');
} finally {
    photosRemoveTree($root);
}

echo $failures === []
    ? "OK: {$checks} comprobaciones de fuente y selección de fotografías.\n"
    : "FALLOS ({$checks} comprobaciones):\n - " . implode("\n - ", $failures) . "\n";
exit($failures === [] ? 0 : 1);
