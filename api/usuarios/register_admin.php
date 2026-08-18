<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

requireMethod('POST');

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    respond(['success' => false, 'error' => 'El administrador inicial solo puede crearse desde este equipo.'], 403);
}

if ((int) $conn->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() !== 0) {
    respond(['success' => false, 'error' => 'La instalación ya tiene usuarios. Use el endpoint autenticado de registro.'], 409);
}

$data = requestJson();
foreach (['nombre_completo', 'nombre_usuario', 'correo_electronico', 'contrasena'] as $field) {
    if (trim((string) ($data[$field] ?? '')) === '') {
        respond(['success' => false, 'error' => "Falta el campo: {$field}."], 422);
    }
}
if (!filter_var($data['correo_electronico'], FILTER_VALIDATE_EMAIL) || mb_strlen($data['contrasena']) < 10) {
    respond(['success' => false, 'error' => 'Use un correo válido y una contraseña de al menos 10 caracteres.'], 422);
}

try {
    $statement = $conn->prepare(
        "INSERT INTO usuarios (nombre_completo, nombre_usuario, correo_electronico, contrasena_hash, rol, activo)
         VALUES (?, ?, ?, ?, 'administrador', 1)"
    );
    $statement->execute([
        trim($data['nombre_completo']),
        trim($data['nombre_usuario']),
        mb_strtolower(trim($data['correo_electronico'])),
        password_hash($data['contrasena'], PASSWORD_DEFAULT),
    ]);
    respond(['success' => true, 'message' => 'Administrador inicial creado.', 'id' => (int) $conn->lastInsertId()], 201);
} catch (PDOException $exception) {
    error_log('Admin bootstrap failed: ' . $exception->getMessage());
    respond(['success' => false, 'error' => 'No se pudo crear el administrador inicial.'], 500);
}
