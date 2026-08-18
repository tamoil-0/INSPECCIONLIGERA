<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

requireMethod('GET');
requireAuth();
$projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT);
$line = trim((string) ($_GET['linea'] ?? ''));
if (!$projectId || $line === '') {
    respond(['success' => false, 'error' => 'proyecto_id y linea son obligatorios.'], 422);
}

try {
    $projectStatement = $conn->prepare('SELECT nombre_proyecto FROM proyectos WHERE id = ?');
    $projectStatement->execute([$projectId]);
    $projectName = $projectStatement->fetchColumn();
    if (!$projectName) {
        respond(['success' => false, 'error' => 'Proyecto no encontrado.'], 404);
    }

    $statement = $conn->prepare(
        'SELECT p.id AS poste_id, p.codigo, p.linea, p.estructura, p.fecha_inspeccion, p.utm_x, p.utm_y,
                d.obstaculos_faja, d.estado_cuencas, d.marcado_arboles, d.criticidad_tala,
                d.criticidad_contacto, d.notificacion_propietario, d.tipo_torre, d.ubicacion,
                d.acceso_torre, d.estado_acceso, d.estado_placas_torre, d.estado_placas_linea,
                d.estado_placas_fases, d.peligro_cerco, d.peligro_torre, d.puesta_tierra,
                d.retenida, d.estado_base, d.limpiar_base, d.crucetas_mensuales,
                d.perfiles_angulares, d.malla_antiescalamiento, d.oxidos_base,
                d.cadena_aisladores, d.tipo_aislador, d.conductor_bajada_pat,
                d.conductor_guarda, d.comentarios, d.distancia_acceso, d.cantidad_pat,
                d.distancia_poste_anterior, d.distancia_vertical, d.distancia_horizontal
         FROM postes p LEFT JOIN poste_datos d ON d.poste_id = p.id
         WHERE p.proyecto_id = ? AND p.linea = ? ORDER BY p.codigo'
    );
    $statement->execute([$projectId, $line]);
    $rows = $statement->fetchAll();
    if (!$rows) {
        respond(['success' => false, 'error' => 'No se encontraron postes para esa línea.'], 404);
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(mb_substr('Línea ' . $line, 0, 31));
    $sheet->fromArray([
        ['Proyecto', $projectName],
        ['Línea', $line],
        ['Cantidad de estructuras', count($rows)],
    ], null, 'A1');
    $sheet->getStyle('A1:A3')->applyFromArray([
        'font' => ['bold' => true],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'CFE2F3']],
    ]);

    $baseFields = ['poste_id', 'estructura', 'linea', 'codigo', 'fecha_inspeccion', 'utm_x', 'utm_y'];
    $extraFields = array_values(array_diff(array_keys($rows[0]), $baseFields));
    $headers = array_merge(['Nro', 'Estructura', 'Línea', 'Código', 'Fecha', 'UTM X', 'UTM Y'], $extraFields);
    $sheet->fromArray($headers, null, 'A5');
    $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
    $sheet->getStyle("A5:{$lastColumn}5")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '305496']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'alignment' => ['horizontal' => 'center', 'vertical' => 'center', 'wrapText' => true],
    ]);

    $excelRow = 6;
    foreach ($rows as $index => $row) {
        $values = [$index + 1, $row['estructura'], $row['linea'], $row['codigo'], $row['fecha_inspeccion'], $row['utm_x'], $row['utm_y']];
        foreach ($extraFields as $field) {
            $value = $row[$field];
            $values[] = is_string($value) ? str_replace('_', ' ', $value) : $value;
        }
        $values = array_map(static function (mixed $value): mixed {
            return is_string($value) && preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
        }, $values);
        $sheet->fromArray($values, null, 'A' . $excelRow++);
    }
    $sheet->freezePane('A6');
    $sheet->setAutoFilter("A5:{$lastColumn}5");
    $sheet->getStyle('A6:' . $lastColumn . ($excelRow - 1))->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
        'alignment' => ['vertical' => 'top', 'wrapText' => true],
    ]);
    for ($column = 1; $column <= count($headers); $column++) {
        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
    }

    $filename = 'linea_' . (preg_replace('/[^A-Za-z0-9_-]+/', '_', $line) ?: 'exporte') . '.xlsx';
    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    (new Xlsx($spreadsheet))->save('php://output');
    $spreadsheet->disconnectWorksheets();
    exit;
} catch (Throwable $exception) {
    error_log('Excel export failed: ' . $exception->getMessage());
    respond(['success' => false, 'error' => 'No se pudo generar el archivo Excel.'], 500);
}
