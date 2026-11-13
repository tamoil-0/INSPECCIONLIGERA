<?php
declare(strict_types=1);

require_once __DIR__ . '/domain.php';

/**
 * Regla unica del estado fotografico de una inspeccion.
 *
 * Una foto omitida no tiene fila en imagenes_poste_estado. Una fila en esa
 * tabla significa que el movil intento enviar ese tipo; sincronizada=1 indica
 * que el servidor confirmo su version mas reciente.
 *
 * @return array{fotos_esperadas:int,fotos_capturadas:int,fotos_sincronizadas:int,fotos_omitidas:int,inspeccion_finalizada:bool,imagenes_subidas:bool,sincronizado:bool}
 */
function photoSynchronizationStatus(bool $finalized, int $captured, int $synced): array
{
    $expected = count(photoTypes());
    $captured = max(0, min($expected, $captured));
    $synced = max(0, min($captured, $synced));
    $allCapturedSynced = $captured > 0 && $captured === $synced;

    return [
        'fotos_esperadas' => $expected,
        'fotos_capturadas' => $captured,
        'fotos_sincronizadas' => $synced,
        'fotos_omitidas' => $expected - $captured,
        'inspeccion_finalizada' => $finalized,
        'imagenes_subidas' => $allCapturedSynced,
        'sincronizado' => $finalized && $allCapturedSynced,
    ];
}

/** Marca solo la fotografia recibida como pendiente antes de reemplazarla. */
function markPhotoPending(PDO $conn, int $postId, string $photoType): void
{
    $statement = $conn->prepare(
        'INSERT INTO imagenes_poste_estado (poste_id, nombre_foto, sincronizada, activo)
         VALUES (?, ?, 0, 1)
         ON DUPLICATE KEY UPDATE sincronizada = 0, activo = 1, archivado_en = NULL,
             actualizado_en = CURRENT_TIMESTAMP'
    );
    $statement->execute([$postId, $photoType]);

    $conn->prepare(
        'UPDATE imagenes_poste SET sincronizada = 0, activo = 1, archivado_en = NULL WHERE poste_id = ? AND nombre_foto = ?'
    )->execute([$postId, $photoType]);
    $conn->prepare('UPDATE postes SET imagenes_subidas = 0, sincronizado = 0 WHERE id = ?')
        ->execute([$postId]);
}

/** Confirma la version mas reciente de una fotografia concreta. */
function markPhotoSynced(PDO $conn, int $postId, string $photoType): void
{
    $statement = $conn->prepare(
        'UPDATE imagenes_poste_estado
         SET sincronizada = 1, activo = 1, archivado_en = NULL, actualizado_en = CURRENT_TIMESTAMP
         WHERE poste_id = ? AND nombre_foto = ?'
    );
    $statement->execute([$postId, $photoType]);
    $conn->prepare(
        'UPDATE imagenes_poste SET sincronizada = 1, activo = 1, archivado_en = NULL WHERE poste_id = ? AND nombre_foto = ?'
    )->execute([$postId, $photoType]);
}

/**
 * Recalcula las banderas agregadas de postes sin comparar contra 28.
 *
 * @return array{fotos_esperadas:int,fotos_capturadas:int,fotos_sincronizadas:int,fotos_omitidas:int,inspeccion_finalizada:bool,imagenes_subidas:bool,sincronizado:bool}
 */
function refreshPostSynchronizationStatus(PDO $conn, int $postId): array
{
    $statement = $conn->prepare(
        'SELECT p.inspeccion_finalizada,
                COUNT(e.nombre_foto) AS fotos_capturadas,
                COALESCE(SUM(CASE WHEN e.sincronizada = 1 THEN 1 ELSE 0 END), 0) AS fotos_sincronizadas
         FROM postes p
         LEFT JOIN imagenes_poste_estado e ON e.poste_id = p.id AND e.activo = 1
         WHERE p.id = ?
         GROUP BY p.id, p.inspeccion_finalizada'
    );
    $statement->execute([$postId]);
    $row = $statement->fetch();
    if (!$row) {
        throw new RuntimeException('Poste no encontrado.');
    }

    $status = photoSynchronizationStatus(
        (bool) $row['inspeccion_finalizada'],
        (int) $row['fotos_capturadas'],
        (int) $row['fotos_sincronizadas']
    );
    $conn->prepare('UPDATE postes SET imagenes_subidas = ?, sincronizado = ? WHERE id = ?')
        ->execute([$status['imagenes_subidas'] ? 1 : 0, $status['sincronizado'] ? 1 : 0, $postId]);

    return $status;
}

/** Campos calculados para los endpoints web y movil que listan postes. */
function photoStatusSelectSql(string $postAlias = 'p', string $stateAlias = 'fe'): string
{
    $expected = count(photoTypes());

    return "{$expected} AS fotos_esperadas,
            COALESCE({$stateAlias}.fotos_capturadas, 0) AS fotos_capturadas,
            COALESCE({$stateAlias}.fotos_sincronizadas, 0) AS fotos_sincronizadas,
            GREATEST({$expected} - COALESCE({$stateAlias}.fotos_capturadas, 0), 0) AS fotos_omitidas,
            {$postAlias}.inspeccion_finalizada";
}

/** Subconsulta agregada comun; evita multiplicar filas de postes. */
function photoStatusJoinSql(string $postAlias = 'p', string $stateAlias = 'fe'): string
{
    // Las fotos de los proyectos con plantilla viven en imagenes_plantilla
    // (cada fila existe solo cuando el servidor ya recibió el archivo).
    return " LEFT JOIN (
                SELECT poste_id,
                       COUNT(*) AS fotos_capturadas,
                       SUM(sincronizada) AS fotos_sincronizadas
                FROM (
                    SELECT poste_id, CASE WHEN sincronizada = 1 THEN 1 ELSE 0 END AS sincronizada
                    FROM imagenes_poste_estado
                    WHERE activo = 1
                    UNION ALL
                    SELECT poste_id, 1 AS sincronizada
                    FROM imagenes_plantilla
                    WHERE activo = 1
                ) fotos_union
                GROUP BY poste_id
             ) {$stateAlias} ON {$stateAlias}.poste_id = {$postAlias}.id";
}
