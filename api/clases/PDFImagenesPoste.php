<?php
require_once __DIR__ . '/optimizador_imagenes.php';

function agregarImagenesPoste($pdf, $imagenes) {
    $fotosEsperadas = [
        'placa',
        'torre_parte_inferior',
        'torre_parte_superior',
        'base_torre',
        'mensulas',
        'crucetas',
        'perfiles_angulares',
        'atiescalamiento',
        'otros'
    ];

    $imagenesMap = [];
    foreach ($imagenes as $img) {
        $ruta = resolveStoredImagePath($img['ruta_archivo'] ?? null);
        if (file_exists($ruta) && @getimagesize($ruta)) {
            $imagenesMap[$img['nombre_foto']] = $ruta;
        }
    }

    $anchoDisponible = $pdf->GetPageWidth() - 20;
    $numColumnas = 3;
    $anchoCelda = $anchoDisponible / $numColumnas;
    $altoImagen = 50;
    $altoTexto = 5;
    $panelAncho = $anchoCelda * $numColumnas;
    $xPanelInicio = 10;
    $yPanelInicio = $pdf->GetY();

    $pdf->SetFillColor(123, 123, 123);
    $pdf->SetTextColor(255);
    $pdf->SetFont('Arial', 'B', 6);
    $pdf->SetXY($xPanelInicio, $yPanelInicio);
    $pdf->Cell($panelAncho, 6, utf8_decode('PANEL FOTOGRÁFICO DE LA ESTRUCTURA'), 1, 1, 'C', true);

    $yActual = $pdf->GetY();
    $xActual = $xPanelInicio;
    $columna = 0;

    foreach ($fotosEsperadas as $fotoNombre) {
        $pdf->Rect($xActual, $yActual, $anchoCelda, $altoImagen + $altoTexto);

        $ruta = $imagenesMap[$fotoNombre] ?? null;

        if ($ruta) {
           $rutaReducida = redimensionarImagenTemporal($ruta);
$pdf->Image($rutaReducida, $xActual + 1, $yActual + 1, $anchoCelda - 2, $altoImagen - 2);
if ($rutaReducida !== $ruta && file_exists($rutaReducida)) {
    unlink($rutaReducida);
}

        } else {
            $pdf->SetXY($xActual, $yActual + ($altoImagen / 2) - 3);
            $pdf->SetFont('Arial', 'B', 6);
            $pdf->SetTextColor(0);
            $pdf->Cell($anchoCelda, 4, utf8_decode('No tiene foto'), 0, 0, 'C');
        }

        $pdf->SetXY($xActual, $yActual + $altoImagen);
        $pdf->SetFillColor(123, 123, 123);
        $pdf->SetTextColor(255);
        $pdf->SetFont('Arial', 'B', 6);
        $pdf->Cell($anchoCelda, $altoTexto, utf8_decode(str_replace('_', ' ', ucfirst($fotoNombre))), 0, 0, 'C', true);

        $columna++;
        if ($columna == $numColumnas) {
            $columna = 0;
            $xActual = $xPanelInicio;
            $yActual += $altoImagen + $altoTexto;
        } else {
            $xActual += $anchoCelda;
        }
    }

    $pdf->SetY($yActual + 5);
}
function agregarPanelAisladores($pdf, $imagenes) {
  

    $fotosAisladores = [
        'aisladores_fase_r_atras',
        'aisladores_fase_s_atras',
        'aisladores_fase_t_atras',
        'aisladores_fase_r_adelante',
        'aisladores_fase_s_adelante',
        'aisladores_fase_t_adelante',
        'ferreteria_fase_r',
        'ferreteria_fase_s',
        'ferreteria_fase_t'
    ];

    $nombresBonitos = [
        'aisladores_fase_r_atras' => 'Aisladores (Fase R) Atrás',
        'aisladores_fase_s_atras' => 'Aisladores (Fase S) Atrás',
        'aisladores_fase_t_atras' => 'Aisladores (Fase T) Atrás',
        'aisladores_fase_r_adelante' => 'Aisladores (Fase R) Adelante',
        'aisladores_fase_s_adelante' => 'Aisladores (Fase S) Adelante',
        'aisladores_fase_t_adelante' => 'Aisladores (Fase T) Adelante',
        'ferreteria_fase_r' => 'Ferretería (Fase R)',
        'ferreteria_fase_s' => 'Ferretería (Fase S)',
        'ferreteria_fase_t' => 'Ferretería (Fase T)'
    ];

    $imagenesMap = [];
    foreach ($imagenes as $img) {
        $ruta = resolveStoredImagePath($img['ruta_archivo'] ?? null);
        if ($ruta && file_exists($ruta) && @getimagesize($ruta)) {
            $imagenesMap[$img['nombre_foto']] = $ruta;
        }
    }

    $numColumnas = 3;
    $anchoDisponible = $pdf->GetPageWidth() - 20;
    $anchoCelda = $anchoDisponible / $numColumnas;
    $altoImagen = 50;
    $altoTexto = 5;
    $xPanelInicio = 10;
    $panelAncho = $anchoCelda * $numColumnas;
    $yPanelInicio = $pdf->GetY();

    $pdf->SetFillColor(198, 89, 17);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->SetFont('Arial', 'B', 6);
    $pdf->SetXY($xPanelInicio, $yPanelInicio);
    $pdf->Cell($panelAncho, 6, utf8_decode('AISLADORES'), 1, 1, 'C', true);

    $yActual = $pdf->GetY();
    $xActual = $xPanelInicio;
    $columna = 0;

    foreach ($fotosAisladores as $index => $fotoNombre) {
        if ($index == 3) {
            $pdf->AddPage();
            $yPanelInicio = $pdf->GetY();
            $yActual = $yPanelInicio;
            $xActual = $xPanelInicio;
        }

        $ruta = $imagenesMap[$fotoNombre] ?? null;
        $pdf->Rect($xActual, $yActual, $anchoCelda, $altoImagen + $altoTexto);

        if ($ruta) {
          $rutaReducida = redimensionarImagenTemporal($ruta);
          $pdf->Image($rutaReducida, $xActual + 1, $yActual + 1, $anchoCelda - 2, $altoImagen - 2);
if ($rutaReducida !== $ruta && file_exists($rutaReducida)) {
    unlink($rutaReducida);
}

        } else {
            $pdf->SetXY($xActual, $yActual + ($altoImagen / 2) - 3);
            $pdf->SetFont('Arial', 'B', 6);
            $pdf->SetTextColor(0);
            $pdf->Cell($anchoCelda, 4, utf8_decode('No tiene foto'), 0, 0, 'C');
        }

        $pdf->SetXY($xActual, $yActual + $altoImagen);
        $pdf->SetFillColor(198, 89, 17);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetFont('Arial', 'B', 6);
        $nombreBonito = $nombresBonitos[$fotoNombre] ?? str_replace('_', ' ', ucfirst($fotoNombre));
        $pdf->Cell($anchoCelda, $altoTexto, utf8_decode($nombreBonito), 0, 0, 'C', true);

        $columna++;
        if ($columna == $numColumnas) {
            $columna = 0;
            $xActual = $xPanelInicio;
            $yActual += $altoImagen + $altoTexto;
        } else {
            $xActual += $anchoCelda;
        }
    }

    $pdf->SetY($yActual + 3);
}

