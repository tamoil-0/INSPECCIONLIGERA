<?php
declare(strict_types=1);

/** ZIP de reportes respaldado por disco: solo un PDF reside en memoria. */
final class LineReportZip
{
    private ZipArchive $zip;
    private string $directory;
    private string $archive;
    private array $files = [];
    private bool $open = false;

    public function __construct(string $exportRoot)
    {
        $this->directory = rtrim($exportRoot, '/\\') . DIRECTORY_SEPARATOR
            . 'line_zip_' . bin2hex(random_bytes(12));
        if (!mkdir($this->directory, 0700)) {
            throw new RuntimeException('No se pudo crear el directorio temporal del ZIP.');
        }
        $this->archive = $this->directory . DIRECTORY_SEPARATOR . 'reportes.zip';
        register_shutdown_function([$this, 'cleanup']);
        $this->zip = new ZipArchive();
        if ($this->zip->open($this->archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo iniciar el archivo ZIP.');
        }
        $this->open = true;
    }

    public function addPdf(string $name, string $contents): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . count($this->files) . '.pdf';
        $this->files[] = $path;
        if (file_put_contents($path, $contents) !== strlen($contents)) {
            throw new RuntimeException('No se pudo guardar el PDF temporal. Compruebe el espacio libre.');
        }
        if (!$this->zip->addFile($path, $name)) {
            throw new RuntimeException('No se pudo agregar el PDF al ZIP.');
        }
        // Las imágenes/PDF ya están comprimidos; evita recomprimirlos en el NAS.
        if (!$this->zip->setCompressionName($name, ZipArchive::CM_STORE)) {
            throw new RuntimeException('No se pudo configurar el PDF dentro del ZIP.');
        }
    }

    public function finish(array $errors = []): string
    {
        if ($errors && !$this->zip->addFromString('errores.txt', implode(PHP_EOL, $errors))) {
            throw new RuntimeException('No se pudo agregar el resumen de errores.');
        }
        if (!$this->zip->close()) {
            throw new RuntimeException('No se pudo completar el ZIP. Compruebe el espacio libre.');
        }
        $this->open = false;
        return $this->archive;
    }

    public function cleanup(): void
    {
        if ($this->open) {
            @$this->zip->close();
            $this->open = false;
        }
        foreach ($this->files as $file) {
            if (is_file($file)) @unlink($file);
        }
        if (is_file($this->archive)) @unlink($this->archive);
        if (is_dir($this->directory)) @rmdir($this->directory);
    }
}
