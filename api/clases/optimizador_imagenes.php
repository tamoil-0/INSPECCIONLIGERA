<?php
function redimensionarImagenTemporal($rutaOriginal, $anchoMax = 800) {
    $info = getimagesize($rutaOriginal);
    if (!$info) return $rutaOriginal;

    [$ancho, $alto] = $info;
    if ($ancho <= $anchoMax) return $rutaOriginal;

    $nuevoAlto = intval($alto * ($anchoMax / $ancho));
    $imgOriginal = imagecreatefromjpeg($rutaOriginal);
    $imgReducida = imagecreatetruecolor($anchoMax, $nuevoAlto);
    imagecopyresampled($imgReducida, $imgOriginal, 0, 0, 0, 0, $anchoMax, $nuevoAlto, $ancho, $alto);

    $rutaTemporal = tempnam(sys_get_temp_dir(), 'img') . '.jpg';
    imagejpeg($imgReducida, $rutaTemporal, 80); // Calidad 80%

    imagedestroy($imgOriginal);
    imagedestroy($imgReducida);

    return $rutaTemporal;
}
