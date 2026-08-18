<?php
declare(strict_types=1);

const PROJECT_ROOT = __DIR__ . '/../..';

/**
 * Configuración leída desde .env para la petición actual.
 *
 * No se usa putenv(): en Apache para Windows las peticiones concurrentes se
 * ejecutan en hilos del mismo proceso y una variable temporal puede desaparecer
 * cuando termina otro request. Eso hacía que JWT_SECRET fuese intermitente.
 *
 * @var array<string, string>
 */
$GLOBALS['app_environment'] = [];

function loadEnvironment(string $file): void
{
    if (!is_file($file)) {
        return;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if ($name === '') {
            continue;
        }

        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        $GLOBALS['app_environment'][$name] = $value;
        $_ENV[$name] = $value;
    }
}

function envValue(string $name, mixed $default = null): mixed
{
    $fileEnvironment = $GLOBALS['app_environment'] ?? [];
    if (array_key_exists($name, $fileEnvironment)) {
        $value = $fileEnvironment[$name];
    } else {
        $value = getenv($name);
        if ($value === false) {
            return $default;
        }
    }

    return match (strtolower($value)) {
        'true', '(true)' => true,
        'false', '(false)' => false,
        'null', '(null)' => null,
        'empty', '(empty)' => '',
        default => $value,
    };
}

function projectPath(string $path = ''): string
{
    return rtrim(PROJECT_ROOT, '/\\') . ($path === '' ? '' : DIRECTORY_SEPARATOR . ltrim($path, '/\\'));
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0750, true) && !is_dir($path)) {
        throw new RuntimeException('No se pudo crear el directorio requerido.');
    }
}

function allowedOrigin(?string $origin): ?string
{
    if (!$origin) {
        return null;
    }

    $environment = (string) envValue('APP_ENV', 'production');
    if ($environment === 'local' && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i', $origin)) {
        return $origin;
    }

    $allowed = array_filter(array_map('trim', explode(',', (string) envValue('CORS_ALLOWED_ORIGINS', ''))));
    return in_array($origin, $allowed, true) ? $origin : null;
}

function bootstrapHttp(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');

    $origin = allowedOrigin($_SERVER['HTTP_ORIGIN'] ?? null);
    if ($origin !== null) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Max-Age: 86400');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function respond(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function requestJson(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) {
        respond(['success' => false, 'error' => 'El cuerpo debe ser JSON válido.'], 400);
    }
    return $data;
}

function requireMethod(string ...$methods): void
{
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $allowed = array_map('strtoupper', $methods);
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        respond(['success' => false, 'error' => 'Método no permitido.'], 405);
    }
}

loadEnvironment(projectPath('.env'));
date_default_timezone_set((string) envValue('APP_TIMEZONE', 'America/Lima'));

$logDirectory = projectPath('storage/logs');
ensureDirectory($logDirectory);
ini_set('display_errors', envValue('APP_DEBUG', false) ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', $logDirectory . DIRECTORY_SEPARATOR . 'api.log');
error_reporting(E_ALL & ~E_DEPRECATED);

bootstrapHttp();
