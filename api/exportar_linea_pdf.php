<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/services/PdfReportService.php';
require_once __DIR__ . '/services/LineReportZip.php';

/*
 * Exporte de los reportes de una línea en un ZIP.
 *
 * La fuente de fotografías es global para el lote y se puede pasar por GET,
 * que es lo que usa el selector de la pantalla de estructuras:
 *
 *     GET api/exportar_linea_pdf.php?proyecto_id=2&linea=L-6021&photo_source=backup
 *
 * QUÉ fotografías entran en cada PDF no se manda: cada estructura respeta
 * automáticamente la configuración que el administrador guardó para ella y
 * para esa fuente. Sin configuración guardada se imprimen todas. Por eso no hay
 * que configurar nada durante la generación del ZIP.
 *
 * POST sigue aceptando una selección explícita, global o por estructura, para
 * saltarse esa configuración. Eso queda reservado a administradores:
 *
 *     {
 *       "proyecto_id": 2, "linea": "L-6021", "photo_source": "backup",
 *       "slots": ["placa", ...] | null,
 *       "overrides": { "1240": { "photo_source": "current", "slots": [...] } }
 *     }
 *
 * Los PDF salen del mismo `renderPostReport()` que el reporte individual: no
 * hay una segunda lógica de generación para el lote.
 */
requireMethod('GET', 'POST');
$user = requireAuth();
// Solo esta exportación larga: no aumenta los límites del resto de la API.
set_time_limit(7200);
ini_set('memory_limit', '4096M');

$payload = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    $decoded = json_decode(is_string($body) ? $body : '', true);
    if (!is_array($decoded)) {
        respond(['success' => false, 'error' => 'El cuerpo de la solicitud no es un JSON válido.'], 422);
    }
    $payload = $decoded;
}

$projectId = filter_var($payload['proyecto_id'] ?? $_GET['proyecto_id'] ?? null, FILTER_VALIDATE_INT);
$line = trim((string) ($payload['linea'] ?? $_GET['linea'] ?? ''));
if (!$projectId || $line === '') {
    respond(['success' => false, 'error' => 'proyecto_id y linea son obligatorios.'], 422);
}

$raw = $payload;
if (!array_key_exists('photo_source', $raw) && array_key_exists('photo_source', $_GET)) {
    $raw['photo_source'] = $_GET['photo_source'];
}
$globalOptions = normalizeReportPhotoOptions($raw);
$overrides = [];
foreach (is_array($payload['overrides'] ?? null) ? $payload['overrides'] : [] as $key => $override) {
    $id = filter_var($key, FILTER_VALIDATE_INT);
    if ($id !== false && $id > 0 && is_array($override)) {
        $overrides[$id] = normalizeReportPhotoOptions($override);
    }
}
// Elegir la fuente lo puede hacer cualquier usuario autorizado. Imponer una
// selección de fotografías —saltarse la configuración guardada— no.
$imposesSelection = $globalOptions['included_slots'] !== null
    || $globalOptions['excluded_slots'] !== []
    || $overrides !== [];
if ($imposesSelection && ($user['rol'] ?? '') !== 'administrador') {
    respond([
        'success' => false,
        'error' => 'Solo un administrador puede imponer una selección de fotografías.',
    ], 403);
}

$statement = $conn->prepare('SELECT id, codigo FROM postes WHERE proyecto_id = ? AND linea = ? ORDER BY codigo');
$statement->execute([$projectId, $line]);
$posts = $statement->fetchAll();
if (!$posts) {
    respond(['success' => false, 'error' => 'No se encontraron postes para esa línea.'], 404);
}
// Un override sólo vale para una estructura de esta línea: los demás se
// descartan en vez de aplicarse a ciegas.
$overrides = array_intersect_key($overrides, array_flip(array_map('intval', array_column($posts, 'id'))));

try {
    $zip = new LineReportZip(storagePath('exports', true));
} catch (Throwable $exception) {
    error_log('Line ZIP initialization failed: ' . $exception->getMessage());
    respond(['success' => false, 'error' => 'No se pudo iniciar el archivo ZIP.'], 500);
}

$started = hrtime(true);
$generated = 0;
$fromCache = 0;
$excluded = 0;
$errors = [];
foreach ($posts as $post) {
    $postId = (int) $post['id'];
    try {
        // Cada poste pasa por la caché: en una línea de 200 estructuras donde
        // solo se reinspeccionaron 3, se regeneran 3 PDF y se reutilizan 197.
        // Antes se recomponían los 200 en cada descarga. La huella incluye la
        // fuente y la selección, así que cada fuente tiene su propia entrada y
        // repetir el ZIP con la misma fuente vuelve a acertar la caché.
        $report = renderPostReport($conn, $postId, $projectId, $overrides[$postId] ?? $globalOptions);
        $filename = (preg_replace('/[^A-Za-z0-9_-]+/', '_', $post['codigo']) ?: 'poste_' . $postId) . '.pdf';
        $zip->addPdf($filename, $report['contenido']);
        $generated++;
        $fromCache += $report['desde_cache'] ? 1 : 0;
        $excluded += (int) $report['photo_options']['excluidas'];
        unset($report);
    } catch (Throwable $exception) {
        error_log('Line PDF item failed: ' . $exception->getMessage());
        $errors[] = 'Poste ' . $post['codigo'] . ': no se pudo generar.';
    }
}

appMetric('exporte de PDF por linea', [
    'proyecto' => $projectId,
    'linea' => $line,
    'postes' => count($posts),
    'generados' => $generated,
    'desde_cache' => $fromCache,
    'errores' => count($errors),
    'ms' => elapsedMs($started),
]);
appLog('sincronizacion', 'Exporte de reportes por linea resuelto.', [
    'project' => $projectId,
    'action' => 'generate_line_zip',
    'structures' => $generated,
    'photo_source' => $globalOptions['photo_source'],
    'overrides' => count($overrides),
    'photos_excluded' => $excluded,
    'errors' => count($errors),
]);
if ($generated === 0) {
    $zip->cleanup();
    respond(['success' => false, 'error' => 'No se pudo generar ningún PDF.'], 500);
}
try {
    $temporary = $zip->finish($errors);
} catch (Throwable $exception) {
    error_log('Line ZIP completion failed: ' . $exception->getMessage());
    $zip->cleanup();
    respond(['success' => false, 'error' => 'No se pudo completar el ZIP. Compruebe el espacio disponible para exportaciones.'], 500);
}

$filename = 'linea_' . (preg_replace('/[^A-Za-z0-9_-]+/', '_', $line) ?: 'exporte') . '.zip';
header_remove('Content-Type');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($temporary));
header('X-Ecoing-Photo-Source: ' . $globalOptions['photo_source']);
header('X-Ecoing-Photo-Overrides: ' . count($overrides));
header('X-Ecoing-Photos-Excluded: ' . $excluded);
readfile($temporary);
$zip->cleanup();
exit;
