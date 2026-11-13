<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/domain.php';
require_once __DIR__ . '/StructureRecoveryService.php';
require_once __DIR__ . '/ReportPhotoSourceService.php';
// `reportPhotoApplySavedConfig()` vive aquí. Antes se llegaba a ella de rebote
// por un require mutuo entre los dos servicios de fotografías: si ese archivo
// faltaba en el servidor, el fallo era un 500 sin cuerpo en TODOS los reportes.
require_once __DIR__ . '/ReportPhotoConfigService.php';
require_once __DIR__ . '/../clases/PDFPoste.php';
require_once __DIR__ . '/../clases/PDFFormularioPoste.php';
require_once __DIR__ . '/../clases/PDFImagenesPoste.php';
require_once __DIR__ . '/PlantillaPdfService.php';

/**
 * Versión de la plantilla del reporte.
 *
 * Entra en la huella de la caché: al tocar cualquiera de las clases PDF* hay
 * que subir este valor, o los PDF ya generados se seguirían sirviendo con el
 * diseño anterior.
 */
const PDF_TEMPLATE_VERSION = '2026-08-26.3';
const PDF_SIGNATURE_CONTRATISTA_PATH = __DIR__ . '/../clases/images/firma.png';

/**
 * Firmantes del reporte.
 *
 * Estaban escritos con el nombre de la persona en el propio identificador
 * (`PDF_SIGNER_HECTOR_NAME`), así que cambiar de supervisor obligaba a tocar
 * código en varios sitios. Ahora la constante nombra el CARGO y el valor se
 * puede fijar por entorno, que es lo único que hay que cambiar la próxima vez.
 *
 * Los dos nombres entran en la huella de la caché (`postReportFingerprint()`),
 * así que al cambiarlos TODOS los PDF ya generados se regeneran solos: ningún
 * informe nuevo puede salir con el supervisor anterior.
 */
function pdfSigner(string $variable, string $default): string
{
    $value = trim((string) envValue($variable, ''));

    return $value !== '' ? $value : $default;
}

/** Supervisor de la contratista (firma con el PNG de la izquierda). */
define('PDF_SIGNER_CONTRATISTA_NAME', pdfSigner('PDF_SUPERVISOR_CONTRATISTA_NOMBRE', 'Victor Raul Ramos Gomez'));
define('PDF_SIGNER_CONTRATISTA_ROLE', pdfSigner('PDF_SUPERVISOR_CONTRATISTA_CARGO', 'Supervisor INTESEL'));

/** Supervisor de obra del cliente. */
define('PDF_SIGNER_OBRA_NAME', pdfSigner('PDF_SUPERVISOR_OBRA_NOMBRE', 'EDUARDO PANCORBO SALAS'));
define('PDF_SIGNER_OBRA_ROLE', pdfSigner('PDF_SUPERVISOR_OBRA_CARGO', 'Supervisor de obra ELPU'));

if (!function_exists('log_pdf')) {
    function log_pdf(string $message): void
    {
        error_log('PDF: ' . $message);
    }
}

function addReportSignatures(PDFPoste $pdf): void
{
    // Reserva la misma altura en ambas columnas; la derecha queda libre para
    // una firma manuscrita y la izquierda recibe el PNG transparente.
    $pdf->Ln(26);
    $pdf->SetTextColor(0);
    $width = ($pdf->GetPageWidth() - 20) / 2;
    $left = 10;
    $right = $left + $width;
    $lineY = $pdf->GetY();
    $lineWidth = min(65.0, $width - 12);
    $leftLineX = $left + (($width - $lineWidth) / 2);
    $rightLineX = $right + (($width - $lineWidth) / 2);

    if (is_file(PDF_SIGNATURE_CONTRATISTA_PATH)) {
        $size = @getimagesize(PDF_SIGNATURE_CONTRATISTA_PATH);
        if (is_array($size) && $size[0] > 0 && $size[1] > 0) {
            $maxImageWidth = 50.0;
            $maxImageHeight = 24.0;
            $scale = min($maxImageWidth / $size[0], $maxImageHeight / $size[1]);
            $imageWidth = $size[0] * $scale;
            $imageHeight = $size[1] * $scale;
            $imageX = $left + (($width - $imageWidth) / 2);
            $imageY = $lineY - $imageHeight - 1;
            $pdf->Image(
                PDF_SIGNATURE_CONTRATISTA_PATH,
                $imageX,
                $imageY,
                $imageWidth,
                $imageHeight,
                'PNG'
            );
        }
    }

    $pdf->SetFont('Arial', '', 9);
    $pdf->SetDrawColor(0);
    $pdf->SetLineWidth(0.2);
    $pdf->Line($leftLineX, $lineY, $leftLineX + $lineWidth, $lineY);
    $pdf->Line($rightLineX, $lineY, $rightLineX + $lineWidth, $lineY);

    $pdf->SetXY($left, $lineY + 2);
    $pdf->Cell($width, 5, PDF_SIGNER_CONTRATISTA_NAME, 0, 0, 'C');
    $pdf->SetXY($right, $lineY + 2);
    $pdf->Cell($width, 5, PDF_SIGNER_OBRA_NAME, 0, 1, 'C');

    $pdf->SetXY($left, $lineY + 7);
    $pdf->Cell($width, 5, PDF_SIGNER_CONTRATISTA_ROLE, 0, 0, 'C');
    $pdf->SetXY($right, $lineY + 7);
    $pdf->Cell($width, 5, PDF_SIGNER_OBRA_ROLE, 0, 1, 'C');

    $pdf->SetXY($left, $lineY + 12);
    $pdf->Cell($width, 5, 'Fecha:', 0, 0, 'C');
    $pdf->SetXY($right, $lineY + 12);
    $pdf->Cell($width, 5, 'Fecha:', 0, 1, 'C');
}

