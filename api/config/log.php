<?php
declare(strict_types=1);

/**
 * Registro persistente de la aplicación.
 *
 * ## Por qué existe
 *
 * `error_log()` va a un único archivo y sirve para lo que falla. Faltaba lo
 * otro: cuánto tardó una subida de 28 fotografías, si el PDF se sirvió de la
 * caché o se regeneró, por qué no se pudo escribir en el NAS. Sin esos datos
 * "la sincronización tarda un minuto" no se puede diagnosticar, solo repetir.
 *
 * Los archivos viven en `LOG_STORAGE_PATH` —en el NAS, no dentro del
 * contenedor— así que sobreviven a `docker compose down`.
 *
 * ## Qué NO se registra, nunca
 *
 * Ni tokens JWT, ni contraseñas, ni cabeceras `Authorization` completas.
 * `redactSensitive()` los recorta aunque quien llame se despiste y los pase
 * dentro del contexto.
 *
 * Canales en uso:
 *   api.log            errores de PHP (lo fija api/config/app.php)
 *   rendimiento.log    duración de subidas y de generación de PDF
 *   sincronizacion.log resultado de cada subida por poste
 *   almacenamiento.log fallos de escritura o de resolución de rutas
 */

require_once __DIR__ . '/storage.php';

/**
 * Recorta del texto cualquier cosa que parezca un secreto.
 */
function redactSensitive(string $text): string
{
    $patterns = [
        // Authorization: Bearer eyJhbGciOi... -> Bearer [oculto]
        '/\bBearer\s+[A-Za-z0-9._\-]+/i' => 'Bearer [oculto]',
        // Un JWT suelto (tres bloques base64url separados por puntos).
        '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/' => '[jwt oculto]',
        // contrasena=..., password: "...", pwd=...
        '/\b(contrasena|contraseña|password|passwd|pwd|secret|token)\b\s*[:=]\s*("[^"]*"|\'[^\']*\'|[^\s,;&]+)/iu' => '$1=[oculto]',
    ];

    foreach ($patterns as $pattern => $replacement) {
        $text = preg_replace($pattern, $replacement, $text) ?? $text;
    }

    return $text;
}

/**
 * Escribe una línea en un canal de log.
 *
 * Formato: `2026-08-20T09:14:31-05:00 [canal] mensaje | clave=valor clave=valor`
 *
 * Nunca lanza: un fallo de registro no puede tumbar una subida de fotografías
 * que ya está confirmada. Si el directorio no es escribible, cae a
 * `error_log()`, que en el contenedor termina en el log de Apache.
 *
 * @param array<string, scalar|null> $context
 */
function appLog(string $channel, string $message, array $context = []): void
{
    $pieces = [];
    foreach ($context as $key => $value) {
        if ($value === null) {
            continue;
        }
        if (is_bool($value)) {
            $value = $value ? 'si' : 'no';
        }
        $pieces[] = $key . '=' . str_replace(["\n", "\r", '|'], ' ', (string) $value);
    }

    $line = sprintf(
        '%s [%s] %s%s',
        date('c'),
        $channel,
        redactSensitive($message),
        $pieces === [] ? '' : ' | ' . redactSensitive(implode(' ', $pieces))
    );

    try {
        $file = storagePath('logs', true) . DIRECTORY_SEPARATOR
            . preg_replace('/[^a-z0-9_-]/i', '', $channel) . '.log';
        if (@file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log($line);
        }
    } catch (Throwable) {
        error_log($line);
    }
}

/**
 * Métrica de rendimiento. Se puede apagar con `UPLOAD_METRICS_ENABLED=false`
 * para no escribir una línea por fotografía en instalaciones donde ya no
 * interese medir.
 *
 * @param array<string, scalar|null> $context
 */
function appMetric(string $message, array $context = []): void
{
    if (envValue('UPLOAD_METRICS_ENABLED', false) !== true) {
        return;
    }

    appLog('rendimiento', $message, $context);
}

/**
 * Milisegundos transcurridos desde `$start` (un `hrtime(true)`).
 */
function elapsedMs(int|float $start): float
{
    return round((hrtime(true) - $start) / 1_000_000, 1);
}
