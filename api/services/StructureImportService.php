<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/AdminCatalogService.php';

const STRUCTURE_IMPORT_HEADERS = ['Linea', 'Torre', 'X', 'Y', 'Ubicacion', 'Cuadrante'];
const STRUCTURE_IMPORT_MAX_BYTES = 5 * 1024 * 1024;
const STRUCTURE_IMPORT_MAX_ROWS = 2000;

function structureImportKey(?string $value): string
{
    return mb_strtolower(trim((string) $value), 'UTF-8');
}

function structureImportSafeFilename(mixed $value): string
{
    $name = is_scalar($value) ? (string) $value : '';
    $name = basename(str_replace('\\', '/', $name));
    $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $name));
    return mb_substr($name !== '' ? $name : 'importacion.xlsx', 0, 180);
}

/**
 * Mueve una carga HTTP a un temporal propio y valida que sea un XLSX real,
 * acotado y sin macros. El llamador siempre debe eliminar `path` en finally.
 *
 * @return array{path:string,file_name:string,mime:string,size:int}
 */
function structureImportSecureUpload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('No se pudo recibir el archivo Excel.');
    }

    $clientName = structureImportSafeFilename($file['name'] ?? 'importacion.xlsx');
    if (strtolower((string) pathinfo($clientName, PATHINFO_EXTENSION)) !== 'xlsx') {
        throw new InvalidArgumentException('Solo se permiten archivos con extensión .xlsx.');
    }

    $source = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > STRUCTURE_IMPORT_MAX_BYTES) {
        throw new InvalidArgumentException('El archivo debe pesar como máximo 5 MB.');
    }
    if ($source === '' || !is_uploaded_file($source)) {
        throw new InvalidArgumentException('La carga temporal del archivo no es válida.');
    }

    $temporary = tempnam(sys_get_temp_dir(), 'ecoing_xlsx_');
    if ($temporary === false) {
        throw new RuntimeException('No se pudo preparar el archivo temporal.');
    }

    try {
        if (!move_uploaded_file($source, $temporary)) {
            throw new RuntimeException('No se pudo aislar el archivo temporal.');
        }

        $actualSize = (int) (@filesize($temporary) ?: 0);
        if ($actualSize <= 0 || $actualSize > STRUCTURE_IMPORT_MAX_BYTES) {
            throw new InvalidArgumentException('El tamaño real del archivo no es válido.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($temporary);
        $allowedMimes = [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream',
        ];
        if (!in_array($mime, $allowedMimes, true)) {
            throw new InvalidArgumentException('El contenido no corresponde a un Excel .xlsx válido.');
        }

        $zip = new ZipArchive();
        if ($zip->open($temporary, ZipArchive::CHECKCONS) !== true) {
            throw new InvalidArgumentException('El archivo .xlsx está dañado o no es un ZIP OpenXML válido.');
        }
        try {
            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                throw new InvalidArgumentException('El archivo no contiene un libro Excel OpenXML válido.');
            }
            if ($zip->numFiles > 5000) {
                throw new InvalidArgumentException('El archivo Excel contiene demasiados elementos internos.');
            }

            $expandedBytes = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                $entryName = str_replace('\\', '/', (string) ($entry['name'] ?? ''));
                if ($entryName === '' || str_starts_with($entryName, '/') || preg_match('#(?:^|/)\.\.(?:/|$)#', $entryName)) {
                    throw new InvalidArgumentException('El archivo Excel contiene rutas internas no válidas.');
                }
                if (strcasecmp($entryName, 'xl/vbaProject.bin') === 0) {
                    throw new InvalidArgumentException('No se permiten libros con macros. Use un archivo .xlsx sin macros.');
                }
                $expandedBytes += (int) ($entry['size'] ?? 0);
                if ($expandedBytes > 50 * 1024 * 1024) {
                    throw new InvalidArgumentException('El contenido expandido del Excel supera el límite permitido.');
                }
            }

            $contentTypes = (string) $zip->getFromName('[Content_Types].xml');
            if (stripos($contentTypes, 'macroEnabled') !== false) {
                throw new InvalidArgumentException('No se permiten libros habilitados para macros.');
            }
        } finally {
            $zip->close();
        }

        return [
            'path' => $temporary,
            'file_name' => $clientName,
            'mime' => $mime,
            'size' => $actualSize,
        ];
    } catch (Throwable $exception) {
        @unlink($temporary);
        throw $exception;
    }
}

