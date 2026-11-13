<?php
declare(strict_types=1);

require_once __DIR__ . '/PDFPoste.php';
require_once __DIR__ . '/optimizador_imagenes.php';

/**
 * Reporte de una estructura dibujado a partir de una plantilla.
 *
 * Hereda de PDFPoste el sello UTM/fecha de las fotos y la transparencia; solo
 * cambia el encabezado (logos, título y colores de la plantilla) y añade el
 * dibujo genérico de cuadros sobre la rejilla de la hoja 1.
 *
 * El aspecto imita al reporte histórico a propósito: cabecera de color con
 * texto blanco, filas «opción | X» con borde fino y fuente Arial 7.
 */
class PDFPlantilla extends PDFPoste
{
    /** Configuración `pdf.encabezado` ya normalizada. */
    public array $encabezado = [];
    public ?string $rutaLogoIzquierdo = null;
    public ?string $rutaLogoDerecho = null;

    public const MARGEN = 10.0;
    public const ANCHO_UTIL = 190.0;
    public const LIMITE_INFERIOR = 287.0;

    public static function txt(mixed $texto): string
    {
        $t = (string) ($texto ?? '');
        $convertido = @iconv('UTF-8', 'windows-1252//TRANSLIT', $t);
        return $convertido === false ? (string) mb_convert_encoding($t, 'ISO-8859-1', 'UTF-8') : $convertido;
    }

    /** '#RRGGBB' → [r, g, b] */
    public static function rgb(string $hex): array
    {
        $h = ltrim($hex, '#');
        if (!preg_match('/^[0-9A-Fa-f]{6}$/', $h)) {
            return [0, 0, 0];
        }
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    /** Blanco o casi negro, el que mejor se lea sobre `$hex`. */
    public static function textoSobre(string $hex): array
    {
        [$r, $g, $b] = self::rgb($hex);
        $luz = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
        return $luz > 0.62 ? [33, 37, 41] : [255, 255, 255];
    }

    public function relleno(string $hex): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $this->SetFillColor($r, $g, $b);
    }

    /** Tamaño de letra más grande (≤ $inicio) con el que `$texto` cabe en `$ancho`. */
    public function ajustarFuente(string $texto, float $ancho, float $inicio, float $minimo, string $estilo = ''): float
    {
        $tam = $inicio;
        $this->SetFont('Arial', $estilo, $tam);
        while ($tam > $minimo && $this->GetStringWidth($texto) > $ancho) {
            $tam -= 0.25;
            $this->SetFont('Arial', $estilo, $tam);
        }
        return $tam;
    }

    /** Recorta con «…» lo que aún no cabe con la fuente actual. */
    public function recortar(string $texto, float $ancho): string
    {
        if ($this->GetStringWidth($texto) <= $ancho) {
            return $texto;
        }
        while ($texto !== '' && $this->GetStringWidth($texto . '...') > $ancho) {
            $texto = substr($texto, 0, -1);
        }
        return $texto . '...';
    }

    public function Header()
    {
        $e = $this->encabezado;
        $yLogo = 6.0;
        $anchoLogo = 35.0;
        $altoLogo = 13.0;
        if ($this->rutaLogoIzquierdo !== null) {
            $this->Image($this->rutaLogoIzquierdo, self::MARGEN, $yLogo, $anchoLogo, $altoLogo, 'PNG');
        }
        if ($this->rutaLogoDerecho !== null) {
            $this->Image($this->rutaLogoDerecho, self::MARGEN + self::ANCHO_UTIL - $anchoLogo, $yLogo, $anchoLogo, $altoLogo, 'PNG');
        }

        // Título centrado entre los logos; se achica en vez de pisarlos.
        $this->SetTextColor(0);
        $anchoTitulo = self::ANCHO_UTIL - 2 * ($anchoLogo + 3);
        $titulo = self::txt($e['titulo'] ?? '');
        $subtitulo = self::txt($e['subtitulo'] ?? '');
        $lineas = array_values(array_filter([$titulo, $subtitulo], static fn($l) => $l !== ''));
        $this->SetY($lineas !== [] && count($lineas) === 1 ? $yLogo + 4 : $yLogo + 1.5);
        foreach ($lineas as $linea) {
            $this->ajustarFuente($linea, $anchoTitulo, 10, 6.5, 'BI');
            $this->SetX(self::MARGEN + $anchoLogo + 3);
            $this->Cell($anchoTitulo, 5, $linea, 0, 1, 'C');
        }
        $this->SetY(max($this->GetY(), $yLogo + $altoLogo) + 1);

        // Fila 1: proyecto | etiqueta | código
        $this->relleno($e['color_barra'] ?? '#0A2D64');
        [$tr, $tg, $tb] = self::rgb($e['color_texto_barra'] ?? '#FFFFFF');
        $this->SetTextColor($tr, $tg, $tb);
        $this->SetDrawColor(0);
        $this->SetLineWidth(0.3);
        $proyecto = self::txt(mb_strtoupper((string) $this->tituloProyecto));
        $this->ajustarFuente($proyecto, 127, 11, 7, 'B');
        $this->Cell(130, 8, $this->recortar($proyecto, 127), 1, 0, 'L', true);
        $this->SetFillColor(230);
        $this->SetTextColor(0);
        $this->SetFont('Arial', 'B', 10);
        $this->Cell(10, 8, self::txt($e['etiqueta_codigo'] ?? 'IL'), 1, 0, 'C', true);
        $codigo = self::txt($this->codigoPoste);
        $this->ajustarFuente($codigo, 48, 11, 7, 'B');
        $this->Cell(50, 8, $codigo, 1, 1, 'C');

        // Fila 2: contratista, ubicación y UTM
        $par = function (string $etiqueta, string $valor, float $anchoEtiqueta, float $anchoValor): void {
            $this->SetFont('Arial', '', 9);
            $this->Cell($anchoEtiqueta, 7, self::txt($etiqueta), 0, 0);
            $this->SetFont('Arial', 'B', 9);
            $v = self::txt($valor);
            $this->ajustarFuente($v, $anchoValor - 1, 9, 6.5, 'B');
            $this->Cell($anchoValor, 7, $this->recortar($v, $anchoValor - 1), 0, 0);
        };
        $par('Contratista:', (string) $this->contratista, 20, 30);
        $par('Ubicación:', (string) $this->ubicacion, 17, 51);
        $par('UTM E:', $this->numeroEncabezado($this->utm_x), 12, 22);
        $par('UTM N:', trim($this->numeroEncabezado($this->utm_y) . ' ' . $this->zona), 12, 26);
        $this->Ln(6);

        // Fila 3: fecha y página
        $this->SetFont('Arial', '', 9);
        $this->Cell(120, 6, self::txt('Fecha de Inspección: ' . $this->fecha_inspeccion), 0, 0);
        $this->Cell(0, 6, self::txt('Página: ') . $this->PageNo(), 0, 1, 'R');
        $this->SetDrawColor(0);
        $this->Line(self::MARGEN, $this->GetY(), self::MARGEN + self::ANCHO_UTIL, $this->GetY());
        $this->Ln(2);
    }

