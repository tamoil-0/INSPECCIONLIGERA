<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/StructureRecoveryService.php';

$GLOBALS['app_environment']['MOBILE_BACKUP_STABILITY_SECONDS'] = '0';
$failures = [];
$checks = 0;

function checkRecovery(bool $condition, string $message): void
{
    global $failures, $checks;
    $checks++;
    if (!$condition) {
        $failures[] = $message;
    }
}

function recoveryTestImage(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwC'
        . 'AAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
    );
}

function recoveryTestPost(): array
{
    return [
        'id' => 25,
        'proyecto_id' => 7,
        'codigo' => 'L-6021-025',
        'linea' => 'L-6021',
        'estructura' => '025',
    ];
}

function recoveryTestDirectory(string $root): string
{
    return $root . DIRECTORY_SEPARATOR . 'Inspeccion Ligera 2026'
        . DIRECTORY_SEPARATOR . 'Proyecto_7'
        . DIRECTORY_SEPARATOR . 'Linea_L-6021'
        . DIRECTORY_SEPARATOR . 'Estructura_025';
}

function createRecoveryBackup(
    string $root,
    int $declared,
    ?int $present = null,
    bool $corruptJson = false
): array {
    $present ??= $declared;
    $directory = recoveryTestDirectory($root);
    $photoDirectory = $directory . DIRECTORY_SEPARATOR . 'Fotos';
    $formDirectory = $directory . DIRECTORY_SEPARATOR . 'Formulario';
    mkdir($photoDirectory, 0777, true);
    mkdir($formDirectory, 0777, true);
    $image = recoveryTestImage();
    $files = [];
    for ($index = 1; $index <= $declared; $index++) {
        $type = 'foto_' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);
        $name = $type . '.png';
        if ($index <= $present) {
            file_put_contents($photoDirectory . DIRECTORY_SEPARATOR . $name, $image);
        }
        $files[] = [
            'tipo' => $type,
            'archivo' => $name,
            'bytes' => strlen($image),
            'checksum_sha256' => hash('sha256', $image),
        ];
    }
    $manifest = [
        'generado_en' => '2026-08-24T10:00:00-05:00',
        'estructura' => [
            'poste_id' => 25,
            'codigo' => 'L-6021-025',
            'estructura' => '025',
            'linea' => 'L-6021',
            'proyecto_id' => '7',
        ],
        'fotografias' => ['archivos' => $files],
        'formulario' => ['comentarios' => 'respaldo'],
        'secciones_rst' => [],
    ];
    $manifestFile = $formDirectory . DIRECTORY_SEPARATOR . 'formulario.json';
    file_put_contents(
        $manifestFile,
        $corruptJson ? '{invalido' : json_encode($manifest, JSON_UNESCAPED_UNICODE)
    );
    return [$directory, $files];
}

function removeRecoveryTree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $target = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($target)) {
            removeRecoveryTree($target);
        } else {
            unlink($target);
        }
    }
    rmdir($path);
}

function recoveryOfficialRows(string $directory, int $count): array
{
    $image = recoveryTestImage();
    $rows = [];
    for ($index = 1; $index <= $count; $index++) {
        $type = 'foto_' . str_pad((string) $index, 2, '0', STR_PAD_LEFT);
        $file = $directory . DIRECTORY_SEPARATOR . 'official_' . $index . '.png';
        file_put_contents($file, $image);
        $rows[] = [
            'nombre_foto' => $type,
            'ruta_archivo' => null,
            'ruta_resuelta' => $file,
            'fecha_captura' => '2026-08-24 11:00:00',
        ];
    }
    return $rows;
}

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'ecoing_recovery_' . bin2hex(random_bytes(6));
mkdir($root, 0777, true);
$post = recoveryTestPost();

