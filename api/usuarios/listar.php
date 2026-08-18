<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('GET');
requireAuth(['administrador']);

$role = trim((string) ($_GET['rol'] ?? ''));
$active = isset($_GET['activo']) && $_GET['activo'] !== '' ? (int) $_GET['activo'] : null;
$search = trim((string) ($_GET['busqueda'] ?? ''));
$allowedOrder = ['id', 'nombre_completo', 'nombre_usuario', 'correo_electronico', 'rol', 'fecha_creacion', 'ultimo_login', 'activo'];
$order = in_array($_GET['orden'] ?? '', $allowedOrder, true) ? $_GET['orden'] : 'fecha_creacion';
$direction = strtoupper((string) ($_GET['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
$page = max(1, (int) ($_GET['pagina'] ?? 1));
$perPage = min(100, max(5, (int) ($_GET['por_pagina'] ?? 10)));
$offset = ($page - 1) * $perPage;

$conditions = [];
$parameters = [];
if ($role !== '') {
    $conditions[] = 'rol = :rol';
    $parameters['rol'] = $role;
}
if ($active !== null) {
    $conditions[] = 'activo = :activo';
    $parameters['activo'] = $active === 1 ? 1 : 0;
}
if ($search !== '') {
    $conditions[] = '(nombre_completo LIKE :search OR nombre_usuario LIKE :search OR correo_electronico LIKE :search)';
    $parameters['search'] = '%' . $search . '%';
}
$where = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

try {
    $count = $conn->prepare('SELECT COUNT(*) FROM usuarios' . $where);
    $count->execute($parameters);
    $total = (int) $count->fetchColumn();

    $sql = "SELECT id, nombre_completo, nombre_usuario, correo_electronico, rol, fecha_creacion,
                   ultimo_login, activo, dispositivo_id, ultima_sincronizacion
            FROM usuarios{$where} ORDER BY {$order} {$direction} LIMIT :limit OFFSET :offset";
    $statement = $conn->prepare($sql);
    foreach ($parameters as $name => $value) {
        $statement->bindValue(':' . $name, $value);
    }
    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    $users = $statement->fetchAll();
    foreach ($users as &$user) {
        $user['id'] = (int) $user['id'];
        $user['activo'] = (bool) $user['activo'];
    }

    respond([
        'success' => true,
        'total' => $total,
        'pagina' => $page,
        'por_pagina' => $perPage,
        'paginas_totales' => (int) ceil($total / $perPage),
        'data' => $users,
    ]);
} catch (PDOException $exception) {
    error_log('User list failed: ' . $exception->getMessage());
    respond(['success' => false, 'error' => 'No se pudieron obtener los usuarios.'], 500);
}