    private function numeroEncabezado(mixed $valor): string
    {
        $v = trim((string) $valor);
        return ($v !== '' && is_numeric($v)) ? number_format((float) $v, 2, '.', '') : $v;
    }
}

/**
 * Ruta de una foto de la estructura. La vista previa del panel usa una imagen
 * de muestra fuera del almacén (`ruta_muestra`), que `reportImagePath()` no
 * aceptaría por seguridad; las fotos reales siempre pasan por esa validación.
 */
function plantillaRutaFoto(array $foto): ?string
{
    if (isset($foto['ruta_muestra']) && is_string($foto['ruta_muestra']) && is_file($foto['ruta_muestra'])) {
        return $foto['ruta_muestra'];
    }
    return reportImagePath($foto);
}

// ---------------------------------------------------------------------------
// Hoja 1: cuadros sobre la rejilla
// ---------------------------------------------------------------------------

/**
 * Dibuja todos los cuadros de la hoja 1.
 *
 * @param array $fotoPanoramica ['ruta' => string|null, 'datos' => array|null]
 */
function plantillaPdfHoja1(PDFPlantilla $pdf, array $config, array $respuestas, array $fotoPanoramica): void
{
    $campos = plantillaCampos($config);
    $hoja = $config['pdf']['hoja1'];
    $columnas = (int) $hoja['columnas'];
    $filas = (int) $hoja['filas'];
    $x0 = PDFPlantilla::MARGEN;
    $y0 = $pdf->GetY();
    $anchoCol = PDFPlantilla::ANCHO_UTIL / $columnas;
    // La rejilla mide 240 mm de alto (48 filas de 5 o 96 de 2,5), o lo que quede.
    $altoFila = min(240.0, PDFPlantilla::LIMITE_INFERIOR - $y0) / $filas;
    $hueco = 1.2; // separación entre cuadros vecinos (la mitad por lado)

    // Sin salto automático: un cuadro nunca debe empujar al siguiente a otra hoja.
    $pdf->SetAutoPageBreak(false);
    foreach ($hoja['cuadros'] as $cuadro) {
        $x = $x0 + $cuadro['x'] * $anchoCol + $hueco / 2;
        $y = $y0 + $cuadro['y'] * $altoFila + $hueco / 2;
        $w = $cuadro['w'] * $anchoCol - $hueco;
        $h = $cuadro['h'] * $altoFila - $hueco;
        match ($cuadro['tipo']) {
            'panoramica' => plantillaPdfPanoramica($pdf, $cuadro, $x, $y, $w, $h, $fotoPanoramica),
            'texto' => plantillaPdfCuadroTexto($pdf, $cuadro, $x, $y, $w, $h),
            default => plantillaPdfCuadroCampos($pdf, $cuadro, $campos, $respuestas, $x, $y, $w, $h),
        };
    }
    $pdf->SetAutoPageBreak(true, 10);
}

/** Banda de título del cuadro. Devuelve su alto. */
function plantillaPdfTitulo(PDFPlantilla $pdf, string $titulo, string $color, float $x, float $y, float $w, array $celdasExtra = []): float
{
    $alto = 6.0;
    $pdf->relleno($color);
    [$r, $g, $b] = PDFPlantilla::textoSobre($color);
    $pdf->SetTextColor($r, $g, $b);
    $pdf->SetDrawColor(0);
    $pdf->SetLineWidth(0.2);
    $anchoExtra = count($celdasExtra) * min(6.0, $w * 0.12);
    $anchoTitulo = $w - $anchoExtra;
    $texto = PDFPlantilla::txt($titulo);
    $tam = $pdf->ajustarFuente($texto, $anchoTitulo - 1.5, 8, 6, 'B');
    if ($pdf->GetStringWidth($texto) > $anchoTitulo - 1.5) {
        // No cabe ni con letra mínima: dos líneas, como «Marcado\nDe Árboles».
        $alto = 8.0;
        $pdf->SetXY($x, $y);
        $pdf->Cell($anchoTitulo, $alto, '', 1, 0, 'C', true);
        $pdf->SetXY($x, $y + 0.4);
        $pdf->MultiCell($anchoTitulo, 3.6, $texto, 0, 'C');
    } else {
        $pdf->SetXY($x, $y);
        $pdf->Cell($anchoTitulo, $alto, $texto, 1, 0, 'C', true);
    }
    $pdf->SetFont('Arial', 'B', min(8, $tam + 0.5));
    $xe = $x + $anchoTitulo;
    foreach ($celdasExtra as $extra) {
        $pdf->SetXY($xe, $y);
        $pdf->Cell($anchoExtra / count($celdasExtra), $alto, PDFPlantilla::txt($extra), 1, 0, 'C', true);
        $xe += $anchoExtra / count($celdasExtra);
    }
    $pdf->SetTextColor(0);
    return $alto;
}