/** @return array{value:string,error:?string} */
function structureImportTextCell(Cell $cell, string $field, bool $formattedNumeric = false): array
{
    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
        return ['value' => '', 'error' => "{$field}: no se permiten fórmulas."];
    }
    $raw = $cell->getValue();
    if ($raw === null) return ['value' => '', 'error' => null];

    $value = $formattedNumeric && is_numeric($raw)
        ? (string) $cell->getFormattedValue()
        : (string) $raw;
    $value = trim(str_replace("\u{00A0}", ' ', $value));
    return ['value' => $value, 'error' => null];
}

/** @return array{value:string,error:?string} */
function structureImportCoordinateCell(Cell $cell, string $field): array
{
    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
        return ['value' => '', 'error' => "{$field}: no se permiten fórmulas."];
    }
    $raw = $cell->getValue();
    if ($raw === null || trim((string) $raw) === '') return ['value' => '', 'error' => null];
    if (is_float($raw) || is_int($raw)) {
        $value = (string) $raw;
        if (stripos($value, 'e') !== false) {
            $value = rtrim(rtrim(number_format((float) $raw, 10, '.', ''), '0'), '.');
        }
        return ['value' => $value, 'error' => null];
    }
    return ['value' => trim((string) $raw), 'error' => null];
}

/**
 * Lee únicamente la hoja de datos. Se tolera una segunda hoja llamada
 * "Instrucciones", como la incluida en la plantilla oficial.
 *
 * @return array{headers:array,columns_valid:bool,file_errors:array,rows:array,sheet:string}
 */
function structureImportReadXlsx(string $path): array
{
    $reader = new XlsxReader();
    $reader->setReadDataOnly(false);
    $reader->setReadEmptyCells(false);
    $reader->setIncludeCharts(false);
    $spreadsheet = $reader->load($path);

    try {
        $dataSheets = [];
        foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
            if (strcasecmp(trim($worksheet->getTitle()), 'Instrucciones') === 0) continue;
            $hasData = $worksheet->getHighestDataRow() > 1
                || trim((string) $worksheet->getCell('A1')->getValue()) !== '';
            if ($hasData) $dataSheets[] = $worksheet;
        }
        if (count($dataSheets) !== 1) {
            throw new InvalidArgumentException('El Excel debe contener una sola hoja de datos; solo se admite además la hoja Instrucciones.');
        }

        $sheet = $dataSheets[0];
        $highestRow = $sheet->getHighestDataRow();
        if ($highestRow > 10000) {
            throw new InvalidArgumentException('La hoja contiene un rango de filas excesivo.');
        }

        $headers = [];
        for ($column = 1; $column <= 6; $column++) {
            $cell = structureImportTextCell($sheet->getCell([$column, 1]), 'Encabezado');
            $headers[] = preg_replace('/^\xEF\xBB\xBF/', '', $cell['value']);
        }
        $fileErrors = [];
        $columnsValid = $headers === STRUCTURE_IMPORT_HEADERS;
        if (!$columnsValid) {
            $fileErrors[] = 'Los encabezados deben ser exactamente: ' . implode(' | ', STRUCTURE_IMPORT_HEADERS) . '.';
        }

        $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        if ($highestColumn > 50) {
            $fileErrors[] = 'La hoja contiene columnas adicionales fuera del formato permitido.';
        } elseif ($highestColumn > 6) {
            $extraFound = false;
            for ($row = 1; $row <= $highestRow && !$extraFound; $row++) {
                for ($column = 7; $column <= $highestColumn; $column++) {
                    if (trim((string) $sheet->getCell([$column, $row])->getValue()) !== '') {
                        $extraFound = true;
                        break;
                    }
                }
            }
            if ($extraFound) $fileErrors[] = 'La hoja contiene columnas adicionales fuera del formato permitido.';
        }

        $rows = [];
        for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
            $line = structureImportTextCell($sheet->getCell([1, $rowNumber]), 'Línea');
            $tower = structureImportTextCell($sheet->getCell([2, $rowNumber]), 'Torre', true);
            $x = structureImportCoordinateCell($sheet->getCell([3, $rowNumber]), 'X');
            $y = structureImportCoordinateCell($sheet->getCell([4, $rowNumber]), 'Y');
            $location = structureImportTextCell($sheet->getCell([5, $rowNumber]), 'Ubicación');
            $zone = structureImportTextCell($sheet->getCell([6, $rowNumber]), 'Cuadrante');
            $values = [$line['value'], $tower['value'], $x['value'], $y['value'], $location['value'], $zone['value']];
            $cellErrors = array_values(array_filter([
                $line['error'], $tower['error'], $x['error'], $y['error'], $location['error'], $zone['error'],
            ]));
            if (!array_filter($values, static fn(string $value): bool => trim($value) !== '') && !$cellErrors) continue;

            $rows[] = [
                'fila' => $rowNumber,
                'linea' => $line['value'],
                'torre' => $tower['value'],
                'x' => $x['value'],
                'y' => $y['value'],
                'ubicacion' => $location['value'],
                'cuadrante' => $zone['value'],
                '_cell_errors' => $cellErrors,
            ];
            if (count($rows) > STRUCTURE_IMPORT_MAX_ROWS) {
                throw new InvalidArgumentException('El Excel supera el límite de 2000 estructuras por importación.');
            }
        }

        if (!$rows) $fileErrors[] = 'La hoja no contiene estructuras para importar.';
        return [
            'headers' => $headers,
            'columns_valid' => $columnsValid,
            'file_errors' => array_values(array_unique($fileErrors)),
            'rows' => $rows,
            'sheet' => $sheet->getTitle(),
        ];
    } finally {
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }
}

