<?php
require_once __DIR__ . '/../vendor/autoload.php';

class PDFPoste extends FPDF {

    public $tituloProyecto = "";
    public $codigoPoste = "";
    public $contratista = "";
    public $ubicacion = "";
    public $utm_x = "";
    public $utm_y = "";
    public $zona = "";
    public $fecha_inspeccion = "";
    public $fecha_inspeccion_sello = ""; // Fecha/hora que se estampa sobre las fotos

    // ▼▼ AJUSTE MANUAL DEL SELLO SOBRE LAS FOTOS ▼▼
    // Transparencia del fondo: 1 = blanco opaco, 0 = invisible (sugerido 0.40 - 0.70)
    public $opacidadSelloFoto = 0.55;
    // Color del fondo del sello (R, G, B)
    public $colorFondoSelloFoto = [255, 255, 255];
    // ▲▲ FIN DEL AJUSTE MANUAL ▲▲

    protected $extgstates = [];
    protected $extgstatesPorAlfa = [];
    public function __construct($orientation = 'P', $unit = 'mm', $size = 'A4') {
        parent::__construct($orientation, $unit, $size);
    }

    // Estampa coordenada UTM y fecha/hora sobre la esquina inferior izquierda
    // de una foto ya dibujada en el PDF. Dos líneas, sin etiquetas:
    //
    //     356214 8663180 19K
    //     03-09-2026 13:56
    //
    // $foto son los datos de ESA fotografía (`utm_este`, `utm_norte`, `zona`,
    // `fecha_captura`). Cada toma tiene su propia posición y su propia hora, así
    // que el sello se arma con ellos. Los valores de la estructura quedan sólo
    // como respaldo, para las fotografías antiguas que no los guardaron: sin ese
    // respaldo el sello desaparecería en los reportes históricos.
    public function selloGeoFoto($x, $y, $ancho, $alto, $foto = null) {
        $datos = is_array($foto) ? $foto : [];
        $lineas = [];

        $este = $this->formatoCoordenadaSello($datos['utm_este'] ?? '');
        $norte = $this->formatoCoordenadaSello($datos['utm_norte'] ?? '');
        $zona = (string) ($datos['zona'] ?? '');
        // El par se toma completo o no se toma: mezclar el este de la foto con
        // el norte de la estructura daría una coordenada que no existe.
        if ($este === '' || $norte === '') {
            $este = $this->formatoCoordenadaSello($this->utm_x);
            $norte = $this->formatoCoordenadaSello($this->utm_y);
            $zona = (string) $this->zona;
        }
        if ($este !== '' && $norte !== '') {
            $lineas[] = $este . ' ' . $norte . ' ' . $this->zonaUTMSello($zona, $norte);
        }

        $fecha = $this->formatoFechaSello($datos['fecha_captura'] ?? '');
        if ($fecha === '') {
            $fecha = trim((string) $this->fecha_inspeccion_sello);
        }
        if ($fecha !== '') {
            $lineas[] = $fecha;
        }
        if (!$lineas || $ancho <= 0 || $alto <= 0) {
            return;
        }

        // Guardar estado actual para no afectar lo que se dibuje después
        $fillPrevio = $this->FillColor;
        $drawPrevio = $this->DrawColor;
        $textPrevio = $this->TextColor;
        $flagPrevio = $this->ColorFlag;
        $familiaPrevia = $this->FontFamily;
        $estiloPrevio = $this->FontStyle;
        $tamanoPrevio = $this->FontSizePt;
        $xPrevio = $this->x;
        $yPrevio = $this->y;

        $tamano = max(4.0, min(7.0, $ancho / 9));
        $this->SetFont('Arial', 'B', $tamano);

        $anchoTexto = 0;
        foreach ($lineas as $linea) {
            $anchoTexto = max($anchoTexto, $this->GetStringWidth($linea));
        }
        $altoLinea = $this->FontSize + 0.9;
        $anchoCaja = min($ancho, $anchoTexto + 2.4);
        $altoCaja = (count($lineas) * $altoLinea) + 0.8;
        if ($altoCaja > $alto) {
            $altoCaja = $alto;
        }
        $xCaja = $x;
        $yCaja = $y + $alto - $altoCaja;

        $fondo = $this->colorFondoSelloFoto;
        $this->SetFillColor((int) $fondo[0], (int) $fondo[1], (int) $fondo[2]);
        $this->SetAlpha($this->opacidadSelloFoto);
        $this->Rect($xCaja, $yCaja, $anchoCaja, $altoCaja, 'F');
        $this->SetAlpha(1); // El texto siempre va opaco para que se lea bien
        $this->SetTextColor(0, 0, 0);
        foreach ($lineas as $indice => $linea) {
            $this->SetXY($xCaja + 0.8, $yCaja + 0.4 + ($indice * $altoLinea));
            $this->Cell($anchoCaja - 1.6, $altoLinea, utf8_decode($linea), 0, 0, 'L');
        }

        // Restaurar estado previo
        $this->FillColor = $fillPrevio;
        $this->DrawColor = $drawPrevio;
        $this->TextColor = $textPrevio;
        $this->ColorFlag = $flagPrevio;
        if ($this->page > 0) {
            $this->_out($fillPrevio);
            $this->_out($drawPrevio);
        }
        if ($familiaPrevia !== '') {
            $this->SetFont($familiaPrevia, $estiloPrevio, $tamanoPrevio);
        }
        $this->SetXY($xPrevio, $yPrevio);
    }