function plantillaPdfCuadroCampos(PDFPlantilla $pdf, array $cuadro, array $campos, array $respuestas, float $x, float $y, float $w, float $h): void
{
    $lista = array_values(array_filter(
        array_map(static fn(string $k) => $campos[$k] ?? null, $cuadro['campos'] ?? []),
        static fn($c) => $c !== null
    ));
    $titulo = $cuadro['titulo'] !== '' ? $cuadro['titulo'] : ($lista[0]['etiqueta'] ?? '');
    $columnas = max(1, (int) ($cuadro['columnas'] ?? 1));
    $unico = count($lista) === 1 ? $lista[0] : null;

    // Tabla de fases: R | S | T en la misma banda del título.
    if ($unico !== null && $unico['tipo'] === 'rst') {
        $fases = $unico['fases'];
        $altoTitulo = plantillaPdfTitulo($pdf, $titulo, $cuadro['color'], $x, $y, $w, $fases);
        $anchoFase = (count($fases) * min(6.0, $w * 0.12)) / count($fases);
        $anchoEtiqueta = $w - $anchoFase * count($fases);
        $opciones = $unico['opciones'];
        $alto = plantillaPdfAltoFila($h - $altoTitulo, count($opciones));
        $valor = (array) ($respuestas[$unico['clave']] ?? []);
        $yy = $y + $altoTitulo;
        foreach ($opciones as $op) {
            $txt = PDFPlantilla::txt($op['texto']);
            $pdf->ajustarFuente($txt, $anchoEtiqueta - 1.5, plantillaPdfFuente($alto), 5);
            $pdf->SetXY($x, $yy);
            $pdf->Cell($anchoEtiqueta, $alto, $pdf->recortar($txt, $anchoEtiqueta - 1.5), 1, 0);
            $pdf->SetFont('Arial', 'B', plantillaPdfFuente($alto));
            foreach ($fases as $fase) {
                $marcada = in_array($fase, (array) ($valor[$op['valor']] ?? []), true);
                $pdf->Cell($anchoFase, $alto, $marcada ? 'X' : '', 1, 0, 'C');
            }
            $yy += $alto;
        }
        return;
    }

    $altoTitulo = plantillaPdfTitulo($pdf, $titulo, $cuadro['color'], $x, $y, $w);
    $yCuerpo = $y + $altoTitulo;
    $altoCuerpo = $h - $altoTitulo;

    // Un solo campo con opciones: una fila «opción | X» por opción.
    if ($unico !== null && in_array($unico['tipo'], ['seleccion', 'multiple', 'si_no'], true)) {
        $opciones = $unico['tipo'] === 'si_no'
            ? [['valor' => 'si', 'texto' => 'Sí'], ['valor' => 'no', 'texto' => 'No']]
            : $unico['opciones'];
        $valor = $respuestas[$unico['clave']] ?? null;
        $marcadas = is_array($valor) ? $valor : ($valor === null ? [] : [$valor]);
        $porColumna = (int) ceil(count($opciones) / $columnas);
        $alto = plantillaPdfAltoFila($altoCuerpo, $porColumna);
        $anchoColumna = $w / $columnas;
        $anchoMarca = min(5.0, max(3.2, $anchoColumna * 0.2));
        foreach ($opciones as $i => $op) {
            $col = intdiv($i, $porColumna);
            $fila = $i % $porColumna;
            $cx = $x + $col * $anchoColumna;
            $cy = $yCuerpo + $fila * $alto;
            $txt = PDFPlantilla::txt($op['texto']);
            $pdf->ajustarFuente($txt, $anchoColumna - $anchoMarca - 1.5, plantillaPdfFuente($alto), 5);
            $pdf->SetXY($cx, $cy);
            $pdf->Cell($anchoColumna - $anchoMarca, $alto, $pdf->recortar($txt, $anchoColumna - $anchoMarca - 1.5), 1, 0);
            $pdf->SetFont('Arial', 'B', plantillaPdfFuente($alto));
            $pdf->Cell($anchoMarca, $alto, in_array($op['valor'], $marcadas, true) ? 'X' : '', 1, 0, 'C');
        }
        return;
    }

    // Varios campos (o uno de texto/número): «Etiqueta: valor», valor con borde.
    if ($lista === []) {
        $pdf->Rect($x, $yCuerpo, $w, max(0.1, $altoCuerpo));
        return;
    }
    $porColumna = (int) ceil(count($lista) / $columnas);
    $alto = plantillaPdfAltoFila($altoCuerpo, $porColumna);
    $anchoColumna = $w / $columnas;
    $pdf->Rect($x, $yCuerpo, $w, $alto * $porColumna);
    foreach ($lista as $i => $campo) {
        $col = intdiv($i, $porColumna);
        $fila = $i % $porColumna;
        $cx = $x + $col * $anchoColumna;
        $cy = $yCuerpo + $fila * $alto;
        $anchoEtiqueta = $anchoColumna * 0.58;
        $anchoValor = $anchoColumna - $anchoEtiqueta;
        $etiqueta = PDFPlantilla::txt($campo['etiqueta'] . ':');
        $pdf->ajustarFuente($etiqueta, $anchoEtiqueta - 2, plantillaPdfFuente($alto), 5);
        $pdf->SetXY($cx + 1, $cy);
        $pdf->Cell($anchoEtiqueta - 1, $alto, $pdf->recortar($etiqueta, $anchoEtiqueta - 2), 0, 0);
        $valor = PDFPlantilla::txt(plantillaValorLegible($campo, $respuestas[$campo['clave']] ?? null));
        $pdf->ajustarFuente($valor, $anchoValor - 1.5, plantillaPdfFuente($alto), 5, 'B');
        $pdf->Cell($anchoValor, $alto, $pdf->recortar($valor, $anchoValor - 1.5), 1, 0, 'C');
    }
}

