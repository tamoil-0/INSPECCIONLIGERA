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
    public function __construct($orientation = 'P', $unit = 'mm', $size = 'A4') {
        parent::__construct($orientation, $unit, $size);
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
$this->Cell(22, 7, $d($this->utm_x), 0, 0);

// UTM N
$this->SetFont('Arial', '', 9);
$this->Cell(12, 7, $d('UTM N:'), 0, 0);
$this->SetFont('Arial', 'B', 9);
$this->Cell(20, 7, $d(trim($this->utm_y . ' ' . $this->zona)), 0, 0);

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
