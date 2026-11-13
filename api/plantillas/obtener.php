<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/PlantillaService.php';
require_once __DIR__ . '/../clases/PDFPlantilla.php';

/*
 * Formulario y fotografías que la app debe pedir en un proyecto.
 *
 * `data: null` significa que el proyecto sigue el flujo fijo de siempre. El
 * diseño del PDF no se envía entero: de él solo viaja, en cada foto,
 * `formato`, la medida con la que se imprime (mm y píxeles), para que la app
 * la encuadre con esa forma. `null` = sin recuadro propio, se toma libre.
 */
requireMethod('GET');
requireAuth(['administrador', 'supervisor', 'tecnico']);

$projectId = filter_input(INPUT_GET, 'proyecto_id', FILTER_VALIDATE_INT);
if (!$projectId) {
    respond(['success' => false, 'error' => 'proyecto_id no es válido.'], 422);
}

try {
    $exists = $conn->prepare('SELECT 1 FROM proyectos WHERE id = ?');
    $exists->execute([$projectId]);
    if (!$exists->fetchColumn()) {
        respond(['success' => false, 'error' => 'Proyecto no encontrado.'], 404);
    }
    $plantilla = plantillaDeProyecto($conn, (int) $projectId);
    if ($plantilla === null) {
        respond(['success' => true, 'data' => null]);
    }
    $formatos = plantillaFormatosFoto($plantilla['config']);
    $fotos = array_map(
        static fn(array $f): array => $f + ['formato' => $formatos[$f['clave']] ?? null],
        $plantilla['config']['fotos']
    );
    respond(['success' => true, 'data' => [
        'plantilla_id' => $plantilla['id'],
        'version' => $plantilla['version'],
        'nombre' => $plantilla['nombre'],
        'formulario' => $plantilla['config']['formulario'],
        'fotos' => $fotos,
    ]]);
} catch (Throwable $e) {
    appLog('sincronizacion', 'No se pudo entregar la plantilla.', ['proyecto' => $projectId, 'motivo' => $e->getMessage()]);
    respond(['success' => false, 'error' => 'No se pudo obtener la plantilla del proyecto.'], 500);
}
