<?php

function agregarFormularioPoste($pdf, $datos, $rst,$rutaImagenPanoramica = null) {
  

    // 👉 
    $anchoFaja = 64; 
    $anchoCuencas = 27; 
    $anchoArboles = 17; 
    $espaciado = 3; 
    $alturaFila = 5;

    $pdf->SetFont('Arial', '', 7);

    // 👉 Datos
    $fajaSeleccionadas = explode(',', $datos['obstaculos_faja'] ?? '');
    $estadoCuencas = $datos['estado_cuencas'] ?? '';
    $marcadoArboles = $datos['marcado_arboles'] ?? '';

    $opcionesFaja = [
        'invasiones_nuevas', 'construcciones_nuevas', 'proceso_construccion', 'cercos_vallas',
        'arboles', 'arbustos', 'arboles_fuera_faja', 'otros'
    ];

   $xInicial = 8; // Margen izquierdo fijo de 2 cm

    $yInicial = $pdf->GetY();

    // 👉 Tabla: Obstáculos Faja de Servidumbre
    $pdf->SetXY($xInicial, $yInicial);
    $pdf->SetFillColor(48, 84, 150);
    $pdf->SetTextColor(255);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($anchoFaja, $alturaFila + 2, utf8_decode('Obstáculos Faja de Servidumbre'), 1, 1, 'C', true);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0);

    $colIzq = array_slice($opcionesFaja, 0, 4);
    $colDer = array_slice($opcionesFaja, 4);

    for ($i = 0; $i < 4; $i++) {
        $pdf->SetX($xInicial);

        $checkIzq = in_array($colIzq[$i], $fajaSeleccionadas) ? 'X' : '';
        $labelIzq = ucfirst(str_replace('_', ' ', $colIzq[$i]));

        $checkDer = in_array($colDer[$i], $fajaSeleccionadas) ? 'X' : '';
        $labelDer = ucfirst(str_replace('_', ' ', $colDer[$i]));

        $pdf->Cell($anchoFaja * 0.43, $alturaFila, utf8_decode($labelIzq), 1, 0);
        $pdf->Cell($anchoFaja * 0.07, $alturaFila, utf8_decode($checkIzq), 1, 0);
        $pdf->Cell($anchoFaja * 0.43, $alturaFila, utf8_decode($labelDer), 1, 0);
        $pdf->Cell($anchoFaja * 0.07, $alturaFila, utf8_decode($checkDer), 1, 1);
    }

    $alturaTotalFaja = ($alturaFila + 2) + (4 * $alturaFila);

    // 👉 Tabla: Estado de Cuencas
    $pdf->SetXY($xInicial + $anchoFaja + $espaciado, $yInicial);
    $pdf->SetFillColor(48, 84, 150);
    $pdf->SetTextColor(255);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($anchoCuencas, $alturaFila + 2, utf8_decode('Estado de Cuencas'), 1, 1, 'C', true);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0);

    $posXCuenca = $xInicial + $anchoFaja + $espaciado;

    foreach (['seguimiento', 'critico', 'n_a'] as $opcion) {
        $check = ($estadoCuencas === $opcion) ? 'X' : '';
        $label = ucfirst(str_replace('_', ' ', $opcion));

        $pdf->SetX($posXCuenca);

        $pdf->Cell($anchoCuencas * 0.7, $alturaFila, utf8_decode($label), 1, 0);
        $pdf->Cell($anchoCuencas * 0.3, $alturaFila, utf8_decode($check), 1, 1);
    }

   // 👉 Tabla: Marcado de Árboles con Título en una sola celda (sin línea intermedia)
$pdf->SetXY($xInicial + $anchoFaja + $anchoCuencas + 2 * $espaciado, $yInicial);
$pdf->SetFillColor(48, 84, 150);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);

// 👉 Celda única con texto en dos líneas
$pdf->MultiCell($anchoArboles, $alturaFila, utf8_decode("Marcado\nDe Árboles"), 1, 'C', true);

// 👉 Opciones Marcado de Árboles
$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXArboles = $xInicial + $anchoFaja + $anchoCuencas + 2 * $espaciado;

