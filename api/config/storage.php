<?php
declare(strict_types=1);

/**
 * Servicio de almacenamiento del sistema.
 *
 * ## Por qué existe
 *
 * Antes cada punto del código componía sus rutas a mano contra la raíz del
 * proyecto: `projectPath('storage/images/' . $postId)` al guardar,
 * `str_starts_with($ruta, 'storage/images/')` al validar, y `storage/logs` fijo
 * para el log de errores. Eso ataba las fotografías al filesystem de la
 * aplicación, que en un contenedor es efímero: recrear `ecoing-app` borraría
 * todas las inspecciones.
 *
 * Aquí se centraliza:
 *
 *   · **dónde** se escribe   -> raíces configurables por .env / entorno;
 *   · **cómo** se nombra     -> jerarquía proyecto/línea/poste y nombre aleatorio;
 *   · **qué es válido leer** -> normalización que corta el path traversal.
 *
 * ## Rutas absolutas fuera, rutas relativas dentro
 *
 * La base de datos guarda SIEMPRE la ruta relativa a la raíz de imágenes:
 *
 *     proyecto_1/linea_L-6021/poste_452/placa_9f3c1a2b4d5e6f708192a3b4.jpg
 *
 * Nunca `/data/imagenes/...` ni `C:\xampp82\...`. Mover el almacén de sitio
 * —de XAMPP al NAS, o de un share a otro— es cambiar una variable de entorno,
 * sin un solo UPDATE en `imagenes_poste`.
 *
 * ## Compatibilidad con lo que ya existe
 *
 * Las filas antiguas guardan `storage/images/452/foto.jpg` (jerarquía plana) y
 * unas pocas muy antiguas `imagenespostes/...`. `resolveStoredImagePath()`
 * resuelve las tres formas: quita el prefijo histórico y busca bajo la raíz de
 * imágenes; si no lo encuentra ahí, prueba la ruta tal cual bajo la raíz del
 * proyecto, que es donde vive en una instalación XAMPP. Así el mismo código
 * genera PDF de inspecciones viejas y nuevas sin migrar la base.
 */

require_once __DIR__ . '/app.php';

/**
 * Prefijos que usaron las versiones anteriores dentro de `ruta_archivo`.
 * Se recortan al resolver para poder buscar bajo la raíz de imágenes actual.
 */
const LEGACY_IMAGE_PREFIXES = ['storage/images/', 'imagenespostes/'];

/**
 * Raíces de almacenamiento, resueltas una sola vez por petición.
 *
 * Cada una se puede fijar por entorno. Si no se fija, se usa la ruta histórica
 * dentro del proyecto, de modo que una instalación XAMPP existente no note
 * ningún cambio.
 *
 * @return array<string, string>
 */
function storageRoots(): array
{
    static $roots = null;
    if ($roots !== null) {
        return $roots;
    }

    $roots = [
        'images'         => storageRootFromEnv('IMAGE_STORAGE_PATH', 'storage/images'),
        'pdf'            => storageRootFromEnv('PDF_STORAGE_PATH', 'storage/pdf'),
        'backups'        => storageRootFromEnv('BACKUP_STORAGE_PATH', 'storage/backups'),
        // La copia visible que Qfile/Qsync trae de los celulares se mantiene
        // separada de los dumps de MariaDB. Si no existe, se informa como no
        // disponible y el flujo oficial sigue funcionando sin cambios.
        //
        // En el NAS el respaldo no vive bajo la carpeta de la aplicación: es su
        // propio share, montado en el contenedor como `/data/elpu`. Se lee de
        // `MOBILE_BACKUP_ROOT`; `MOBILE_BACKUP_STORAGE_PATH` se sigue aceptando
        // para no romper una instalación ya configurada.
        'mobile_backups' => storageRootFromEnv(
            ['MOBILE_BACKUP_ROOT', 'MOBILE_BACKUP_STORAGE_PATH'],
            'storage/backups/moviles'
        ),
        'admin_archive'  => storageRootFromEnv(
            'ADMIN_ARCHIVE_STORAGE_PATH',
            'storage/backups/archivo_admin'
        ),
        'logs'           => storageRootFromEnv('LOG_STORAGE_PATH', 'storage/logs'),
        'exports'        => storageRootFromEnv('EXPORT_STORAGE_PATH', 'storage/exports'),
        // Configuración de impresión del reporte: qué fotografías se imprimen
        // por estructura y fuente. Vive aparte de las tablas de inspección y
        // aparte de las fotografías; borrarla sólo devuelve el comportamiento
        // por defecto (se imprime todo), nunca pierde una fotografía.
        'report_config'  => storageRootFromEnv(
            'REPORT_CONFIG_STORAGE_PATH',
            'storage/report_config'
        ),
    ];

    return $roots;
}