/** Alto de cada fila: llena el cuadro sin pasar de 5 mm (el del reporte histórico). */
function plantillaPdfAltoFila(float $altoDisponible, int $filas): float
{
    return max(2.6, min(5.0, $altoDisponible / max(1, $filas)));
}

function plantillaPdfFuente(float $altoFila): float
{
    return max(5.0, min(7.0, $altoFila * 1.4));
}

function plantillaPdfCuadroTexto(PDFPlantilla $pdf, array $cuadro, float $x, float $y, float $w, float $h): void
{
    $altoTitulo = $cuadro['titulo'] !== '' ? plantillaPdfTitulo($pdf, $cuadro['titulo'], $cuadro['color'], $x, $y, $w) : 0;
    $pdf->Rect($x, $y + $altoTitulo, $w, $h - $altoTitulo);
    $pdf->SetFont('Arial', '', 7);
    $pdf->SetTextColor(0);
    $pdf->SetXY($x + 1, $y + $altoTitulo + 1);
    $pdf->MultiCell($w - 2, 3.4, PDFPlantilla::txt($cuadro['texto'] ?? ''), 0, 'L');
}

function plantillaPdfPanoramica(PDFPlantilla $pdf, array $cuadro, float $x, float $y, float $w, float $h, array $foto): void
{
    $altoPie = 6.0;
    $altoImagen = $h - $altoPie;
    $ruta = $foto['ruta'] ?? null;
    $preparada = (is_string($ruta) && is_file($ruta)) ? redimensionarImagenTemporal($ruta, PHP_INT_MAX) : null;
    $pdf->SetDrawColor(0);
    $pdf->SetLineWidth(0.2);
    $pdf->Rect($x, $y, $w, $altoImagen);
    if ($preparada !== null) {
        plantillaPdfImagenEncajada($pdf, $preparada, $x, $y, $w, $altoImagen, $foto['datos'] ?? null);
        if ($preparada !== $ruta && is_file($preparada)) {
            @unlink($preparada);
        }
    } else {
        $pdf->SetFillColor(245, 245, 245);
        $pdf->Rect($x, $y, $w, $altoImagen, 'DF');
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetTextColor(120);
        $pdf->SetXY($x, $y + $altoImagen / 2 - 3);
        $pdf->Cell($w, 6, PDFPlantilla::txt('Sin foto panorámica'), 0, 0, 'C');
    }
    $pdf->relleno($cuadro['color'] ?: '#F0F0F0');
    [$r, $g, $b] = PDFPlantilla::textoSobre($cuadro['color'] ?: '#F0F0F0');
    $pdf->SetTextColor($r, $g, $b);
    $pdf->SetFont('Arial', 'B', 8);
    $pdf->SetXY($x, $y + $altoImagen);
    $pdf->Cell($w, $altoPie, PDFPlantilla::txt($cuadro['titulo'] !== '' ? $cuadro['titulo'] : 'Foto Panorámica'), 1, 0, 'C', true);
    $pdf->SetTextColor(0);
}

/**
 * Dibuja una foto dentro de un recuadro. `$llenar` recorta para cubrir todo el
 * recuadro (como la panorámica histórica, que nunca dejaba franjas); sin él la
 * foto se encaja entera y centrada.
 */
function plantillaPdfImagenEncajada(PDFPlantilla $pdf, string $ruta, float $x, float $y, float $w, float $h, ?array $datos, bool $llenar = false): void
{
    $info = @getimagesize($ruta);
    if (!$info || $info[0] <= 0 || $info[1] <= 0) {
        return;
    }
    $margen = 0.6;
    [$bx, $by, $bw, $bh] = [$x + $margen, $y + $margen, $w - 2 * $margen, $h - 2 * $margen];
    if ($llenar) {
        // FPDF no recorta: se dibuja estirada al recuadro, igual que el reporte histórico.
        $pdf->Image($ruta, $bx, $by, $bw, $bh);
        $pdf->selloGeoFoto($bx, $by, $bw, $bh, $datos);
        return;
    }
    // Fondo neutro: la foto entra entera, sin deformarse, y las franjas que
    // sobran no parecen un hueco.
    $pdf->SetFillColor(242, 242, 242);
    $pdf->Rect($bx, $by, $bw, $bh, 'F');
    $escala = min($bw / $info[0], $bh / $info[1]);
    $iw = $info[0] * $escala;
    $ih = $info[1] * $escala;
    $ix = $bx + ($bw - $iw) / 2;
    $iy = $by + ($bh - $ih) / 2;
    $pdf->Image($ruta, $ix, $iy, $iw, $ih);
    $pdf->selloGeoFoto($ix, $iy, $iw, $ih, $datos);
}