function structureImportText(mixed $value, int $max, string $field, bool $required = true): ?string
{
    return adminText($value, $max, $field, $required);
}

function structureImportCoordinate(mixed $value, string $field): ?string
{
    if ($value === null || trim((string) $value) === '') return null;
    $text = trim((string) $value);
    if (!preg_match('/^-?\d{1,10}(?:\.\d{1,5})?$/', $text) || !is_finite((float) $text)) {
        throw new InvalidArgumentException("{$field} debe ser numérico y respetar DECIMAL(15,5).");
    }
    $digits = strlen(str_replace(['-', '.'], '', $text));
    if ($digits > 15) throw new InvalidArgumentException("{$field} excede la precisión DECIMAL(15,5).");
    return $text;
}

function structureImportZone(mixed $value): ?string
{
    if ($value === null || trim((string) $value) === '') return null;
    $zone = structureImportText($value, 10, 'Cuadrante');
    if (!preg_match('/^(?:[1-9]|[1-5][0-9]|60)[C-HJ-NP-X]$/', (string) $zone)) {
        throw new InvalidArgumentException('Cuadrante no es una zona UTM válida (ejemplo: 19K).');
    }
    return $zone;
}

function structureImportComparableDecimal(mixed $value): ?string
{
    if ($value === null || trim((string) $value) === '') return null;
    $text = trim((string) $value);
    $negative = str_starts_with($text, '-');
    if ($negative) $text = substr($text, 1);
    [$integer, $fraction] = array_pad(explode('.', $text, 2), 2, '');
    $integer = ltrim($integer, '0');
    $integer = $integer === '' ? '0' : $integer;
    $fraction = rtrim($fraction, '0');
    $normalized = $integer . ($fraction !== '' ? '.' . $fraction : '');
    return $negative && $normalized !== '0' ? '-' . $normalized : $normalized;
}

function structureImportMasterChanges(array $post, array $row): array
{
    $definitions = [
        ['columna' => 'linea', 'campo' => 'Línea', 'actual' => $post['linea'], 'excel' => $row['linea'], 'decimal' => false],
        ['columna' => 'estructura', 'campo' => 'Torre', 'actual' => $post['estructura'], 'excel' => $row['torre'], 'decimal' => false],
        ['columna' => 'codigo', 'campo' => 'Código', 'actual' => $post['codigo'], 'excel' => $row['codigo'], 'decimal' => false],
        ['columna' => 'utm_x', 'campo' => 'UTM X', 'actual' => $post['utm_x'], 'excel' => $row['utm_x'], 'decimal' => true],
        ['columna' => 'utm_y', 'campo' => 'UTM Y', 'actual' => $post['utm_y'], 'excel' => $row['utm_y'], 'decimal' => true],
        ['columna' => 'zona', 'campo' => 'Zona', 'actual' => $post['zona'], 'excel' => $row['zona'], 'decimal' => false],
        ['columna' => 'ubicaciones', 'campo' => 'Ubicación', 'actual' => $post['ubicaciones'], 'excel' => $row['ubicacion'], 'decimal' => false],
    ];
    $changes = [];
    foreach ($definitions as $definition) {
        $same = $definition['decimal']
            ? structureImportComparableDecimal($definition['actual']) === structureImportComparableDecimal($definition['excel'])
            : $definition['actual'] === $definition['excel'];
        if (!$same) {
            unset($definition['decimal']);
            $changes[] = $definition;
        }
    }
    return $changes;
}

function structureImportPublicRows(array $rows): array
{
    return array_map(static function (array $row): array {
        foreach (array_keys($row) as $key) {
            if (str_starts_with((string) $key, '_')) unset($row[$key]);
        }
        return $row;
    }, $rows);
}

