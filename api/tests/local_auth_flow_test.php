<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/jwt.php';

if ((string) $conn->query('SELECT DATABASE()')->fetchColumn() !== 'ecoing_inspeccion_recuperada') {
    throw new RuntimeException('Esta prueba solo admite ecoing_inspeccion_recuperada.');
}

$adminToken = (string) getenv('TEST_ADMIN_JWT');
if ($adminToken === '') {
    throw new RuntimeException('Falta el token administrativo temporal para la prueba.');
}

$issuer = rtrim((string) envValue('APP_URL', 'ecoing-local'), '/');
$techToken = generateJWT(999, 'tech_dev_virtual', 'tecnico');
$expiredToken = Firebase\JWT\JWT::encode([
    'iss' => $issuer,
    'aud' => $issuer,
    'sub' => '999',
    'iat' => time() - 600,
    'nbf' => time() - 600,
    'exp' => time() - 300,
    'data' => ['id' => 999, 'username' => 'expired_dev_virtual', 'rol' => 'administrador'],
], jwtSecret(), 'HS256');

$status = static function (string $url, ?string $token = null): int {
    $headers = "Accept: application/json\r\n";
    if ($token !== null) $headers .= "Authorization: Bearer {$token}\r\n";
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'header' => $headers, 'ignore_errors' => true, 'timeout' => 10,
    ]]);
    @file_get_contents($url, false, $context);
    $line = $http_response_header[0] ?? '';
    return preg_match('/\s(\d{3})\s/', $line, $match) ? (int) $match[1] : 0;
};

$base = rtrim($issuer, '/');
$validated = validateJWT($adminToken);
$results = [
    'jwt_admin_valido' => is_array($validated) && ($validated['rol'] ?? null) === 'administrador',
    'endpoint_admin' => $status($base . '/api/admin/proyectos.php', $adminToken),
    'tecnico_virtual' => $status($base . '/api/admin/proyectos.php', $techToken),
    'token_expirado' => $status($base . '/api/admin/proyectos.php', $expiredToken),
    'token_invalido' => $status($base . '/api/admin/proyectos.php', 'token-invalido'),
    'panel_html' => $status($base . '/web/admin.html'),
];

if ($results !== [
    'jwt_admin_valido' => true,
    'endpoint_admin' => 200,
    'tecnico_virtual' => 403,
    'token_expirado' => 401,
    'token_invalido' => 401,
    'panel_html' => 200,
]) {
    throw new RuntimeException('El flujo de autenticacion local no coincide con lo esperado.');
}

echo json_encode(['success' => true, 'results' => $results], JSON_PRETTY_PRINT), PHP_EOL;