/**
 * Compone el documento.
 *
 * `$options` elige la fuente de información. En NAS, formulario, RST,
 * coordenadas y fotos salen del estado actual; en Backup, los datos de
 * inspección salen de formulario.json y las fotos de Fotos/. Los datos
 * maestros del padrón y el diseño no cambian. No existe fallback Backup -> NAS.
 */
function buildPostReport(
    PDO $connection,
    int $postId,
    int $projectId,
    ?array $resolved = null,
    array $options = []
): PDFPoste
{
    $resolved ??= resolveStructureResources($connection, $postId, $projectId);
    // Idempotente: si renderPostReport() ya aplicó la selección, no se repite.
    $resolved = applyReportPhotoOptions($resolved, $options);
    $post = $resolved['post'];
    $details = $resolved['details'];
    $rst = $resolved['rst'];
    $images = $resolved['images'];

    $panoramicPath = null;
    $panoramicData = null;
    foreach ($images as $index => $image) {
        if ($image['nombre_foto'] === 'foto_panoramica') {
            $panoramicPath = reportImagePath($image);
            $panoramicData = $image;
            unset($images[$index]);
            break;
        }
    }
    $images = array_values($images);

    $pdf = new PDFPoste();
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->SetMargins(10, 10, 10);
    $pdf->tituloProyecto = $post['nombre_proyecto'];
    $pdf->codigoPoste = $post['codigo'];
    $pdf->contratista = $post['contratista'];
    $pdf->ubicacion = $post['ubicaciones'] ?: $post['proyecto_ubicacion'];
    $pdf->utm_x = (string) ($post['utm_x'] ?? '');
    $pdf->utm_y = (string) ($post['utm_y'] ?? '');
    $pdf->zona = (string) ($post['zona'] ?? '');
    $pdf->fecha_inspeccion = $post['fecha_inspeccion']
        ? date('d/m/Y H:i:s', strtotime($post['fecha_inspeccion']))
        : 'Sin fecha';
    $pdf->fecha_inspeccion_sello = $post['fecha_inspeccion']
        ? date('d-m-Y H:i', strtotime($post['fecha_inspeccion']))
        : '';

    $pdf->AddPage();
    agregarFormularioPoste($pdf, $details, $rst, $panoramicPath, $panoramicData);
    $pdf->AddPage();
    agregarImagenesPoste($pdf, $images);
    agregarPanelAisladores($pdf, $images);
    agregarPanelPersonalizado($pdf, 'CABLE DE GUARDA Y OPGW', $images, [
        ['nombre' => 'cable_guarda', 'titulo' => 'Cable de Guarda'],
        ['nombre' => 'ferreteria_de_cable_de_guarda', 'titulo' => 'Ferretería de cable de guarda'],
    ], ['numColumnas' => 2, 'colorTitulo' => [48, 84, 150], 'colorTexto' => [48, 84, 150]]);
    agregarPanelPersonalizado($pdf, 'CONDUCTORES', $images, [
        ['nombre' => 'conductor', 'titulo' => 'Conductor'],
        ['nombre' => 'ferreteria_de_conductor', 'titulo' => 'Ferretería de conductor'],
    ], ['numColumnas' => 2, 'colorTitulo' => [84, 130, 53], 'colorTexto' => [84, 130, 53]]);

    $pdf->AddPage();
    agregarPanelPersonalizado($pdf, '', $images, [
        ['nombre' => 'puesta_tierra', 'titulo' => 'Puesta a Tierra', 'color' => [198, 89, 17]],
        ['nombre' => 'puesta_tierra_2', 'titulo' => 'Puesta a Tierra 2', 'color' => [198, 89, 17]],
    ], ['numColumnas' => 2, 'tituloArriba' => true]);
    agregarPanelPersonalizado($pdf, 'FAJA DE SERVIDUMBRE / UBICACIÓN ACCESO', $images, [
        ['nombre' => 'faja_servidumbre', 'titulo' => ''],
        ['nombre' => 'ubicacion_acceso', 'titulo' => ''],
    ], ['numColumnas' => 2, 'colorTitulo' => [123, 123, 123], 'ocultarNombres' => true]);
    agregarPanelPersonalizado($pdf, 'RETENIDA', $images, [
        ['nombre' => 'retenida', 'titulo' => ''],
    ], ['numColumnas' => 1, 'anchoCelda' => ($pdf->GetPageWidth() - 20) / 2, 'colorTitulo' => [84, 130, 53], 'ocultarNombres' => true]);

    $pdf->Ln(5);
    agregarComentariosPoste($pdf, $details['comentarios'] ?? '');
    if ($pdf->GetPageHeight() - $pdf->GetY() < 54) {
        $pdf->AddPage();
    }
    addReportSignatures($pdf);
    return $pdf;
}

