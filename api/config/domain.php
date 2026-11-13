<?php
declare(strict_types=1);

require_once __DIR__ . '/storage.php';

function photoTypes(): array
{
    return [
        'foto_panoramica', 'placa', 'torre_parte_inferior', 'torre_parte_superior', 'base_torre',
        'mensulas', 'crucetas', 'perfiles_angulares', 'atiescalamiento', 'otros',
        'aisladores_fase_r_atras', 'aisladores_fase_s_atras', 'aisladores_fase_t_atras',
        'aisladores_fase_r_adelante', 'aisladores_fase_s_adelante', 'aisladores_fase_t_adelante',
        'ferreteria_fase_r', 'ferreteria_fase_s', 'ferreteria_fase_t', 'cable_guarda',
        'ferreteria_de_cable_de_guarda', 'conductor', 'ferreteria_de_conductor',
        'puesta_tierra', 'puesta_tierra_2', 'retenida', 'faja_servidumbre', 'ubicacion_acceso',
    ];
}

function inspectionRules(): array
{
    return [
        'obstaculos_faja' => ['invasiones_nuevas', 'construcciones_nuevas', 'proceso_construccion', 'cercos_vallas', 'arboles', 'arbustos', 'arboles_fuera_faja', 'otros', 'n_a'],
        'estado_cuencas' => ['seguimiento', 'critico', 'n_a'],
        'marcado_arboles' => ['si', 'no'],
        'criticidad_tala' => ['bajo', 'seguimiento', 'critico', 'n_a'],
        'criticidad_contacto' => ['bajo', 'seguimiento', 'critico', 'n_a'],
        'notificacion_propietario' => ['persona_natural', 'persona_juridica', 'otro'],
        'tipo_torre' => ['alineamiento', 'angulo', 'fin_linea'],
        'ubicacion' => ['rural_con_vegetacion', 'urbana', 'industrial', 'rural_sin_vegetacion', 'zona_sujeta_huaycos', 'desertico'],
        'acceso_torre' => ['a_pie', 'en_vehiculo'],
        'estado_acceso' => ['bueno', 'mal_estado'],
        'estado_placas_torre' => ['bueno', 'malo', 'no_existe'],
        'estado_placas_linea' => ['bueno', 'malo', 'no_existe'],
        'estado_placas_fases' => ['bueno', 'malo', 'no_existe'],
        'peligro_cerco' => ['bueno', 'malo', 'no_existe'],
        'peligro_torre' => ['bueno', 'malo', 'no_existe'],
        'puesta_tierra' => ['bueno', 'malo', 'no_existe'],
        'retenida' => ['buen_estado', 'cambiar_preforme', 'retemplar', 'n_a'],
        'estado_base' => ['buen_estado', 'mal_estado'],
        'limpiar_base' => ['si', 'no'],
        'crucetas_mensuales' => ['buen_estado', 'mal_estado', 'falta_ajustar', 'n_a'],
        'perfiles_angulares' => ['buen_estado', 'mal_estado', 'falta', 'n_a'],
        'malla_antiescalamiento' => ['buen_estado', 'mal_estado', 'falta', 'n_a'],
        'oxidos_base' => ['si', 'no', 'n_a'],
        'cadena_aisladores' => ['en_suspension', 'en_anclaje', 'en_cuello_muerto'],
        'tipo_aislador' => ['vidrio', 'porcelana', 'polimero'],
        'conductor_bajada_pat' => ['buen_estado', 'conductor_en_mal_estado', 'grapas_en_mal_estado', 'listones_en_mal_estado', 'n_a'],
        'conductor_guarda' => ['hebras_rotas', 'encanastillado', 'empalme_deformado', 'objetos_extranos', 'n_a'],
    ];
}

function normalizeDateTime(mixed $value): ?string
{
    if ($value === null || trim((string) $value) === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable((string) $value))->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return null;
    }
}

/**
 * Valida una fotografía subida y la guarda en el almacén persistente.
 *
 * ## Qué cambió respecto a la versión anterior
 *
 * 1. **Dónde escribe.** Antes, siempre `storage/images/{poste_id}/`, dentro del
 *    árbol de la aplicación. Ahora, la raíz configurable de `storage.php`
 *    (`/data/imagenes` en el NAS) y con jerarquía
 *    `proyecto_{id}/linea_{linea}/poste_{id}/`. Lo que se devuelve —y lo que
 *    entra en la base— sigue siendo una ruta RELATIVA.
 *
 * 2. **Doble procesamiento.** Antes, toda imagen se decodificaba, se
 *    redimensionaba a 2400 px y se recodificaba con GD, incluso cuando el
 *    teléfono ya la había dejado en 3072 px y 2 MB con códecs nativos. En el
 *    ARM del NAS ese ciclo es lo más caro de la subida y además degrada la
 *    imagen una segunda vez sin ganar nada. Con `IMAGE_REENCODE_MODE=auto`, una
 *    imagen que ya cumple se guarda tal cual.
 *
 *    Lo que NO cambió: la validación. Se sigue comprobando que el archivo sea
 *    realmente JPEG o PNG por su contenido (`getimagesize`, no la extensión ni
 *    el Content-Type), que no exceda 10 MB y que su resolución sea razonable.
 *    Una imagen solo evita el reprocesado si además su orientación EXIF es la
 *    normal, porque si no el PDF la dibujaría girada.
 *
 * @param array{error?:int,size?:int,tmp_name?:string} $file  Entrada de $_FILES.
 * @param array{proyecto_id?:int|string,linea?:string}  $context Proyecto y línea
 *        del poste, para construir la jerarquía de carpetas.
 *
 * @return array{absolute:string,relative:string,bytes:int,ancho:int,alto:int,reprocesada:bool,ms:float}
 */
