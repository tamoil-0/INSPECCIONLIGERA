<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/domain.php';
require_once __DIR__ . '/../clases/PDFPoste.php';
require_once __DIR__ . '/../clases/PDFFormularioPoste.php';
require_once __DIR__ . '/../clases/PDFImagenesPoste.php';

if (!function_exists('log_pdf')) {
    function log_pdf(string $message): void
    {
        error_log('PDF: ' . $message);
    }
}

function addReportSignatures(PDFPoste $pdf): void
{
    $pdf->Ln(20);
    $pdf->SetTextColor(0);
    $width = ($pdf->GetPageWidth() - 20) / 2;
    $left = 10;
    $right = $left + $width;
    $y = $pdf->GetY();

    $contractorName = trim((string) envValue('SUPERVISOR_CONTRATISTA_NOMBRE', ''));
    $contractorCode = trim((string) envValue('SUPERVISOR_CONTRATISTA_CIP', ''));
    $clientName = trim((string) envValue('SUPERVISOR_CLIENTE_NOMBRE', ''));
    $clientCode = trim((string) envValue('SUPERVISOR_CLIENTE_CIP', ''));

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY($left, $y);
    $pdf->Cell($width, 6, '__________________________', 0, 0, 'C');
    $pdf->SetXY($right, $y);
    $pdf->Cell($width, 6, '__________________________', 0, 1, 'C');

    $pdf->SetXY($left, $y + 5);
    $pdf->Cell($width, 6, utf8_decode($contractorName ?: 'Supervisor del contratista'), 0, 0, 'C');
    $pdf->SetXY($right, $y + 5);
    $pdf->Cell($width, 6, utf8_decode($clientName ?: 'Supervisor del cliente'), 0, 1, 'C');

    $pdf->SetXY($left, $y + 10);
    $pdf->Cell($width, 6, utf8_decode($contractorCode ? 'CIP: ' . $contractorCode : 'Contratista'), 0, 0, 'C');
    $pdf->SetXY($right, $y + 10);
    $pdf->Cell($width, 6, utf8_decode($clientCode ? 'CIP: ' . $clientCode : 'Cliente'), 0, 1, 'C');

    $pdf->SetXY($left, $y + 15);
    $pdf->Cell($width, 6, 'Fecha: __________________', 0, 0, 'C');
    $pdf->SetXY($right, $y + 15);
    $pdf->Cell($width, 6, 'Fecha: __________________', 0, 1, 'C');
}

function buildPostReport(PDO $connection, int $postId, int $projectId): PDFPoste
{
    $statement = $connection->prepare(
        'SELECT p.*, pr.nombre_proyecto, pr.contratista, pr.ubicacion AS proyecto_ubicacion
         FROM postes p
         INNER JOIN proyectos pr ON pr.id = p.proyecto_id
         WHERE p.id = ? AND p.proyecto_id = ?'
    );
    $statement->execute([$postId, $projectId]);
    $post = $statement->fetch();
    if (!$post) {
        throw new DomainException('El poste no existe dentro del proyecto indicado.');
    }

    $detailsStatement = $connection->prepare('SELECT * FROM poste_datos WHERE poste_id = ?');
    $detailsStatement->execute([$postId]);
    $details = $detailsStatement->fetch() ?: [];

    $rstStatement = $connection->prepare('SELECT seccion, atributo, fase FROM poste_secciones_rst WHERE poste_id = ? ORDER BY seccion, fase');
    $rstStatement->execute([$postId]);
    $rst = $rstStatement->fetchAll();

    $imageStatement = $connection->prepare('SELECT nombre_foto, ruta_archivo, fecha_captura FROM imagenes_poste WHERE poste_id = ? ORDER BY nombre_foto');
    $imageStatement->execute([$postId]);
    $images = $imageStatement->fetchAll();

    $panoramicPath = null;
    foreach ($images as $index => $image) {
        if ($image['nombre_foto'] === 'foto_panoramica') {
            $panoramicPath = resolveStoredImagePath($image['ruta_archivo']);
            unset($images[$index]);
            break;
        }
    }
    $images = array_values($images);

    $pdf = new PDFPoste();
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->SetMargins(10, 10, 10);
    $pdf->tituloProyecto = $post['nombre_proyecto'];
    $pdf->codigoPoste = $post['codigo'];
    $pdf->contratista = $post['contratista'];
    $pdf->ubicacion = $post['ubicaciones'] ?: $post['proyecto_ubicacion'];
    $pdf->utm_x = (string) ($post['utm_x'] ?? '');
    $pdf->utm_y = (string) ($post['utm_y'] ?? '');
    $pdf->zona = (string) ($post['zona'] ?? '');
    $pdf->fecha_inspeccion = $post['fecha_inspeccion']
        ? date('d/m/Y H:i:s', strtotime($post['fecha_inspeccion']))
        : 'Sin fecha';

    $pdf->AddPage();
    agregarFormularioPoste($pdf, $details, $rst, $panoramicPath);
    $pdf->AddPage();
    agregarImagenesPoste($pdf, $images);
    agregarPanelAisladores($pdf, $images);
    agregarPanelPersonalizado($pdf, 'CABLE DE GUARDA Y OPGW', $images, [
        ['nombre' => 'cable_guarda', 'titulo' => 'Cable de Guarda'],
        ['nombre' => 'ferreteria_de_cable_de_guarda', 'titulo' => 'Ferretería de cable de guarda'],
    ], ['numColumnas' => 2, 'colorTitulo' => [48, 84, 150], 'colorTexto' => [48, 84, 150]]);
    agregarPanelPersonalizado($pdf, 'CONDUCTORES', $images, [
        ['nombre' => 'conductor', 'titulo' => 'Conductor'],
        ['nombre' => 'ferreteria_de_conductor', 'titulo' => 'Ferretería de conductor'],
    ], ['numColumnas' => 2, 'colorTitulo' => [84, 130, 53], 'colorTexto' => [84, 130, 53]]);

    $pdf->AddPage();
    agregarPanelPersonalizado($pdf, '', $images, [
        ['nombre' => 'puesta_tierra', 'titulo' => 'Puesta a Tierra', 'color' => [198, 89, 17]],
        ['nombre' => 'puesta_tierra_2', 'titulo' => 'Puesta a Tierra 2', 'color' => [198, 89, 17]],
    ], ['numColumnas' => 2, 'tituloArriba' => true]);
    agregarPanelPersonalizado($pdf, 'FAJA DE SERVIDUMBRE / UBICACIÓN ACCESO', $images, [
        ['nombre' => 'faja_servidumbre', 'titulo' => ''],
        ['nombre' => 'ubicacion_acceso', 'titulo' => ''],
    ], ['numColumnas' => 2, 'colorTitulo' => [123, 123, 123], 'ocultarNombres' => true]);
    agregarPanelPersonalizado($pdf, 'RETENIDA', $images, [
        ['nombre' => 'retenida', 'titulo' => ''],
    ], ['numColumnas' => 1, 'anchoCelda' => ($pdf->GetPageWidth() - 20) / 2, 'colorTitulo' => [84, 130, 53], 'ocultarNombres' => true]);

    $pdf->Ln(5);
    agregarComentariosPoste($pdf, $details['comentarios'] ?? '');
    if ($pdf->GetPageHeight() - $pdf->GetY() < 40) {
        $pdf->AddPage();
    }
    addReportSignatures($pdf);
    return $pdf;
}