/**
 * Huella del contenido que alimenta el reporte de un poste.
 *
 * ## Para qué
 *
 * Generar el PDF de una estructura cuesta segundos: hay que leer 28 fotografías
 * del disco, reducirlas y componerlas con FPDF. Multiplicado por las estructuras
 * de una línea, `exportar_linea_pdf.php` puede tardar minutos y repite ese
 * trabajo íntegro en cada descarga, aunque nada haya cambiado.
 *
 * La huella resuelve el "¿cambió algo?" sin añadir columnas a la base: se hace
 * sha256 de todo lo que el reporte dibuja —el poste, su formulario, sus
 * secciones RST y sus imágenes— más el tamaño y la fecha de modificación de
 * cada archivo de imagen, más la versión de la plantilla.
 *
 * Con eso:
 *   · cambia un campo del formulario  -> cambia la huella  -> se regenera;
 *   · se sustituye una fotografía     -> cambia mtime/size -> se regenera;
 *   · se edita una clase PDF*         -> cambia la versión -> se regenera;
 *   · no cambia nada                  -> misma huella      -> se reutiliza.
 *
 * El coste de calcularla son cuatro consultas ligeras y un `stat` por imagen:
 * despreciable frente a lo que evita.
 */
function postReportFingerprint(
    PDO $connection,
    int $postId,
    int $projectId,
    ?array $resolved = null
): string
{
    $resolved ??= resolveStructureResources($connection, $postId, $projectId);
    $material = ['plantilla' => PDF_TEMPLATE_VERSION, 'poste_id' => $postId, 'proyecto_id' => $projectId];
    $material['poste'] = $resolved['post'];
    $material['datos'] = $resolved['details'];
    $material['rst'] = $resolved['rst'];
    $material['source'] = $resolved['source'];
    // La selección de fotografías entra en la huella sólo cuando no es la
    // histórica: así la huella del reporte estándar no cambia y los PDF ya
    // cacheados siguen siendo válidos. Aun así `renderPostReport()` no lee ni
    // escribe la caché de un reporte personalizado; esto es la segunda barrera.
    $photoOptions = $resolved['photo_options'] ?? null;
    if (is_array($photoOptions) && !reportPhotoOptionsAreDefault($photoOptions)) {
        $material['fotos'] = [
            'source' => $photoOptions['photo_source'],
            'incluidos' => $photoOptions['included_slots'],
            // La configuración guardada en el panel admin entra por aquí: si el
            // administrador cambia qué se imprime, la huella cambia y el PDF se
            // regenera, sin que haya que invalidar nada a mano.
            'excluidos' => $photoOptions['excluded_slots'] ?? [],
            'form_state' => $photoOptions['form_state'] ?? null,
        ];
        // En modo respaldo el formulario del informe sale de
        // `Formulario/formulario.json`: su tamaño y su fecha entran en la huella
        // para que editar ese archivo regenere el PDF en vez de servir el viejo.
        $manifestFile = $resolved['backup']['manifest_file'] ?? null;
        if (is_string($manifestFile) && $manifestFile !== '') {
            $material['fotos']['manifiesto'] = [
                'bytes' => (int) (@filesize($manifestFile) ?: 0),
                'mtime' => (int) (@filemtime($manifestFile) ?: 0),
            ];
        }
    }
    $material['imagenes'] = [];
    foreach ($resolved['images'] as $image) {
        $absolute = reportImagePath($image);
        $material['imagenes'][] = [
            'nombre' => $image['nombre_foto'],
            'ruta' => $image['ruta_archivo'] ?? null,
            'captura' => $image['fecha_captura'] ?? null,
            'subida' => $image['fecha_subida'] ?? null,
            'source' => $image['source'] ?? 'official',
            // Detecta que el archivo se reemplazó incluso si la fila no cambió.
            'bytes' => $absolute !== null ? (int) (@filesize($absolute) ?: 0) : 0,
            'mtime' => $absolute !== null ? (int) (@filemtime($absolute) ?: 0) : 0,
        ];
    }

    // La firma es parte de la plantilla y debe invalidar la caché si cambia.
    $material['firmas'] = [
        'contratista' => [
            'nombre' => PDF_SIGNER_CONTRATISTA_NAME,
            'cargo' => PDF_SIGNER_CONTRATISTA_ROLE,
            'png' => basename(PDF_SIGNATURE_CONTRATISTA_PATH),
            'bytes' => (int) (@filesize(PDF_SIGNATURE_CONTRATISTA_PATH) ?: 0),
            'sha256' => is_file(PDF_SIGNATURE_CONTRATISTA_PATH)
                ? (string) hash_file('sha256', PDF_SIGNATURE_CONTRATISTA_PATH)
                : '',
        ],
        'obra' => [
            'nombre' => PDF_SIGNER_OBRA_NAME,
            'cargo' => PDF_SIGNER_OBRA_ROLE,
        ],
    ];

    return hash('sha256', json_encode($material, JSON_UNESCAPED_UNICODE) ?: serialize($material));
}