/**
 * Lee una raíz del entorno. Una ruta relativa se interpreta contra la raíz del
 * proyecto; una absoluta (`/data/imagenes`, `D:\fotos`) se respeta tal cual.
 *
 * Se admite una lista de variables para poder renombrar una sin dejar de leer
 * la anterior: gana la primera que traiga un valor.
 *
 * @param string|list<string> $variable
 */
function storageRootFromEnv(string|array $variable, string $relativeFallback): string
{
    $value = '';
    foreach (is_array($variable) ? $variable : [$variable] as $name) {
        $value = trim((string) envValue($name, ''));
        if ($value !== '') {
            break;
        }
    }
    if ($value === '') {
        return normalizeAbsoluteStoragePath(projectPath($relativeFallback));
    }

    $normalized = rtrim(str_replace('\\', '/', $value), '/');
    if ($normalized === '') {
        return normalizeAbsoluteStoragePath(projectPath($relativeFallback));
    }

    $root = isAbsoluteStoragePath($normalized) ? $normalized : projectPath($normalized);

    return normalizeAbsoluteStoragePath($root);
}

/**
 * Deja una ruta absoluta en forma canónica, sin tocar el disco.
 *
 * Hace falta porque `PROJECT_ROOT` es `api/config/../..`: sin esto, la raíz de
 * imágenes de una instalación XAMPP se registra como
 * `C:\...\api\config/../..\storage/images`. Funciona, pero es ilegible en los
 * logs y hace inútil cualquier comparación de texto entre rutas.
 *
 * No se usa `realpath()` porque el directorio puede no existir todavía: es
 * justo lo que se está a punto de crear.
 */
function normalizeAbsoluteStoragePath(string $path): string
{
    $unified = str_replace('\\', '/', $path);

    // Se aparta el prefijo que no participa en la resolución de `..`:
    // la unidad en Windows (`C:`) o el servidor en una ruta UNC (`//equipo`).
    $prefix = '';
    if (preg_match('#^([A-Za-z]:|//[^/]+)#', $unified, $matches) === 1) {
        $prefix = $matches[1];
        $unified = substr($unified, strlen($prefix));
    }
    $isAbsolute = str_starts_with($unified, '/');

    $segments = [];
    foreach (explode('/', $unified) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $segment;
    }

    $joined = implode('/', $segments);
    $result = $prefix . ($isAbsolute || $prefix !== '' ? '/' : '') . $joined;

    return str_replace('/', DIRECTORY_SEPARATOR, rtrim($result, '/')) ?: DIRECTORY_SEPARATOR;
}

/**
 * `/data/imagenes` en Linux, `C:/fotos` o `//servidor/share` en Windows.
 */
function isAbsoluteStoragePath(string $path): bool
{
    return str_starts_with($path, '/')
        || str_starts_with($path, '\\\\')
        || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
}

/**
 * Ruta absoluta de una de las raíces.
 *
 * @param string $key    images|pdf|backups|mobile_backups|admin_archive|logs|exports|report_config
 * @param bool   $ensure Crear el directorio si no existe.
 */
function storagePath(string $key, bool $ensure = false): string
{
    $roots = storageRoots();
    if (!isset($roots[$key])) {
        throw new InvalidArgumentException('Raíz de almacenamiento desconocida.');
    }

    $root = $roots[$key];
    if ($ensure) {
        // 0775 y no 0750: en el NAS la carpeta la comparten el usuario del
        // share y el www-data del contenedor a través del grupo.
        ensureDirectory($root, 0775);
    }

    return $root;
}

/**
 * Deja un segmento de ruta en algo seguro para el filesystem.
 *
 * Conserva letras, dígitos, punto, guion y guion bajo —suficiente para códigos
 * de línea reales como `L-6027-1`— y reemplaza cualquier otra cosa por `_`.
 * Corta puntos al inicio/final para que no puedan formarse `.` ni `..`.
 */
function sanitizeStorageSegment(mixed $value, string $fallback = 'sin_dato'): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return $fallback;
    }

    $text = preg_replace('/[^A-Za-z0-9._-]+/', '_', $text) ?? '';
    $text = preg_replace('/_{2,}/', '_', $text) ?? '';
    $text = trim($text, '._-');
    if ($text === '' || $text === '.' || $text === '..') {
        return $fallback;
    }

    return mb_substr($text, 0, 60);
}

/**
 * Normaliza una ruta relativa venida de la base de datos y rechaza todo lo que
 * pueda escapar de la raíz de almacenamiento.
 *
 * Devuelve `null` si la ruta está vacía, es absoluta, trae unidad de Windows o
 * contiene un segmento `.`/`..`. No toca el disco: es validación sintáctica.
 */
