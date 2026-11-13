<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/AdminCatalogService.php';
require_once __DIR__ . '/../services/PlantillaService.php';

$user = requireAuth(['administrador']);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

/** Resumen para la lista: sin la configuración completa, que puede pesar. */
function plantillaResumen(PDO $db, array $p): array
{
    $c = $p['config'];
    $campos = 0;
    $obligatorios = 0;
    foreach ($c['formulario']['pasos'] ?? [] as $paso) {
        foreach ($paso['campos'] as $campo) {
            $campos++;
            $obligatorios += $campo['obligatorio'] ? 1 : 0;
        }
    }
    $q = $db->prepare("SELECT COUNT(*) FROM proyectos WHERE plantilla_id = ?");
    $q->execute([$p['id']]);
    return [
        'id' => $p['id'], 'nombre' => $p['nombre'], 'descripcion' => $p['descripcion'],
        'version' => $p['version'], 'activa' => $p['activa'], 'actualizado_en' => $p['actualizado_en'],
        'pasos' => count($c['formulario']['pasos'] ?? []), 'campos' => $campos, 'obligatorios' => $obligatorios,
        'fotos' => count($c['fotos'] ?? []),
        'fotos_obligatorias' => count(array_filter($c['fotos'] ?? [], static fn($f) => $f['obligatoria'])),
        'cuadros_pdf' => count($c['pdf']['hoja1']['cuadros'] ?? []),
        'logo_izquierdo' => $c['pdf']['encabezado']['logo_izquierdo'] ?? null,
        'proyectos' => (int) $q->fetchColumn(),
    ];
}

function plantillaBloquear(PDO $db, int $id): array
{
    $q = $db->prepare('SELECT * FROM plantillas WHERE id = ? FOR UPDATE');
    $q->execute([$id]);
    $row = $q->fetch();
    if (!$row) {
        throw new DomainException('Plantilla no encontrada.');
    }
    return plantillaDesdeFila($row);
}