// ---------------------------------------------------------------------------
// Páginas siguientes: paneles de fotografías, comentarios y firmas
// ---------------------------------------------------------------------------

/**
 * @param array<string, list<array>> $fotosPorClave fotos de la estructura agrupadas por clave
 */
/** Columnas de la rejilla de las hojas de fotos y alto de cada fila (mm). */
const PLANTILLA_FOTO_COLUMNAS = 12;
const PLANTILLA_FOTO_FILA = 5;

/** Resolución de impresión de las fotos del PDF y límites del lado mayor que pide la app. */
const PLANTILLA_FOTO_DPI = 300;
const PLANTILLA_FOTO_LADO_MINIMO = 1600;
const PLANTILLA_FOTO_LADO_MAXIMO = 3000;

/** Inicio de las hojas de fotos (y de la rejilla de la hoja 1) bajo el encabezado, en mm. */
const PLANTILLA_Y0 = 42.5;

function plantillaMmAPx(float $mm): int
{
    return (int) round($mm / 25.4 * PLANTILLA_FOTO_DPI);
}

/**
 * Medida con la que se imprime cada foto, para que la app la encuadre y la
 * guarde con esa forma: así llena su recuadro del PDF sin franjas ni
 * deformación. Los píxeles son los de PLANTILLA_FOTO_DPI, con un lado mayor de
 * al menos PLANTILLA_FOTO_LADO_MINIMO para que se pueda ampliar una placa.
 *
 * Usa las mismas cuentas que plantillaPdfHoja1() (panorámica) y
 * plantillaPdfDistribuirFotos() (paneles). Una foto que el PDF no imprime en
 * un recuadro propio no trae formato y la app la toma como siempre.
 *
 * @return array<string, array{ancho_mm: float, alto_mm: float, ancho_px: int, alto_px: int}>
 */
function plantillaFormatosFoto(array $config): array
{
    $formato = static function (float $anchoMm, float $altoMm): ?array {
        if ($anchoMm <= 1 || $altoMm <= 1) {
            return null;
        }
        $ancho = $anchoMm / 25.4 * PLANTILLA_FOTO_DPI;
        $alto = $altoMm / 25.4 * PLANTILLA_FOTO_DPI;
        $mayor = max($ancho, $alto);
        $escala = $mayor < PLANTILLA_FOTO_LADO_MINIMO ? PLANTILLA_FOTO_LADO_MINIMO / $mayor
            : ($mayor > PLANTILLA_FOTO_LADO_MAXIMO ? PLANTILLA_FOTO_LADO_MAXIMO / $mayor : 1.0);
        return [
            'ancho_mm' => round($anchoMm, 1),
            'alto_mm' => round($altoMm, 1),
            'ancho_px' => (int) round($ancho * $escala),
            'alto_px' => (int) round($alto * $escala),
        ];
    };
    $margen = 2 * 0.6; // plantillaPdfImagenEncajada()
    $formatos = [];

    // Panorámica: su cuadro de la hoja 1, menos la separación y el pie.
    $hoja = $config['pdf']['hoja1'] ?? [];
    $columnas = max(1, (int) ($hoja['columnas'] ?? PLANTILLA_GRID_COLUMNAS));
    $filas = max(1, (int) ($hoja['filas'] ?? PLANTILLA_GRID_FILAS));
    $anchoCol = PDFPlantilla::ANCHO_UTIL / $columnas;
    $altoFila = min(240.0, PDFPlantilla::LIMITE_INFERIOR - PLANTILLA_Y0) / $filas;
    foreach ($hoja['cuadros'] ?? [] as $cuadro) {
        if (($cuadro['tipo'] ?? '') !== 'panoramica') {
            continue;
        }
        $f = $formato($cuadro['w'] * $anchoCol - 1.2 - $margen, $cuadro['h'] * $altoFila - 1.2 - 6.0 - $margen);
        foreach ($config['fotos'] ?? [] as $foto) {
            if (!empty($foto['panoramica']) && $f !== null) {
                $formatos[$foto['clave']] = $f;
            }
        }
        break;
    }

    // Paneles de las hojas 2+: ancho en columnas de 12 y alto propio o del panel.
    $anchoColFoto = PDFPlantilla::ANCHO_UTIL / PLANTILLA_FOTO_COLUMNAS;
    foreach (plantillaPdfPanelesEfectivos($config) as $panel) {
        $tituloAlto = trim((string) $panel['titulo']) !== '' ? 6.0 : 0.0;
        $maxFilas = (int) floor((PDFPlantilla::LIMITE_INFERIOR - PLANTILLA_Y0 - $tituloAlto) / PLANTILLA_FOTO_FILA);
        foreach ($panel['fotos'] as $clave) {
            if (isset($formatos[$clave])) {
                continue;
            }
            $t = plantillaPdfTamanoFoto($panel, $clave);
            $hi = $t['filas'] > $maxFilas ? $maxFilas * PLANTILLA_FOTO_FILA - $t['hn'] : $t['hi'];
            $f = $formato($t['w'] * $anchoColFoto - $margen, $hi - $margen);
            if ($f !== null) {
                $formatos[$clave] = $f;
            }
        }
    }
    return $formatos;
}