function normalizeStorageRelativePath(?string $path): ?string
{
    if ($path === null) {
        return null;
    }

    $normalized = trim(str_replace('\\', '/', $path));
    if ($normalized === '') {
        return null;
    }
    if (isAbsoluteStoragePath($normalized) || str_contains($normalized, "\0")) {
        return null;
    }

    $segments = [];
    foreach (explode('/', $normalized) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            return null;
        }
        $segments[] = $segment;
    }

    return $segments === [] ? null : implode('/', $segments);
}

/**
 * Quita el prefijo de una versión anterior, si lo trae.
 *
 * `storage/images/452/foto.jpg` -> `452/foto.jpg`
 * `proyecto_1/linea_X/...`      -> se devuelve igual
 */
function stripLegacyImagePrefix(string $relative): string
{
    foreach (LEGACY_IMAGE_PREFIXES as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return substr($relative, strlen($prefix));
        }
    }

    return $relative;
}

/**
 * Construye la ruta relativa de una fotografía nueva.
 *
 * Estructura resultante:
 *
 *     proyecto_{id}/linea_{linea}/poste_{poste_id}/{tipo}_{24 hex}.jpg
 *
 * El nombre lleva 12 bytes aleatorios: dos subidas del mismo tipo nunca chocan
 * y el nombre no revela nada del contenido. `$type` ya viene validado contra
 * `photoTypes()` por el endpoint, y aun así se sanea.
 */
function buildImageRelativePath(int $projectId, mixed $line, int $postId, string $type): string
{
    $project = $projectId > 0 ? 'proyecto_' . $projectId : 'proyecto_sin_asignar';
    $lineSegment = 'linea_' . sanitizeStorageSegment($line, 'sin_linea');
    $postSegment = 'poste_' . ($postId > 0 ? $postId : 'sin_id');
    $filename = sanitizeStorageSegment($type, 'foto') . '_' . bin2hex(random_bytes(12)) . '.jpg';

    return $project . '/' . $lineSegment . '/' . $postSegment . '/' . $filename;
}

/**
 * Ruta absoluta donde escribir una ruta relativa recién construida.
 *
 * Crea el directorio contenedor. Lanza si la ruta relativa no es válida.
 */
function imageAbsoluteTarget(string $relative): string
{
    $normalized = normalizeStorageRelativePath($relative);
    if ($normalized === null) {
        throw new RuntimeException('La ruta de destino de la imagen no es válida.');
    }

    $absolute = storagePath('images', true) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    ensureDirectory(dirname($absolute), 0775);

    return $absolute;
}

/**
 * Comprueba que una ruta ya resuelta esté realmente dentro de `$root`.
 *
 * Se compara sobre `realpath()` de los dos lados: así un enlace simbólico
 * colocado dentro del almacén no puede apuntar fuera.
 */
function pathIsInside(string $absolute, string $root): bool
{
    $realAbsolute = realpath($absolute);
    $realRoot = realpath($root);
    if ($realAbsolute === false || $realRoot === false) {
        return false;
    }

    $realRoot = rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

    return str_starts_with($realAbsolute, $realRoot);
}

/**
 * Resuelve `ruta_archivo` de la base a una ruta absoluta legible, o `null`.
 *
 * Orden de búsqueda:
 *   1. bajo la raíz de imágenes, quitando el prefijo histórico si lo hubiera
 *      (cubre las rutas nuevas y las antiguas ya copiadas al NAS);
 *   2. bajo la raíz de imágenes con la ruta tal cual (cubre el caso en que las
 *      fotos viejas se copiaron conservando el prefijo);
 *   3. bajo la raíz del proyecto (instalación XAMPP sin migrar).
 *
 * Devuelve `null` —y no una excepción— porque la generación del PDF debe
 * continuar cuando falta una fotografía: el hueco se dibuja como "sin imagen".
 */
function resolveStoredImagePath(?string $relativePath): ?string
{
    $normalized = normalizeStorageRelativePath($relativePath);
    if ($normalized === null) {
        return null;
    }

    $imagesRoot = storagePath('images');
    $candidates = [];

    $withoutPrefix = stripLegacyImagePrefix($normalized);
    $candidates[] = [$imagesRoot, $withoutPrefix];
    if ($withoutPrefix !== $normalized) {
        $candidates[] = [$imagesRoot, $normalized];
    }
    $candidates[] = [projectPath(), $normalized];

    foreach ($candidates as [$root, $relative]) {
        $absolute = rtrim($root, '/\\') . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($absolute) && pathIsInside($absolute, $root)) {
            return realpath($absolute) ?: null;
        }
    }

    return null;
}

/**
 * Borra el archivo de una fotografía sustituida.
 *
 * Solo actúa si la ruta resuelve dentro de un almacén conocido; una ruta
 * inventada no puede hacer que se borre nada de fuera.
 */
