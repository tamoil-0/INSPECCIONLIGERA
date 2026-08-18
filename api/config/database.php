<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

$host = (string) envValue('DB_HOST', '127.0.0.1');
$port = (int) envValue('DB_PORT', 3306);
$database = (string) envValue('DB_DATABASE', 'ecoing_inspeccion');
$username = (string) envValue('DB_USERNAME', 'root');
$password = (string) envValue('DB_PASSWORD', '');

try {
    $conn = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]
    );
} catch (PDOException $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    respond(['success' => false, 'error' => 'No se pudo conectar con la base de datos.'], 503);
}
