<?php
declare(strict_types=1);

/**
 * Comprobación de estado del servicio. NO requiere autenticación.
 *
 * Se usa desde tres sitios:
 *   · el HEALTHCHECK del contenedor (Container Station lo pinta en verde/rojo);
 *   · un navegador de la oficina: http://192.168.1.3:8081/api/health.php
 *   · cualquier monitor del NAS.
 *
 * ## Qué NO devuelve
 *
 * Ni versión de PHP, ni de MariaDB, ni rutas internas, ni nombres de host, ni
 * credenciales, ni mensajes de error de la base. Un endpoint público que
 * enumera versiones es un catálogo de vulnerabilidades para quien esté dentro
 * de la red. Cuando algo falla, dice *qué* subsistema falla y nada más; el
 * detalle real queda en /data/logs/api.log, que sí es privado.
 *
 * ## Por qué no reemplaza a info.php
 *
 * `api/info.php` es la ruta que la app Flutter usa para decidir si puede
 * sincronizar (`ApiConfig.salud`). Se deja intacta a propósito: cambiarla
 * obligaría a recompilar el APK de los inspectores.
 */

require_once __DIR__ . '/config/app.php';

requireMethod('GET');

$checks = [];
$healthy = true;

// --- Base de datos ---------------------------------------------------------
// Se conecta aparte y no con config/database.php, porque ese archivo responde
// un 503 y termina la petición cuando falla: aquí interesa seguir y reportar.
try {
    $connection = new PDO(
        sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) envValue('DB_HOST', '127.0.0.1'),
            (int) envValue('DB_PORT', 3306),
            (string) envValue('DB_DATABASE', 'ecoing_inspeccion')
        ),
        (string) envValue('DB_USERNAME', 'root'),
        (string) envValue('DB_PASSWORD', ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]
    );
    $connection->query('SELECT 1')->fetchColumn();
    $checks['database'] = 'ok';
} catch (Throwable $exception) {
    // El mensaje del driver puede incluir el host y el usuario: solo al log.
    error_log('Health check: database unavailable: ' . $exception->getMessage());
    $checks['database'] = 'error';
    $healthy = false;
}

// --- Almacenamiento --------------------------------------------------------
// No basta con que el directorio exista: en un bind mount del NAS puede existir
// y no ser escribible por www-data, que es justo el fallo que hay que detectar
// antes de que un inspector pierda una subida de 28 fotografías.
foreach (['images' => 'storage_imagenes', 'pdf' => 'storage_pdf', 'logs' => 'storage_logs'] as $key => $label) {
    try {
        $root = storagePath($key, true);
        $probe = $root . DIRECTORY_SEPARATOR . '.health_' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'ok') === false) {
            throw new RuntimeException('no escribible');
        }
        @unlink($probe);
        $checks[$label] = 'ok';
    } catch (Throwable $exception) {
        error_log('Health check: storage ' . $key . ' not writable: ' . $exception->getMessage());
        $checks[$label] = 'error';
        $healthy = false;
    }
}

respond(
    [
        'status' => $healthy ? 'ok' : 'degraded',
        'service' => 'ECOING API',
        'checks' => $checks,
        'time' => date('c'),
    ],
    $healthy ? 200 : 503
);
