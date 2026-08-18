<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/services/PdfReportService.php';

requireMethod('GET');
requireAuth();
set_time_limit(1500);
ini_set('memory_limit', '512M');

$projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT);
$line = trim((string) ($_GET['linea'] ?? ''));
if (!$projectId || $line === '') {
    respond(['success' => false, 'error' => 'proyecto_id y linea son obligatorios.'], 422);
}

$statement = $conn->prepare('SELECT id, codigo FROM postes WHERE proyecto_id = ? AND linea = ? ORDER BY codigo');
$statement->execute([$projectId, $line]);
$posts = $statement->fetchAll();
if (!$posts) {
    respond(['success' => false, 'error' => 'No se encontraron postes para esa línea.'], 404);
}

$temporary = tempnam(sys_get_temp_dir(), 'ecoing_zip_');
$zip = new ZipArchive();
if ($temporary === false || $zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    respond(['success' => false, 'error' => 'No se pudo iniciar el archivo ZIP.'], 500);
}

$generated = 0;
$errors = [];
foreach ($posts as $post) {
    try {
        $pdf = buildPostReport($conn, (int) $post['id'], $projectId);
        $filename = (preg_replace('/[^A-Za-z0-9_-]+/', '_', $post['codigo']) ?: 'poste_' . $post['id']) . '.pdf';
        $zip->addFromString($filename, $pdf->Output('S'));
        $generated++;
        unset($pdf);
    } catch (Throwable $exception) {
        error_log('Line PDF item failed: ' . $exception->getMessage());
        $errors[] = 'Poste ' . $post['codigo'] . ': no se pudo generar.';
    }
}
if ($errors) {
    $zip->addFromString('errores.txt', implode(PHP_EOL, $errors));
}
$zip->close();

if ($generated === 0) {
    @unlink($temporary);
    respond(['success' => false, 'error' => 'No se pudo generar ningún PDF.'], 500);
}

$filename = 'linea_' . (preg_replace('/[^A-Za-z0-9_-]+/', '_', $line) ?: 'exporte') . '.zip';
header_remove('Content-Type');
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($temporary));
readfile($temporary);
unlink($temporary);
exit;