/**
 * Nombre de archivo del reporte a partir del código del poste.
 *
 * Se consulta aparte para poder nombrar la descarga en un acierto de caché sin
 * componer el PDF completo solo para leer `codigoPoste`.
 */
function postReportFileCode(PDO $connection, int $postId, int $projectId): string
{
    $statement = $connection->prepare('SELECT codigo FROM postes WHERE id = ? AND proyecto_id = ?');
    $statement->execute([$postId, $projectId]);
    $code = (string) ($statement->fetchColumn() ?: '');

    return preg_replace('/[^A-Za-z0-9_-]+/', '_', $code) ?: 'poste_' . $postId;
}

/**
 * Devuelve el PDF de un poste, desde la caché si sigue vigente.
 *
 * Es el punto de entrada que deben usar los endpoints. `buildPostReport()` se
 * mantiene intacto y público porque compone el documento; no sabe nada de caché
 * ni de disco.
 *
 * Si el almacén de PDF no se puede escribir, el reporte se genera y se devuelve
 * igualmente: un problema de caché nunca debe impedir una descarga.
 *
 * ## Caché y fuente de fotografías
 *
 * Cada combinación de fuente y selección es un PDF distinto y se cachea por
 * separado: la huella incluye la fuente y los slots excluidos, y el nombre del
 * archivo lleva la huella dentro. Por eso no existe el caso «me devolvió el PDF
 * de otra configuración»: una configuración distinta busca otro archivo.
 *
 * Al escribir se poda la carpeta con `prunePostPdfCache()` en vez de vaciarla,
 * para que el reporte NAS y el Backup del mismo poste puedan convivir. Vaciarla
 * sigue siendo lo correcto cuando cambia el contenido, y eso lo sigue haciendo
 * `invalidatePostPdfCache()` desde donde llega una fotografía o se guarda el
 * formulario.
 *
 * @param array $options {photo_source: auto|current|backup, slots: list|null,
 *                        excluded_slots: list}
 * @return array{contenido:string,desde_cache:bool,ms:float,codigo:string}
 */
