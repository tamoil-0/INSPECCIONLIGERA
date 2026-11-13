<?php
declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/StructureImportService.php';

$user = requireAuth(['administrador']);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($method === 'GET') {
        if (($_GET['plantilla'] ?? '') !== '1') {
            respond(['success' => false, 'error' => 'Recurso no encontrado.'], 404);
        }

        $spreadsheet = structureImportTemplate();
        header_remove('Content-Type');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Plantilla_Estructuras_ECOING.xlsx"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        (new Xlsx($spreadsheet))->save('php://output');
        $spreadsheet->disconnectWorksheets();
        exit;
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        respond(['success' => false, 'error' => 'Método no permitido.'], 405);
    }

    if (isset($_FILES['archivo'])) {
        $projectId = adminId($_POST['proyecto_id'] ?? null, 'Proyecto');
        $contextLine = adminText($_POST['linea_contexto'] ?? null, 100, 'Línea de contexto', false);
        $allowMissing = filter_var($_POST['permitir_lineas_nuevas'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($contextLine !== null) $allowMissing = false;

        $upload = structureImportSecureUpload($_FILES['archivo']);
        try {
            $parsed = structureImportReadXlsx($upload['path']);
            $validation = structureImportValidateRows(
                $conn,
                $projectId,
                $parsed['rows'],
                $contextLine,
                $allowMissing ? 'preview' : 'none',
                [],
                $parsed['file_errors'],
                $parsed['columns_valid']
            );
        } finally {
            @unlink($upload['path']);
        }

        respond([
            'success' => true,
            'message' => $validation['can_import']
                ? 'Archivo leído y validado. Revise el resumen antes de importar.'
                : 'El archivo contiene errores que debe corregir antes de importar.',
            'data' => [
                'file_name' => $upload['file_name'],
                'project' => [
                    'id' => (int) $validation['project']['id'],
                    'name' => $validation['project']['nombre_proyecto'],
                ],
                'headers' => $parsed['headers'],
                'columns_valid' => $validation['columns_valid'],
                'file_errors' => $validation['file_errors'],
                'summary' => $validation['summary'],
                'line_groups' => $validation['line_groups'],
                'missing_lines' => $validation['missing_lines'],
                'rows' => structureImportPublicRows($validation['rows']),
                'can_import' => $validation['can_import'],
                'context_line' => $contextLine,
                'can_create_missing_lines' => $allowMissing,
            ],
        ]);
    }

    $request = requestJson();
    if (($request['accion'] ?? '') !== 'importar') {
        throw new InvalidArgumentException('Acción de importación no válida.');
    }
    $projectId = adminId($request['proyecto_id'] ?? null, 'Proyecto');
    $records = $request['registros'] ?? null;
    if (!is_array($records)) throw new InvalidArgumentException('Los registros validados son obligatorios.');
    $contextLine = adminText($request['linea_contexto'] ?? null, 100, 'Línea de contexto', false);
    $authorizedMissing = $request['crear_lineas'] ?? [];
    if (!is_array($authorizedMissing)) throw new InvalidArgumentException('La confirmación de líneas no es válida.');
    $authorizedReactivations = $request['reactivar_postes'] ?? [];
    if (!is_array($authorizedReactivations)) throw new InvalidArgumentException('La confirmación de reactivaciones no es válida.');
    if ($contextLine !== null && $authorizedMissing) {
        throw new DomainException('No se pueden crear líneas desde el contexto de una línea concreta.');
    }

    $result = structureImportExecute(
        $conn,
        $user,
        $projectId,
        $records,
        $authorizedMissing,
        $authorizedReactivations,
        $contextLine,
        structureImportSafeFilename($request['file_name'] ?? 'importacion.xlsx')
    );
    respond([
        'success' => true,
        'message' => 'Padrón importado: ' . $result['estructuras_creadas'] . ' nuevas, '
            . $result['estructuras_actualizadas'] . ' actualizadas y '
            . $result['estructuras_reactivadas'] . ' reactivadas.',
        'data' => $result,
    ], 201);
} catch (InvalidArgumentException $exception) {
    if ($conn->inTransaction()) $conn->rollBack();
    respond(['success' => false, 'error' => $exception->getMessage()], 422);
} catch (DomainException $exception) {
    if ($conn->inTransaction()) $conn->rollBack();
    respond(['success' => false, 'error' => $exception->getMessage()], 409);
} catch (PDOException $exception) {
    if ($conn->inTransaction()) $conn->rollBack();
    $driverCode = (int) ($exception->errorInfo[1] ?? 0);
    appLog('administracion', 'Fallo SQL en importación de estructuras.', ['codigo' => $driverCode]);
    respond([
        'success' => false,
        'error' => $driverCode === 1062
            ? 'Se detectó un duplicado durante la confirmación. No se insertó ninguna estructura.'
            : 'No se pudo completar la importación. No se realizaron cambios.',
    ], $driverCode === 1062 ? 409 : 500);
} catch (Throwable $exception) {
    if ($conn->inTransaction()) $conn->rollBack();
    appLog('administracion', 'Fallo en importación de estructuras.', ['motivo' => $exception->getMessage()]);
    respond(['success' => false, 'error' => 'No se pudo procesar la importación. No se realizaron cambios.'], 500);
}