/**
 * Paneles que se imprimen: los configurados (o uno por categoría si no hay)
 * más «Otras fotografías» con las fotos que no están en ningún panel. Solo
 * los que tienen alguna foto.
 */
function plantillaPdfPanelesEfectivos(array $config): array
{
    $fotos = plantillaFotos($config);
    $paneles = $config['pdf']['paneles'];
    if ($paneles === []) {
        $porCategoria = [];
        foreach ($config['fotos'] as $f) {
            if (!$f['panoramica']) {
                $porCategoria[$f['categoria']][] = $f['clave'];
            }
        }
        foreach ($porCategoria as $categoria => $claves) {
            $paneles[] = ['titulo' => (string) $categoria, 'color' => '#7B7B7B', 'columnas' => 3, 'alto_foto' => 0,
                'fotos' => $claves, 'tamanos' => [], 'mostrar_nombres' => true, 'nueva_pagina' => false];
        }
    } else {
        $usadas = [];
        foreach ($paneles as $panel) {
            foreach ($panel['fotos'] as $clave) {
                $usadas[$clave] = true;
            }
        }
        $sueltas = [];
        foreach ($config['fotos'] as $f) {
            if (!$f['panoramica'] && !isset($usadas[$f['clave']])) {
                $sueltas[] = $f['clave'];
            }
        }
        if ($sueltas !== []) {
            $paneles[] = ['titulo' => 'Otras fotografías', 'color' => '#7B7B7B', 'columnas' => 3, 'alto_foto' => 0,
                'fotos' => $sueltas, 'tamanos' => [], 'mostrar_nombres' => true, 'nueva_pagina' => false];
        }
    }
    return array_values(array_filter($paneles, static function (array $panel) use ($fotos): bool {
        foreach ($panel['fotos'] as $clave) {
            if (isset($fotos[$clave])) {
                return true;
            }
        }
        return false;
    }));
}

/** Alto automático de la imagen según su ancho en columnas (múltiplo de 5 mm). */
function plantillaPdfAltoAuto(int $columnas): int
{
    $ancho = $columnas * PDFPlantilla::ANCHO_UTIL / PLANTILLA_FOTO_COLUMNAS;
    return max(20, min(70, (int) round($ancho * 0.79 / 5) * 5));
}

/** Tamaño de una foto en su panel: propio o el del panel. Igual que tamanoFoto() en plantillas.js. */
function plantillaPdfTamanoFoto(array $panel, string $clave): array
{
    $porFila = max(1, min(4, (int) ($panel['columnas'] ?? 3)));
    // Recién normalizado es un objeto (para que el JSON sea {}); leído de la base, un arreglo.
    $tamanos = $panel['tamanos'] ?? [];
    $tamanos = is_object($tamanos) ? (array) $tamanos : (is_array($tamanos) ? $tamanos : []);
    $t = $tamanos[$clave] ?? null;
    $t = is_array($t) ? $t : (is_object($t) ? (array) $t : []);
    $w = (int) ($t['w'] ?? 0) > 0 ? max(2, min(12, (int) $t['w'])) : intdiv(PLANTILLA_FOTO_COLUMNAS, $porFila);
    $base = (int) ($t['h'] ?? 0) > 0
        ? (int) $t['h']
        : ((int) ($panel['alto_foto'] ?? 0) > 0 ? (int) $panel['alto_foto'] : plantillaPdfAltoAuto($w));
    $hi = max(20, min(220, (int) round($base / 5) * 5));
    $hn = ($panel['mostrar_nombres'] ?? true) ? 5 : 0;
    return ['w' => $w, 'hi' => $hi, 'hn' => $hn, 'filas' => intdiv($hi + $hn, PLANTILLA_FOTO_FILA)];
}

/** Primer hueco libre de w×filas en la rejilla ocupada de un tramo. */
function plantillaPdfHuecoFoto(array $ocupado, int $capacidad, int $w, int $filas): ?array
{
    for ($r = 0; $r + $filas <= $capacidad; $r++) {
        for ($c = 0; $c + $w <= PLANTILLA_FOTO_COLUMNAS; $c++) {
            $libre = true;
            for ($rr = $r; $rr < $r + $filas && $libre; $rr++) {
                for ($cc = $c; $cc < $c + $w; $cc++) {
                    if (!empty($ocupado[$rr][$cc])) {
                        $libre = false;
                        break;
                    }
                }
            }
            if ($libre) {
                return ['r' => $r, 'c' => $c];
            }
        }
    }
    return null;
}

/**
 * Distribuye los paneles en hojas. Cada foto ocupa w columnas (de 12) y un
 * alto múltiplo de 5 mm, y se ubica en el primer hueco libre: así las fotos
 * chicas llenan los espacios que dejan las grandes. Misma lógica que
 * paginarFotos() en web/js/plantillas.js, para que la vista previa coincida.
 */
