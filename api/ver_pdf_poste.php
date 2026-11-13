<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/services/PdfReportService.php';

requireMethod('GET');
$user = requireAuth();
$postId = filter_input(INPUT_GET, 'poste_id', FILTER_VALIDATE_INT);
$projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT);
if (!$postId || !$projectId) {
    respond(['success' => false, 'error' => 'poste_id y proyecto_id son obligatorios.'], 422);
}

/*
 * Fuente de fotografías y selección.
 *
 * `photo_source=current|backup` (NAS / Backup) lo puede pedir cualquier usuario
 * autorizado: es el selector de la vista normal del reporte. Qué fotografías se
 * imprimen NO se manda desde aquí: sale de la configuración que el
 * administrador guardó para esa estructura y esa fuente, y la aplica
 * `renderPostReport()`. Sin configuración guardada se imprimen todas.
 *
 * `slots` / `excluded_slots` siguen aceptados para saltarse esa configuración
 * de forma explícita, y eso sí queda reservado a administradores.
 *
 * Del cliente sólo se aceptan una clave de fuente y nombres de slot; la ruta de
 * cada archivo la vuelve a resolver el servidor. `slots` ausente significa
 * «todas»; `slots` presente y vacío significa «ninguna».
 */
$raw = [];
if (array_key_exists('photo_source', $_GET)) {
    $raw['photo_source'] = $_GET['photo_source'];
}
foreach (['slots', 'excluded_slots'] as $key) {
    if (array_key_exists($key, $_GET)) {
        $raw[$key] = $_GET[$key];
    }
}
$options = normalizeReportPhotoOptions($raw);
$overridesConfig = $options['included_slots'] !== null || $options['excluded_slots'] !== [];
if ($overridesConfig && ($user['rol'] ?? '') !== 'administrador') {
    respond([
        'success' => false,
        'error' => 'Solo un administrador puede imponer una selección de fotografías.',
    ], 403);
}

try {
    // renderPostReport() aplica la configuración guardada de esa fuente y
    // devuelve el PDF ya cacheado cuando ni el formulario, ni las fotografías,
    // ni la selección cambiaron desde la última vez: la huella de la caché
    // incluye la fuente y los slots excluidos, así que cada configuración tiene
    // su propio archivo.
    $report = renderPostReport($conn, $postId, $projectId, $options);
    $content = $report['contenido'];
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $report['codigo'] . '.pdf"');
    header('Content-Length: ' . strlen($content));
    // Diagnóstico para saber, desde el navegador, si el reporte salió de la
    // caché sin tener que abrir los logs del NAS.
    header('X-Ecoing-Pdf-Cache: ' . ($report['desde_cache'] ? 'hit' : 'miss'));
    header('X-Ecoing-File-Source: ' . $report['source']);
    header('X-Ecoing-Recovered-Files: ' . $report['recovered_files']);
    header('X-Ecoing-Missing-Files: ' . $report['missing_files']);
    header('X-Ecoing-Photo-Source: ' . $report['photo_options']['photo_source']);
    header('X-Ecoing-Photos-Included: ' . $report['photo_options']['incluidas']);
    header('X-Ecoing-Photos-Excluded: ' . $report['photo_options']['excluidas']);
    // Estado del formulario del respaldo: en `backup` el informe sale del
    // manifiesto, y si no estaba hay que poder decirlo en pantalla.
    header('X-Ecoing-Backup-Form: ' . ($report['photo_options']['form_state'] ?? 'n/a'));
    echo $content;
    exit;
} catch (DomainException $exception) {
    respond(['success' => false, 'error' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    // Se registra clase y origen, no sólo el mensaje: un "Argument #2 must be
    // of type GdImage" sin fichero ni línea no dice qué fotografía lo provocó.
    // Al cliente sigue yendo el mismo texto genérico.
    error_log(sprintf(
        'PDF generation failed (poste %d, proyecto %d, fotos %s): %s: %s en %s:%d',
        $postId,
        $projectId,
        $options['photo_source'],
        get_class($exception),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    ));
    respond(['success' => false, 'error' => 'No se pudo generar el reporte PDF.'], 500);
}