foreach (['si', 'no'] as $opcion) {
    $check = ($marcadoArboles === $opcion) ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXArboles);

    $pdf->Cell($anchoArboles * 0.7, $alturaFila, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoArboles * 0.3, $alturaFila, utf8_decode($check), 1, 1);
}
$corregirOrientacionImagen = static function($ruta) {
    if (!function_exists('exif_read_data')) return $ruta;

    $info = @exif_read_data($ruta);
    if (!isset($info['Orientation'])) return $ruta;

    $imagen = @imagecreatefromjpeg($ruta);
    if (!$imagen) return $ruta;

    switch ($info['Orientation']) {
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
            return $ruta; // No requiere rotación
    }

    // Guardar imagen corregida en archivo temporal con extensión .jpg
    $tmpPath = tempnam(sys_get_temp_dir(), 'img') . '.jpg';
    imagejpeg($imagen, $tmpPath, 90);
    imagedestroy($imagen);
    return $tmpPath;
};

if (!empty($rutaImagenPanoramica) && file_exists($rutaImagenPanoramica)) {
    $imagenCorregida = $corregirOrientacionImagen($rutaImagenPanoramica);

    $anchoImagen = 75;
    $altoImagen = 120;

    $xImagen = $xInicial + $anchoFaja + $anchoCuencas + $anchoArboles + 3 * $espaciado + 5;
    $yImagen = $yInicial;

    $pdf->Image($imagenCorregida, $xImagen, $yImagen, $anchoImagen, $altoImagen);
    if ($imagenCorregida !== $rutaImagenPanoramica && is_file($imagenCorregida)) {
        unlink($imagenCorregida);
    }

    $yTexto = $yImagen + $altoImagen;
    $pdf->SetXY($xImagen, $yTexto);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetTextColor(0);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->Cell($anchoImagen, 6, utf8_decode('Foto Panorámica'), 1, 1, 'C', true);

    log_pdf("Imagen panorámica cargada y corregida.");
} else {
    log_pdf("Advertencia: No se pasó o no se encontró la imagen panorámica.");
}



    // 👉 Bajar el cursor al final de la tabla más alta
    $pdf->SetY($yInicial + $alturaTotalFaja + 5);
// 👉 Iniciar la segunda fila
$yInicialFila2 = $pdf->GetY(); // Posicion actual después de la primera fila

// 👉 Anchos configurados para la segunda fila
$anchoTala = 25;
$anchoContacto = 25;
$anchoNotificacion = 30;
$anchoTorre = 25;
$espaciadoFila2 = 3;
$alturaFila2 = 5;

// 👉 Tabla: Criticidad de Tala
$pdf->SetXY($xInicial, $yInicialFila2);
$pdf->SetFillColor(48, 84, 150);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($anchoTala, $alturaFila2 + 2, utf8_decode('Criticidad de Tala'), 1, 1, 'C', true);
$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXTala = $xInicial;

foreach (['bajo', 'seguimiento', 'critico', 'n_a'] as $opcion) {
    $check = ($datos['criticidad_tala'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXTala);
    $pdf->Cell($anchoTala * 0.7, $alturaFila2, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoTala * 0.3, $alturaFila2, utf8_decode($check), 1, 1);
}

// 👉 Tabla: Criticidad de Contacto con Título en dos líneas
$pdf->SetXY($xInicial + $anchoTala + $espaciadoFila2, $yInicialFila2);
$pdf->SetFillColor(48, 84, 150);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);

$pdf->MultiCell($anchoContacto, $alturaFila2, utf8_decode("Criticidad de\nContacto"), 1, 'C', true);
// 👉 Opciones Criticidad de Contacto
$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXContacto = $xInicial + $anchoTala + $espaciadoFila2;

foreach (['bajo', 'seguimiento', 'critico', 'n_a'] as $opcion) {
    $check = ($datos['criticidad_contacto'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXContacto);
    $pdf->Cell($anchoContacto * 0.7, $alturaFila2, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoContacto * 0.3, $alturaFila2, utf8_decode($check), 1, 1);
}


// 👉 Tabla: Notificación a Propietario con Título en dos líneas
$pdf->SetXY($xInicial + $anchoTala + $anchoContacto + 2 * $espaciadoFila2, $yInicialFila2);
$pdf->SetFillColor(48, 84, 150);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);

$pdf->MultiCell($anchoNotificacion, $alturaFila2, utf8_decode("Notificación a\nPropietario"), 1, 'C', true);
// 👉 Opciones Notificación a Propietario
$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXNotificacion = $xInicial + $anchoTala + $anchoContacto + 2 * $espaciadoFila2;

