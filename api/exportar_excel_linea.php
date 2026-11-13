<?php
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/domain.php';
require_once __DIR__ . '/clases/PDFImagenesPoste.php';
require_once __DIR__ . '/services/StructureRecoveryService.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Las tres tablas R/S/T del formulario. Se declaran aquí con las mismas claves
 * y el mismo orden que usa el PDF (PDFFormularioPoste.php), para que el Excel y
 * el informe impreso nunca digan cosas distintas de la misma inspección.
 *
 * Una fila en `poste_secciones_rst` significa «casilla marcada»; su ausencia,
 * casilla vacía. No hay estado intermedio.
 */
const EXPORT_RST_SECTIONS = [
    'conductores_fase' => [
        'titulo' => 'Conductores de Fase',
        'color' => '2F75B5',
        'atributos' => [
            'hebras_rotas' => 'Hebras rotas',
            'encanastillado' => 'Encanastillado',
            'empalme_deformado' => 'Empalme deformado',
            'objetos_extranos' => 'Objetos extraños',
        ],
    ],
    'conductores_cuellos' => [
        'titulo' => 'Conductores de Cuellos',
        'color' => '1F4E79',
        'atributos' => [
            'hebras_rotas' => 'Hebras rotas',
            'encanastillado' => 'Encanastillado',
            'empalme_deformado' => 'Empalme deformado',
            'objetos_extranos' => 'Objetos extraños',
        ],
    ],
    'estado_aisladores' => [
        'titulo' => 'Estado de Aisladores',
        'color' => 'C65911',
        'atributos' => [
            'buen_estado' => 'Buen estado',
            'rotos_suspension' => 'Rotos suspensión',
            'rotos_anclaje_adelante' => 'Rotos anclaje adelante',
            'rotos_anclaje_atras' => 'Rotos anclaje atrás',
            'mal_estado' => 'Mal estado',
        ],
    ],
];

/** Encabezado legible a partir del nombre de la columna de `poste_datos`. */
function exportHeaderLabel(string $field): string
{
    return ucfirst(str_replace('_', ' ', $field));
}

/**
 * Excel interpreta como fórmula todo lo que empiece por = + - o @. Un comentario
 * de campo que arranque con un guión no debe convertirse en una fórmula al abrir
 * el archivo, así que se antepone un apóstrofo (no se ve en la celda).
 */
function exportSafeValue(mixed $value): mixed
{
    return is_string($value) && preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
}

/**
 * Traduce una ruta de dentro del contenedor a la ruta equivalente en el NAS.
 *
 * PHP ve `/data/imagenes/...` porque así está montado el volumen; quien abra el
 * Excel busca la carpeta en el explorador del QNAP y esa ruta no le sirve de
 * nada. La equivalencia se declara con `NAS_IMAGE_ROOT` y `NAS_BACKUP_ROOT` en
 * `docker/.env`. Sin configurar, se devuelve la ruta tal cual: es la verdad,
 * aunque sea la verdad del contenedor.
 */
function exportNasPath(?string $absolute): string
{
    $absolute = str_replace('\\', '/', (string) $absolute);
    if ($absolute === '') {
        return '';
    }
    $mappings = [
        rtrim((string) envValue('IMAGE_STORAGE_PATH', ''), '/') => rtrim((string) envValue('NAS_IMAGE_ROOT', ''), '/'),
        rtrim((string) envValue('MOBILE_BACKUP_ROOT', ''), '/') => rtrim((string) envValue('NAS_BACKUP_ROOT', ''), '/'),
    ];
    foreach ($mappings as $container => $nas) {
        if ($container !== '' && $nas !== '' && str_starts_with($absolute, $container . '/')) {
            return $nas . substr($absolute, strlen($container));
        }
    }
    return $absolute;
}

/**
 * Convierte un datetime de MySQL al número de serie que Excel usa para fechas.
 *
 * Se leen los componentes tal como vienen y NO se pasa por `strtotime()`:
 * strtotime interpreta la cadena en la zona horaria de PHP (America/Lima) y
 * `Date::PHPToExcel()` la reconvierte en la de PhpSpreadsheet (UTC), así que una
 * inspección de las 15:03 acababa escrita como las 20:03. Con los componentes no
 * hay conversión de zona posible.
 *
 * Devuelve null si no hay fecha o si es la fecha cero de MySQL.
 */