try {
    plantillasAsegurarEsquema($conn);

    if ($method === 'GET') {
        $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id) {
            $p = plantillaPorId($conn, (int) $id);
            if (!$p) {
                throw new DomainException('Plantilla no encontrada.');
            }
            respond(['success' => true, 'data' => $p + ['resumen' => plantillaResumen($conn, $p)]]);
        }
        $rows = $conn->query('SELECT * FROM plantillas ORDER BY activa DESC, nombre')->fetchAll();
        $data = array_map(static fn(array $r): array => plantillaResumen($conn, plantillaDesdeFila($r)), $rows);
        respond(['success' => true, 'data' => $data, 'total' => count($data)]);
    }

    $d = requestJson();

    if ($method === 'POST') {
        $nombre = adminText($d['nombre'] ?? null, 150, 'Nombre');
        $descripcion = adminText($d['descripcion'] ?? null, 500, 'Descripción', false);
        $origen = filter_var($d['duplicar_de'] ?? null, FILTER_VALIDATE_INT);
        if ($origen) {
            $base = plantillaPorId($conn, (int) $origen);
            if (!$base) {
                throw new DomainException('La plantilla a duplicar no existe.');
            }
            $config = $base['config'];
        } else {
            $config = is_array($d['config'] ?? null) ? $d['config'] : plantillaVacia();
        }
        $config = plantillaNormalizar($config);

        $conn->beginTransaction();
        $conn->prepare('INSERT INTO plantillas (nombre, descripcion, version, config, creado_por) VALUES (?, ?, 1, ?, ?)')
            ->execute([$nombre, $descripcion, plantillaJson($config), $user['id'] ?? null]);
        $id = (int) $conn->lastInsertId();

        // Al duplicar, los logos se copian a la carpeta de la nueva plantilla:
        // cada una es dueña de sus archivos y borrar una no rompe la otra.
        if ($origen) {
            $imagenes = [$config['pdf']['encabezado']['logo_izquierdo'] ?? null, $config['pdf']['encabezado']['logo_derecho'] ?? null];
            foreach ($config['pdf']['firmas'] ?? [] as $firma) {
                $imagenes[] = $firma['imagen'] ?? null;
            }
            foreach (array_filter($imagenes) as $archivoImagen) {
                $ruta = plantillaRutaLogo((int) $origen, $archivoImagen, $conn);
                if ($ruta !== null) {
                    @copy($ruta, plantillaDirectorio($id, true) . DIRECTORY_SEPARATOR . $archivoImagen);
                    plantillaArchivoGuardarEnBd($id, $archivoImagen, (string) @file_get_contents($ruta), $conn);
                }
            }
        }
        $json = plantillaJson($config);
        $conn->prepare('INSERT INTO plantilla_versiones (plantilla_id, version, config, creado_por) VALUES (?, 1, ?, ?)')
            ->execute([$id, $json, $user['id'] ?? null]);
        adminAudit($conn, $user, 'template_created', 'plantilla', [], null, ['id' => $id, 'nombre' => $nombre, 'duplicada_de' => $origen ?: null]);
        $conn->commit();
        $p = plantillaPorId($conn, $id);
        respond(['success' => true, 'message' => 'Plantilla creada.', 'data' => $p], 201);
    }

    if ($method === 'PATCH') {
        $id = adminId($d['id'] ?? null, 'Plantilla');
        $accion = (string) ($d['accion'] ?? 'actualizar');
        $conn->beginTransaction();
        $antes = plantillaBloquear($conn, $id);

        if ($accion === 'actualizar') {
            $nombre = adminText($d['nombre'] ?? $antes['nombre'], 150, 'Nombre');
            $descripcion = adminText(array_key_exists('descripcion', $d) ? $d['descripcion'] : $antes['descripcion'], 500, 'Descripción', false);
            $cambiaConfig = is_array($d['config'] ?? null);
            $config = $cambiaConfig ? plantillaNormalizar($d['config']) : $antes['config'];
            $json = plantillaJson($config);
            $version = $antes['version'];
            if ($cambiaConfig && $json !== plantillaJson($antes['config'])) {
                // Control de concurrencia: si otro administrador guardó mientras
                // tanto, no se pisa su trabajo en silencio.
                $esperada = filter_var($d['version'] ?? null, FILTER_VALIDATE_INT);
                if ($esperada && (int) $esperada !== $antes['version']) {
                    throw new DomainException('Otra persona guardó esta plantilla mientras la editabas. Recarga para ver sus cambios.');
                }
                $version++;
                $conn->prepare('INSERT INTO plantilla_versiones (plantilla_id, version, config, creado_por) VALUES (?, ?, ?, ?)')
                    ->execute([$id, $version, $json, $user['id'] ?? null]);
            }
            $conn->prepare('UPDATE plantillas SET nombre = ?, descripcion = ?, config = ?, version = ? WHERE id = ?')
                ->execute([$nombre, $descripcion, $json, $version, $id]);
            $evento = 'template_updated';
        } elseif (in_array($accion, ['desactivar', 'reactivar'], true)) {
            $conn->prepare('UPDATE plantillas SET activa = ? WHERE id = ?')->execute([$accion === 'reactivar' ? 1 : 0, $id]);
            $evento = $accion === 'reactivar' ? 'template_enabled' : 'template_disabled';
        } else {
            throw new InvalidArgumentException('Acción no válida.');
        }

        adminAudit($conn, $user, $evento, 'plantilla', [], ['id' => $id, 'version' => $antes['version']], ['id' => $id, 'accion' => $accion]);
        $conn->commit();
        $p = plantillaPorId($conn, $id);
        respond(['success' => true, 'message' => 'Plantilla guardada.', 'data' => $p + ['resumen' => plantillaResumen($conn, $p)]]);
    }

    respond(['success' => false, 'error' => 'Método no permitido.'], 405);
} catch (InvalidArgumentException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    respond(['success' => false, 'error' => $e->getMessage()], 422);
} catch (DomainException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    respond(['success' => false, 'error' => $e->getMessage()], 409);
} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    $dup = (int) ($e->errorInfo[1] ?? 0) === 1062;
    appLog('administracion', 'Fallo en plantillas admin.', ['motivo' => $e->getMessage()]);
    respond(['success' => false, 'error' => $dup ? 'Ya existe una plantilla con ese nombre.' : 'No se pudo completar la operación.'], $dup ? 409 : 500);
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    appLog('administracion', 'Fallo en plantillas admin.', ['motivo' => $e->getMessage()]);
    respond(['success' => false, 'error' => 'No se pudo completar la operación.'], 500);
}
