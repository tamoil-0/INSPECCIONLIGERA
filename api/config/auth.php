<?php
declare(strict_types=1);

require_once __DIR__ . '/jwt.php';

function bearerToken(): ?string
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($authorization === '' && function_exists('apache_request_headers')) {
        $headers = array_change_key_case(apache_request_headers(), CASE_LOWER);
        $authorization = $headers['authorization'] ?? '';
    }

    return preg_match('/^Bearer\s+([^\s]+)$/i', trim($authorization), $matches)
        ? $matches[1]
        : null;
}

function requireAuth(array $roles = []): array
{
    $user = validateJWT(bearerToken());
    if ($user === false) {
        respond(['success' => false, 'error' => 'Token ausente, inválido o expirado.'], 401);
    }

    if ($roles !== [] && !in_array($user['rol'] ?? '', $roles, true)) {
        respond(['success' => false, 'error' => 'No tiene permisos para realizar esta acción.'], 403);
    }

    return $user;
}