function plantillaPdfDistribuirFotos(array $config, array $paneles, float $y0): array
{
    $fotos = plantillaFotos($config);
    $limite = PDFPlantilla::LIMITE_INFERIOR;
    $fila = PLANTILLA_FOTO_FILA;
    $anchoCol = PDFPlantilla::ANCHO_UTIL / PLANTILLA_FOTO_COLUMNAS;
    $paginas = [];
    $p = -1;
    $y = $y0;
    $nueva = static function () use (&$paginas, &$p, &$y, $y0): void {
        $paginas[] = [];
        $p = count($paginas) - 1;
        $y = $y0;
    };
    $primero = true;
    foreach ($paneles as $ip => $panel) {
        $celdas = [];
        foreach ($panel['fotos'] as $clave) {
            $def = $fotos[$clave] ?? null;
            if ($def === null) {
                continue;
            }
            $t = plantillaPdfTamanoFoto($panel, $clave);
            $n = max(1, (int) $def['cantidad']);
            for ($i = 1; $i <= $n; $i++) {
                $celdas[] = $t + ['clave' => $clave, 'indice' => $i, 'nombre' => $def['nombre'] . ($n > 1 ? " ({$i})" : '')];
            }
        }
        if ($celdas === []) {
            continue;
        }
        if ($p < 0) {
            $nueva();
        }
        $alto = trim((string) $panel['titulo']) !== '' ? 6.0 : 0.0;
        $maxFilas = (int) floor(($limite - $y0 - $alto) / $fila);
        foreach ($celdas as &$c) {
            if ($c['filas'] > $maxFilas) {
                $c['filas'] = $maxFilas;
                $c['hi'] = $maxFilas * $fila - $c['hn'];
            }
        }
        unset($c);
        if (!$primero && (!empty($panel['nueva_pagina']) || $y + $alto + $celdas[0]['filas'] * $fila > $limite)) {
            $nueva();
        }
        $primero = false;

        $tope = 0.0;
        $capacidad = 0;
        $ocupado = [];
        $usadas = 0;
        $tramo = static function () use (&$paginas, &$p, &$y, &$tope, &$capacidad, &$ocupado, &$usadas, $alto, $ip, $limite, $fila): void {
            if ($alto > 0) {
                $paginas[$p][] = ['tipo' => 'titulo', 'panel' => $ip, 'y' => $y];
            }
            $tope = $y + $alto;
            $capacidad = (int) floor(($limite - $tope) / $fila);
            $ocupado = [];
            $usadas = 0;
        };
        $tramo();
        foreach ($celdas as $c) {
            $pos = plantillaPdfHuecoFoto($ocupado, $capacidad, $c['w'], $c['filas']);
            if ($pos === null) {
                $nueva();
                $tramo();
                $pos = plantillaPdfHuecoFoto($ocupado, $capacidad, $c['w'], $c['filas']);
            }
            for ($rr = $pos['r']; $rr < $pos['r'] + $c['filas']; $rr++) {
                for ($cc = $pos['c']; $cc < $pos['c'] + $c['w']; $cc++) {
                    $ocupado[$rr][$cc] = true;
                }
            }
            $usadas = max($usadas, $pos['r'] + $c['filas']);
            $paginas[$p][] = $c + [
                'tipo' => 'celda', 'panel' => $ip,
                'x' => PDFPlantilla::MARGEN + $pos['c'] * $anchoCol,
                'y' => $tope + $pos['r'] * $fila,
                'ancho' => $c['w'] * $anchoCol,
            ];
        }
        $y = $tope + $usadas * $fila + 4;
    }
    return ['paginas' => $paginas, 'y' => $y];
}

function plantillaPdfPaneles(PDFPlantilla $pdf, array $config, array $fotosPorClave): void
{
    $paneles = plantillaPdfPanelesEfectivos($config);
    if ($paneles === []) {
        return;
    }
    $pdf->AddPage();
    // 42,5 mm = inicio que usa la vista previa del editor (HOJA.y0); así la
    // paginación del PDF y la de la pantalla son idénticas.
    $distribucion = plantillaPdfDistribuirFotos($config, $paneles, max($pdf->GetY(), PLANTILLA_Y0));
    $pdf->SetAutoPageBreak(false);
    foreach ($distribucion['paginas'] as $n => $items) {
        if ($n > 0) {
            $pdf->AddPage();
        }
        foreach ($items as $item) {
            $panel = $paneles[$item['panel']];
            if ($item['tipo'] === 'titulo') {
                $pdf->relleno($panel['color']);
                [$r, $g, $b] = PDFPlantilla::textoSobre($panel['color']);
                $pdf->SetTextColor($r, $g, $b);
                $pdf->SetFont('Arial', 'B', 7);
                $pdf->SetDrawColor(0);
                $pdf->SetXY(PDFPlantilla::MARGEN, $item['y']);
                $pdf->Cell(PDFPlantilla::ANCHO_UTIL, 6, PDFPlantilla::txt(mb_strtoupper($panel['titulo'])), 1, 0, 'C', true);
                $pdf->SetTextColor(0);
                continue;
            }
            $celda = ['nombre' => $item['nombre'], 'foto' => $fotosPorClave[$item['clave']][$item['indice']] ?? null];
            plantillaPdfCeldaFoto($pdf, $celda, $panel, $item['x'], $item['y'], $item['ancho'], (float) $item['hi'], (float) $item['hn']);
        }
    }
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->SetY($distribucion['y']);
}