function saveUploadedImage(array $file, int $postId, string $type, array $context = []): array
{
    $started = hrtime(true);

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('La carga del archivo falló.');
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('Cada imagen debe pesar como máximo 10 MB.');
    }

    $temporary = (string) ($file['tmp_name'] ?? '');
    $info = @getimagesize($temporary);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
        throw new RuntimeException('El archivo debe ser una imagen JPEG o PNG válida.');
    }
    if ($info[0] * $info[1] > 40_000_000) {
        throw new RuntimeException('La resolución de la imagen es demasiado grande.');
    }

    $maxDimension = max(600, (int) envValue('IMAGE_MAX_DIMENSION', 2400));
    $orientation = imageExifOrientation($temporary, (string) $info['mime']);

    $relative = buildImageRelativePath(
        (int) ($context['proyecto_id'] ?? 0),
        $context['linea'] ?? null,
        $postId,
        $type
    );
    $absolute = imageAbsoluteTarget($relative);

    $reprocesada = true;
    if (imageCanBeStoredAsIs($info, (int) $file['size'], $orientation, $maxDimension)) {
        storeUploadedFileAsIs($temporary, $absolute);
        $reprocesada = false;
    } else {
        reencodeImageToJpeg($temporary, $absolute, $info, $orientation, $maxDimension);
    }

    @chmod($absolute, 0664);
    $finalSize = (int) (@filesize($absolute) ?: 0);
    if ($finalSize <= 0) {
        @unlink($absolute);
        throw new RuntimeException('No se pudo guardar la imagen procesada.');
    }

    $finalInfo = $reprocesada ? (@getimagesize($absolute) ?: $info) : $info;

    return [
        'absolute' => $absolute,
        'relative' => $relative,
        'bytes' => $finalSize,
        'ancho' => (int) ($finalInfo[0] ?? 0),
        'alto' => (int) ($finalInfo[1] ?? 0),
        'reprocesada' => $reprocesada,
        'ms' => elapsedMs($started),
    ];
}

/**
 * Orientación declarada en el EXIF. 1 = normal, 0 = sin EXIF o ilegible.
 */
function imageExifOrientation(string $absolute, string $mime): int
{
    if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
        return 0;
    }

    $exif = @exif_read_data($absolute);

    return (int) ($exif['Orientation'] ?? 0);
}

/**
 * ¿Se puede archivar la imagen tal como llegó?
 *
 * Se exigen las cuatro condiciones a la vez:
 *   · el modo lo permite (`IMAGE_REENCODE_MODE=auto`);
 *   · es JPEG (un PNG se convierte siempre: el PDF y el peso lo agradecen);
 *   · su lado mayor ya cabe en el límite;
 *   · pesa menos de `IMAGE_REENCODE_MAX_BYTES`;
 *   · su orientación EXIF es la normal (0 o 1), porque no reprocesar significa
 *     no poder aplicar la rotación a los píxeles.
 */
function imageCanBeStoredAsIs(array $info, int $bytes, int $orientation, int $maxDimension): bool
{
    $mode = strtolower(trim((string) envValue('IMAGE_REENCODE_MODE', 'auto')));
    if ($mode !== 'auto') {
        return false;
    }
    if (($info['mime'] ?? '') !== 'image/jpeg') {
        return false;
    }
    if (max((int) $info[0], (int) $info[1]) > $maxDimension) {
        return false;
    }
    if ($bytes > max(256 * 1024, (int) envValue('IMAGE_REENCODE_MAX_BYTES', 4 * 1024 * 1024))) {
        return false;
    }

    return $orientation === 0 || $orientation === 1;
}

/**
 * Traslada el archivo subido al almacén sin tocar sus píxeles.
 *
 * `move_uploaded_file()` es lo correcto para una carga real; el `copy()` de
 * respaldo permite reutilizar esta función desde un script de migración, donde
 * el archivo no viene de una petición HTTP.
 */
function storeUploadedFileAsIs(string $temporary, string $absolute): void
{
    $moved = is_uploaded_file($temporary)
        ? @move_uploaded_file($temporary, $absolute)
        : @copy($temporary, $absolute);

    if (!$moved) {
        appLog('almacenamiento', 'No se pudo trasladar la imagen al almacén.', ['destino' => $absolute]);
        throw new RuntimeException('No se pudo guardar la imagen recibida.');
    }
}

/**
 * Camino clásico: decodificar, corregir orientación, redimensionar y recodificar
 * a JPEG. Es el que se usa para PNG, para imágenes grandes y para cualquiera
 * con orientación EXIF girada.
 */
function reencodeImageToJpeg(
    string $temporary,
    string $absolute,
    array $info,
    int $orientation,
    int $maxDimension
): void {
    $contents = @file_get_contents($temporary);
    $source = $contents === false ? false : @imagecreatefromstring($contents);
    unset($contents);
    if (!$source) {
        throw new RuntimeException('No se pudo procesar la imagen.');
    }

    $rotated = match ($orientation) {
        3 => imagerotate($source, 180, 0),
        6 => imagerotate($source, -90, 0),
        8 => imagerotate($source, 90, 0),
        default => false,
    };
    if ($rotated !== false) {
        imagedestroy($source);
        $source = $rotated;
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min(1, $maxDimension / max(1, max($width, $height)));
    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));

    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefill($target, 0, 0, $white);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

    $quality = min(100, max(60, (int) envValue('IMAGE_JPEG_QUALITY', 85)));
    $written = imagejpeg($target, $absolute, $quality);
    imagedestroy($source);
    imagedestroy($target);

    if (!$written) {
        appLog('almacenamiento', 'imagejpeg() falló al escribir la imagen.', ['destino' => $absolute]);
        throw new RuntimeException('No se pudo guardar la imagen procesada.');
    }
}
