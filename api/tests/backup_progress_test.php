<?php
declare(strict_types=1);

/**
 * Avance por línea calculado sobre el respaldo.
 *
 * Fixture con dos líneas y casos deliberadamente sucios:
 *
 *   L-100: 10 estructuras en el padrón
 *          8 con carpeta en el respaldo
 *          7 con formulario.json válido (1 corrupto)
 *          6 que cumplen «fotos confirmadas»
 *   L-200: 5 de 5 en todo
 *
 * Comprueba además que no cuentan los `formulario_conflict(N).json`, que un
 * JSON de otra estructura no contamina, que las fotografías físicas fuera del
 * manifiesto sí se detectan y que el total de estructuras sigue saliendo del
 * padrón y no del respaldo.
 *
 * Sólo lectura: no toca la base, el almacén ni el respaldo real.
 *
 *   php api/tests/backup_progress_test.php
 */

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecoing_prog_' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
putenv('MOBILE_BACKUP_ROOT=' . $root);
putenv('MOBILE_BACKUP_STABILITY_SECONDS=0');

require_once __DIR__ . '/../services/BackupProgressService.php';

$failures = [];
$checks = 0;

function checkProg(bool $condition, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

function progJpeg(): string
{
    $canvas = imagecreatetruecolor(8, 8);
    imagefilledrectangle($canvas, 0, 0, 8, 8, (int) imagecolorallocate($canvas, 80, 110, 70));
    ob_start();
    imagejpeg($canvas, null, 85);
    $bytes = (string) ob_get_clean();
    imagedestroy($canvas);

    return $bytes;
}

function progPost(int $id, string $line, string $structure): array
{
    return [
        'id' => $id,
        'proyecto_id' => 2,
        'codigo' => $line . '-' . $structure,
        'linea' => $line,
        'estructura' => $structure,
    ];
}

/**
 * Escribe una estructura en el respaldo.
 *
 * @param string $form  ok | ausente | roto | ajeno | vacio | soloconflicto
 * @param int    $fotos cuántas fotografías físicas dejar
 * @param bool   $declararTodas si el manifiesto declara las que hay
 */
function progWrite(
    string $root,
    array $post,
    string $form = 'ok',
    int $fotos = 26,
    bool $declararTodas = true
): void {
    $directory = $root . DIRECTORY_SEPARATOR . 'ELPU' . DIRECTORY_SEPARATOR . 'Inspeccion_Piso'
        . DIRECTORY_SEPARATOR . 'Linea_' . $post['linea']
        . DIRECTORY_SEPARATOR . 'Estructura_' . $post['estructura'];
    $photos = $directory . DIRECTORY_SEPARATOR . 'Fotos';
    $forms = $directory . DIRECTORY_SEPARATOR . 'Formulario';
    mkdir($photos, 0777, true);
    mkdir($forms, 0777, true);

    $slots = photoTypes();
    $jpeg = progJpeg();
    $declared = [];
    for ($n = 1; $n <= $fotos; $n++) {
        $name = str_pad((string) $n, 2, '0', STR_PAD_LEFT) . '_Foto.jpg';
        file_put_contents($photos . DIRECTORY_SEPARATOR . $name, $jpeg);
        if ($declararTodas) {
            $declared[] = ['tipo' => $slots[$n - 1], 'archivo' => $name,
                'bytes' => strlen($jpeg), 'checksum_sha256' => hash('sha256', $jpeg)];
        }
    }

    // Siempre presente: no debe contar como formulario en ningún caso.
    file_put_contents(
        $forms . DIRECTORY_SEPARATOR . 'formulario_conflict(1).json',
        json_encode([
            'estructura' => ['poste_id' => $post['id'], 'codigo' => $post['codigo'],
                'estructura' => $post['estructura'], 'linea' => $post['linea'], 'proyecto_id' => 2],
            'fotografias' => ['archivos' => []],
            'formulario' => ['comentarios' => 'conflicto'],
        ], JSON_UNESCAPED_UNICODE)
    );
    if ($form === 'ausente' || $form === 'soloconflicto') {
        return;
    }

    $file = $forms . DIRECTORY_SEPARATOR . 'formulario.json';
    if ($form === 'roto') {
        file_put_contents($file, '{ esto no es json');
        return;
    }
    $identity = ['poste_id' => $post['id'], 'codigo' => $post['codigo'],
        'estructura' => $post['estructura'], 'linea' => $post['linea'], 'proyecto_id' => 2];
    if ($form === 'ajeno') {
        $identity['poste_id'] = 999999;
    }
    file_put_contents($file, json_encode([
        'generado_en' => '2026-08-31T22:54:00-05:00',
        'estructura' => $identity,
        'fotografias' => ['archivos' => $declared],
        'formulario' => $form === 'vacio' ? [] : ['comentarios' => 'del respaldo'],
        'secciones_rst' => [],
    ], JSON_UNESCAPED_UNICODE));
}

function progRemove(string $path): void
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
        progRemove($path . DIRECTORY_SEPARATOR . $item);
    }
    @rmdir($path);
}