try {
    // A: ambas fuentes completas; siempre gana la oficial.
    $caseA = $root . DIRECTORY_SEPARATOR . 'case_a';
    createRecoveryBackup($caseA, 2);
    $backupA = inspectMobileBackup($post, $caseA, 0);
    $officialA = recoveryOfficialRows($caseA, 2);
    $mergedA = mergeStructureImages($officialA, ['foto_01', 'foto_02'], $backupA);
    checkRecovery($mergedA['source'] === 'official', 'A: no priorizó oficial');
    checkRecovery($mergedA['backup_used'] === 0, 'A: usó respaldo sin necesidad');

    // B: oficial completo sin respaldo.
    $empty = [
        'status' => RECOVERY_NO_FILES,
        'photos' => [],
        'declared_types' => [],
    ];
    $mergedB = mergeStructureImages($officialA, ['foto_01', 'foto_02'], $empty);
    checkRecovery($mergedB['missing'] === [], 'B: oficial completo quedó incompleto');

    // C: una oficial y otra recuperada del respaldo.
    $mergedC = mergeStructureImages(
        [$officialA[0]],
        ['foto_01', 'foto_02'],
        $backupA
    );
    checkRecovery($mergedC['source'] === 'mixed', 'C: no detectó origen mixto');
    checkRecovery($mergedC['backup_used'] === 1, 'C: no recuperó la faltante');

    // D: las dos fuentes incompletas.
    $caseD = $root . DIRECTORY_SEPARATOR . 'case_d';
    createRecoveryBackup($caseD, 2, 1);
    $backupD = inspectMobileBackup($post, $caseD, 0);
    $mergedD = mergeStructureImages([], ['foto_01', 'foto_02'], $backupD);
    checkRecovery(count($mergedD['missing']) === 1, 'D: no informó la faltante');
    checkRecovery($backupD['status'] === RECOVERY_BACKUP_PARTIAL, 'D: respaldo no quedó parcial');

    // E, F y G: la integridad depende de lo declarado, nunca de 28.
    foreach ([5 => 'E', 28 => 'F', 35 => 'G'] as $count => $label) {
        $case = $root . DIRECTORY_SEPARATOR . 'case_' . strtolower($label);
        createRecoveryBackup($case, $count);
        $inspected = inspectMobileBackup($post, $case, 0);
        checkRecovery(
            $inspected['status'] === RECOVERY_BACKUP_AVAILABLE,
            $label . ': cantidad válida fue marcada incompleta'
        );
        checkRecovery(
            count($inspected['photos']) === $count,
            $label . ': cantidad encontrada incorrecta'
        );
    }

    // H: 13 declaradas, 11 presentes.
    $caseH = $root . DIRECTORY_SEPARATOR . 'case_h';
    createRecoveryBackup($caseH, 13, 11);
    $backupH = inspectMobileBackup($post, $caseH, 0);
    checkRecovery(count($backupH['photos']) === 11, 'H: no encontró 11 fotos');
    checkRecovery(count($backupH['missing']) === 2, 'H: no informó 2 faltantes');

    // I: un oficial válido gana aunque exista un respaldo.
    $mergedI = mergeStructureImages($officialA, ['foto_01', 'foto_02'], $backupA);
    checkRecovery(
        array_column($mergedI['images'], 'source') === ['official', 'official'],
        'I: el respaldo sustituyó archivos oficiales válidos'
    );

    // J: JSON corrupto no lanza ni rompe el flujo oficial.
    $caseJ = $root . DIRECTORY_SEPARATOR . 'case_j';
    createRecoveryBackup($caseJ, 1, 1, true);
    $backupJ = inspectMobileBackup($post, $caseJ, 0);
    checkRecovery($backupJ['status'] === RECOVERY_INTEGRITY_ERROR, 'J: JSON corrupto no detectado');
    $mergedJ = mergeStructureImages($officialA, ['foto_01', 'foto_02'], $backupJ);
    checkRecovery($mergedJ['source'] === 'official', 'J: JSON corrupto afectó al oficial');

    // K: archivo todavía ausente/parcial no se usa silenciosamente.
    $caseK = $root . DIRECTORY_SEPARATOR . 'case_k';
    createRecoveryBackup($caseK, 1, 0);
    $backupK = inspectMobileBackup($post, $caseK, 0);
    checkRecovery($backupK['status'] === RECOVERY_BACKUP_PARTIAL, 'K: parcial no detectado');
    checkRecovery($backupK['photos'] === [], 'K: archivo parcial fue aceptado');

    // L: una vista no registrada no invalida las fotos que sí se capturaron.
    $caseL = $root . DIRECTORY_SEPARATOR . 'case_l';
    mkdir($caseL, 0777, true);
    $officialL = recoveryOfficialRows($caseL, 4);
    $mergedL = mergeStructureImages(
        $officialL,
        ['foto_01', 'foto_02', 'foto_03', 'foto_04'],
        $empty
    );
    checkRecovery($mergedL['missing'] === [], 'L: foto opcional no registrada bloqueó el resultado');
} finally {
    removeRecoveryTree($root);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo 'OK - ' . $checks . ' comprobaciones de recuperación' . PHP_EOL;