function structureImportPostDataCounts(PDO $db, array $postIds, bool $lock = false): array
{
    $ids = array_values(array_unique(array_map('intval', $postIds)));
    if (!$ids) return [];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = $db->prepare(
        "SELECT poste_id,COUNT(*) cantidad FROM poste_datos WHERE poste_id IN ({$placeholders}) GROUP BY poste_id"
        . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute($ids);
    $counts = array_fill_keys($ids, 0);
    foreach ($statement->fetchAll() as $row) $counts[(int) $row['poste_id']] = (int) $row['cantidad'];
    return $counts;
}

/**
 * @param string $missingPolicy none|preview|authorized
 * @param string[] $authorizedMissingLines
 * @return array{project:array,rows:array,summary:array,missing_lines:array,line_groups:array,can_import:bool,file_errors:array,columns_valid:bool}
 */
function structureImportValidateRows(
    PDO $db,
    int $projectId,
    array $sourceRows,
    ?string $contextLine = null,
    string $missingPolicy = 'none',
    array $authorizedMissingLines = [],
    array $fileErrors = [],
    bool $columnsValid = true,
    bool $lock = false
): array {
    if (!in_array($missingPolicy, ['none', 'preview', 'authorized'], true)) {
        throw new InvalidArgumentException('Política de líneas faltantes no válida.');
    }
    if (count($sourceRows) > STRUCTURE_IMPORT_MAX_ROWS) {
        throw new InvalidArgumentException('La importación no puede superar 2000 estructuras.');
    }
    if (!$sourceRows && !$fileErrors) {
        throw new InvalidArgumentException('La importación debe contener al menos una estructura.');
    }

    $projectStatement = $db->prepare(
        'SELECT id,nombre_proyecto,ubicacion,estado FROM proyectos WHERE id=?' . ($lock ? ' FOR UPDATE' : '')
    );
    $projectStatement->execute([$projectId]);
    $project = $projectStatement->fetch();
    if (!$project) throw new DomainException('El proyecto destino no existe.');
    if ($project['estado'] !== 'activo') throw new DomainException('El proyecto destino debe estar activo.');

    $lineStatement = $db->prepare(
        'SELECT id,nombre,ubicacion,activa FROM lineas WHERE proyecto_id=? ORDER BY nombre' . ($lock ? ' FOR UPDATE' : '')
    );
    $lineStatement->execute([$projectId]);
    $lineMap = [];
    foreach ($lineStatement->fetchAll() as $line) $lineMap[structureImportKey($line['nombre'])] = $line;

    $postStatement = $db->prepare(
        'SELECT p.id,p.codigo,p.linea,p.estructura,p.utm_x,p.utm_y,p.zona,p.ubicaciones,
                p.activo,p.desactivado_en,
                (SELECT COUNT(*) FROM poste_datos d WHERE d.poste_id=p.id) poste_datos_count
         FROM postes p WHERE p.proyecto_id=?' . ($lock ? ' FOR UPDATE' : '')
    );
    $postStatement->execute([$projectId]);
    $postsByCode = [];
    $postsByStructure = [];
    foreach ($postStatement->fetchAll() as $post) {
        $postsByCode[structureImportKey($post['codigo'])][] = $post;
        $postsByStructure[structureImportKey($post['linea']) . "\0" . structureImportKey($post['estructura'])][] = $post;
    }

    $authorized = [];
    foreach ($authorizedMissingLines as $line) {
        if (!is_scalar($line)) continue;
        $authorized[structureImportKey((string) $line)] = true;
    }
    $contextLine = trim((string) $contextLine);
    $contextKey = structureImportKey($contextLine);
    $projectLocation = trim((string) ($project['ubicacion'] ?? ''));
    $normalized = [];
    $codeCounts = [];
    $structureCounts = [];

    foreach (array_values($sourceRows) as $index => $source) {
        if (!is_array($source)) $source = [];
        $errors = array_values(array_filter(
            is_array($source['_cell_errors'] ?? null) ? $source['_cell_errors'] : [],
            'is_string'
        ));
        $lineName = '';
        $tower = '';
        $x = $source['x'] ?? $source['utm_x'] ?? '';
        $y = $source['y'] ?? $source['utm_y'] ?? '';
        $location = $source['ubicacion'] ?? $source['ubicaciones'] ?? '';
        $zone = $source['cuadrante'] ?? $source['zona'] ?? '';

        try { $lineName = (string) structureImportText($source['linea'] ?? null, 100, 'Línea'); }
        catch (InvalidArgumentException $exception) { $errors[] = $exception->getMessage(); }
        try { $tower = (string) structureImportText($source['torre'] ?? $source['estructura'] ?? null, 100, 'Torre'); }
        catch (InvalidArgumentException $exception) { $errors[] = $exception->getMessage(); }
        try { $x = structureImportCoordinate($x, 'X'); }
        catch (InvalidArgumentException $exception) { $errors[] = $exception->getMessage(); }
        try { $y = structureImportCoordinate($y, 'Y'); }
        catch (InvalidArgumentException $exception) { $errors[] = $exception->getMessage(); }
        try { $location = structureImportText($location, 255, 'Ubicación', false); }
        catch (InvalidArgumentException $exception) { $errors[] = $exception->getMessage(); }
        try { $zone = structureImportZone($zone); }
        catch (InvalidArgumentException $exception) { $errors[] = $exception->getMessage(); }

        $lineKey = structureImportKey($lineName);
        $lineRecord = $lineKey !== '' ? ($lineMap[$lineKey] ?? null) : null;
        $missingLine = false;
        if ($lineKey !== '' && $contextKey !== '' && $lineKey !== $contextKey) {
            $errors[] = "La línea {$lineName} no coincide con la línea {$contextLine} del contexto actual.";
        }
        if ($lineRecord) {
            if ((int) $lineRecord['activa'] !== 1) {
                $errors[] = "La línea {$lineName} existe, pero está archivada.";
            } else {
                $lineName = (string) $lineRecord['nombre'];
                $lineKey = structureImportKey($lineName);
            }
        } elseif ($lineKey !== '') {
            $missingLine = true;
            $allowed = $contextKey === '' && (
                $missingPolicy === 'preview'
                || ($missingPolicy === 'authorized' && isset($authorized[$lineKey]))
            );
            if (!$allowed) $errors[] = "La línea {$lineName} no existe o no está autorizada para crearse.";
        }

        if ($location === null) {
            $lineLocation = $lineRecord && (int) $lineRecord['activa'] === 1
                ? trim((string) ($lineRecord['ubicacion'] ?? ''))
                : '';
            $location = $lineLocation !== '' ? $lineLocation : ($projectLocation !== '' ? $projectLocation : null);
        }

        $code = '';
        if ($lineName !== '' && $tower !== '') {
            try { $code = (string) structureImportText($lineName . '-' . $tower, 50, 'Código generado'); }
            catch (InvalidArgumentException $exception) { $errors[] = $exception->getMessage(); }
        }
        $codeKey = structureImportKey($code);
        $structureKey = $lineKey !== '' && $tower !== '' ? $lineKey . "\0" . structureImportKey($tower) : '';
        if ($codeKey !== '') $codeCounts[$codeKey] = ($codeCounts[$codeKey] ?? 0) + 1;
        if ($structureKey !== '') $structureCounts[$structureKey] = ($structureCounts[$structureKey] ?? 0) + 1;

        $normalized[] = [
            'fila' => max(1, (int) ($source['fila'] ?? ($index + 2))),
            'linea' => $lineName,
            'torre' => $tower,
            'codigo' => $code,
            'utm_x' => $x,
            'utm_y' => $y,
            'ubicacion' => $location,
            'zona' => $zone,
            'linea_faltante' => $missingLine,
            'poste_id' => null,
            'cambios' => [],
            'errors' => $errors,
            '_code_key' => $codeKey,
            '_structure_key' => $structureKey,
            '_existing' => null,
        ];
    }

    $missingCounts = [];
    $lineCounts = [];
    $duplicateRows = 0;
    foreach ($normalized as &$row) {
        $duplicate = false;
        $conflict = false;
        if ($row['_code_key'] !== '' && ($codeCounts[$row['_code_key']] ?? 0) > 1) {
            $row['errors'][] = 'Código duplicado dentro del propio Excel.';
            $duplicate = true;
        }
        if ($row['_structure_key'] !== '' && ($structureCounts[$row['_structure_key']] ?? 0) > 1) {
            $row['errors'][] = 'Torre duplicada dentro de la misma línea del Excel.';
            $duplicate = true;
        }

        $codeMatches = $row['_code_key'] !== '' ? ($postsByCode[$row['_code_key']] ?? []) : [];
        $structureMatches = $row['_structure_key'] !== '' ? ($postsByStructure[$row['_structure_key']] ?? []) : [];
        $existing = null;
        if ($row['_code_key'] !== '' && $row['_structure_key'] !== '') {
            if (!$codeMatches && !$structureMatches) {
                // Estructura realmente nueva.
            } elseif (count($codeMatches) === 1 && count($structureMatches) === 1
                && (int) $codeMatches[0]['id'] === (int) $structureMatches[0]['id']) {
                $existing = $codeMatches[0];
            } else {
                $conflict = true;
                $row['errors'][] = 'CONFLICTO: el código y la combinación línea/torre no identifican el mismo poste.';
            }
        }

        if ($existing !== null) {
            $row['poste_id'] = (int) $existing['id'];
            $row['_existing'] = $existing;
            $row['cambios'] = structureImportMasterChanges($existing, $row);
            // poste_id es PRIMARY KEY en poste_datos: 0 filas es normal (poste sin inspeccionar)
            // y >1 es imposible; se conserva la guarda por si el esquema cambiara.
            if ((int) $existing['poste_datos_count'] > 1) {
                $row['errors'][] = 'El poste existente tiene más de una fila en poste_datos; requiere revisión manual.';
            }
        }

        $row['errors'] = array_values(array_unique($row['errors']));
        $row['duplicado'] = $duplicate;
        if ($duplicate) $duplicateRows++;
        if ($row['linea'] !== '') {
            $lineCounts[$row['linea']] = ($lineCounts[$row['linea']] ?? 0) + 1;
            if ($row['linea_faltante']) $missingCounts[$row['linea']] = ($missingCounts[$row['linea']] ?? 0) + 1;
        }

        if ($conflict) $classification = 'conflicto';
        elseif ($row['errors']) $classification = 'error';
        elseif ($existing === null) $classification = 'nueva';
        elseif ((int) $existing['activo'] !== 1) $classification = 'existente_archivada';
        elseif ($row['cambios']) $classification = 'existente_activa';
        else $classification = 'sin_cambios';
        $row['clasificacion'] = $classification;
        $row['estado'] = $classification;
        $row['requiere_reactivacion'] = $classification === 'existente_archivada';
        $row['activo_actual'] = $existing === null ? null : (int) $existing['activo'];
        unset($row['_code_key'], $row['_structure_key']);
    }
    unset($row);

    ksort($lineCounts, SORT_NATURAL | SORT_FLAG_CASE);
    ksort($missingCounts, SORT_NATURAL | SORT_FLAG_CASE);
    $lineGroups = [];
    foreach ($lineCounts as $name => $count) $lineGroups[] = ['nombre' => $name, 'cantidad' => $count];
    $missingLines = [];
    foreach ($missingCounts as $name => $count) $missingLines[] = ['nombre' => $name, 'cantidad' => $count];
    $counts = array_fill_keys(['nueva', 'existente_activa', 'existente_archivada', 'sin_cambios', 'conflicto', 'error'], 0);
    foreach ($normalized as $row) $counts[$row['clasificacion']]++;
    $total = count($normalized);
    $fileErrors = array_values(array_unique(array_filter($fileErrors, 'is_string')));

    return [
        'project' => $project,
        'rows' => $normalized,
        'summary' => [
            'registros' => $total,
            'validos' => $total - $counts['conflicto'] - $counts['error'],
            'nuevas' => $counts['nueva'],
            'existentes_actualizar' => $counts['existente_activa'],
            'archivadas' => $counts['existente_archivada'],
            'sin_cambios' => $counts['sin_cambios'],
            'conflictos' => $counts['conflicto'],
            'errores' => $counts['error'],
            'duplicados' => $duplicateRows,
            'lineas_detectadas' => count($lineGroups),
        ],
        'missing_lines' => $missingLines,
        'line_groups' => $lineGroups,
        'columns_valid' => $columnsValid,
        'file_errors' => $fileErrors,
        'can_import' => $total > 0 && $counts['conflicto'] === 0 && $counts['error'] === 0
            && $columnsValid && !$fileErrors,
    ];
}

/**
 * Revalida bajo bloqueos y aplica el padrón sin reemplazar postes existentes.
 * poste_datos solo se crea para filas clasificadas como nuevas.
 */
function structureImportExecute(
    PDO $db,
    array $user,
    int $projectId,
    array $records,
    array $authorizedMissingLines,
    array $authorizedReactivationPostIds,
    ?string $contextLine,
    string $fileName
): array {
    if ($db->inTransaction()) throw new LogicException('Ya existe una transacción activa.');
    $reactivationIds = [];
    foreach ($authorizedReactivationPostIds as $value) {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) throw new InvalidArgumentException('La autorización de reactivación no es válida.');
        $reactivationIds[(int) $id] = true;
    }

    $db->beginTransaction();
    try {
        $validation = structureImportValidateRows(
            $db,
            $projectId,
            $records,
            $contextLine,
            'authorized',
            $authorizedMissingLines,
            [],
            true,
            true
        );
        if (!$validation['can_import']) {
            throw new DomainException('La validación final encontró errores o conflictos. No se modificó el padrón.');
        }

        $authorizedKeys = [];
        foreach ($authorizedMissingLines as $line) $authorizedKeys[structureImportKey((string) $line)] = true;
        $createdLines = [];
        foreach ($validation['missing_lines'] as $missing) {
            $name = (string) $missing['nombre'];
            if (!isset($authorizedKeys[structureImportKey($name)])) {
                throw new DomainException("La creación de la línea {$name} no fue confirmada.");
            }
            $db->prepare('INSERT INTO lineas(proyecto_id,nombre,ubicacion,activa) VALUES(?,?,?,1)')
                ->execute([$projectId, $name, $validation['project']['ubicacion'] ?: null]);
            $createdLines[] = $name;
        }

        $existingIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int) ($row['poste_id'] ?? 0),
            $validation['rows']
        ))));
        $dataCountsBefore = structureImportPostDataCounts($db, $existingIds, true);
        foreach ($existingIds as $id) {
            if (($dataCountsBefore[$id] ?? 0) > 1) {
                throw new DomainException('Un poste existente tiene más de una fila en poste_datos.');
            }
        }

        $insertPost = $db->prepare(
            'INSERT INTO postes(codigo,linea,estructura,proyecto_id,utm_x,utm_y,zona,ubicaciones,activo)
             VALUES(?,?,?,?,?,?,?,?,1)'
        );
        $insertData = $db->prepare('INSERT INTO poste_datos(poste_id) VALUES(?)');
        $updateMaster = $db->prepare(
            'UPDATE postes SET linea=?,estructura=?,codigo=?,utm_x=?,utm_y=?,zona=?,ubicaciones=? WHERE id=? AND proyecto_id=?'
        );
        $updateMasterAndReactivate = $db->prepare(
            'UPDATE postes SET linea=?,estructura=?,codigo=?,utm_x=?,utm_y=?,zona=?,ubicaciones=?,activo=1,desactivado_en=NULL
             WHERE id=? AND proyecto_id=?'
        );
        $reactivateOnly = $db->prepare(
            'UPDATE postes SET activo=1,desactivado_en=NULL WHERE id=? AND proyecto_id=? AND activo=0'
        );

        $createdIds = [];
        $updated = 0;
        $reactivated = 0;
        $unchanged = 0;
        $archivedKept = 0;
        foreach ($validation['rows'] as $row) {
            if ($row['clasificacion'] === 'nueva') {
                $insertPost->execute([
                    $row['codigo'], $row['linea'], $row['torre'], $projectId,
                    $row['utm_x'], $row['utm_y'], $row['zona'], $row['ubicacion'],
                ]);
                if ($insertPost->rowCount() !== 1) throw new RuntimeException('No se creó una estructura esperada.');
                $postId = (int) $db->lastInsertId();
                $insertData->execute([$postId]);
                if ($insertData->rowCount() !== 1) throw new RuntimeException('No se creó el registro inicial de poste_datos.');
                $createdIds[] = $postId;
                continue;
            }

            $postId = (int) $row['poste_id'];
            $archived = $row['clasificacion'] === 'existente_archivada';
            $reactivate = $archived && isset($reactivationIds[$postId]);
            if ($archived && !$reactivate) {
                // Sin autorizacion explicita del administrador una estructura archivada
                // no se toca: ni padron maestro, ni activo, ni desactivado_en.
                $archivedKept++;
                continue;
            }
            $hasMasterChanges = $row['cambios'] !== [];
            $parameters = [
                $row['linea'], $row['torre'], $row['codigo'], $row['utm_x'], $row['utm_y'],
                $row['zona'], $row['ubicacion'], $postId, $projectId,
            ];

            if ($hasMasterChanges && $reactivate) $updateMasterAndReactivate->execute($parameters);
            elseif ($hasMasterChanges) $updateMaster->execute($parameters);
            elseif ($reactivate) $reactivateOnly->execute([$postId, $projectId]);
            else {
                // SIN CAMBIOS: el padron ya coincide, no se ejecuta ningun UPDATE.
                $unchanged++;
                continue;
            }

            $beforeAudit = [];
            $afterAudit = [];
            foreach ($row['cambios'] as $change) {
                $beforeAudit[$change['columna']] = $change['actual'];
                $afterAudit[$change['columna']] = $change['excel'];
            }
            if ($hasMasterChanges) $updated++;
            if ($reactivate) {
                $beforeAudit['activo'] = 0;
                $beforeAudit['desactivado_en'] = $row['_existing']['desactivado_en'];
                $afterAudit['activo'] = 1;
                $afterAudit['desactivado_en'] = null;
                $reactivated++;
            }
            adminAudit(
                $db,
                $user,
                $reactivate ? 'structure_excel_reactivated' : 'structure_master_excel_updated',
                'estructura',
                ['proyecto_id' => $projectId, 'linea' => $row['linea'], 'poste_id' => $postId],
                $beforeAudit,
                $afterAudit,
                'ok',
                'Actualización de padrón maestro desde Excel.'
            );
        }

        $newPostCount = 0;
        $newDataCount = 0;
        if ($createdIds) {
            $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
            $verifyPosts = $db->prepare("SELECT COUNT(*) FROM postes WHERE id IN ({$placeholders}) AND proyecto_id=?");
            $verifyPosts->execute([...$createdIds, $projectId]);
            $newPostCount = (int) $verifyPosts->fetchColumn();
            $verifyData = $db->prepare("SELECT COUNT(*) FROM poste_datos WHERE poste_id IN ({$placeholders})");
            $verifyData->execute($createdIds);
            $newDataCount = (int) $verifyData->fetchColumn();
            if ($newPostCount !== count($createdIds) || $newDataCount !== count($createdIds)) {
                throw new RuntimeException('La verificación de postes nuevos/poste_datos no coincide.');
            }
        }

        if ($existingIds) {
            $placeholders = implode(',', array_fill(0, count($existingIds), '?'));
            $verifyExisting = $db->prepare("SELECT COUNT(*) FROM postes WHERE id IN ({$placeholders}) AND proyecto_id=?");
            $verifyExisting->execute([...$existingIds, $projectId]);
            if ((int) $verifyExisting->fetchColumn() !== count($existingIds)) {
                throw new RuntimeException('Un poste existente cambió de identidad durante la importación.');
            }
            $dataCountsAfter = structureImportPostDataCounts($db, $existingIds);
            if ($dataCountsAfter !== $dataCountsBefore) {
                throw new RuntimeException('poste_datos de un poste existente fue alterado; se canceló la importación.');
            }
        }

        $lineNames = array_column($validation['line_groups'], 'nombre');
        $auditSummary = [
            'file_name' => structureImportSafeFilename($fileName),
            'nuevos' => $newPostCount,
            'actualizados' => $updated,
            'reactivados' => $reactivated,
            'sin_cambios' => $unchanged,
            'archivados_mantenidos' => $archivedKept,
            'poste_datos_creados' => $newDataCount,
            'lines' => $lineNames,
            'lines_created' => $createdLines,
        ];
        adminAudit(
            $db,
            $user,
            'structures_excel_import',
            'estructura',
            [
                'proyecto_id' => $projectId,
                'linea' => count($lineNames) === 1 ? $lineNames[0] : null,
            ],
            null,
            $auditSummary,
            'ok',
            "Padrón importado: {$newPostCount} nuevos, {$updated} actualizados, {$reactivated} reactivados."
        );

        $db->commit();
        return [
            'estructuras_creadas' => $newPostCount,
            'estructuras_actualizadas' => $updated,
            'estructuras_reactivadas' => $reactivated,
            'sin_cambios' => $unchanged,
            'archivadas_mantenidas' => $archivedKept,
            'poste_datos_creados' => $newDataCount,
            'lineas' => $lineNames,
            'lineas_creadas' => $createdLines,
            'proyecto_id' => $projectId,
            'proyecto' => $validation['project']['nombre_proyecto'],
        ];
    } catch (Throwable $exception) {
        if ($db->inTransaction()) $db->rollBack();
        throw $exception;
    }
}

