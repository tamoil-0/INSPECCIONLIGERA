<?php

/**
 * Traza del reporte.
 *
 * Vive aquí, y no en `PdfReportService.php`, porque las clases PDF la usan y
 * no pueden requerir ese servicio: es él quien las requiere a ellas. El guard
 * `function_exists` mantiene la definición que ya hacía el servicio.
 */
if (!function_exists('log_pdf')) {
    function log_pdf(string $message): void
    {
        error_log('PDF: ' . $message);
    }
}

/**
 * Decodifica una fotografía con el lector que corresponde a su contenido real.
 *
 * El tipo se toma de `getimagesize()` y NO de la extensión: en el NAS conviven
 * archivos `.jpg` que en realidad son PNG o WEBP (los deja así el teléfono o la
 * copia de Qfile), y `imagecreatefromjpeg()` devuelve `false` con ellos.
 *
 * Devuelve `null` —y nunca lanza— cuando el archivo no se puede decodificar:
 * una fotografía ilegible tiene que dibujarse como "sin foto", no tumbar la
 * generación completa del reporte.
 */
function decodificarImagenReporte($rutaOriginal, $tipo) {
    $lector = match ($tipo) {
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_GIF => 'imagecreatefromgif',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
        IMAGETYPE_BMP => 'imagecreatefrombmp',
        default => null,
    };
    if ($lector === null || !function_exists($lector)) {
        return null;
    }

    $imagen = @$lector($rutaOriginal);

    return $imagen instanceof GdImage ? $imagen : null;
}

/**
 * Deja una fotografía lista para incrustarla en el PDF.
 *
 * Devuelve:
 *   · la ruta original    cuando el archivo ya es del tipo que FPDF deduce de
 *                         su extensión y no hace falta reducirlo (caso normal,
 *                         sin coste añadido y con el mismo resultado de antes);
 *   · una ruta temporal   con un JPEG reducido a `$anchoMax` de ancho;
 *   · null                cuando el archivo no se puede leer como imagen.
 *
 * El `null` es lo que evita el HTTP 500: antes, un archivo que `getimagesize()`
 * aceptaba pero `imagecreatefromjpeg()` no podía abrir —un PNG o un WEBP con
 * nombre `.jpg`, un JPEG cortado a medias por una sincronización interrumpida—
 * llegaba como `false` a `imagecopyresampled()` y reventaba con un TypeError, o
 * llegaba tal cual a `FPDF::Image()` y reventaba con "Not a JPEG file". En
 * ambos casos se perdía el reporte entero por una sola fotografía.
 */
function redimensionarImagenTemporal($rutaOriginal, $anchoMax = 800, $calidad = 80) {
    $info = @getimagesize($rutaOriginal);
    if (!$info) {
        return null;
    }

    [$ancho, $alto, $tipo] = $info;
    if ($ancho <= 0 || $alto <= 0) {
        return null;
    }

    // FPDF elige el intérprete por la extensión del archivo, así que sólo se
    // puede entregar el original cuando extensión y contenido coinciden.
    $extension = strtolower((string) pathinfo((string) $rutaOriginal, PATHINFO_EXTENSION));
    $extensionCoincide = match ($tipo) {
        IMAGETYPE_JPEG => in_array($extension, ['jpg', 'jpeg'], true),
        IMAGETYPE_PNG => $extension === 'png',
        IMAGETYPE_GIF => $extension === 'gif',
        default => false,
    };
    if ($extensionCoincide && $ancho <= $anchoMax) {
        return $rutaOriginal;
    }

    $imgOriginal = decodificarImagenReporte($rutaOriginal, $tipo);
    if ($imgOriginal === null) {
        return null;
    }

    $nuevoAncho = (int) min($ancho, $anchoMax);
    $nuevoAlto = max(1, intval($alto * ($nuevoAncho / $ancho)));
    $imgReducida = @imagecreatetruecolor($nuevoAncho, $nuevoAlto);
    if (!$imgReducida instanceof GdImage) {
        imagedestroy($imgOriginal);
        return null;
    }
    // Fondo blanco: un PNG con transparencia saldría negro al pasar a JPEG.
    imagefilledrectangle(
        $imgReducida,
        0,
        0,
        $nuevoAncho,
        $nuevoAlto,
        (int) imagecolorallocate($imgReducida, 255, 255, 255)
    );
    imagecopyresampled($imgReducida, $imgOriginal, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

    // tempnam() crea el archivo sin extensión; hay que retirarlo o el NAS
    // acumula un huérfano de 0 bytes por cada fotografía de cada reporte.
    $reserva = @tempnam(sys_get_temp_dir(), 'img');
    if ($reserva === false) {
        imagedestroy($imgOriginal);
        imagedestroy($imgReducida);
        return null;
    }
    $rutaTemporal = $reserva . '.jpg';
    @unlink($reserva);

    $guardada = @imagejpeg($imgReducida, $rutaTemporal, (int) $calidad);

    imagedestroy($imgOriginal);
    imagedestroy($imgReducida);

    if (!$guardada || !is_file($rutaTemporal)) {
        @unlink($rutaTemporal);
        return null;
    }

    return $rutaTemporal;
}