function exportExcelDate(?string $value): ?float
{
    $value = trim((string) $value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/', $value, $parts)) {
        return null;
    }
    return ExcelDate::formattedPHPToExcel(
        (int) $parts[1],
        (int) $parts[2],
        (int) $parts[3],
        (int) ($parts[4] ?? 0),
        (int) ($parts[5] ?? 0),
        (int) ($parts[6] ?? 0)
    );
}

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

    // Cada estructura se resuelve como lo hace el informe: lo que está en la
    // base y, si ahí no hay nada, lo que conserva el respaldo del teléfono. Una
    // consulta plana sobre `poste_datos` dejaría vacías las inspecciones que aún
    // no se sincronizaron, que en el PDF sí salen llenas.
    //
    // Cuesta una inspección del respaldo por estructura, así que el archivo de
    // una línea de 341 tarda bastante más que antes. Es el precio de que el
    // Excel y el PDF digan lo mismo.
    $rstMarks = [];
    $photoPaths = [];
    $photoSources = [];
    foreach ($rows as $index => $row) {
        $postId = (int) $row['poste_id'];
        try {
            $resolved = resolveStructureResources($conn, $postId, (int) $projectId);
        } catch (Throwable $exception) {
            // Una estructura ilegible no puede llevarse por delante el reporte
            // completo: queda con lo que ya trae la consulta plana.
            appLog('exportacion', 'No se pudo resolver una estructura para el Excel.', [
                'poste_id' => $postId, 'motivo' => $exception->getMessage(),
            ]);
            continue;
        }

        foreach (($resolved['details'] ?? []) as $field => $value) {
            if ($field !== 'poste_id' && array_key_exists($field, $rows[$index])) {
                $rows[$index][$field] = $value;
            }
        }
        foreach (($resolved['rst'] ?? []) as $mark) {
            $key = $mark['seccion'] . '|' . $mark['atributo'] . '|' . strtoupper((string) $mark['fase']);
            $rstMarks[$postId][$key] = true;
        }
        foreach (($resolved['images'] ?? []) as $image) {
            $type = (string) ($image['nombre_foto'] ?? '');
            if ($type === '') {
                continue;
            }
            $absolute = reportImagePath($image);
            if ($absolute === null) {
                continue;
            }
            $photoPaths[$postId][$type] = exportNasPath($absolute);
            $photoSources[$postId][$type] = (string) ($image['source'] ?? 'official');
        }
    }

    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('ECOING')
        ->setTitle('Línea ' . $line)
        ->setSubject((string) $projectName);
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle(mb_substr('Línea ' . $line, 0, 31));
    $sheet->getDefaultRowDimension()->setRowHeight(-1);
    $spreadsheet->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

    // ---------------------------------------------------------------------
    // Encabezado del reporte
    // ---------------------------------------------------------------------
    $sheet->fromArray([
        ['Proyecto', $projectName],
        ['Línea', $line],
        ['Cantidad de estructuras', count($rows)],
        ['Generado', date('d/m/Y H:i')],
    ], null, 'A1');
    $sheet->getStyle('A1:A4')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => '1F4E79']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DDEBF7']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'B4C6E7']]],
    ]);
    $sheet->getStyle('B1:B4')->applyFromArray([
        'font' => ['bold' => true],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
    ]);

    // ---------------------------------------------------------------------
    // Columnas: base + datos del formulario + las tres tablas R/S/T
    // ---------------------------------------------------------------------
    $baseFields = ['poste_id', 'estructura', 'linea', 'codigo', 'fecha_inspeccion', 'utm_x', 'utm_y'];
    $baseHeaders = ['Nro', 'Estructura', 'Línea', 'Código', 'Fecha', 'UTM X', 'UTM Y'];
    $extraFields = array_values(array_diff(array_keys($rows[0]), $baseFields));

    // Cada columna R/S/T queda descrita por su sección, su atributo y su fase.
    // Las etiquetas de atributo se repiten entre secciones («Hebras rotas» está
    // en Fase y en Cuellos), así que la columna solo es identificable por la
    // combinación de las tres bandas del encabezado.
    $rstColumns = [];
    foreach (EXPORT_RST_SECTIONS as $sectionKey => $section) {
        foreach ($section['atributos'] as $attributeKey => $attributeLabel) {
            foreach (['R', 'S', 'T'] as $phase) {
                $rstColumns[] = [
                    'seccion' => $sectionKey,
                    'titulo' => $section['titulo'],
                    'color' => $section['color'],
                    'atributo' => $attributeKey,
                    'atributo_label' => $attributeLabel,
                    'fase' => $phase,
                    'clave' => $sectionKey . '|' . $attributeKey . '|' . $phase,
                ];
            }
        }
    }

    // Una columna por tipo de fotografía con su ruta; vacía significa que esa
    // estructura no la tiene. Antes va el conteo, para poder filtrar de un
    // vistazo las que están incompletas.
    $photoTypes = photoTypes();
    $firstRstColumn = count($baseHeaders) + count($extraFields) + 1;
    $firstPhotoColumn = $firstRstColumn + count($rstColumns);
    $totalColumns = $firstPhotoColumn + count($photoTypes);
    $lastColumn = Coordinate::stringFromColumnIndex($totalColumns);

    // Encabezado de tres bandas, igual que las tablas del PDF:
    //   fila 6  sección   (Conductores de Fase | Cuellos | Estado de Aisladores)
    //   fila 7  atributo  (Hebras rotas, Encanastillado, ...)
    //   fila 8  fase      (R | S | T)  ← aquí va el filtro
    // Las columnas de datos normales ocupan las tres filas fusionadas.
    $sectionRow = 6;
    $attributeRow = 7;
    $headerRow = 8;
    $firstDataRow = 9;

    $column = 1;
    foreach (array_merge($baseHeaders, array_map('exportHeaderLabel', $extraFields)) as $header) {
        $letter = Coordinate::stringFromColumnIndex($column);
        $sheet->mergeCells("{$letter}{$sectionRow}:{$letter}{$headerRow}");
        $sheet->setCellValue([$column, $sectionRow], $header);
        $column++;
    }

    // Banda de sección: una celda fusionada por tabla.
    $sectionStart = $firstRstColumn;
    // Banda de atributo: una celda fusionada cada tres columnas (R, S, T).
    foreach ($rstColumns as $index => $rstColumn) {
        $columnIndex = $firstRstColumn + $index;
        $sheet->setCellValue([$columnIndex, $headerRow], $rstColumn['fase']);

        if ($rstColumn['fase'] === 'R') {
            $from = Coordinate::stringFromColumnIndex($columnIndex);
            $to = Coordinate::stringFromColumnIndex($columnIndex + 2);
            $sheet->mergeCells("{$from}{$attributeRow}:{$to}{$attributeRow}");
            $sheet->setCellValue([$columnIndex, $attributeRow], $rstColumn['atributo_label']);
        }

        $isLast = $index === count($rstColumns) - 1;
        if ($isLast || $rstColumns[$index + 1]['seccion'] !== $rstColumn['seccion']) {
            $from = Coordinate::stringFromColumnIndex($sectionStart);
            $to = Coordinate::stringFromColumnIndex($columnIndex);
            $sheet->mergeCells("{$from}{$sectionRow}:{$to}{$sectionRow}");
            $sheet->setCellValue([$sectionStart, $sectionRow], $rstColumn['titulo']);
            $sheet->getStyle("{$from}{$sectionRow}:{$to}{$attributeRow}")->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rstColumn['color']]],
            ]);
            $sectionStart = $columnIndex + 1;
        }
    }

    // Bloque de fotografías: una banda de sección sobre el conteo y las rutas.
    $photoCountColumn = $firstPhotoColumn;
    $sheet->mergeCells(
        Coordinate::stringFromColumnIndex($photoCountColumn) . $sectionRow
        . ':' . $lastColumn . $sectionRow
    );
    $sheet->setCellValue([$photoCountColumn, $sectionRow], 'Fotografías · ubicación en el NAS');
    $sheet->getStyle(
        Coordinate::stringFromColumnIndex($photoCountColumn) . $sectionRow
        . ':' . $lastColumn . $attributeRow
    )->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '548235']],
    ]);
    $sheet->mergeCells(
        Coordinate::stringFromColumnIndex($photoCountColumn) . $attributeRow
        . ':' . Coordinate::stringFromColumnIndex($photoCountColumn) . $headerRow
    );
    $sheet->setCellValue([$photoCountColumn, $attributeRow], 'Fotos');
    foreach ($photoTypes as $offset => $type) {
        $columnIndex = $firstPhotoColumn + 1 + $offset;
        $sheet->mergeCells(
            Coordinate::stringFromColumnIndex($columnIndex) . $attributeRow
            . ':' . Coordinate::stringFromColumnIndex($columnIndex) . $headerRow
        );
        $sheet->setCellValue([$columnIndex, $attributeRow], exportHeaderLabel($type));
    }

    // Estilo común de todo el bloque de encabezado.
    $sheet->getStyle("A{$sectionRow}:{$lastColumn}{$headerRow}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => true,
        ],
    ]);
    // Las columnas normales y la fila de fases van en el azul del informe; las
    // bandas de sección y atributo conservan el color de su tabla, aplicado
    // arriba, así que se repintan solo las que no son R/S/T.
    $lastPlainColumn = Coordinate::stringFromColumnIndex($firstRstColumn - 1);
    $sheet->getStyle("A{$sectionRow}:{$lastPlainColumn}{$headerRow}")
        ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('305496');
    $firstRstLetterHeader = Coordinate::stringFromColumnIndex($firstRstColumn);
    $sheet->getStyle("{$firstRstLetterHeader}{$headerRow}:{$lastColumn}{$headerRow}")
        ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('305496');

    $sheet->getRowDimension($sectionRow)->setRowHeight(20);
    $sheet->getRowDimension($attributeRow)->setRowHeight(30);
    $sheet->getRowDimension($headerRow)->setRowHeight(16);

    // ---------------------------------------------------------------------
    // Datos
    // ---------------------------------------------------------------------
    $excelRow = $firstDataRow;
    foreach ($rows as $index => $row) {
        $column = 1;
        $values = [
            $index + 1,
            $row['estructura'],
            $row['linea'],
            $row['codigo'],
            null, // la fecha se escribe aparte, como fecha real
            $row['utm_x'] !== null ? (float) $row['utm_x'] : null,
            $row['utm_y'] !== null ? (float) $row['utm_y'] : null,
        ];
        foreach ($extraFields as $field) {
            $value = $row[$field];
            $values[] = is_string($value) ? str_replace('_', ' ', $value) : $value;
        }
        foreach ($values as $value) {
            if ($value !== null) {
                $sheet->setCellValue([$column, $excelRow], exportSafeValue($value));
            }
            $column++;
        }

        // La fecha va como fecha de Excel y no como texto: así se ordena y se
        // filtra por rango, que es justo para lo que se abre este archivo.
        $excelDate = exportExcelDate($row['fecha_inspeccion']);
        if ($excelDate !== null) {
            $sheet->setCellValue([5, $excelRow], $excelDate);
        }

        $postId = (int) $row['poste_id'];
        $marks = $rstMarks[$postId] ?? [];
        foreach ($rstColumns as $offset => $rstColumn) {
            if (isset($marks[$rstColumn['clave']])) {
                $sheet->setCellValue([$firstRstColumn + $offset, $excelRow], 'X');
            }
        }

        $rutas = $photoPaths[$postId] ?? [];
        $sheet->setCellValue([$firstPhotoColumn, $excelRow], count($rutas) . '/' . count($photoTypes));
        foreach ($photoTypes as $offset => $type) {
            $ruta = $rutas[$type] ?? '';
            if ($ruta === '') {
                continue; // Celda vacía = esa estructura no tiene esa fotografía.
            }
            $columnIndex = $firstPhotoColumn + 1 + $offset;
            $sheet->setCellValueExplicit([$columnIndex, $excelRow], $ruta, DataType::TYPE_STRING);
            // Una foto que todavía vive solo en el respaldo del teléfono se
            // marca en ámbar: está, pero aún no en el almacén oficial del NAS.
            if (($photoSources[$postId][$type] ?? 'official') !== 'official') {
                $sheet->getStyle([$columnIndex, $excelRow])
                    ->getFont()->getColor()->setRGB('B45309');
            }
        }
        $excelRow++;
    }
    $lastDataRow = $excelRow - 1;

    // ---------------------------------------------------------------------
    // Formato del cuerpo
    // ---------------------------------------------------------------------
    $sheet->getStyle("A{$firstDataRow}:{$lastColumn}{$lastDataRow}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9D9D9']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
    ]);

    $sheet->getStyle("A{$firstDataRow}:A{$lastDataRow}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("B{$firstDataRow}:D{$lastDataRow}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("E{$firstDataRow}:E{$lastDataRow}")
        ->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
    $sheet->getStyle("E{$firstDataRow}:E{$lastDataRow}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    // Dos decimales, igual que el encabezado del PDF.
    $sheet->getStyle("F{$firstDataRow}:G{$lastDataRow}")
        ->getNumberFormat()->setFormatCode('#,##0.00');

    // Las casillas R/S/T: centradas, en negrita y estrechas. Una X suelta en una
    // columna ancha se lee fatal cuando hay 39 seguidas.
    $firstRstLetter = Coordinate::stringFromColumnIndex($firstRstColumn);
    $sheet->getStyle("{$firstRstLetter}{$firstDataRow}:{$lastColumn}{$lastDataRow}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'C00000']],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_CENTER,
            'vertical' => Alignment::VERTICAL_CENTER,
        ],
    ]);

    // ---------------------------------------------------------------------
    // Anchos, filtro y vista
    // ---------------------------------------------------------------------
    $fixedWidths = ['A' => 6, 'B' => 12, 'C' => 12, 'D' => 16, 'E' => 17, 'F' => 13, 'G' => 14];
    foreach ($fixedWidths as $letter => $width) {
        $sheet->getColumnDimension($letter)->setWidth($width);
    }
    foreach ($extraFields as $offset => $field) {
        $letter = Coordinate::stringFromColumnIndex(count($baseHeaders) + $offset + 1);
        // `comentarios` es texto libre y puede traer párrafos enteros: con
        // autoajuste se comería la pantalla, así que va a un ancho fijo.
        if ($field === 'comentarios') {
            $sheet->getColumnDimension($letter)->setWidth(45);
        } else {
            $sheet->getColumnDimension($letter)->setAutoSize(true);
        }
    }
    for ($offset = 0; $offset < count($rstColumns); $offset++) {
        $letter = Coordinate::stringFromColumnIndex($firstRstColumn + $offset);
        $sheet->getColumnDimension($letter)->setWidth(5.5);
    }
    // Las rutas son largas: ancho fijo y sin ajuste de línea, para que la hoja
    // siga siendo navegable. Se leen completas en la barra de fórmulas.
    $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($firstPhotoColumn))->setWidth(8);
    for ($offset = 0; $offset < count($photoTypes); $offset++) {
        $letter = Coordinate::stringFromColumnIndex($firstPhotoColumn + 1 + $offset);
        $sheet->getColumnDimension($letter)->setWidth(38);
    }
    $firstPhotoLetter = Coordinate::stringFromColumnIndex($firstPhotoColumn);
    $sheet->getStyle("{$firstPhotoLetter}{$firstDataRow}:{$lastColumn}{$lastDataRow}")
        ->getAlignment()->setWrapText(false)->setVertical(Alignment::VERTICAL_CENTER);
    $sheet->getStyle("{$firstPhotoLetter}{$firstDataRow}:{$firstPhotoLetter}{$lastDataRow}")
        ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

    $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$headerRow}");
    // Se congelan las cuatro primeras columnas junto con los encabezados: al
    // desplazarse hasta las tablas R/S/T hay que seguir viendo de qué estructura
    // es la fila.
    $sheet->freezePane('E' . $firstDataRow);
    $sheet->setSelectedCell('A' . $firstDataRow);

    $sheet->getPageSetup()
        ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
        ->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToWidth(1)
        ->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.3)->setRight(0.3);
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($sectionRow, $headerRow);
    $sheet->getHeaderFooter()->setOddFooter('&L' . $line . '&C&P / &N&R&D');

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