function agregarPanelPersonalizado($pdf, $titulo, $imagenes, $fotosEsperadas, $opciones = []) {
    $anchoCelda = $opciones['anchoCelda'] ?? 94.5;
    $altoImagen = $opciones['altoImagen'] ?? 50;
    $altoTexto = $opciones['altoTexto'] ?? 5;
    $numColumnas = $opciones['numColumnas'] ?? 3;
    $colorTitulo = $opciones['colorTitulo'] ?? [200, 200, 200];
    $colorTexto = $opciones['colorTexto'] ?? [230, 230, 230];
    $tituloArriba = $opciones['tituloArriba'] ?? false;
    $ocultarNombres = $opciones['ocultarNombres'] ?? false;

    $imagenesMap = [];
    foreach ($imagenes as $img) {
        $ruta = resolveStoredImagePath($img['ruta_archivo'] ?? null);
        if ($ruta && file_exists($ruta) && @getimagesize($ruta)) {
            $imagenesMap[$img['nombre_foto']] = $ruta;
        }
    }
if (!isset($opciones['anchoCelda'])) {
    $anchoDisponible = $pdf->GetPageWidth() - 20;
    $anchoCelda = $anchoDisponible / $numColumnas;
}

    $panelAncho = $anchoCelda * $numColumnas;
    $yPanelInicio = $pdf->GetY();
    $xPanelInicio = 10;

    // 🔠 Título del panel
    if (!empty($titulo)) {
        $pdf->SetFillColor($colorTitulo[0], $colorTitulo[1], $colorTitulo[2]);
        $pdf->SetFont('Arial', 'B', 6);
        $pdf->SetXY($xPanelInicio, $yPanelInicio);
        $pdf->Cell($panelAncho, 6, utf8_decode(strtoupper($titulo)), 1, 1, 'C', true);
        $yActual = $pdf->GetY();
    } else {
        $yActual = $yPanelInicio;
    }

    // 🔁 Mostrar cada celda de imagen
    $xActual = $xPanelInicio;
    $columna = 0;

    foreach ($fotosEsperadas as $foto) {
        $ruta = $imagenesMap[$foto['nombre']] ?? null;
        $altoCelda = $altoImagen + ($tituloArriba ? $altoTexto : ($ocultarNombres ? 0 : $altoTexto));

        // Dibujar borde del recuadro
        $pdf->Rect($xActual, $yActual, $anchoCelda, $altoCelda);

        // Título arriba si corresponde
        if ($tituloArriba) {
            $pdf->SetXY($xActual, $yActual);
            $pdf->SetFillColor(...($foto['color'] ?? $colorTexto));
            $pdf->SetFont('Arial', 'B', 6);
            $pdf->Cell($anchoCelda, $altoTexto, utf8_decode($foto['titulo']), 0, 0, 'C', true);
            $yImagen = $yActual + $altoTexto;
        } else {
            $yImagen = $yActual;
        }

        // Mostrar imagen o texto "No tiene foto"
        if ($ruta) {
            $rutaReducida = redimensionarImagenTemporal($ruta);
            $pdf->Image($rutaReducida, $xActual + 1, $yImagen + 1, $anchoCelda - 2, $altoImagen - 2);
if ($rutaReducida !== $ruta && file_exists($rutaReducida)) {
    unlink($rutaReducida);
}

        } else {
            $pdf->SetXY($xActual, $yImagen + ($altoImagen / 2) - 3);
     $pdf->SetFont('Arial', 'B', 6);
$pdf->SetTextColor(0, 0, 0); // Negro solo para el mensaje
$pdf->Cell($anchoCelda, 4, utf8_decode('No tiene foto'), 0, 0, 'C');
$pdf->SetTextColor(255, 255, 255); // ⚪ Volver a blanco para los demás textos

        
        }
        

        // Nombre abajo si corresponde
        if (!$ocultarNombres && !$tituloArriba) {
            $pdf->SetXY($xActual, $yImagen + $altoImagen);
            $pdf->SetFillColor(...$colorTexto);
            $pdf->SetFont('Arial', 'B', 6);
            $pdf->Cell($anchoCelda, $altoTexto, utf8_decode($foto['titulo']), 0, 0, 'C', true);
        }

        // Siguiente celda
        $columna++;
        if ($columna == $numColumnas) {
            $columna = 0;
            $xActual = $xPanelInicio;
            $yActual += $altoCelda;
        } else {
            $xActual += $anchoCelda;
        }
    }

    // Actualizar posición Y final
    $pdf->SetY($yActual);
}


function agregarComentariosPoste($pdf, $comentarios) {
    if (is_array($comentarios)) {
        $comentarios = implode(' ', $comentarios);
    }

    if (empty(trim($comentarios))) {
        $comentarios = 'Sin comentarios u observaciones.';
    }

    $anchoCaja = $pdf->GetPageWidth() - 20;
    $xInicio = 10;
    $yInicio = $pdf->GetY();

    // Encabezado con fondo gris
    $pdf->SetFillColor(200, 200, 200);
    $pdf->SetTextColor(0); // Texto negro
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->SetXY($xInicio, $yInicio);
    $pdf->Cell($anchoCaja, 8, utf8_decode('COMENTARIOS / OBSERVACIONES'), 1, 1, 'C', true);

    // Texto de comentarios en color negro, sin fondo
    $pdf->SetTextColor(0); // Solo texto negro
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetXY($xInicio, $pdf->GetY());
    $pdf->MultiCell($anchoCaja, 6, utf8_decode($comentarios), 1, 'L'); // Sin fondo

    $pdf->Ln(10);
}

?>