function plantillaPdfCeldaFoto(PDFPlantilla $pdf, array $celda, array $panel, float $x, float $y, float $w, float $altoImagen, float $altoNombre): void
{
    $pdf->SetDrawColor(0);
    $pdf->SetLineWidth(0.2);
    $pdf->Rect($x, $y, $w, $altoImagen + $altoNombre);
    $foto = $celda['foto'];
    $ruta = $foto !== null ? plantillaRutaFoto($foto) : null;
    $info = $ruta !== null ? @getimagesize($ruta) : false;
    $preparada = null;
    if ($info && $info[0] > 0 && $info[1] > 0) {
        // Ancho con el que la foto entra entera en la celda, a PLANTILLA_FOTO_DPI.
        $anchoPx = (int) ceil(min(plantillaMmAPx($w), plantillaMmAPx($altoImagen) * $info[0] / $info[1]));
        $preparada = redimensionarImagenTemporal($ruta, max(400, $anchoPx), 88);
    }
    if ($preparada) {
        plantillaPdfImagenEncajada($pdf, $preparada, $x, $y, $w, $altoImagen, $foto);
        if ($preparada !== $ruta && is_file($preparada)) {
            @unlink($preparada);
        }
    } else {
        $pdf->SetFillColor(247, 247, 247);
        $pdf->Rect($x + 0.3, $y + 0.3, $w - 0.6, $altoImagen - 0.6, 'F');
        $pdf->SetFont('Arial', 'B', 6);
        $pdf->SetTextColor(130);
        $pdf->SetXY($x, $y + $altoImagen / 2 - 2);
        $pdf->Cell($w, 4, PDFPlantilla::txt('No tiene foto'), 0, 0, 'C');
    }
    if ($altoNombre > 0) {
        $pdf->relleno($panel['color']);
        [$r, $g, $b] = PDFPlantilla::textoSobre($panel['color']);
        $pdf->SetTextColor($r, $g, $b);
        $nombre = PDFPlantilla::txt($celda['nombre']);
        $pdf->ajustarFuente($nombre, $w - 2, 6.5, 5, 'B');
        $pdf->SetXY($x, $y + $altoImagen);
        $pdf->Cell($w, $altoNombre, $pdf->recortar($nombre, $w - 2), 1, 0, 'C', true);
    }
    $pdf->SetTextColor(0);
}

function plantillaPdfComentarios(PDFPlantilla $pdf, array $config, array $respuestas): void
{
    $clave = $config['pdf']['comentarios']['campo'] ?? null;
    if ($clave === null) {
        return;
    }
    $texto = trim((string) (is_scalar($respuestas[$clave] ?? null) ? $respuestas[$clave] : ''));
    if ($pdf->GetY() + 30 > PDFPlantilla::LIMITE_INFERIOR) {
        $pdf->AddPage();
    }
    $pdf->SetX(PDFPlantilla::MARGEN);
    $pdf->SetFillColor(200, 200, 200);
    $pdf->SetTextColor(0);
    $pdf->SetDrawColor(0);
    $pdf->SetFont('Arial', 'B', 10);
    $pdf->Cell(PDFPlantilla::ANCHO_UTIL, 8, PDFPlantilla::txt($config['pdf']['comentarios']['titulo']), 1, 1, 'C', true);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetX(PDFPlantilla::MARGEN);
    $pdf->MultiCell(PDFPlantilla::ANCHO_UTIL, 6, PDFPlantilla::txt($texto !== '' ? $texto : 'Sin comentarios u observaciones.'), 1, 'L');
    $pdf->Ln(6);
}

function plantillaPdfFirmas(PDFPlantilla $pdf, array $config, int $plantillaId = 0): void
{
    $firmas = $config['pdf']['firmas'] ?? [];
    if ($firmas === []) {
        return;
    }
    if ($pdf->GetY() + 48 > PDFPlantilla::LIMITE_INFERIOR) {
        $pdf->AddPage();
    }
    $pdf->Ln(22);
    $ancho = PDFPlantilla::ANCHO_UTIL / count($firmas);
    $yLinea = $pdf->GetY();
    $largo = min(65.0, $ancho - 12);
    $pdf->SetDrawColor(0);
    $pdf->SetLineWidth(0.2);
    $pdf->SetTextColor(0);
    foreach ($firmas as $i => $firma) {
        $x = PDFPlantilla::MARGEN + $i * $ancho;
        // Imagen de la firma: apoyada sobre la línea, centrada y desplazada
        // lo que el administrador eligió en el editor.
        $ruta = plantillaRutaLogo($plantillaId, $firma['imagen'] ?? null);
        $tam = $ruta !== null ? @getimagesize($ruta) : false;
        if ($tam && $tam[0] > 0 && $tam[1] > 0) {
            $alto = (float) ($firma['alto'] ?? 16);
            $anchoImg = $alto * $tam[0] / $tam[1];
            if ($anchoImg > $ancho - 4) {
                $anchoImg = $ancho - 4;
                $alto = $anchoImg * $tam[1] / $tam[0];
            }
            $cx = $x + $ancho / 2 + (float) ($firma['dx'] ?? 0);
            $base = $yLinea + 1 + (float) ($firma['dy'] ?? 0);
            $pdf->Image($ruta, $cx - $anchoImg / 2, $base - $alto, $anchoImg, $alto, 'PNG');
        }
        $pdf->Line($x + ($ancho - $largo) / 2, $yLinea, $x + ($ancho + $largo) / 2, $yLinea);
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetXY($x, $yLinea + 2);
        $pdf->Cell($ancho, 5, PDFPlantilla::txt($firma['nombre']), 0, 0, 'C');
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetXY($x, $yLinea + 7);
        $pdf->Cell($ancho, 5, PDFPlantilla::txt($firma['cargo']), 0, 0, 'C');
        $pdf->SetXY($x, $yLinea + 12);
        $pdf->Cell($ancho, 5, 'Fecha:', 0, 0, 'C');
    }
    $pdf->SetY($yLinea + 18);
}
