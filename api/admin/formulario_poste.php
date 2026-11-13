<?php
declare(strict_types=1);

/**
 * Edición administrativa del formulario de inspección de una estructura.
 *
 * GET  ?poste_id=N   Devuelve el formulario completo: el valor actual de cada
 *                    campo, el catálogo de opciones admitidas (leído del propio
 *                    esquema, para que nunca se desincronice de los enum de la
 *                    base) y la matriz R/S/T entera, marcada y sin marcar.
 *
 * PATCH              Aplica ÚNICAMENTE lo que venga en el cuerpo. Un campo que
 *                    no se envía no se toca: así dos administradores editando
 *                    cosas distintas de la misma estructura no se pisan.
 *
 * Las fotografías, la sincronización y el padrón maestro quedan fuera: esto
 * solo escribe en `poste_datos` y en `poste_secciones_rst`.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/AdminCatalogService.php';
require_once __DIR__ . '/../services/PosteFormService.php';
require_once __DIR__ . '/../services/StructureRecoveryService.php';

/**
 * El formulario tal como lo ve el informe: lo de la base y, si ahí no hay nada,
 * lo del respaldo del móvil. Devuelve además de dónde salió cada bloque, porque
 * eso cambia cómo hay que guardarlo.
 */
function formularioResuelto(PDO $db, array $post): array
{
    $resolved = resolveStructureResources($db, (int) $post['id'], (int) $post['proyecto_id']);
    $details = is_array($resolved['details'] ?? null) ? $resolved['details'] : [];
    unset($details['poste_id']);
    return [
        'valores' => $details,
        'rst' => is_array($resolved['rst'] ?? null) ? $resolved['rst'] : [],
        // Un bloque viene del respaldo cuando la tabla oficial está vacía. En
        // ese caso guardar exige consolidar todo, no solo lo modificado.
        'campos_del_respaldo' => ($resolved['details_official'] ?? []) === [] && $details !== [],
        'rst_del_respaldo' => ($resolved['rst_official'] ?? []) === [] && ($resolved['rst'] ?? []) !== [],
    ];
}

$user = requireAuth(['administrador']);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($method === 'GET') {
        $postId = adminId($_GET['poste_id'] ?? null, 'Estructura');
        $post = adminFetch($conn, 'postes', $postId);
        $resuelto = formularioResuelto($conn, $post);
        respond([
            'success' => true,
            'data' => [
                'poste' => [
                    'id' => (int) $post['id'],
                    'codigo' => $post['codigo'],
                    'linea' => $post['linea'],
                    'estructura' => $post['estructura'],
                    'proyecto_id' => (int) $post['proyecto_id'],
                ],
                'campos' => posteFormFields($conn, $resuelto['valores']),
                'rst' => posteFormRstMatrix($resuelto['rst']),
                'origen' => [
                    'campos' => $resuelto['campos_del_respaldo'] ? 'respaldo' : 'base',
                    'rst' => $resuelto['rst_del_respaldo'] ? 'respaldo' : 'base',
                ],
            ],
        ]);
    }

    if ($method !== 'PATCH') {
        header('Allow: GET, PATCH');
        respond(['success' => false, 'error' => 'Método no permitido.'], 405);
    }

    $body = requestJson();
    $postId = adminId($body['poste_id'] ?? null, 'Estructura');
    $fieldChanges = is_array($body['campos'] ?? null) ? $body['campos'] : [];
    $rstChanges = is_array($body['rst'] ?? null) ? $body['rst'] : [];
    if ($fieldChanges === [] && $rstChanges === []) {
        respond(['success' => false, 'error' => 'No se recibió ningún cambio.'], 422);
    }

    $conn->beginTransaction();
    $post = adminFetch($conn, 'postes', $postId, true);

    // Se resuelve ANTES de tocar nada: crear la fila de `poste_datos` haría que
    // la resolución dejara de considerar el respaldo.
    $resuelto = formularioResuelto($conn, $post);

    // `poste_datos` puede no existir todavía si la estructura nunca se inspeccionó.
    $conn->prepare('INSERT IGNORE INTO poste_datos(poste_id) VALUES(?)')->execute([$postId]);

    $beforeFields = posteFormValues($conn, $postId);
    $beforeRst = posteFormMarks($conn, $postId);

    $applied = posteFormApplyFields(
        $conn,
        $postId,
        $fieldChanges,
        $resuelto['campos_del_respaldo'] ? $resuelto['valores'] : []
    );
    $appliedRst = posteFormApplyRst(
        $conn,
        $postId,
        $rstChanges,
        $resuelto['rst_del_respaldo'] ? $resuelto['rst'] : []
    );

    if ($applied === [] && $appliedRst === []) {
        $conn->rollBack();
        respond(['success' => false, 'error' => 'Los valores enviados ya son los vigentes. No se realizaron cambios.'], 409);
    }

    $afterFields = posteFormValues($conn, $postId);
    $afterRst = posteFormMarks($conn, $postId);

    adminAudit(
        $conn,
        $user,
        'inspection_form_updated',
        'formulario',
        ['proyecto_id' => (int) $post['proyecto_id'], 'linea' => $post['linea'], 'poste_id' => $postId],
        // Solo se registra lo que cambió: un antes/después de los 33 campos
        // enteros haría el historial ilegible.
        ['campos' => array_intersect_key($beforeFields, array_flip($applied)), 'rst' => $beforeRst],
        ['campos' => array_intersect_key($afterFields, array_flip($applied)), 'rst' => $afterRst],
        'ok',
        count($applied) . ' campo(s) y ' . count($appliedRst) . ' casilla(s) R/S/T.'
    );
    $conn->commit();

    $final = formularioResuelto($conn, $post);
    respond([
        'success' => true,
        'message' => 'Formulario actualizado.',
        'data' => [
            'campos_actualizados' => $applied,
            'rst_actualizados' => $appliedRst,
            'consolidado' => $resuelto['campos_del_respaldo'] || $resuelto['rst_del_respaldo'],
            'campos' => posteFormFields($conn, $final['valores']),
            'rst' => posteFormRstMatrix($final['rst']),
            'origen' => [
                'campos' => $final['campos_del_respaldo'] ? 'respaldo' : 'base',
                'rst' => $final['rst_del_respaldo'] ? 'respaldo' : 'base',
            ],
        ],
    ]);
} catch (InvalidArgumentException $exception) {
    if ($conn->inTransaction()) $conn->rollBack();
    respond(['success' => false, 'error' => $exception->getMessage()], 400);
} catch (DomainException $exception) {
    if ($conn->inTransaction()) $conn->rollBack();
    respond(['success' => false, 'error' => $exception->getMessage()], 409);
} catch (Throwable $exception) {
    if ($conn->inTransaction()) $conn->rollBack();
    appLog('administracion', 'Fallo al editar el formulario de inspección.', ['motivo' => $exception->getMessage()]);
    respond(['success' => false, 'error' => 'No se pudo completar la operación. No se realizaron cambios.'], 500);
}
