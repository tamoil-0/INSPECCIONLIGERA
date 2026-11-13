<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/photo_status.php';

$cases = [
    ['28/28', true, 28, 28, true, 0],
    ['24/24', true, 24, 24, true, 4],
    ['24/23', true, 24, 23, false, 4],
    ['24/24 no finalizada', false, 24, 24, false, 4],
    ['0/0 no finalizada', false, 0, 0, false, 28],
];

foreach ($cases as [$name, $finalized, $captured, $synced, $expected, $omitted]) {
    $status = photoSynchronizationStatus($finalized, $captured, $synced);
    if ($status['sincronizado'] !== $expected || $status['fotos_omitidas'] !== $omitted) {
        fwrite(STDERR, "Fallo {$name}\n");
        exit(1);
    }
    echo $name . ': ' . ($status['sincronizado'] ? 'sincronizada' : 'pendiente') . PHP_EOL;
}
