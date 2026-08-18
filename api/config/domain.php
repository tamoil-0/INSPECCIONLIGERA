<?php
declare(strict_types=1);

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

function saveUploadedImage(array $file, int $postId, string $type): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('La carga del archivo falló.');
    }
    if (($file['size'] ?? 0) <= 0 || $file['size'] > 10 * 1024 * 1024) {
        throw new RuntimeException('Cada imagen debe pesar como máximo 10 MB.');
    }

    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) {
        throw new RuntimeException('El archivo debe ser una imagen JPEG o PNG válida.');
    }
    if ($info[0] * $info[1] > 40_000_000) {
        throw new RuntimeException('La resolución de la imagen es demasiado grande.');
    }

    $contents = file_get_contents($file['tmp_name']);
    $source = $contents === false ? false : @imagecreatefromstring($contents);
    if (!$source) {
        throw new RuntimeException('No se pudo procesar la imagen.');
    }

    if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $orientation = (int) ($exif['Orientation'] ?? 1);
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
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $scale = min(1, 2400 / max($width, $height));
    $targetWidth = max(1, (int) round($width * $scale));
    $targetHeight = max(1, (int) round($height * $scale));
    $target = imagecreatetruecolor($targetWidth, $targetHeight);
    $white = imagecolorallocate($target, 255, 255, 255);
    imagefill($target, 0, 0, $white);
    imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

    $directory = projectPath('storage/images/' . $postId);
    ensureDirectory($directory);
    $filename = $type . '_' . bin2hex(random_bytes(12)) . '.jpg';
    $absolute = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!imagejpeg($target, $absolute, 85)) {
        imagedestroy($source);
        imagedestroy($target);
        throw new RuntimeException('No se pudo guardar la imagen procesada.');
    }
    imagedestroy($source);
    imagedestroy($target);

    return [
        'absolute' => $absolute,
        'relative' => 'storage/images/' . $postId . '/' . $filename,
    ];
}

function deleteStoredFile(?string $relativePath): void
{
    if (!$relativePath || !str_starts_with(str_replace('\\', '/', $relativePath), 'storage/images/')) {
        return;
    }
    $absolute = realpath(projectPath($relativePath));
    $root = realpath(projectPath('storage/images'));
    if ($absolute && $root && str_starts_with($absolute, $root . DIRECTORY_SEPARATOR) && is_file($absolute)) {
        unlink($absolute);
    }
}

function resolveStoredImagePath(?string $relativePath): ?string
{
    if (!$relativePath) {
        return null;
    }
    $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
    if (!str_starts_with($normalized, 'storage/images/') && !str_starts_with($normalized, 'imagenespostes/')) {
        return null;
    }
    $absolute = realpath(projectPath($normalized));
    $root = realpath(projectPath());
    return $absolute && $root && str_starts_with($absolute, $root . DIRECTORY_SEPARATOR) && is_file($absolute)
        ? $absolute
        : null;
}
