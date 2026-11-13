<?php
declare(strict_types=1);

/**
 * Avance por línea calculado sobre el respaldo del NAS.
 *
 * El panel de líneas cuenta con las banderas de la BD, que describen lo que
 * llegó al servidor. Esto responde lo mismo mirando sólo `/data/elpu`, para
 * poder comparar las dos fuentes sin mezclarlas.
 *
 * Sólo lectura: no escribe en la base, ni en el respaldo, ni en el almacén.
 *
 *   GET api/proyectos/avance_respaldo.php?proyecto_id=2[&linea=L-6028]
 *
 * El total de estructuras de cada línea sale del padrón de la BD —es el
 * universo oficial del proyecto—; el resto de los contadores, del respaldo.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/BackupProgressService.php';

requireMethod('GET');
requireAuth();

$projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT);
if (!$projectId) {
    respond(['success' => false, 'error' => 'Se requiere proyecto_id válido.'], 422);
}
// La línea se compara tal cual contra `postes.linea` en una consulta preparada:
// no forma parte de ninguna ruta ni se concatena en el SQL.
$line = trim((string) ($_GET['linea'] ?? ''));
if ($line !== '' && mb_strlen($line) > 100) {
    respond(['success' => false, 'error' => 'El nombre de la línea no es válido.'], 422);
}

try {
    $progress = backupProgressByLine($conn, $projectId, $line !== '' ? $line : null);
    if (!$progress['respaldo_configurado']) {
        // Nunca se devuelven los datos del NAS como si fueran del respaldo: si
        // la raíz no está montada, se dice.
        respond([
            'success' => false,
            'error' => 'El respaldo no está disponible en este servidor.',
        ], 503);
    }

    respond(['success' => true, 'data' => $progress]);
} catch (DomainException $exception) {
    respond(['success' => false, 'error' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    error_log(sprintf(
        'Backup progress failed (proyecto %d, linea %s): %s: %s en %s:%d',
        $projectId,
        $line !== '' ? $line : '(todas)',
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
    respond([
        'success' => false,
        'error' => 'No se pudo calcular el avance del respaldo.',
    ], 500);
}
