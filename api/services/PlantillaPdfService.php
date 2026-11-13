<?php
declare(strict_types=1);

require_once __DIR__ . '/PlantillaService.php';
require_once __DIR__ . '/../clases/PDFPlantilla.php';
require_once __DIR__ . '/../clases/PDFImagenesPoste.php';

/**
 * Reporte PDF de las estructuras cuyo proyecto usa plantilla.
 *
 * `renderPostReport()` deriva aquí cuando el proyecto tiene plantilla, así que
 * el PDF individual, el ZIP de la línea y el panel usan este camino sin tocar
 * sus endpoints. Devuelve las mismas claves que el reporte histórico.
 */

/** Versión del dibujo genérico; entra en la huella de la caché. */
const PLANTILLA_PDF_VERSION = '2026-10-15.1';

/**
 * Compone el documento.
 *
 * @param array $post      codigo, nombre_proyecto, contratista, ubicacion, utm_x, utm_y, zona, fecha_inspeccion
 * @param array $fotos     filas de imagenes_plantilla (o equivalentes de muestra)
 */
function plantillaConstruirPdf(array $plantilla, array $post, array $respuestas, array $fotos): PDFPlantilla
{
    $config = $plantilla['config'];
    $pdf = new PDFPlantilla();
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetTitle(PDFPlantilla::txt('Inspección ' . ($post['codigo'] ?? '')));
    $pdf->encabezado = $config['pdf']['encabezado'];
    $pdf->rutaLogoIzquierdo = plantillaRutaLogo($plantilla['id'], $config['pdf']['encabezado']['logo_izquierdo'] ?? null);
    $pdf->rutaLogoDerecho = plantillaRutaLogo($plantilla['id'], $config['pdf']['encabezado']['logo_derecho'] ?? null);
    $pdf->tituloProyecto = (string) ($post['nombre_proyecto'] ?? '');
    $pdf->codigoPoste = (string) ($post['codigo'] ?? '');
    $pdf->contratista = (string) ($post['contratista'] ?? '');
    $pdf->ubicacion = (string) ($post['ubicacion'] ?? '');
    $pdf->utm_x = (string) ($post['utm_x'] ?? '');
    $pdf->utm_y = (string) ($post['utm_y'] ?? '');
    $pdf->zona = (string) ($post['zona'] ?? '');
    $fecha = $post['fecha_inspeccion'] ?? null;
    $pdf->fecha_inspeccion = $fecha ? date('d/m/Y H:i:s', strtotime((string) $fecha)) : 'Sin fecha';
    $pdf->fecha_inspeccion_sello = $fecha ? date('d-m-Y H:i', strtotime((string) $fecha)) : '';

    // Fotos agrupadas por clave e índice; la panorámica va a la hoja 1.
    $porClave = [];
    foreach ($fotos as $f) {
        $porClave[$f['foto_clave']][(int) $f['indice']] = $f + ['nombre_foto' => $f['foto_clave']];
    }
    $panoramica = ['ruta' => null, 'datos' => null];
    foreach ($config['fotos'] as $def) {
        if ($def['panoramica'] && isset($porClave[$def['clave']][1])) {
            $panoramica = ['ruta' => plantillaRutaFoto($porClave[$def['clave']][1]), 'datos' => $porClave[$def['clave']][1]];
        }
    }

    $pdf->AddPage();
    plantillaPdfHoja1($pdf, $config, $respuestas, $panoramica);
    plantillaPdfPaneles($pdf, $config, $porClave);
    plantillaPdfComentarios($pdf, $config, $respuestas);
    plantillaPdfFirmas($pdf, $config, (int) ($plantilla['id'] ?? 0));
    return $pdf;
}

function plantillaDatosPoste(PDO $db, int $postId, int $projectId): array
{
    $q = $db->prepare(
        'SELECT p.id, p.codigo, p.linea, p.utm_x, p.utm_y, p.zona, p.fecha_inspeccion, p.ubicaciones,
                pr.nombre_proyecto, pr.contratista, pr.ubicacion AS proyecto_ubicacion
         FROM postes p INNER JOIN proyectos pr ON pr.id = p.proyecto_id
         WHERE p.id = ? AND p.proyecto_id = ?'
    );
    $q->execute([$postId, $projectId]);
    $post = $q->fetch();
    if (!$post) {
        throw new DomainException('Poste no encontrado.');
    }
    $post['ubicacion'] = ($post['ubicaciones'] ?? '') !== '' ? $post['ubicaciones'] : $post['proyecto_ubicacion'];
    return $post;
}