function renderPostReport(PDO $connection, int $postId, int $projectId, array $options = []): array
{
    // Un proyecto con plantilla tiene su propio formulario, fotos y diseño: el
    // reporte histórico no sabría dibujarlo. La selección de fotos por
    // estructura y la fuente Backup son exclusivas del flujo fijo.
    $template = plantillaDeProyecto($connection, $projectId);
    if ($template !== null) {
        return plantillaRenderPdfPoste($connection, $postId, $projectId, $template);
    }

    $started = hrtime(true);
    // Si quien pide el reporte no impuso una selección propia, manda la que el
    // administrador guardó para esa estructura y esa fuente. Sin configuración
    // guardada la lista queda vacía y se imprime todo lo disponible.
    $photoOptions = reportPhotoApplySavedConfig($options, $postId, $projectId);
    $cacheEnabled = envValue('PDF_CACHE_ENABLED', true) === true;
    $resolved = applyReportPhotoOptions(
        resolveStructureResources($connection, $postId, $projectId),
        $photoOptions
    );

    $fingerprint = '';
    $cacheable = false;
    if ($cacheEnabled) {
        try {
            $fingerprint = postReportFingerprint(
                $connection,
                $postId,
                $projectId,
                $resolved
            );
            $cacheable = true;
            $cacheFile = pdfCacheFile($postId, $fingerprint);
            if (is_file($cacheFile) && filesize($cacheFile) > 0) {
                $contents = @file_get_contents($cacheFile);
                if (is_string($contents) && $contents !== '') {
                    appMetric('pdf servido desde cache', [
                        'poste' => $postId,
                        'kb' => (int) round(strlen($contents) / 1024),
                        'ms' => elapsedMs($started),
                    ]);

                    return [
                        'contenido' => $contents,
                        'desde_cache' => true,
                        'ms' => elapsedMs($started),
                        'codigo' => postReportFileCode($connection, $postId, $projectId),
                        'source' => $resolved['source'],
                        'recovered_files' => $resolved['backup_used'],
                        'missing_files' => count($resolved['missing']),
                        'photo_options' => $resolved['photo_options'],
                    ];
                }
            }
        } catch (Throwable $exception) {
            appLog('almacenamiento', 'No se pudo consultar la cache de PDF.', [
                'poste' => $postId,
                'motivo' => $exception->getMessage(),
            ]);
            $cacheable = false;
        }
    }

    $pdf = buildPostReport($connection, $postId, $projectId, $resolved);
    $contents = $pdf->Output('S');
    $code = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $pdf->codigoPoste) ?: 'poste_' . $postId;

    if ($cacheable && $contents !== '') {
        try {
            writeFileAtomic(pdfCacheFile($postId, $fingerprint, true), $contents);
            // Se poda en vez de vaciar: el mismo poste tiene un reporte válido
            // por fuente, y borrarlos todos haría que alternar NAS/Backup no
            // acertara nunca la caché. La huella va en el nombre, así que
            // conservar varios no puede servir un PDF equivocado.
            prunePostPdfCache($postId);
        } catch (Throwable $exception) {
            appLog('almacenamiento', 'No se pudo guardar el PDF en la cache.', [
                'poste' => $postId,
                'motivo' => $exception->getMessage(),
            ]);
        }
    }

    appMetric('pdf generado', [
        'poste' => $postId,
        'kb' => (int) round(strlen($contents) / 1024),
        'ms' => elapsedMs($started),
        'cacheado' => $cacheable,
        'source' => $resolved['source'],
        'recovered_files' => $resolved['backup_used'],
        'missing_files' => count($resolved['missing']),
    ]);
    // Auditoría de la decisión: qué fuente se pidió y cuántas fotografías
    // entraron o se dejaron fuera. Sin rutas y sin datos de sesión.
    appLog('sincronizacion', 'Reporte de estructura resuelto.', [
        'project' => $projectId,
        'structure' => $postId,
        'action' => 'generate_pdf',
        'source' => $resolved['source'],
        'photo_source' => $resolved['photo_options']['photo_source'],
        'form_state' => $resolved['photo_options']['form_state'],
        'photos_included' => $resolved['photo_options']['incluidas'],
        'photos_excluded' => $resolved['photo_options']['excluidas'],
        'recovered_files' => $resolved['backup_used'],
        'missing_files' => count($resolved['missing']),
    ]);

    return [
        'contenido' => $contents,
        'desde_cache' => false,
        'ms' => elapsedMs($started),
        'codigo' => $code,
        'source' => $resolved['source'],
        'recovered_files' => $resolved['backup_used'],
        'missing_files' => count($resolved['missing']),
        'photo_options' => $resolved['photo_options'],
    ];
}