foreach (['persona_natural', 'persona_juridica', 'otro'] as $opcion) {
    $check = ($datos['notificacion_propietario'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXNotificacion);
    $pdf->Cell($anchoNotificacion * 0.7, $alturaFila2, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoNotificacion * 0.3, $alturaFila2, utf8_decode($check), 1, 1);
}


// 👉 Tabla: Tipo de Torre
$pdf->SetXY($xInicial + $anchoTala + $anchoContacto + $anchoNotificacion + 3 * $espaciadoFila2, $yInicialFila2);
$pdf->SetFillColor(84, 130, 53);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);

// 🟢 Título en dos líneas
$pdf->MultiCell($anchoTorre, $alturaFila2, utf8_decode("Tipo de\nTorre"), 1, 'C', true);
$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXTorre = $xInicial + $anchoTala + $anchoContacto + $anchoNotificacion + 3 * $espaciadoFila2;

foreach (['alineamiento', 'angulo', 'fin_linea'] as $opcion) {
    $check = ($datos['tipo_torre'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXTorre);
    $pdf->Cell($anchoTorre * 0.7, $alturaFila2, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoTorre * 0.3, $alturaFila2, utf8_decode($check), 1, 1);
}

// 👉 Bajar el cursor al final de la segunda fila
$pdf->SetY($pdf->GetY() + 10);
// 👉 Configuración Fila 3
$anchoUbicacion = 60;
$anchoAcceso = 27;
$anchoEstado = 21;
$espaciadoFila3 = 3;
$alturaFila3 = 5;

// 👉 Coordenadas iniciales de la Fila 3
$yInicialFila3 = $pdf->GetY();
$xInicialFila3 = 8; // margen izquierdo fijo

// ==========================
// 👉 Tabla: Ubicación (opciones en dos filas)
// ==========================
$pdf->SetXY($xInicialFila3, $yInicialFila3);
$pdf->SetFillColor(84, 130, 53);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($anchoUbicacion, $alturaFila3 + 2, utf8_decode('Ubicación'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXUbicacion = $xInicialFila3;

$ubicacionOpciones = [
    'rural_con_vegetacion', 'urbana', 'industrial',
    'rural_sin_vegetacion', 'zona_sujeta_huaycos', 'desertico'
];

// 👉 Imprimimos dos columnas en dos filas
for ($i = 0; $i < 3; $i++) {
    $pdf->SetX($posXUbicacion);

    $checkIzq = ($datos['ubicacion'] ?? '') === $ubicacionOpciones[$i] ? 'X' : '';
    $labelIzq = ucfirst(str_replace('_', ' ', $ubicacionOpciones[$i]));

    $checkDer = ($datos['ubicacion'] ?? '') === $ubicacionOpciones[$i + 3] ? 'X' : '';
    $labelDer = ucfirst(str_replace('_', ' ', $ubicacionOpciones[$i + 3]));

    $pdf->Cell($anchoUbicacion * 0.4, $alturaFila3, utf8_decode($labelIzq), 1, 0);
    $pdf->Cell($anchoUbicacion * 0.1, $alturaFila3, utf8_decode($checkIzq), 1, 0);
    $pdf->Cell($anchoUbicacion * 0.4, $alturaFila3, utf8_decode($labelDer), 1, 0);
    $pdf->Cell($anchoUbicacion * 0.1, $alturaFila3, utf8_decode($checkDer), 1, 1);
}

// ==========================
// 👉 Tabla: Acceso Torre
// ==========================
$pdf->SetXY($xInicialFila3 + $anchoUbicacion + $espaciadoFila3, $yInicialFila3);
$pdf->SetFillColor(84, 130, 53);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($anchoAcceso, $alturaFila3 + 2, utf8_decode('Acceso Torre'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXAcceso = $xInicialFila3 + $anchoUbicacion + $espaciadoFila3;

foreach (['a_pie', 'en_vehiculo'] as $opcion) {
    $check = ($datos['acceso_torre'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXAcceso);
    $pdf->Cell($anchoAcceso * 0.8, $alturaFila3, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoAcceso * 0.2, $alturaFila3, utf8_decode($check), 1, 1);
}

// ==========================
$pdf->SetX($posXAcceso);

// Etiqueta: "Distancia\nAcceso" en una sola celda, con salto de línea
$pdf->SetFillColor(84, 130, 53); // fondo verde
$pdf->SetTextColor(255);         // texto blanco
$pdf->SetFont('Arial', '', 6.5); // un poco más pequeño para que quepa
$pdf->MultiCell($anchoAcceso * 0.7, $alturaFila3 / 2, utf8_decode("Distancia\nAcceso"), 1, 'C', true);

// Ajustamos manualmente la posición para que la celda derecha esté al mismo nivel
$pdf->SetXY($posXAcceso + $anchoAcceso * 0.7, $pdf->GetY() - $alturaFila3);

// Valor: numérico (e.g., 20), fondo blanco
$pdf->SetFillColor(255);
$pdf->SetTextColor(0);
$pdf->SetFont('Arial', 'B', 8);
$valor = $datos['distancia_acceso'] ?? '';
$valorMostrar = is_numeric($valor) ? ((fmod($valor, 1.0) == 0.0) ? intval($valor) : $valor) : '';
$pdf->Cell($anchoAcceso * 0.3, $alturaFila3, utf8_decode((string)$valorMostrar), 1, 1, 'C', true);

// 👉 Tabla: Estado Acceso
// ==========================
$pdf->SetXY($xInicialFila3 + $anchoUbicacion + $anchoAcceso + 2 * $espaciadoFila3, $yInicialFila3);
$pdf->SetFillColor(84, 130, 53);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($anchoEstado, $alturaFila3 + 2, utf8_decode('Estado Acceso'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXEstado = $xInicialFila3 + $anchoUbicacion + $anchoAcceso + 2 * $espaciadoFila3;

foreach (['bueno', 'mal_estado'] as $opcion) {
    $check = ($datos['estado_acceso'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXEstado);
    $pdf->Cell($anchoEstado * 0.7, $alturaFila3, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoEstado * 0.3, $alturaFila3, utf8_decode($check), 1, 1);
}

// 👉 Bajar el cursor al final de la tabla más alta
$pdf->SetY($yInicialFila3 + (3 * $alturaFila3) + 12);
// 👉 Configuración Fila 4
$anchoEstadoPlacas = 80;
$anchoRetenida = 31;
$espaciadoFila4 = 3;
$alturaFila4 = 5;

$yInicialFila4 = $pdf->GetY();
$xInicialFila4 = 8; // Margen izquierdo fijo

// ==========================
//// 👉 Tabla: Estado de Placas y Peligros (Caja grande con bordes externos, respuestas con cuadro)
$pdf->SetXY($xInicialFila4, $yInicialFila4);
$pdf->SetFillColor(191, 143, 0);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);

// 👉 Título principal
$pdf->Cell($anchoEstadoPlacas, $alturaFila4 + 2, utf8_decode('Estado de Placas y Peligros'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXEstadoPlacas = $xInicialFila4;
$xInicioCaja = $xInicialFila4;
$yInicioCaja = $yInicialFila4 + $alturaFila4 + 2; // Justo debajo del título

$pdf->SetXY($xInicioCaja, $yInicioCaja);

$estadoCampos = [
    ['label' => 'De la Torre', 'campo' => 'estado_placas_torre'],
    ['label' => 'De la Línea', 'campo' => 'estado_placas_linea'],
    ['label' => 'De Fases', 'campo' => 'estado_placas_fases'],
    ['label' => 'Peligro Cerco', 'campo' => 'peligro_cerco'],
    ['label' => 'Peligro Torre', 'campo' => 'peligro_torre'],
    ['label' => 'Puesta a Tierra', 'campo' => 'puesta_tierra']
];

$lineaAltura = $alturaFila4;

for ($i = 0; $i < 3; $i++) {
    $pdf->SetX($posXEstadoPlacas);

    // 👉 Celda izquierda
    $labelIzq = '     ' . $estadoCampos[$i]['label'] . ':'; // 👉 Margen de 5 espacios
    $valorIzq = ucfirst(str_replace('_', ' ', $datos[$estadoCampos[$i]['campo']] ?? ''));

    // 👉 Celda derecha
    $labelDer = '     ' . $estadoCampos[$i + 3]['label'] . ':'; // 👉 Margen de 5 espacios
    $valorDer = ucfirst(str_replace('_', ' ', $datos[$estadoCampos[$i + 3]['campo']] ?? ''));

    $anchoColumna = $anchoEstadoPlacas / 2;

    // 👉 Imprimir la fila (label sin bordes, valor con bordes)
    $pdf->Cell($anchoColumna * 0.6, $lineaAltura, utf8_decode($labelIzq), 0, 0);
    $pdf->Cell($anchoColumna * 0.4, $lineaAltura, utf8_decode($valorIzq), 1, 0); // 👉 Borde solo en la respuesta

    $pdf->Cell($anchoColumna * 0.6, $lineaAltura, utf8_decode($labelDer), 0, 0);
    $pdf->Cell($anchoColumna * 0.4, $lineaAltura, utf8_decode($valorDer), 1, 1); // 👉 Borde solo en la respuesta
}

// 👉 Calcular alto de la caja grande
$yFinCaja = $pdf->GetY();
$altoCaja = $yFinCaja - $yInicioCaja;

// 👉 Dibujar la caja grande alrededor de todo
$pdf->Rect($xInicioCaja, $yInicioCaja, $anchoEstadoPlacas, $altoCaja);

// ==========================
// 👉 Tabla: Retenida
// ==========================
$pdf->SetXY($xInicialFila4 + $anchoEstadoPlacas + $espaciadoFila4, $yInicialFila4);
$pdf->SetFillColor(0, 112, 192);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($anchoRetenida, $alturaFila4 + 2, utf8_decode('Retenida'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXRetenida = $xInicialFila4 + $anchoEstadoPlacas + $espaciadoFila4;

foreach (['buen_estado', 'cambiar_preforme', 'retemplar', 'n_a'] as $opcion) {
    $check = ($datos['retenida'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXRetenida);
    $pdf->Cell($anchoRetenida * 0.7, $alturaFila4, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoRetenida * 0.3, $alturaFila4, utf8_decode($check), 1, 1);
}

// 👉 Bajar el cursor al final de la tabla más alta
$pdf->SetY($yInicialFila4 + (4 * $alturaFila4) + 14);
// 👉 Configuración inicial de Fila 5
//FILA 5
// 👉 Configuración inicial de Fila 5
$yInicialFila5 = $pdf->GetY();
$espaciadoFila5 = 3;

$anchoEstadoBase = 30;
$anchoLimpiarBase = 25;
$anchoCrucetasMensulas = 35;
$anchoPerfilesAngulares = 35;
$anchoMallaAntiescalamiento = 35;
$anchoOxidosBase = 50;

$alturaFila5 = 5;

// 👉 Tabla: Estado de la Base
$pdf->SetXY($xInicialFila4, $yInicialFila5);
$pdf->SetFillColor(123, 123, 123);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($anchoEstadoBase, $alturaFila5 + 2, utf8_decode('Estado de la Base'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXEstadoBase = $xInicialFila4;

foreach (['buen_estado', 'mal_estado'] as $opcion) {
    $check = ($datos['estado_base'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXEstadoBase);
    $pdf->Cell($anchoEstadoBase * 0.75, $alturaFila5, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoEstadoBase * 0.25, $alturaFila5, utf8_decode($check), 1, 1);
}

// 👉 Tabla: Limpiar Base
$pdf->SetXY($xInicialFila4 + $anchoEstadoBase + $espaciadoFila5, $yInicialFila5);
$pdf->SetFillColor(123, 123, 123);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($anchoLimpiarBase, $alturaFila5 + 2, utf8_decode('Limpiar Base'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXLimpiarBase = $xInicialFila4 + $anchoEstadoBase + $espaciadoFila5;

foreach (['si', 'no'] as $opcion) {
    $check = ($datos['limpiar_base'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXLimpiarBase);
    $pdf->Cell($anchoLimpiarBase * 0.75, $alturaFila5, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoLimpiarBase * 0.25, $alturaFila5, utf8_decode($check), 1, 1);
}

// 👉 Tabla: Crucetas y Mensulas
$pdf->SetXY($posXLimpiarBase + $anchoLimpiarBase + $espaciadoFila5, $yInicialFila5);
$pdf->SetFillColor(123, 123, 123);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($anchoCrucetasMensulas, $alturaFila5 + 2, utf8_decode('Crucetas y Mensulas'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXCrucetas = $posXLimpiarBase + $anchoLimpiarBase + $espaciadoFila5;

foreach (['buen_estado', 'mal_estado', 'falta_ajustar', 'n_a'] as $opcion) {
    $check = ($datos['crucetas_mensuales'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXCrucetas);
    $pdf->Cell($anchoCrucetasMensulas * 0.75, $alturaFila5, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoCrucetasMensulas * 0.25, $alturaFila5, utf8_decode($check), 1, 1);
}

// 👉 Tabla: Perfiles Angulares
$pdf->SetXY($posXCrucetas + $anchoCrucetasMensulas + $espaciadoFila5, $yInicialFila5);
$pdf->SetFillColor(123, 123, 123);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($anchoPerfilesAngulares, $alturaFila5 + 2, utf8_decode('Perfiles Angulares'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXPerfiles = $posXCrucetas + $anchoCrucetasMensulas + $espaciadoFila5;

foreach (['buen_estado', 'mal_estado', 'falta', 'n_a'] as $opcion) {
    $check = ($datos['perfiles_angulares'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXPerfiles);
    $pdf->Cell($anchoPerfilesAngulares * 0.75, $alturaFila5, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoPerfilesAngulares * 0.25, $alturaFila5, utf8_decode($check), 1, 1);
}

// 👉 Tabla: Malla Antiescalamiento
$pdf->SetXY($posXPerfiles + $anchoPerfilesAngulares + $espaciadoFila5, $yInicialFila5);
$pdf->SetFillColor(123, 123, 123);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($anchoMallaAntiescalamiento, $alturaFila5 + 2, utf8_decode('Malla Antiescalamiento'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXMalla = $posXPerfiles + $anchoPerfilesAngulares + $espaciadoFila5;

foreach (['buen_estado', 'mal_estado', 'falta', 'n_a'] as $opcion) {
    $check = ($datos['malla_antiescalamiento'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXMalla);
    $pdf->Cell($anchoMallaAntiescalamiento * 0.75, $alturaFila5, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoMallaAntiescalamiento * 0.25, $alturaFila5, utf8_decode($check), 1, 1);
}

// 👉 Tabla: Oxidos en Base debajo de Estado de la Base y Limpiar Base
$pdf->SetXY($xInicialFila4, $pdf->GetY() -5);
$pdf->SetFillColor(123, 123, 123);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($anchoOxidosBase, $alturaFila5 + 2, utf8_decode('Oxidos en Base'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXOxidos = $xInicialFila4;

$opcionesOxidos = ['si', 'no', 'n_a'];
$anchoPorOpcion = ($anchoOxidosBase / 3);

$pdf->SetX($posXOxidos);

foreach ($opcionesOxidos as $opcion) {
    $check = ($datos['oxidos_base'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    // 👉 Celda de opción (sin bordes internos)
    $pdf->Cell($anchoPorOpcion * 0.7, $alturaFila5, utf8_decode($label), 1, 0);

    // 👉 Celda de la X (con borde)
    $pdf->Cell($anchoPorOpcion * 0.3, $alturaFila5, utf8_decode($check), 1, 0);
}
$pdf->Ln($alturaFila5 + 5);
// 👉 Configuración inicial de Fila 6
$yInicialFila6 = $pdf->GetY();
$espaciadoFila6 = 2;

$anchoCadenaAisladores = 40;
$anchoTipoAislador = 30;
$anchoEstadoAisladores = 60;
$anchoConductorPAT = 43;

$alturaFila6 = 5;

// 👉 Tabla: Cadena de Aisladores
$pdf->SetXY($xInicialFila4, $yInicialFila6);
$pdf->SetFillColor(198, 89, 17);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($anchoCadenaAisladores, $alturaFila6 + 2, utf8_decode('Cadena de Aisladores'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXCadenas = $xInicialFila4;

foreach (['en_suspension', 'en_anclaje', 'en_cuello_muerto'] as $opcion) {
    $check = ($datos['cadena_aisladores'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXCadenas);

    $pdf->Cell($anchoCadenaAisladores * 0.7, $alturaFila6, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoCadenaAisladores * 0.3, $alturaFila6, utf8_decode($check), 1, 1);
}

// 👉 Tabla: Tipo de Aislador
$pdf->SetXY($posXCadenas + $anchoCadenaAisladores + $espaciadoFila6, $yInicialFila6);
$pdf->SetFillColor(198, 89, 17);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($anchoTipoAislador, $alturaFila6 + 2, utf8_decode('Tipo de Aislador'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXTipoAislador = $posXCadenas + $anchoCadenaAisladores + $espaciadoFila6;

foreach (['vidrio', 'porcelana', 'polimero'] as $opcion) {
    $check = ($datos['tipo_aislador'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst($opcion);

    $pdf->SetX($posXTipoAislador);

    $pdf->Cell($anchoTipoAislador * 0.7, $alturaFila6, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoTipoAislador * 0.3, $alturaFila6, utf8_decode($check), 1, 1);
}
// 👉 Tabla: Estado de Aisladores (Título + R S T en la misma fila)
$pdf->SetXY($posXTipoAislador + $anchoTipoAislador + $espaciadoFila6, $yInicialFila6);
$pdf->SetFillColor(198, 89, 17);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);

// 👉 Cabecera: Título + R | S | T (una sola fila)
$pdf->Cell($anchoEstadoAisladores * 0.6, $alturaFila6 + 2, utf8_decode('Estado de Aisladores'), 1, 0, 'C', true);
$pdf->Cell($anchoEstadoAisladores * 0.1, $alturaFila6 + 2, 'R', 1, 0, 'C', true);
$pdf->Cell($anchoEstadoAisladores * 0.1, $alturaFila6 + 2, 'S', 1, 0, 'C', true);
$pdf->Cell($anchoEstadoAisladores * 0.1, $alturaFila6 + 2, 'T', 1, 1, 'C', true);

// 👉 Opciones: Aisladores con columnas de R | S | T
$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXEstadoAisladores = $posXTipoAislador + $anchoTipoAislador + $espaciadoFila6;

$estadoOpciones = [
    'buen_estado',
    'rotos_suspension',
    'rotos_anclaje_adelante',
    'rotos_anclaje_atras',
    'mal_estado'
];

foreach ($estadoOpciones as $opcion) {
    $pdf->SetX($posXEstadoAisladores);
    // 👉 Nombre del atributo
    $pdf->Cell($anchoEstadoAisladores * 0.6, $alturaFila6, utf8_decode(ucfirst(str_replace('_', ' ', $opcion))), 1, 0);

    // 👉 Recorriendo R S T
    foreach (['R', 'S', 'T'] as $fase) {
        $check = '';
        foreach ($rst as $r) {
            if ($r['seccion'] === 'estado_aisladores' && $r['atributo'] === $opcion && $r['fase'] === $fase) {
                $check = 'X';
                break;
            }
        }
        $pdf->Cell($anchoEstadoAisladores * 0.1, $alturaFila6, utf8_decode($check), 1, 0, 'C');
    }
    $pdf->Ln();
}



// 👉 Tabla: Conductor de Bajada a PAT
$pdf->SetXY($posXEstadoAisladores + $anchoEstadoAisladores + $espaciadoFila6, $yInicialFila6);
$pdf->SetFillColor(51, 63, 79);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($anchoConductorPAT, $alturaFila6 + 2, utf8_decode('Conductor Bajada a PAT'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXConductorPAT = $posXEstadoAisladores + $anchoEstadoAisladores + $espaciadoFila6;

foreach (['buen_estado', 'conductor_en_mal_estado', 'grapas_en_mal_estado', 'listones_en_mal_estado', 'n_a'] as $opcion) {
    $check = ($datos['conductor_bajada_pat'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXConductorPAT);

    $pdf->Cell($anchoConductorPAT * 0.8, $alturaFila6, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoConductorPAT * 0.2, $alturaFila6, utf8_decode($check), 1, 1);
}

// 👉 Espaciado final
$pdf->Ln($alturaFila6 );

// 👉 Configuración inicial de Fila 7
$yInicialFila7 = $pdf->GetY();
$espaciadoFila7 = 2;

$anchoConductoresFase = 60;
$anchoConductoresCuellos = 60;
$anchoConductoresGuarda = 35;

$alturaFila7 = 5;

// 👉 Tabla: Conductores de Fase
$pdf->SetXY($xInicialFila4, $yInicialFila7);
$pdf->SetFillColor(47, 117, 181);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);

// 👉 Cabecera: Título + R S T
$pdf->Cell($anchoConductoresFase * 0.6, $alturaFila7 + 2, utf8_decode('Conductores de Fase'), 1, 0, 'C', true);
$pdf->Cell($anchoConductoresFase * 0.1, $alturaFila7 + 2, 'R', 1, 0, 'C', true);
$pdf->Cell($anchoConductoresFase * 0.1, $alturaFila7 + 2, 'S', 1, 0, 'C', true);
$pdf->Cell($anchoConductoresFase * 0.1, $alturaFila7 + 2, 'T', 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXFase = $xInicialFila4;

$atributosFase = ['hebras_rotas', 'encanastillado', 'empalme_deformado', 'objetos_extranos'];

foreach ($atributosFase as $atributo) {
    $pdf->SetX($posXFase);
    $pdf->Cell($anchoConductoresFase * 0.6, $alturaFila7, utf8_decode(ucfirst(str_replace('_', ' ', $atributo))), 1, 0);

    foreach (['R', 'S', 'T'] as $fase) {
        $check = '';
        foreach ($rst as $r) {
            if ($r['seccion'] === 'conductores_fase' && $r['atributo'] === $atributo && $r['fase'] === $fase) {
                $check = 'X';
                break;
            }
        }
        $pdf->Cell($anchoConductoresFase * 0.1, $alturaFila7, utf8_decode($check), 1, 0, 'C');
    }
    $pdf->Ln();
}

// 👉 Tabla: Conductores de Cuellos
$pdf->SetXY($posXFase + $anchoConductoresFase + $espaciadoFila7, $yInicialFila7);
$pdf->SetFillColor(47, 117, 181);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);

$pdf->Cell($anchoConductoresCuellos * 0.6, $alturaFila7 + 2, utf8_decode('Conductores de Cuellos'), 1, 0, 'C', true);
$pdf->Cell($anchoConductoresCuellos * 0.1, $alturaFila7 + 2, 'R', 1, 0, 'C', true);
$pdf->Cell($anchoConductoresCuellos * 0.1, $alturaFila7 + 2, 'S', 1, 0, 'C', true);
$pdf->Cell($anchoConductoresCuellos * 0.1, $alturaFila7 + 2, 'T', 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXCuellos = $posXFase + $anchoConductoresFase + $espaciadoFila7;

foreach ($atributosFase as $atributo) {
    $pdf->SetX($posXCuellos);
    $pdf->Cell($anchoConductoresCuellos * 0.6, $alturaFila7, utf8_decode(ucfirst(str_replace('_', ' ', $atributo))), 1, 0);

    foreach (['R', 'S', 'T'] as $fase) {
        $check = '';
        foreach ($rst as $r) {
            if ($r['seccion'] === 'conductores_cuellos' && $r['atributo'] === $atributo && $r['fase'] === $fase) {
                $check = 'X';
                break;
            }
        }
        $pdf->Cell($anchoConductoresCuellos * 0.1, $alturaFila7, utf8_decode($check), 1, 0, 'C');
    }
    $pdf->Ln();
}
// 👉 Tabla: Conductores de Guarda
$pdf->SetXY($posXCuellos + $anchoConductoresCuellos + $espaciadoFila7, $yInicialFila7);
$pdf->SetFillColor(47, 117, 181);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 8);
$pdf->Cell($anchoConductoresGuarda, $alturaFila7 + 2, utf8_decode('Conductores de Guarda'), 1, 1, 'C', true);

$pdf->SetFont('Arial', '', 7);
$pdf->SetTextColor(0);

$posXGuarda = $posXCuellos + $anchoConductoresCuellos + $espaciadoFila7;

foreach (['hebras_rotas', 'encanastillado', 'empalme_deformado', 'objetos_extranos', 'n_a'] as $opcion) {
    $check = ($datos['conductor_guarda'] ?? '') === $opcion ? 'X' : '';
    $label = ucfirst(str_replace('_', ' ', $opcion));

    $pdf->SetX($posXGuarda);

    // 👉 Aquí asignamos 80% para el texto y 20% para el check
    $pdf->Cell($anchoConductoresGuarda * 0.8, $alturaFila7, utf8_decode($label), 1, 0);
    $pdf->Cell($anchoConductoresGuarda * 0.2, $alturaFila7, utf8_decode($check), 1, 1);
}
// ==========================
// 👉 Tabla: Cantidad PAT
// ==========================
$anchoCantidadPAT = 20; // Puedes ajustar si necesitas más espacio
$posXPAT = $posXGuarda + $anchoConductoresGuarda + $espaciadoFila7;

// Cabecera
$pdf->SetXY($posXPAT, $yInicialFila7);
$pdf->SetFillColor(47, 117, 181);
$pdf->SetTextColor(255);
$pdf->SetFont('Arial', 'B', 7);
$pdf->MultiCell($anchoCantidadPAT, $alturaFila7 / 2, utf8_decode("Cantidad\nPAT"), 1, 'C', true);

// Valor (debajo)
$pdf->SetXY($posXPAT, $pdf->GetY());
$pdf->SetFillColor(255);
$pdf->SetTextColor(0);
$pdf->SetFont('Arial', 'B', 8);
$valorPAT = $datos['cantidad_pat'] ?? '';
$valorPATMostrar = is_numeric($valorPAT) ? ((fmod($valorPAT, 1.0) == 0.0) ? intval($valorPAT) : $valorPAT) : '';
$pdf->Cell($anchoCantidadPAT, $alturaFila7, utf8_decode((string)$valorPATMostrar), 1, 1, 'C', true);




// 👉 Separación después de la fila
$pdf->Ln($alturaFila7 );


  
}


?>
