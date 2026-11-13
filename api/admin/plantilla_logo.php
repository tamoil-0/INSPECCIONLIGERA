<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/PlantillaService.php';

requireMethod('GET', 'POST');
requireAuth(['administrador']);

$plantillaId = (int) filter_var($_REQUEST['plantilla_id'] ?? 0, FILTER_VALIDATE_INT);
if ($plantillaId <= 0) {
    respond(['success' => false, 'error' => 'plantilla_id no es válido.'], 422);
}

try {
    if (!plantillaPorId($conn, $plantillaId)) {
        respond(['success' => false, 'error' => 'Plantilla no encontrada.'], 404);
    }

    if (strtoupper($_SERVER['REQUEST_METHOD']) === 'GET') {
        $ruta = plantillaRutaLogo($plantillaId, (string) ($_GET['archivo'] ?? ''), $conn);
        if ($ruta === null) {
            respond(['success' => false, 'error' => 'Logo no encontrado.'], 404);
        }
        header_remove('Content-Type');
        header('Content-Type: image/png');
        header('Content-Length: ' . filesize($ruta));
        // Caché corta: si el archivo faltara en el servidor, el editor lo nota pronto.
        header('Cache-Control: private, max-age=600');
        readfile($ruta);
        exit;
    }

    // El logo queda guardado pero no se usa hasta que el administrador guarde
    // la plantilla con ese nombre de archivo: subir no cambia ningún PDF.
    $archivo = plantillaGuardarLogo($_FILES['archivo'] ?? [], $plantillaId, (string) ($_POST['lado'] ?? ''), $conn);
    respond(['success' => true, 'archivo' => $archivo]);
} catch (InvalidArgumentException $e) {
    respond(['success' => false, 'error' => $e->getMessage()], 422);
} catch (Throwable $e) {
    appLog('administracion', 'Fallo al subir un logo.', ['motivo' => $e->getMessage()]);
    respond(['success' => false, 'error' => 'No se pudo guardar el logo.'], 500);
}