function structureImportTemplate(): Spreadsheet
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Estructuras');
    $sheet->fromArray(STRUCTURE_IMPORT_HEADERS, null, 'A1');
    $sheet->getStyle('A1:F1')->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '173D5A']],
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
    ]);
    foreach (range('A', 'F') as $column) $sheet->getColumnDimension($column)->setWidth(22);
    $sheet->getColumnDimension('B')->setWidth(16);
    $sheet->getStyle('B2:B2001')->getNumberFormat()->setFormatCode('@');
    $sheet->freezePane('A2');
    $sheet->setAutoFilter('A1:F1');

    $instructions = $spreadsheet->createSheet();
    $instructions->setTitle('Instrucciones');
    $instructions->fromArray([
        ['Plantilla de importación de estructuras ECOING'],
        ['1.', 'No cambie los encabezados ni agregue columnas.'],
        ['2.', 'Escriba Torre como texto para conservar 01, 02, 03, etc.'],
        ['3.', 'X e Y corresponden a coordenadas UTM y admiten hasta cinco decimales.'],
        ['4.', 'Cuadrante es opcional; ejemplo válido: 19K.'],
        ['5.', 'Use una fila por estructura. Ubicacion es opcional.'],
    ], null, 'A1');
    $instructions->getStyle('A1:B1')->getFont()->setBold(true);
    $instructions->getColumnDimension('A')->setWidth(12);
    $instructions->getColumnDimension('B')->setWidth(85);
    $instructions->getStyle('A1:B6')->getAlignment()->setWrapText(true)->setVertical('top');
    $spreadsheet->setActiveSheetIndex(0);
    return $spreadsheet;
}