    // Zona UTM con banda de latitud (19L, 19K, ...) calculada desde el norte
    private function zonaUTMSello($zona, $utmNorte) {
        $numero = preg_replace('/\D/', '', (string) $zona);
        if ($numero === '') {
            $numero = '19';
        }
        $norte = (float) $utmNorte;
        if ($norte > 0 && $norte < 10000000) {
            $bandas = ['M', 'L', 'K', 'J', 'H', 'G', 'F', 'E', 'D', 'C'];
            $indice = (int) floor((10000000 - $norte) / 884600); // 8° de latitud por banda
            if ($indice < 0) {
                $indice = 0;
            }
            if ($indice > 9) {
                $indice = 9;
            }
            return $numero . $bandas[$indice];
        }
        $ultimo = strtoupper(substr(trim((string) $zona), -1));
        return $numero . (ctype_alpha($ultimo) ? $ultimo : '');
    }

    // `fecha_captura` llega como datetime de MySQL. Se muestra en el mismo
    // formato corto que ya usaba el sello, para no cambiar el aspecto del PDF
    // más de lo pedido. Una fecha vacía o inválida devuelve '' y deja que el
    // llamador recurra a la fecha de inspección de la estructura.
    private function formatoFechaSello($valor) {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return '';
        }
        $instante = strtotime($valor);
        if ($instante === false || $instante <= 0) {
            return '';
        }
        return date('d-m-Y H:i', $instante);
    }

    // === Soporte de transparencia (ExtGState) para FPDF ===
    public function SetAlpha($alfa, $modo = 'Normal') {
        $alfa = max(0, min(1, (float) $alfa));
        $clave = $modo . '|' . sprintf('%.3F', $alfa);
        if (!isset($this->extgstatesPorAlfa[$clave])) {
            $this->extgstates[] = ['ca' => $alfa, 'CA' => $alfa, 'BM' => '/' . $modo];
            $this->extgstatesPorAlfa[$clave] = count($this->extgstates);
        }
        $this->_out(sprintf('/GS%d gs', $this->extgstatesPorAlfa[$clave]));
    }

    protected function _enddoc() {
        if ($this->extgstates && $this->PDFVersion < '1.4') {
            $this->PDFVersion = '1.4';
        }
        parent::_enddoc();
    }

    protected function _putresources() {
        foreach ($this->extgstates as $indice => $estado) {
            $this->_newobj();
            $this->extgstates[$indice]['n'] = $this->n;
            $this->_put('<</Type /ExtGState');
            $this->_put(sprintf('/ca %.3F', $estado['ca']));
            $this->_put(sprintf('/CA %.3F', $estado['CA']));
            $this->_put('/BM ' . $estado['BM']);
            $this->_put('>>');
            $this->_put('endobj');
        }
        parent::_putresources();
    }

    protected function _putresourcedict() {
        parent::_putresourcedict();
        if ($this->extgstates) {
            $this->_put('/ExtGState <<');
            foreach ($this->extgstates as $indice => $estado) {
                $this->_put('/GS' . ($indice + 1) . ' ' . $estado['n'] . ' 0 R');
            }
            $this->_put('>>');
        }
    }

    // El UTM llega de la base como decimal(15,5), así que sin formatear sale
    // '387514.22000'. En el encabezado se muestra con dos decimales, que es la
    // precisión que se usa en campo. Un valor no numérico se devuelve tal cual:
    // mejor que se vea raro a que el encabezado lo borre sin avisar.
    private function formatoCoordenadaEncabezado($valor) {
        $valor = trim((string) $valor);
        if ($valor === '' || !is_numeric($valor)) {
            return $valor;
        }
        return number_format((float) $valor, 2, '.', '');
    }

    private function formatoCoordenadaSello($valor) {
        $valor = trim((string) $valor);
        if ($valor === '' || !is_numeric($valor) || (float) $valor == 0.0) {
            return '';
        }
        return number_format((float) $valor, 0, '.', '');
    }
    function Header() {
         // Función auxiliar para decodificar
    $d = function($txt) { return utf8_decode($txt); };

    // === Agregar imágenes lado a lado ===
  $leftImageX = 10;   // Logo izquierdo
$rightImageX = 170; // Logo derecho
$yImage = 6;        // Altura superior

// Logos
$this->Image(__DIR__ . '/images/electro.png', $leftImageX, $yImage, 35, 13);
$this->Image(__DIR__ . '/images/logo_intesel.png', $rightImageX, $yImage, 35, 13);
$this->SetTextColor(0);
// Posicionar el texto centrado entre los dos logos
$this->SetY($yImage + 5); // Subimos el texto para que quede alineado verticalmente al centro de los logos
$this->SetFont('Arial', 'BI', 10); // Negrita y cursiva

// Primera línea de texto
$this->Cell(0, 5, $d('SERVICIO DE MANTENIMIENTO DE LINEAS DE TRANSMISION'), 0, 1, 'C');

// Segunda línea de texto
$this->Cell(0, 5, $d('DE 60 KV DE ELECTRO PUNO S.A.A'), 0, 1, 'C');

// Eliminar saltos grandes, y solo agregar un salto mínimo si quieres
$this->Ln(1); // Puedes usar Ln(0) si quieres pegadísimo sin espacio.

// Colores y fuentes para el cuadro de proyecto
$this->SetFillColor(10, 45, 100); // Azul fondo
$this->SetTextColor(255);
$this->SetDrawColor(0); // Bordes negros
$this->SetLineWidth(0.3);
$this->SetFont('Arial', 'B', 11);

// === Fila 1: Nombre Proyecto + IL + Código ===
$this->Cell(130, 8, $d(strtoupper($this->tituloProyecto)), 1, 0, 'L', true);
    $this->SetFillColor(230); // gris claro para IL
    $this->SetTextColor(0);
    $this->SetFont('Arial', 'B', 10);
    $this->Cell(10, 8, $d('IL'), 1, 0, 'C', true);
    $this->SetFont('Arial', 'B', 11);
    $this->Cell(50, 8, $d($this->codigoPoste), 1, 1, 'C');
    
  // === Fila 2: Contratista | Ubicación | UTM E | UTM N ===
$this->SetFont('Arial', '', 9);
$this->SetTextColor(0);

// Contratista
$this->Cell(20, 7, $d('Contratista:'), 0, 0);
$this->SetFont('Arial', 'B', 9);
$this->Cell(25 ,7, $d($this->contratista), 0, 0);

// Ubicación (más alineado a la izquierda)
// Ubicación (en una sola celda, pegado)
$this->SetFont('Arial', '', 9);
$this->Cell(20, 7, $d('Ubicación:'), 0, 0); // Texto normal

$this->SetFont('Arial', 'B', 9);
$this->Cell(55, 7, $d($this->ubicacion), 0, 0); // Solo valor en negrita

// UTM E
$this->SetFont('Arial', '', 9);
$this->Cell(12, 7, $d('UTM E:'), 0, 0);
$this->SetFont('Arial', 'B', 9);
$this->Cell(22, 7, $d($this->formatoCoordenadaEncabezado($this->utm_x)), 0, 0);

// UTM N
$this->SetFont('Arial', '', 9);
$this->Cell(12, 7, $d('UTM N:'), 0, 0);
$this->SetFont('Arial', 'B', 9);
$this->Cell(20, 7, $d(trim($this->formatoCoordenadaEncabezado($this->utm_y) . ' ' . $this->zona)), 0, 0);

// 👉 Reduce el espacio vertical manualmente
$this->Ln(3.5); // Puedes probar con 0 si quieres que estén totalmente pegados

// === Fila 3: Fecha + Página
$this->SetFont('Arial', '', 9);
$this->Cell(120, 7, $d('Fecha de Inspección: ') . $d($this->fecha_inspeccion), 0, 0);
$this->Cell(0, 7, $d('Página: ') . $this->PageNo(), 0, 1, 'R');

        // === Línea de separación
        $this->SetDrawColor(0);
        $this->Line(10, $this->GetY(), 200, $this->GetY());
        $this->Ln(2);

        
    }
}
?>
