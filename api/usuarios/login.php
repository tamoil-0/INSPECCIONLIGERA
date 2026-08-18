<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';

requireMethod('POST');
$data = requestJson();
$username = trim((string) ($data['nombre_usuario'] ?? ''));
$password = (string) ($data['contrasena'] ?? '');

if ($username === '' || $password === '') {
    respond(['success' => false, 'error' => 'Debe proporcionar nombre_usuario y contrasena.'], 422);
}

$clientKey = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . mb_strtolower($username));

try {
    $rate = $conn->prepare('SELECT intentos, bloqueado_hasta FROM login_intentos WHERE clave = ?');
    $rate->execute([$clientKey]);
    $attempt = $rate->fetch();
    if ($attempt && $attempt['bloqueado_hasta'] && strtotime($attempt['bloqueado_hasta']) > time()) {
        respond(['success' => false, 'error' => 'Demasiados intentos. Intente nuevamente en unos minutos.'], 429);
    }
    if ($attempt && $attempt['bloqueado_hasta'] && strtotime($attempt['bloqueado_hasta']) <= time()) {
        $attempt['intentos'] = 0;
        $attempt['bloqueado_hasta'] = null;
    }

    $statement = $conn->prepare(
        'SELECT id, nombre_usuario, nombre_completo, correo_electronico, contrasena_hash, rol, activo, dispositivo_id
         FROM usuarios WHERE nombre_usuario = ? LIMIT 1'
    );
    $statement->execute([$username]);
    $user = $statement->fetch();

    if (!$user || !password_verify($password, $user['contrasena_hash'])) {
        $attempts = (int) ($attempt['intentos'] ?? 0) + 1;
        $blockedUntil = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
        $save = $conn->prepare(
            'INSERT INTO login_intentos (clave, intentos, bloqueado_hasta, actualizado)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE intentos = VALUES(intentos), bloqueado_hasta = VALUES(bloqueado_hasta), actualizado = NOW()'
        );
        $save->execute([$clientKey, $attempts, $blockedUntil]);
        respond(['success' => false, 'error' => 'Credenciales inválidas.'], 401);
    }

    if (!(bool) $user['activo']) {
        respond(['success' => false, 'error' => 'La cuenta está desactivada.'], 403);
    }

    $conn->prepare('DELETE FROM login_intentos WHERE clave = ?')->execute([$clientKey]);
    $conn->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = ?')->execute([$user['id']]);

    $ttl = max(300, (int) envValue('JWT_TTL', 604800));
    respond([
        'success' => true,
        'token' => generateJWT($user['id'], $user['nombre_usuario'], $user['rol']),
        'token_ttl' => $ttl,
        'usuario' => [
            'id' => (int) $user['id'],
            'nombre_usuario' => $user['nombre_usuario'],
            'nombre_completo' => $user['nombre_completo'],
            'correo_electronico' => $user['correo_electronico'],
            'rol' => $user['rol'],
            'dispositivo_id' => $user['dispositivo_id'],
        ],
        'message' => 'Autenticación exitosa.',
    ]);
} catch (PDOException $exception) {
    error_log('Login failed: ' . $exception->getMessage());
    respond(['success' => false, 'error' => 'No se pudo iniciar sesión.'], 500);
}
