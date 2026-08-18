<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

requireMethod('POST');
requireAuth(['administrador']);
$data = requestJson();

$required = ['nombre_completo', 'nombre_usuario', 'correo_electronico', 'contrasena', 'rol'];
foreach ($required as $field) {
    if (trim((string) ($data[$field] ?? '')) === '') {
        respond(['success' => false, 'error' => "Falta el campo: {$field}."], 422);
    }
}

$roles = ['administrador', 'supervisor', 'tecnico', 'invitado'];
if (!in_array($data['rol'], $roles, true)) {
    respond(['success' => false, 'error' => 'Rol no válido.'], 422);
}
if (!filter_var($data['correo_electronico'], FILTER_VALIDATE_EMAIL)) {
    respond(['success' => false, 'error' => 'El correo electrónico no es válido.'], 422);
}
if (mb_strlen($data['contrasena']) < 10) {
    respond(['success' => false, 'error' => 'La contraseña debe tener al menos 10 caracteres.'], 422);
}

try {
    $statement = $conn->prepare(
        'INSERT INTO usuarios (nombre_completo, nombre_usuario, correo_electronico, contrasena_hash, rol, activo)
         VALUES (?, ?, ?, ?, ?, 1)'
    );
    $statement->execute([
        trim($data['nombre_completo']),
        trim($data['nombre_usuario']),
        mb_strtolower(trim($data['correo_electronico'])),
        password_hash($data['contrasena'], PASSWORD_DEFAULT),
        $data['rol'],
    ]);
    respond(['success' => true, 'message' => 'Usuario registrado.', 'id' => (int) $conn->lastInsertId(), 'rol' => $data['rol']], 201);
} catch (PDOException $exception) {
    if ((int) $exception->errorInfo[1] === 1062) {
        respond(['success' => false, 'error' => 'El usuario o correo ya existe.'], 409);
    }
    error_log('User registration failed: ' . $exception->getMessage());
    respond(['success' => false, 'error' => 'No se pudo registrar el usuario.'], 500);
}
