<?php
/**
 * Corrige la orientación de una imagen JPEG según los metadatos EXIF.
 * Gira automáticamente si fue tomada en modo vertical/horizontal invertido.
 *
 * @param string $ruta Ruta absoluta del archivo de imagen.
 * @return void
 */
function corregirOrientacionImagen($ruta) {
    if (!function_exists('exif_read_data')) return;

    $info = @exif_read_data($ruta);
    if (!$info || !isset($info['Orientation'])) return;

    $orientacion = $info['Orientation'];
    $imagen = @imagecreatefromjpeg($ruta);
    if (!$imagen) return;

    switch ($orientacion) {
        case 3:
            $imagen = imagerotate($imagen, 180, 0);
            break;
        case 6:
            $imagen = imagerotate($imagen, -90, 0);
            break;
        case 8:
            $imagen = imagerotate($imagen, 90, 0);
            break;
        default:
            return;
    }

    imagejpeg($imagen, $ruta, 90);
    imagedestroy($imagen);
}
