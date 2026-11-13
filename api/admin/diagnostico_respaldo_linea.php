<?php
declare(strict_types=1);

/**
 * Por qué el respaldo de una línea detecta menos estructuras de las que hay.
 *
 * Sólo lectura y sólo para administradores. Compara el padrón con las carpetas
 * físicas del respaldo y, para CADA discrepancia, dice el motivo concreto en vez
 * de dejar un número sin explicación.
 *
 * No escribe en la base, ni en el respaldo, ni en el almacén.
 *
 *   GET api/admin/diagnostico_respaldo_linea.php?proyecto_id=2&linea=L-6021
 *
 * También se puede ejecutar dentro del contenedor sin pasar por HTTP:
 *
 *   docker exec ecoing-app php /var/www/html/api/admin/diagnostico_respaldo_linea.php 2 L-6021
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/BackupProgressService.php';

$esCli = PHP_SAPI === 'cli';
if (!$esCli) {
    require_once __DIR__ . '/../config/auth.php';
    requireMethod('GET');
    requireAuth(['administrador']);
    $projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT);
    $line = trim((string) ($_GET['linea'] ?? ''));
} else {
    $projectId = (int) ($argv[1] ?? 0);
    $line = trim((string) ($argv[2] ?? ''));
}
if (!$projectId || $line === '') {
    $error = ['success' => false, 'error' => 'Se requieren proyecto_id y linea.'];
    if ($esCli) {
        fwrite(STDERR, "uso: php diagnostico_respaldo_linea.php <proyecto_id> <linea>\n");
        exit(2);
    }
    respond($error, 422);
}

/**
 * Versión actual: reutiliza exactamente el mismo barrido e indexado que pinta
 * la UI. Así el diagnóstico no puede discrepar del contador que está explicando.
 */
function diagnoseBackupLine(PDO $connection, int $projectId, string $line): array
{
    $progress = backupProgressByLine($connection, $projectId, $line, null, true);
    $diagnostic = $progress['diagnostico'];
    $summary = $diagnostic['resumen'];

    return [
        'solo_lectura' => true,
        'linea' => $line,
        'proyecto_id' => $projectId,
        'respaldo_configurado' => $progress['respaldo_configurado'],
        'carpetas_indexadas_total' => $diagnostic['carpetas_visitadas'],
        'indice_truncado' => $diagnostic['indice_truncado'],
        'resumen' => [
            'padron_oficial' => $summary['total_padron'],
            'carpetas_backup' => $summary['carpetas_fisicas'],
            'carpetas_asociadas' => $summary['carpetas_asociadas'],
            'estructuras_asociadas' => $summary['estructuras_asociadas'],
            'no_asociadas' => $summary['no_asociadas'],
            'duplicadas' => $summary['duplicadas'],
            'con_formulario' => $summary['con_formulario'],
            'con_formulario_valido' => $summary['formulario_valido'],
            'con_formulario_invalido' => $summary['formulario_invalido'],
            'sin_formulario' => $summary['sin_formulario'],
            'con_fotos' => $summary['con_fotos'],
            'sin_fotos' => $summary['sin_fotos'],
            'sincronizadas' => $summary['sincronizadas'],
            'en_proceso' => $summary['en_proceso'],
            'pendientes' => $summary['pendientes'],
            // Avance que se muestra en modo Backup. Por construcción:
            //   respaldadas = sincronizadas + en_proceso  <=  padron_oficial
            'con_evidencia' => $summary['sincronizadas'] + $summary['en_proceso'],
            'respaldadas' => $summary['sincronizadas'] + $summary['en_proceso'],
            'fuera_de_layout' => $summary['fuera_de_layout'],
        ],
        'estructuras' => $progress['estructuras'],
        'mapeo_carpetas' => $diagnostic['mapeo_carpetas'],
        'fuera_de_layout' => $diagnostic['fuera_de_layout'],
        'ms' => $progress['ms'],
    ];
}

try {
    $data = diagnoseBackupLine($conn, $projectId, $line);
    if (!$esCli) {
        respond(['success' => true, 'data' => $data]);
    }

    // Salida legible para ejecutarlo dentro del contenedor.
    $r = $data['resumen'];
    printf("%s\n%s\n", $data['linea'], str_repeat('-', 32));
    printf("Padrón oficial:            %d\n", $r['padron_oficial']);
    printf("Carpetas Backup:           %d\n", $r['carpetas_backup']);
    printf("Carpetas asociadas:        %d\n", $r['carpetas_asociadas']);
    printf("Estructuras asociadas:     %d\n", $r['estructuras_asociadas']);
    printf("No asociadas:              %d\n", $r['no_asociadas']);
    printf("Duplicadas:                %d\n", $r['duplicadas']);
    printf("Con formulario:            %d\n", $r['con_formulario']);
    printf("Con formulario válido:     %d\n", $r['con_formulario_valido']);
    printf("Con formulario inválido:   %d\n", $r['con_formulario_invalido']);
    printf("Sin formulario:            %d\n", $r['sin_formulario']);
    printf("Con fotos:                 %d\n", $r['con_fotos']);
    printf("Sin fotos:                 %d\n", $r['sin_fotos']);
    printf("Sincronizadas:             %d\n", $r['sincronizadas']);
    printf("En proceso:                %d\n", $r['en_proceso']);
    printf("Pendientes:                %d\n", $r['pendientes']);
    printf("RESPALDADAS (avance):      %d de %d = %d%%\n",
        $r['respaldadas'],
        $r['padron_oficial'],
        $r['padron_oficial'] > 0
            ? (int) round($r['respaldadas'] / $r['padron_oficial'] * 100)
            : 0);
    printf("  respaldadas = sincronizadas + en_proceso : %s\n",
        $r['respaldadas'] === $r['sincronizadas'] + $r['en_proceso'] ? 'ok' : 'NO CUADRA');
    printf("  respaldadas <= padron                    : %s\n",
        $r['respaldadas'] <= $r['padron_oficial'] ? 'ok' : 'NO CUADRA');
    printf("(%d carpetas recorridas, %.0f ms)\n\n", $data['carpetas_indexadas_total'], $data['ms']);

    echo "\nCARPETA FÍSICA -> ESTRUCTURA:\n";
    foreach ($data['mapeo_carpetas'] as $mapping) {
        printf(
            "  %s -> %s%s\n",
            $mapping['carpeta'],
            $mapping['asociada']
                ? (string) $mapping['estructura_asociada']
                : 'NO ASOCIADA: ' . (string) $mapping['razon'],
            $mapping['duplicada'] ? ' (DUPLICADA)' : ''
        );
    }
    foreach ($data['fuera_de_layout'] as $mapping) {
        printf("  %s -> NO ASOCIADA: %s\n", $mapping['carpeta'], $mapping['razon']);
    }
    exit(0);
} catch (Throwable $exception) {
    if ($esCli) {
        fwrite(STDERR, $exception->getMessage() . "\n");
        exit(1);
    }
    error_log(sprintf(
        'Backup line diagnostics failed (proyecto %d, linea %s): %s: %s en %s:%d',
        $projectId,
        $line,
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
    respond(['success' => false, 'error' => 'No se pudo diagnosticar el respaldo de la línea.'], 500);
}