/** Misma forma de respuesta que renderPostReport(). */
function plantillaRenderPdfPoste(PDO $db, int $postId, int $projectId, array $plantilla): array
{
    $inicio = hrtime(true);
    $post = plantillaDatosPoste($db, $postId, $projectId);
    $respuestas = plantillaRespuestasDePoste($db, $postId);
    $fotos = plantillaFotosDePoste($db, $postId);

    $material = [
        'motor' => PLANTILLA_PDF_VERSION,
        'plantilla' => [$plantilla['id'], $plantilla['version'], hash('sha256', plantillaJson($plantilla['config']))],
        'post' => $post,
        'respuestas' => $respuestas,
        'fotos' => [],
    ];
    foreach (['logo_izquierdo', 'logo_derecho'] as $k) {
        $material[$k] = $plantilla['config']['pdf']['encabezado'][$k] ?? null;
    }
    foreach ($fotos as $f) {
        $ruta = resolveStoredImagePath($f['ruta_archivo']);
        $material['fotos'][] = [$f['foto_clave'], $f['indice'], $f['ruta_archivo'], $f['fecha_captura'],
            $ruta ? (int) @filesize($ruta) : 0, $ruta ? (int) @filemtime($ruta) : 0];
    }
    $huella = hash('sha256', json_encode($material, JSON_UNESCAPED_UNICODE) ?: serialize($material));
    $codigo = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $post['codigo']) ?: 'poste_' . $postId;
    $base = [
        'codigo' => $codigo, 'source' => 'current', 'recovered_files' => 0, 'missing_files' => 0,
        'photo_options' => ['photo_source' => 'current', 'incluidas' => count($fotos), 'excluidas' => 0, 'form_state' => 'n/a'],
    ];

    $cacheActiva = envValue('PDF_CACHE_ENABLED', true) === true;
    if ($cacheActiva) {
        $archivo = pdfCacheFile($postId, $huella);
        if (is_file($archivo) && filesize($archivo) > 0) {
            $contenido = (string) @file_get_contents($archivo);
            if ($contenido !== '') {
                return $base + ['contenido' => $contenido, 'desde_cache' => true, 'ms' => elapsedMs($inicio)];
            }
        }
    }

    $contenido = plantillaConstruirPdf($plantilla, $post, $respuestas, $fotos)->Output('S');
    if ($cacheActiva && $contenido !== '') {
        try {
            writeFileAtomic(pdfCacheFile($postId, $huella, true), $contenido);
            prunePostPdfCache($postId);
        } catch (Throwable $e) {
            appLog('almacenamiento', 'No se pudo guardar el PDF de plantilla en la cache.', ['poste' => $postId, 'motivo' => $e->getMessage()]);
        }
    }
    appMetric('pdf de plantilla generado', ['poste' => $postId, 'kb' => (int) round(strlen($contenido) / 1024), 'ms' => elapsedMs($inicio)]);
    return $base + ['contenido' => $contenido, 'desde_cache' => false, 'ms' => elapsedMs($inicio)];
}

// ---------------------------------------------------------------------------
// Vista previa con datos de ejemplo (panel admin)
// ---------------------------------------------------------------------------

function plantillaRespuestasDeEjemplo(array $config): array
{
    $r = [];
    foreach (plantillaCampos($config) as $clave => $campo) {
        $ops = array_column($campo['opciones'] ?? [], 'valor');
        $r[$clave] = match ($campo['tipo']) {
            'texto' => 'Texto de ejemplo',
            'parrafo' => 'Comentario de ejemplo: así se verá el texto largo que escriba el inspector en campo.',
            'numero' => round(12.5, (int) ($campo['decimales'] ?? 2)),
            'fecha' => date('Y-m-d'),
            'si_no' => 'si',
            'seleccion' => $ops[0] ?? null,
            'multiple' => array_slice($ops, 0, 2),
            'rst' => $ops ? [$ops[0] => array_slice($campo['fases'], 0, 2)] : [],
            default => null,
        };
    }
    return $r;
}

/** Imagen de muestra (degradado con texto) para que la vista previa muestre fotos. */
function plantillaImagenDeEjemplo(): ?string
{
    $ruta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecoing_foto_ejemplo_v1.jpg';
    if (is_file($ruta)) {
        return $ruta;
    }
    if (!function_exists('imagecreatetruecolor')) {
        return null;
    }
    $w = 800; $h = 600;
    $img = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $t = $y / $h;
        $color = imagecolorallocate($img, (int) (120 + 60 * $t), (int) (160 + 40 * $t), (int) (200 - 40 * $t));
        imageline($img, 0, $y, $w, $y, $color);
    }
    $blanco = imagecolorallocate($img, 255, 255, 255);
    imagefilledrectangle($img, 250, 270, 550, 330, imagecolorallocatealpha($img, 0, 0, 0, 80));
    imagestring($img, 5, 330, 292, 'FOTO DE EJEMPLO', $blanco);
    imagejpeg($img, $ruta, 80);
    imagedestroy($img);
    return is_file($ruta) ? $ruta : null;
}

function plantillaPdfDeEjemplo(array $plantilla): string
{
    $config = $plantilla['config'];
    $muestra = plantillaImagenDeEjemplo();
    $fotos = [];
    if ($muestra !== null) {
        foreach ($config['fotos'] as $def) {
            for ($i = 1; $i <= (int) $def['cantidad']; $i++) {
                $fotos[] = [
                    'foto_clave' => $def['clave'], 'indice' => $i, 'ruta_archivo' => null, 'ruta_muestra' => $muestra,
                    'fecha_captura' => date('Y-m-d H:i:s'), 'utm_este' => 387514.22, 'utm_norte' => 8249713.4, 'zona' => '19',
                ];
            }
        }
    }
    $post = [
        'codigo' => 'E-001', 'nombre_proyecto' => 'Proyecto de ejemplo', 'contratista' => 'Contratista',
        'ubicacion' => 'Ubicación de ejemplo', 'utm_x' => '387514.22', 'utm_y' => '8249713.40', 'zona' => '19S',
        'fecha_inspeccion' => date('Y-m-d H:i:s'),
    ];
    return plantillaConstruirPdf($plantilla, $post, plantillaRespuestasDeEjemplo($config), $fotos)->Output('S');
}
