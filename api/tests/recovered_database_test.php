<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AdminCatalogService.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$database = (string) $conn->query('SELECT DATABASE()')->fetchColumn();
$assert($database === 'ecoing_inspeccion_recuperada', 'Conexion dirigida a una base inesperada.');

$tables = $conn->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$requiredTables = [
    'proyectos', 'lineas', 'postes', 'poste_datos', 'poste_secciones_rst',
    'imagenes_poste', 'imagenes_poste_estado', 'usuarios', 'admin_auditoria',
];
foreach ($requiredTables as $table) {
    $assert(in_array($table, $tables, true), "Falta la tabla {$table}.");
}

$requiredColumns = [
    'postes' => ['activo', 'desactivado_en', 'ultima_limpieza_admin', 'inspeccion_finalizada'],
    'poste_secciones_rst' => ['activo', 'archivado_en'],
    'imagenes_poste' => ['activo', 'archivado_en'],
    'imagenes_poste_estado' => ['activo', 'archivado_en'],
];
foreach ($requiredColumns as $table => $columns) {
    $present = $conn->query("SHOW COLUMNS FROM {$table}")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($columns as $column) {
        $assert(in_array($column, $present, true), "Falta {$table}.{$column}.");
    }
}

$project = adminFetch($conn, 'proyectos', 2);
$stats = adminCleanupStats($conn, 2, 'L-6024');
$mobileCount = (int) $conn->query(
    "SELECT COUNT(*) FROM postes p
     INNER JOIN proyectos pr ON pr.id=p.proyecto_id AND pr.estado='activo'
     LEFT JOIN lineas l ON l.proyecto_id=p.proyecto_id AND l.nombre=p.linea
     WHERE p.activo=1 AND COALESCE(l.activa,1)=1"
)->fetchColumn();

$baseCount = (int) $conn->query("SELECT COUNT(*) FROM postes WHERE linea <> 'DEV-PANEL-20260824'")->fetchColumn();
$devCount = (int) $conn->query("SELECT COUNT(*) FROM postes WHERE linea = 'DEV-PANEL-20260824'")->fetchColumn();
$assert($baseCount === 1235, 'El padron recuperado ya no contiene exactamente 1,235 postes.');
$assert($mobileCount === $baseCount + $devCount, 'El filtro movil no devuelve todos los postes activos.');
$assert((int) $stats['estructuras'] === 118, 'Las estadisticas admin de L-6024 no coinciden.');

echo json_encode([
    'success' => true,
    'database' => $database,
    'project' => $project['nombre_proyecto'],
    'recovered_base_posts' => $baseCount,
    'development_posts' => $devCount,
    'mobile_active_posts' => $mobileCount,
    'admin_l6024_stats' => $stats,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