try {
    // ── Padrón: L-100 con 10 estructuras, L-200 con 5 ───────────────────────
    $postes = [];
    for ($i = 1; $i <= 10; $i++) {
        $postes[] = progPost($i, 'L-100', str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }
    for ($i = 11; $i <= 15; $i++) {
        $postes[] = progPost($i, 'L-200', str_pad((string) ($i - 10), 2, '0', STR_PAD_LEFT));
    }

    // L-100: 8 con carpeta. De esas, 7 con formulario válido y 6 con fotos ok.
    //   01..06 -> completas (formulario válido + fotos declaradas presentes)
    //   07     -> formulario válido pero le falta una fotografía declarada
    //   08     -> formulario corrupto, con fotografías
    //   09, 10 -> sin carpeta en el respaldo
    for ($i = 1; $i <= 6; $i++) {
        progWrite($root, $postes[$i - 1], 'ok', 26, true);
    }
    progWrite($root, $postes[6], 'ok', 26, true);
    // Se borra una fotografía que el manifiesto declara: deja de estar «confirmada».
    $faltante = $root . DIRECTORY_SEPARATOR . 'ELPU' . DIRECTORY_SEPARATOR . 'Inspeccion_Piso'
        . DIRECTORY_SEPARATOR . 'Linea_L-100' . DIRECTORY_SEPARATOR . 'Estructura_07'
        . DIRECTORY_SEPARATOR . 'Fotos' . DIRECTORY_SEPARATOR . '13_Foto.jpg';
    unlink($faltante);
    progWrite($root, $postes[7], 'roto', 26, true);

    // L-200: las 5 completas.
    foreach ([10, 11, 12, 13, 14] as $indice) {
        progWrite($root, $postes[$indice], 'ok', 26, true);
    }

    $index = backupProgressIndex($root);
    checkProg($index['truncated'] === false, 'A: el índice se truncó con un fixture pequeño.');
    // El índice se guarda con claves comparables: minúsculas y, si son todo
    // dígitos, sin ceros a la izquierda.
    checkProg(isset($index['index']['l-100']), 'A: no indexó L-100.');
    checkProg(count($index['index']['l-100'] ?? []) === 8, 'A: no encontró 8 carpetas en L-100.');
    checkProg(count($index['index']['l-200'] ?? []) === 5, 'A: no encontró 5 carpetas en L-200.');
    checkProg(
        ($index['index']['l-100']['1']['estructura'] ?? null) === '01',
        'A: el índice no conserva el nombre real de la carpeta.'
    );

    // ── B: contadores por estructura ────────────────────────────────────────
    $contar = static function (array $postes, array $index): array {
        $c = ['encontradas' => 0, 'inspeccionadas' => 0, 'formularios' => 0,
            'fotos' => 0, 'sincronizadas' => 0];
        foreach ($postes as $post) {
            $s = backupProgressStructureState($post, $index);
            $c['encontradas'] += $s['encontrada'] ? 1 : 0;
            $c['inspeccionadas'] += $s['inspeccionada'] ? 1 : 0;
            $c['formularios'] += $s['formulario'] ? 1 : 0;
            $c['fotos'] += $s['fotos'] ? 1 : 0;
            $c['sincronizadas'] += $s['sincronizada'] ? 1 : 0;
        }
        return $c;
    };
    $l100 = $contar(array_slice($postes, 0, 10), $index['index']);
    $l200 = $contar(array_slice($postes, 10, 5), $index['index']);

    checkProg($l100['encontradas'] === 8, 'B: L-100 no encontró 8 estructuras (dio ' . $l100['encontradas'] . ').');
    checkProg($l100['inspeccionadas'] === 8, 'B: L-100 inspeccionadas != 8 (dio ' . $l100['inspeccionadas'] . ').');
    checkProg($l100['formularios'] === 7, 'B: L-100 formularios != 7 (dio ' . $l100['formularios'] . ').');
    checkProg($l100['fotos'] === 6, 'B: L-100 fotos confirmadas != 6 (dio ' . $l100['fotos'] . ').');
    checkProg($l100['sincronizadas'] === 6, 'B: L-100 sincronizadas != 6 (dio ' . $l100['sincronizadas'] . ').');
    checkProg($l200['encontradas'] === 5 && $l200['formularios'] === 5 && $l200['fotos'] === 5,
        'B: L-200 no dio 5/5/5.');

    // ── C: el conflict no cuenta como formulario ────────────────────────────
    $soloConflicto = progPost(90, 'L-300', '01');
    progWrite($root, $soloConflicto, 'soloconflicto', 26, true);
    $indexC = backupProgressIndex($root)['index'];
    $estadoC = backupProgressStructureState($soloConflicto, $indexC);
    checkProg($estadoC['encontrada'], 'C: no encontró la carpeta con sólo el conflict.');
    checkProg(!$estadoC['formulario'], 'C: contó formulario_conflict como formulario válido.');
    checkProg($estadoC['form_state'] === RECOVERY_FORM_MISSING, 'C: no reportó el formulario como ausente.');
    // Sin manifiesto no hay nada declarado, así que las fotografías presentes
    // cuentan como confirmadas: es el criterio «hay fotos y no falta ninguna».
    checkProg($estadoC['fotos_presentes'] === 26, 'C: no detectó las fotografías físicas sin manifiesto.');
    checkProg($estadoC['inspeccionada'], 'C: una carpeta con fotografías no cuenta como inspeccionada.');

    // ── D: JSON corrupto no es formulario válido ────────────────────────────
    $estadoD = backupProgressStructureState($postes[7], $index['index']);
    checkProg(!$estadoD['formulario'], 'D: un JSON corrupto contó como formulario.');
    checkProg($estadoD['form_state'] === RECOVERY_FORM_INVALID, 'D: no reportó el JSON como ilegible.');
    checkProg($estadoD['inspeccionada'], 'D: con fotografías debería contar como inspeccionada.');
    checkProg(
        !$estadoD['fotos'],
        'D: con el manifiesto ilegible no se puede confirmar que estén todas.'
    );
    checkProg($estadoD['fotos_presentes'] === 26, 'D: no contó las fotografías presentes.');

    // ── E: manifiesto de otra estructura no cuenta ──────────────────────────
    $ajeno = progPost(91, 'L-400', '01');
    progWrite($root, $ajeno, 'ajeno', 26, true);
    $estadoE = backupProgressStructureState($ajeno, backupProgressIndex($root)['index']);
    checkProg(!$estadoE['formulario'], 'E: aceptó un formulario de otra estructura.');
    checkProg($estadoE['form_state'] === RECOVERY_FORM_MISMATCH, 'E: no reportó la identidad distinta.');

    // ── F: una estructura de otra línea no contamina ───────────────────────
    $otraLinea = progPost(92, 'L-500', '01');
    $indexF = backupProgressIndex($root)['index'];
    checkProg(
        backupProgressStructureState($otraLinea, $indexF)['encontrada'] === false,
        'F: encontró respaldo para una línea que no existe.'
    );
    // El poste 01 de L-100 no debe resolverse contra el 01 de L-200.
    $dirL100 = $indexF['l-100']['1']['ruta'] ?? '';
    $dirL200 = $indexF['l-200']['1']['ruta'] ?? '';
    checkProg($dirL100 !== '' && $dirL200 !== '' && $dirL100 !== $dirL200,
        'F: dos líneas distintas comparten la carpeta de su estructura 01.');

    // ── G: fotografías fuera del manifiesto sí se detectan ─────────────────
    $extra = progPost(93, 'L-600', '01');
    progWrite($root, $extra, 'ok', 5, false);
    $estadoG = backupProgressStructureState($extra, backupProgressIndex($root)['index']);
    checkProg($estadoG['fotos_presentes'] === 5, 'G: no detectó las fotografías no declaradas.');
    checkProg($estadoG['fotos'], 'G: con fotografías y nada declarado deberían contar como confirmadas.');
    checkProg($estadoG['formulario'], 'G: el formulario válido dejó de contar por no declarar fotos.');

    // ── H: estructura sin ceros a la izquierda ─────────────────────────────
    $sinCero = progPost(1, 'L-100', '1');
    checkProg(
        backupProgressStructureState($sinCero, $index['index'])['encontrada'],
        'H: `estructura=1` no encontró la carpeta `Estructura_01`.'
    );

    // ── I: basura en Fotos/ no infla el recuento ────────────────────────────
    $fotosL200 = $index['index']['l-200']['1']['ruta'] . DIRECTORY_SEPARATOR . 'Fotos';
    file_put_contents($fotosL200 . DIRECTORY_SEPARATOR . 'Thumbs.db', 'basura');
    file_put_contents($fotosL200 . DIRECTORY_SEPARATOR . '.parcial.jpg', progJpeg());
    file_put_contents($fotosL200 . DIRECTORY_SEPARATOR . '99_Inexistente.jpg', progJpeg());
    file_put_contents($fotosL200 . DIRECTORY_SEPARATOR . 'vacia.jpg', '');
    $estadoI = backupProgressStructureState($postes[10], backupProgressIndex($root)['index']);
    checkProg($estadoI['fotos_presentes'] === 26, 'I: la basura infló el recuento (' . $estadoI['fotos_presentes'] . ').');

    // ── J: nada de lo anterior escribió en el respaldo ─────────────────────
    // Se compara la lista de archivos antes y después de recorrerlo entero.
    $listar = static function (string $dir) use (&$listar): array {
        $out = [];
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = $dir . DIRECTORY_SEPARATOR . $e;
            $out[] = $p . '|' . (is_file($p) ? (string) filesize($p) : 'dir');
            if (is_dir($p)) {
                $out = array_merge($out, $listar($p));
            }
        }
        sort($out);
        return $out;
    };
    $antes = $listar($root);
    for ($i = 0; $i < 3; $i++) {
        $idx = backupProgressIndex($root);
        foreach ($postes as $post) {
            backupProgressStructureState($post, $idx['index']);
        }
    }
    checkProg($listar($root) === $antes, 'J: recorrer el respaldo modificó algún archivo.');

    // ── K: el índice se hace UNA vez y localiza todo ────────────────────────
    $indexK = backupProgressIndex($root);
    checkProg($indexK['dirs'] > 0, 'K: el índice no reportó carpetas visitadas.');
    // L-100, L-200, L-300, L-400 y L-600 tienen carpeta; L-500 sólo se consulta.
    checkProg(
        count($indexK['index']) === 5,
        'K: no indexó las 5 líneas con carpeta (dio ' . count($indexK['index']) . ').'
    );

    // ── L: normalización de nombres y evidencia real ───────────────────────
    checkProg(mobileBackupCompareKey('0090') === mobileBackupCompareKey('90'),
        'L: 0090 y 90 no se normalizaron a la misma estructura.');
    checkProg(mobileBackupCompareKey('64') !== mobileBackupCompareKey('64A'),
        'L: 64 se confundió con 64A.');
    checkProg(mobileBackupCompareKey('12-BIS') === mobileBackupCompareKey('12-bis'),
        'L: el sufijo alfanumérico no se comparó sin distinguir mayúsculas.');

    $variant = progPost(190, 'L-800', '90');
    $variantDir = $root . DIRECTORY_SEPARATOR . 'Variantes'
        . DIRECTORY_SEPARATOR . 'linea-l-800' . DIRECTORY_SEPARATOR . 'estructura 0090';
    mkdir($variantDir . DIRECTORY_SEPARATOR . 'Fotos', 0777, true);
    file_put_contents($variantDir . DIRECTORY_SEPARATOR . 'Fotos'
        . DIRECTORY_SEPARATOR . 'evidencia_sin_slot.jpg', progJpeg());
    $variantState = backupProgressStructureState($variant, backupProgressIndex($root)['index']);
    checkProg($variantState['encontrada'], 'L: no reconoció guion/espacio ni 0090 = 90.');
    checkProg($variantState['backup_presente'] && $variantState['inspeccionada'],
        'L: una foto física no mapeada no contó como evidencia.');
    checkProg($variantState['archivos_presentes'] === 1
        && $variantState['fotos_presentes'] === 0
        && $variantState['fotos_sin_mapear'] === 1,
        'L: no separó fotos físicas, mapeadas y no mapeadas.');
    checkProg(!$variantState['sincronizada'] && $variantState['estado'] === 'en_proceso',
        'L: tener evidencia se confundió con estar sincronizada.');

    $emptyPost = progPost(191, 'L-801', '01');
    $emptyDir = $root . DIRECTORY_SEPARATOR . 'Variantes'
        . DIRECTORY_SEPARATOR . 'Linea_L-801' . DIRECTORY_SEPARATOR . 'Estructura_01';
    mkdir($emptyDir, 0777, true);
    $emptyState = backupProgressStructureState($emptyPost, backupProgressIndex($root)['index']);
    checkProg($emptyState['encontrada'] && !$emptyState['backup_presente'],
        'L: una carpeta vacía se presentó como respaldo con evidencia.');
    checkProg($emptyState['estado'] === 'pendiente',
        'L: una carpeta vacía no quedó pendiente.');

    // ── M: duplicados sin mezclar evidencias ────────────────────────────────
    $duplicatePost = progPost(192, 'L-900', '01');
    $duplicateA = $root . DIRECTORY_SEPARATOR . 'Duplicado_A'
        . DIRECTORY_SEPARATOR . 'Linea_L-900' . DIRECTORY_SEPARATOR . 'Estructura_01';
    $duplicateB = $root . DIRECTORY_SEPARATOR . 'Duplicado_B'
        . DIRECTORY_SEPARATOR . 'Linea_L-900' . DIRECTORY_SEPARATOR . 'Estructura_001';
    mkdir($duplicateA, 0777, true);
    mkdir($duplicateB . DIRECTORY_SEPARATOR . 'Fotos', 0777, true);
    file_put_contents($duplicateB . DIRECTORY_SEPARATOR . 'Fotos'
        . DIRECTORY_SEPARATOR . '01_Panoramica.jpg', progJpeg());
    $unmatched = $root . DIRECTORY_SEPARATOR . 'Duplicado_B'
        . DIRECTORY_SEPARATOR . 'Linea_L-900' . DIRECTORY_SEPARATOR . 'Estructura_99';
    mkdir($unmatched, 0777, true);

    $duplicateIndex = backupProgressIndex($root);
    checkProg(count($duplicateIndex['index']['l-900']['1']['candidatas'] ?? []) === 2,
        'M: el índice sobrescribió una carpeta duplicada.');
    $duplicateState = backupProgressStructureState($duplicatePost, $duplicateIndex['index']);
    checkProg($duplicateState['duplicada'] && $duplicateState['carpetas_encontradas'] === 2,
        'M: el estado no informó las dos carpetas duplicadas.');
    checkProg($duplicateState['backup_presente'] && $duplicateState['archivos_presentes'] === 1,
        'M: no eligió la carpeta duplicada que sí contenía evidencia.');

    $missingPost = progPost(193, 'L-900', '02');
    $toPayload = static fn(array $post, array $state): array => [
        'poste_id' => $post['id'],
        'formulario' => [
            'existe' => $state['formulario_existe'],
            'valido' => $state['formulario'],
        ],
        'fotos' => ['archivos' => $state['archivos_presentes']],
        'estado' => $state['estado'],
    ];
    $diagnostic = backupProgressDiagnostic(
        [$duplicatePost, $missingPost],
        [
            $toPayload($duplicatePost, $duplicateState),
            $toPayload($missingPost, backupProgressEmptyState()),
        ],
        $duplicateIndex,
        'L-900'
    );
    checkProg($diagnostic['solo_lectura'] === true,
        'M: el diagnóstico no declara su carácter read-only.');
    checkProg($diagnostic['resumen']['total_padron'] === 2
        && $diagnostic['resumen']['carpetas_fisicas'] === 3
        && $diagnostic['resumen']['carpetas_asociadas'] === 2
        && $diagnostic['resumen']['no_asociadas'] === 1
        && $diagnostic['resumen']['duplicadas'] === 1,
        'M: el diagnóstico no explicó asociadas, no asociadas y duplicadas.');
    checkProg(count($diagnostic['mapeo_carpetas']) === 3,
        'M: el diagnóstico no listó cada carpeta física de la línea.');
} finally {
    progRemove($root);
}

echo $failures === []
    ? "OK: {$checks} comprobaciones del avance sobre el respaldo.\n"
    : "FALLOS ({$checks} comprobaciones):\n - " . implode("\n - ", $failures) . "\n";
exit($failures === [] ? 0 : 1);
