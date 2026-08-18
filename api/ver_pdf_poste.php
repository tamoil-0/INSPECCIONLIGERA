<?php
declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/services/PdfReportService.php';

requireMethod('GET');
requireAuth();
$postId = filter_input(INPUT_GET, 'poste_id', FILTER_VALIDATE_INT);
$projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT);
if (!$postId || !$projectId) {
    respond(['success' => false, 'error' => 'poste_id y proyecto_id son obligatorios.'], 422);
}

try {
    $pdf = buildPostReport($conn, $postId, $projectId);
    $content = $pdf->Output('S');
    $code = preg_replace('/[^A-Za-z0-9_-]+/', '_', $pdf->codigoPoste) ?: 'poste_' . $postId;
    header_remove('Content-Type');
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $code . '.pdf"');
    header('Content-Length: ' . strlen($content));
    echo $content;
    exit;
} catch (DomainException $exception) {
    respond(['success' => false, 'error' => $exception->getMessage()], 404);
} catch (Throwable $exception) {
    error_log('PDF generation failed: ' . $exception->getMessage());
    respond(['success' => false, 'error' => 'No se pudo generar el reporte PDF.'], 500);
}
