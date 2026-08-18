<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function jwtSecret(): string
{
    $secret = (string) envValue('JWT_SECRET', '');
    if (strlen($secret) < 32) {
        throw new RuntimeException('JWT_SECRET debe tener al menos 32 caracteres.');
    }
    return $secret;
}

function generateJWT(int|string $userId, string $username, string $role): string
{
    $issuedAt = time();
    $ttl = max(300, (int) envValue('JWT_TTL', 604800));
    $issuer = rtrim((string) envValue('APP_URL', 'ecoing-local'), '/');

    return JWT::encode([
        'iss' => $issuer,
        'aud' => $issuer,
        'sub' => (string) $userId,
        'iat' => $issuedAt,
        'nbf' => $issuedAt,
        'exp' => $issuedAt + $ttl,
        'data' => [
            'id' => (int) $userId,
            'username' => $username,
            'rol' => $role,
        ],
    ], jwtSecret(), 'HS256');
}

function validateJWT(?string $token): array|false
{
    if (!$token) {
        return false;
    }

    try {
        $decoded = JWT::decode($token, new Key(jwtSecret(), 'HS256'));
        $issuer = rtrim((string) envValue('APP_URL', 'ecoing-local'), '/');
        if (($decoded->iss ?? null) !== $issuer || ($decoded->aud ?? null) !== $issuer || !isset($decoded->data)) {
            return false;
        }
        return (array) $decoded->data;
    } catch (Throwable $exception) {
        error_log('JWT validation failed: ' . $exception->getMessage());
        return false;
    }
}
