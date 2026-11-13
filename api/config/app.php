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

/**
 * Crea un directorio si falta.
 *
 * El modo es un parámetro porque los dos usos son distintos: 0750 para lo que
 * vive dentro del proyecto y 0775 para las carpetas montadas del NAS, que
 * comparten el usuario del share y el www-data del contenedor a través del
 * grupo. Ver `api/config/storage.php`.
 */
function ensureDirectory(string $path, int $mode = 0750): void
{
    if (!is_dir($path) && !mkdir($path, $mode, true) && !is_dir($path)) {
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

/**
 * Convierte un error fatal en una respuesta JSON.
 *
 * Un `require_once` que no encuentra su archivo, o una llamada a una función
 * que no existe, son fatales que ningún `try/catch` del endpoint puede
 * atrapar: ocurren al compilar, antes de que haya un handler. Con
 * `display_errors=0` —lo correcto en producción— eso sale como un 500 con el
 * cuerpo VACÍO, y el cliente sólo ve «Unexpected end of JSON input», que no
 * dice nada de la causa.
 *
 * Aquí se registra un cierre que, si la petición muere por un fatal y todavía
 * no se ha enviado nada, responde JSON con el código adecuado. El detalle va
 * al log del servidor; al cliente sólo se le manda el archivo y la línea
 * cuando APP_DEBUG está activo, nunca la traza.
 *
 * Si ya hay cabeceras enviadas no se toca la respuesta: un reporte PDF a medio
 * escribir no se puede convertir en JSON, y añadirlo sólo lo corrompería.
 */
function registerFatalJsonHandler(): void
{
    register_shutdown_function(static function (): void {
        $error = error_get_last();
        $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if ($error === null || !in_array($error['type'] ?? 0, $fatal, true)) {
            return;
        }

        error_log(sprintf(
            'Fatal sin capturar en %s: %s en %s:%d',
            $_SERVER['REQUEST_URI'] ?? PHP_SAPI,
            $error['message'] ?? 'sin mensaje',
            $error['file'] ?? 'desconocido',
            (int) ($error['line'] ?? 0)
        ));

        if (headers_sent()) {
            return;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        header_remove('Content-Type');
        header('Content-Type: application/json; charset=utf-8');
        $payload = [
            'success' => false,
            'error' => 'El servidor no pudo completar la operación. No se modificó ninguna información.',
        ];
        if (envValue('APP_DEBUG', false) === true) {
            $payload['detalle'] = sprintf(
                '%s en %s:%d',
                $error['message'] ?? '',
                basename((string) ($error['file'] ?? '')),
                (int) ($error['line'] ?? 0)
            );
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    });
}

function bootstrapHttp(): void
{
    // Se registra antes de cualquier otra cosa y también en CLI: así cubre los
    // require de los endpoints, que se ejecutan después de este arranque.
    registerFatalJsonHandler();
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
        header('Access-Control-Expose-Headers: Content-Disposition, X-Ecoing-File-Source, X-Ecoing-Recovered-Files, X-Ecoing-Missing-Files');
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

/**
 * Ruta del archivo .env que se debe cargar.
 *
 * `APP_ENV_FILE` se lee del entorno del proceso —no del propio .env, que
 * todavía no está cargado— y permite dejar las credenciales FUERA del
 * DocumentRoot, por ejemplo montando `/config/.env` como solo-lectura.
 *
 * En la pila Docker no se usa ningún archivo: las variables llegan como
 * variables de entorno del contenedor y `envValue()` las encuentra con
 * getenv(). `loadEnvironment()` simplemente no hace nada si el archivo no
 * existe, así que no hay que configurar nada para ese caso.
 */
function environmentFilePath(): string
{
    $configured = getenv('APP_ENV_FILE');
    if (is_string($configured) && trim($configured) !== '') {
        return trim($configured);
    }

    return projectPath('.env');
}

loadEnvironment(environmentFilePath());
date_default_timezone_set((string) envValue('APP_TIMEZONE', 'America/Lima'));

// Almacenamiento y registro. Se cargan aquí, después del .env, porque resuelven
// sus rutas a partir de él. `storage.php` y `log.php` solo declaran funciones:
// el require_once cruzado hacia este archivo es un no-op.
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/log.php';

// Los logs de PHP van al almacenamiento persistente (en el NAS, /data/logs), no
// al filesystem del contenedor, que se borra al recrearlo.
try {
    $logDirectory = storagePath('logs', true);
} catch (Throwable) {
    // Almacén no disponible: mejor un log en el árbol del proyecto que ninguno.
    $logDirectory = projectPath('storage/logs');
    ensureDirectory($logDirectory);
}
ini_set('display_errors', envValue('APP_DEBUG', false) ? '1' : '0');
ini_set('log_errors', '1');
ini_set('error_log', $logDirectory . DIRECTORY_SEPARATOR . 'api.log');
error_reporting(E_ALL & ~E_DEPRECATED);

bootstrapHttp();