function deleteStoredFile(?string $relativePath): void
{
    $absolute = resolveStoredImagePath($relativePath);
    if ($absolute === null) {
        return;
    }

    if (!pathIsInside($absolute, storagePath('images')) && !pathIsInside($absolute, projectPath())) {
        return;
    }

    @unlink($absolute);
}

/**
 * Directorio de caché de PDF de un poste.
 *
 * Se agrupa por poste y no todo en un nivel: una carpeta con decenas de miles
 * de archivos es donde el rendimiento del volumen del NAS se degrada primero.
 */
function pdfCacheDirectory(int $postId, bool $ensure = false): string
{
    $directory = storagePath('pdf', $ensure) . DIRECTORY_SEPARATOR . 'poste_' . max(0, $postId);
    if ($ensure) {
        ensureDirectory($directory, 0775);
    }

    return $directory;
}

/**
 * Ruta del PDF en caché para una huella de contenido concreta.
 *
 * El nombre lleva la huella dentro: un PDF con otra huella es otro archivo, así
 * que no existe el caso "archivo con el nombre correcto y contenido viejo".
 */
function pdfCacheFile(int $postId, string $fingerprint, bool $ensure = false): string
{
    $safe = preg_replace('/[^a-f0-9]/i', '', $fingerprint) ?: 'sinhuella';

    return pdfCacheDirectory($postId, $ensure) . DIRECTORY_SEPARATOR
        . 'poste_' . max(0, $postId) . '_' . substr($safe, 0, 32) . '.pdf';
}

/**
 * Borra los PDF cacheados de un poste.
 *
 * Se llama cuando se sabe que el reporte quedó obsoleto (llegó una fotografía
 * nueva, se guardó el formulario). No es imprescindible para la corrección —la
 * huella de contenido ya impide servir un PDF viejo— pero evita que la carpeta
 * acumule versiones que nadie va a volver a pedir.
 */
/**
 * Deja en la caché de un poste sólo los `$keep` PDF más recientes.
 *
 * Hace falta porque un mismo poste tiene ahora más de un reporte válido a la
 * vez: uno por fuente de fotografías (NAS / Backup). Borrar la carpeta entera
 * en cada escritura haría que alternar entre las dos fuentes no acertara nunca
 * la caché. Cada archivo lleva su huella de contenido en el nombre, así que
 * conservar varios no puede servir un PDF que no corresponda.
 *
 * `invalidatePostPdfCache()` sigue existiendo y sigue vaciándolo todo: es lo
 * correcto cuando cambia el contenido (llega una fotografía, se guarda el
 * formulario), porque entonces TODAS las variantes quedan obsoletas.
 */
function prunePostPdfCache(int $postId, int $keep = 4): void
{
    if (envValue('PDF_CACHE_ENABLED', true) !== true) {
        return;
    }

    try {
        $directory = pdfCacheDirectory($postId);
    } catch (Throwable) {
        return;
    }
    $files = is_dir($directory)
        ? (glob($directory . DIRECTORY_SEPARATOR . '*.pdf') ?: [])
        : [];
    if (count($files) <= max(0, $keep)) {
        return;
    }

    // Del más reciente al más antiguo; se retiran los que sobran por el final.
    usort($files, static fn(string $a, string $b): int
        => (int) (@filemtime($b) ?: 0) <=> (int) (@filemtime($a) ?: 0));
    foreach (array_slice($files, max(0, $keep)) as $file) {
        @unlink($file);
    }
}

function invalidatePostPdfCache(int $postId): void
{
    if (envValue('PDF_CACHE_ENABLED', true) !== true) {
        return;
    }

    try {
        $directory = pdfCacheDirectory($postId);
    } catch (Throwable) {
        return;
    }
    if (!is_dir($directory)) {
        return;
    }

    foreach (glob($directory . DIRECTORY_SEPARATOR . '*.pdf') ?: [] as $file) {
        @unlink($file);
    }
}

/**
 * Escribe un archivo de forma atómica: primero un temporal en el mismo
 * directorio y luego `rename()`.
 *
 * Importa en el NAS: si el contenedor muere a mitad de un `file_put_contents()`
 * de 8 MB, sin esto quedaría un PDF truncado que la caché daría por válido.
 */
function writeFileAtomic(string $absolute, string $contents): bool
{
    $directory = dirname($absolute);
    ensureDirectory($directory, 0775);

    $temporary = $directory . DIRECTORY_SEPARATOR . '.tmp_' . bin2hex(random_bytes(8));
    if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
        @unlink($temporary);
        return false;
    }
    @chmod($temporary, 0664);

    if (!@rename($temporary, $absolute)) {
        @unlink($temporary);
        return false;
    }

    return true;
}
