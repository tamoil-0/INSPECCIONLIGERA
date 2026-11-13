<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/LineReportZip.php';

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecoing_zip_test_' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$writer = null;
$reader = null;
try {
    $writer = new LineReportZip($root);
    $before = memory_get_usage(true);
    $contents = "%PDF-1.4\n" . str_repeat('PDF fixture ', 50000) . "\n%%EOF";
    $hash = hash('sha256', $contents);
    for ($i = 1; $i <= 300; $i++) {
        $writer->addPdf('poste_' . $i . '.pdf', $contents);
    }
    if (memory_get_usage(true) - $before > 32 * 1024 * 1024) {
        throw new RuntimeException('El lote de 300 archivos acumuló más de 32 MB en RAM.');
    }
    $archive = $writer->finish(['Poste de prueba: no se pudo generar.']);
    $reader = new ZipArchive();
    if ($reader->open($archive, ZipArchive::CHECKCONS) !== true || $reader->numFiles !== 301) {
        throw new RuntimeException('ZIP incompleto o ilegible.');
    }
    for ($i = 1; $i <= 300; $i++) {
        $data = $reader->getFromName('poste_' . $i . '.pdf');
        if (!is_string($data) || hash('sha256', $data) !== $hash) {
            throw new RuntimeException('PDF alterado en el ZIP: ' . $i);
        }
    }
    if ($reader->getFromName('errores.txt') !== 'Poste de prueba: no se pudo generar.') {
        throw new RuntimeException('Falta el informe de errores parciales.');
    }
    $reader->close();
    $reader = null;
    $writer->cleanup();
    $writer->cleanup();
    if (glob($root . DIRECTORY_SEPARATOR . '*') !== []) {
        throw new RuntimeException('Quedaron temporales tras completar el ZIP.');
    }
    $writer = new LineReportZip($root);
    $writer->addPdf('interrumpido.pdf', $contents);
    $writer->cleanup();
    if (glob($root . DIRECTORY_SEPARATOR . '*') !== []) {
        throw new RuntimeException('Quedaron temporales tras interrumpir el ZIP.');
    }
    echo "OK: 300 archivos (~172 MB), contenido íntegro, RAM acotada, errores parciales y limpieza.\n";
} finally {
    if ($reader !== null) $reader->close();
    if ($writer !== null) $writer->cleanup();
    @rmdir($root);
}
