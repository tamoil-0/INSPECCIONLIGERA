<?php
declare(strict_types=1);

/**
 * Edición administrativa del formulario de inspección.
 *
 * Necesita una base MariaDB/MySQL real: el servicio lee el catálogo de opciones
 * de INFORMATION_SCHEMA y la semántica de reemplazo de las marcas R/S/T depende
 * de la clave única (poste_id, seccion, fase). SQLite no reproduce ninguna de
 * las dos cosas, así que una prueba con SQLite daría falsa confianza.
 *
 * Uso:
 *   FORM_TEST_DSN='mysql:host=127.0.0.1;port=3399;dbname=ecoing_inspeccion' \
 *   FORM_TEST_USER=root FORM_TEST_PASS= php api/tests/poste_form_admin_test.php
 */

require_once __DIR__ . '/../services/PosteFormService.php';

$dsn = getenv('FORM_TEST_DSN') ?: '';
if ($dsn === '') {
    fwrite(STDERR, "OMITIDA: defina FORM_TEST_DSN para ejecutar esta prueba.\n");
    exit(0);
}

$db = new PDO($dsn, getenv('FORM_TEST_USER') ?: 'root', getenv('FORM_TEST_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$fallos = [];
$ok = 0;
function verificar(string $caso, $esperado, $real, array &$fallos, int &$ok): void
{
    if ($esperado === $real) { $ok++; return; }
    $fallos[] = $caso . ': esperaba ' . var_export($esperado, true) . ', obtuve ' . var_export($real, true);
}
function esperaError(string $caso, callable $fn, array &$fallos, int &$ok): void
{
    try { $fn(); $fallos[] = $caso . ': no lanzó excepción'; }
    catch (InvalidArgumentException $e) { $ok++; }
}

// --- Datos de prueba --------------------------------------------------------
$db->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (['poste_secciones_rst', 'poste_datos', 'postes', 'lineas', 'proyectos'] as $t) {
    $db->exec("DELETE FROM `$t`");
}
$db->exec('SET FOREIGN_KEY_CHECKS = 1');

$db->exec("INSERT INTO proyectos (id, nombre_proyecto, contratista, ubicacion, estado) VALUES (1,'PRUEBA','INTESEL','PUNO','activo')");
$db->exec("INSERT INTO lineas (proyecto_id, nombre, ubicacion, activa) VALUES (1,'L-9001','PUNO',1)");
$db->exec("INSERT INTO postes (id, codigo, linea, estructura, proyecto_id, zona, activo) VALUES (7,'L-9001-01','L-9001','01',1,'19S',1)");
$db->exec('INSERT INTO poste_datos (poste_id) VALUES (7)');

// --- 1. El catálogo sale del esquema, no de una lista escrita a mano --------
$schema = posteFormSchema($db);
verificar('estado_acceso es enum', 'enum', $schema['estado_acceso']['tipo'] ?? null, $fallos, $ok);
verificar('opciones de estado_acceso', ['bueno', 'mal_estado'], $schema['estado_acceso']['opciones'] ?? null, $fallos, $ok);
verificar('obstaculos_faja es set', 'set', $schema['obstaculos_faja']['tipo'] ?? null, $fallos, $ok);
verificar('obstaculos_faja tiene 9 opciones', 9, count($schema['obstaculos_faja']['opciones'] ?? []), $fallos, $ok);
verificar('comentarios es texto', 'texto', $schema['comentarios']['tipo'] ?? null, $fallos, $ok);
verificar('cantidad_pat es numero', 'numero', $schema['cantidad_pat']['tipo'] ?? null, $fallos, $ok);
verificar('poste_id no aparece como campo', false, isset($schema['poste_id']), $fallos, $ok);

// --- 2. El formulario llega completo, con los vacíos incluidos --------------
$grupos = posteFormFields($db, posteFormValues($db, 7));
$totalCampos = array_sum(array_map(static fn(array $g): int => count($g['campos']), $grupos));
verificar('se agrupan todos los campos', count($schema), $totalCampos, $fallos, $ok);
// Todo empieza vacío salvo `cantidad_pat`, que es NOT NULL DEFAULT 0: no es un
// dato que el inspector haya respondido, pero la columna nunca es nula.
$rellenos = [];
foreach ($grupos as $g) { foreach ($g['campos'] as $c) { if ($c['relleno']) $rellenos[] = $c['campo']; } }
verificar('solo cantidad_pat viene con valor', ['cantidad_pat'], $rellenos, $fallos, $ok);
verificar('y su valor es cero', '0', $grupos[3]['campos'][1]['valor'] ?? null, $fallos, $ok);

// --- 3. Solo se escribe lo que cambia de verdad -----------------------------
$aplicados = posteFormApplyFields($db, 7, [
    'estado_acceso' => 'bueno',
    'cantidad_pat' => '3',
    'comentarios' => 'Acceso por trocha',
]);
sort($aplicados);
verificar('se aplican los 3 campos', ['cantidad_pat', 'comentarios', 'estado_acceso'], $aplicados, $fallos, $ok);

// Reenviar lo mismo no debe producir ninguna escritura
verificar('reenviar el mismo valor no cambia nada', [], posteFormApplyFields($db, 7, [
    'estado_acceso' => 'bueno',
    'cantidad_pat' => '3',
]), $fallos, $ok);

// Vaciar un campo es un cambio legítimo
verificar('vaciar un campo cuenta como cambio', ['estado_acceso'], posteFormApplyFields($db, 7, ['estado_acceso' => '']), $fallos, $ok);
verificar('el campo quedó nulo', null, posteFormValues($db, 7)['estado_acceso'], $fallos, $ok);

// Corregir un número mal tecleado: el caso real por el que existe este editor.
verificar('corregir 100 -> 10', ['distancia_vertical'], posteFormApplyFields($db, 7, ['distancia_vertical' => '100']), $fallos, $ok);
posteFormApplyFields($db, 7, ['distancia_vertical' => '10']);
verificar('el número quedó corregido', '10.000', posteFormValues($db, 7)['distancia_vertical'], $fallos, $ok);
verificar('se acepta la coma decimal', ['distancia_vertical'], posteFormApplyFields($db, 7, ['distancia_vertical' => '12,5']), $fallos, $ok);
verificar('la coma se guarda como punto', '12.500', posteFormValues($db, 7)['distancia_vertical'], $fallos, $ok);
verificar('vaciar un número que admite nulo lo deja nulo', ['distancia_vertical'], posteFormApplyFields($db, 7, ['distancia_vertical' => '']), $fallos, $ok);
verificar('y queda en NULL', null, posteFormValues($db, 7)['distancia_vertical'], $fallos, $ok);

// `cantidad_pat` es NOT NULL DEFAULT 0: vaciarlo debe reponer el 0, no reventar
// con «Column cannot be null» bajo sql_mode estricto.
posteFormApplyFields($db, 7, ['cantidad_pat' => '4']);
verificar('vaciar un campo obligatorio repone el defecto', ['cantidad_pat'], posteFormApplyFields($db, 7, ['cantidad_pat' => '']), $fallos, $ok);
// La columna es INT y PDO la devuelve como entero, no como cadena.
verificar('cantidad_pat vuelve a 0, no a NULL', '0', (string) posteFormValues($db, 7)['cantidad_pat'], $fallos, $ok);

// --- 4. Validación ----------------------------------------------------------
esperaError('valor fuera del enum', static fn() => posteFormApplyFields($db, 7, ['estado_acceso' => 'excelente']), $fallos, $ok);
esperaError('campo inexistente', static fn() => posteFormApplyFields($db, 7, ['campo_fantasma' => 'x']), $fallos, $ok);
esperaError('número negativo', static fn() => posteFormApplyFields($db, 7, ['distancia_vertical' => '-5']), $fallos, $ok);
esperaError('número no numérico', static fn() => posteFormApplyFields($db, 7, ['distancia_vertical' => 'diez']), $fallos, $ok);
esperaError('opción inválida en el set', static fn() => posteFormApplyFields($db, 7, ['obstaculos_faja' => 'arboles,volcan']), $fallos, $ok);

// El set se guarda en el orden del esquema para que no haya falsos cambios
posteFormApplyFields($db, 7, ['obstaculos_faja' => 'arboles,cercos_vallas']);
verificar('el set se ordena según el esquema', 'cercos_vallas,arboles', posteFormValues($db, 7)['obstaculos_faja'], $fallos, $ok);
verificar('el mismo set en otro orden no es un cambio', [], posteFormApplyFields($db, 7, ['obstaculos_faja' => 'arboles,cercos_vallas']), $fallos, $ok);
verificar('el mismo set como arreglo tampoco', [], posteFormApplyFields($db, 7, ['obstaculos_faja' => ['cercos_vallas', 'arboles']]), $fallos, $ok);

// --- 5. R/S/T: una sola marca por (sección, fase) ---------------------------
$cambios = posteFormApplyRst($db, 7, [
    ['seccion' => 'conductores_fase', 'fase' => 'R', 'atributo' => 'hebras_rotas'],
    ['seccion' => 'estado_aisladores', 'fase' => 'S', 'atributo' => 'buen_estado'],
]);
verificar('se aplican 2 marcas', 2, count($cambios), $fallos, $ok);
verificar('marcas vigentes', ['conductores_fase|hebras_rotas|R', 'estado_aisladores|buen_estado|S'], posteFormMarks($db, 7), $fallos, $ok);

// Marcar otro atributo en la MISMA fase reemplaza al anterior, no se suma
posteFormApplyRst($db, 7, [['seccion' => 'conductores_fase', 'fase' => 'R', 'atributo' => 'encanastillado']]);
verificar(
    'marcar otro atributo en la misma fase reemplaza',
    ['conductores_fase|encanastillado|R', 'estado_aisladores|buen_estado|S'],
    posteFormMarks($db, 7),
    $fallos,
    $ok
);
$filas = $db->query('SELECT COUNT(*) FROM poste_secciones_rst WHERE poste_id = 7 AND seccion = "conductores_fase" AND fase = "R"')->fetchColumn();
verificar('no se duplica la fila al reemplazar', 1, (int) $filas, $fallos, $ok);

// Repetir la misma marca no cambia nada
verificar('marca idéntica no genera cambio', [], posteFormApplyRst($db, 7, [
    ['seccion' => 'conductores_fase', 'fase' => 'R', 'atributo' => 'encanastillado'],
]), $fallos, $ok);

// Quitar la marca archiva, no borra
posteFormApplyRst($db, 7, [['seccion' => 'conductores_fase', 'fase' => 'R', 'atributo' => '']]);
verificar('quitar la marca la retira de las vigentes', ['estado_aisladores|buen_estado|S'], posteFormMarks($db, 7), $fallos, $ok);
$archivada = $db->query('SELECT activo, archivado_en IS NOT NULL FROM poste_secciones_rst WHERE poste_id = 7 AND seccion = "conductores_fase" AND fase = "R"')->fetch();
verificar('la fila se archiva, no se borra', ['activo' => 0, 'archivado_en IS NOT NULL' => 1], $archivada, $fallos, $ok);

// Volver a marcar reactiva la misma fila
posteFormApplyRst($db, 7, [['seccion' => 'conductores_fase', 'fase' => 'R', 'atributo' => 'objetos_extranos']]);
$total = $db->query('SELECT COUNT(*) FROM poste_secciones_rst WHERE poste_id = 7 AND seccion = "conductores_fase" AND fase = "R"')->fetchColumn();
verificar('reactivar no crea una fila nueva', 1, (int) $total, $fallos, $ok);

// --- 6. Validación R/S/T ----------------------------------------------------
esperaError('sección inválida', static fn() => posteFormApplyRst($db, 7, [['seccion' => 'conductores_guarda', 'fase' => 'R', 'atributo' => 'hebras_rotas']]), $fallos, $ok);
esperaError('fase inválida', static fn() => posteFormApplyRst($db, 7, [['seccion' => 'conductores_fase', 'fase' => 'Z', 'atributo' => 'hebras_rotas']]), $fallos, $ok);
esperaError('atributo ajeno a la sección', static fn() => posteFormApplyRst($db, 7, [['seccion' => 'conductores_fase', 'fase' => 'S', 'atributo' => 'buen_estado']]), $fallos, $ok);
esperaError('dos valores para la misma casilla', static fn() => posteFormApplyRst($db, 7, [
    ['seccion' => 'conductores_fase', 'fase' => 'T', 'atributo' => 'hebras_rotas'],
    ['seccion' => 'conductores_fase', 'fase' => 'T', 'atributo' => 'encanastillado'],
]), $fallos, $ok);

// --- 7. La matriz refleja el estado ----------------------------------------
$matriz = posteFormRstMatrix($db->query('SELECT seccion, atributo, fase FROM poste_secciones_rst WHERE poste_id = 7 AND activo = 1')->fetchAll());
verificar('3 tablas R/S/T', 3, count($matriz), $fallos, $ok);
$casillas = 0;
foreach ($matriz as $seccion) { $casillas += count($seccion['filas']) * 3; }
verificar('39 casillas en total', 39, $casillas, $fallos, $ok);
$aisladores = array_values(array_filter($matriz, static fn(array $s): bool => $s['seccion'] === 'estado_aisladores'))[0];
verificar('la selección de aisladores en S', 'buen_estado', $aisladores['seleccion']['S'], $fallos, $ok);
verificar('aisladores sin marca en R', '', $aisladores['seleccion']['R'], $fallos, $ok);
$marcadasEnFila = 0;
foreach ($aisladores['filas'] as $fila) {
    foreach ($fila['fases'] as $f) { if ($f['marcado']) $marcadasEnFila++; }
}
verificar('solo una casilla marcada en aisladores', 1, $marcadasEnFila, $fallos, $ok);

// Etiquetas con tilde
verificar('etiqueta con tilde', 'Objetos extraños', posteFormLabel('objetos_extranos'), $fallos, $ok);
verificar('etiqueta derivada', 'Tipo torre', posteFormLabel('tipo_torre'), $fallos, $ok);

// --- 8. Consolidación desde el respaldo del móvil ---------------------------
//
// Cuando el formulario solo existe en el respaldo, `poste_datos` está vacía y el
// PDF lo muestra igual gracias al recurso al manifiesto. Si al guardar se
// escribiera SOLO el campo tocado, la tabla dejaría de estar vacía,
// `resolveStructureResources` ya no recurriría al respaldo y el informe perdería
// todo lo demás. Por eso se consolida el conjunto completo.
$db->exec("INSERT INTO postes (id, codigo, linea, estructura, proyecto_id, zona, activo) VALUES (8,'L-9001-02','L-9001','02',1,'19S',1)");
$db->exec('INSERT INTO poste_datos (poste_id) VALUES (8)');

$delRespaldo = [
    'tipo_torre' => 'alineamiento',
    'estado_acceso' => 'bueno',
    'puesta_tierra' => 'bueno',
    'distancia_vertical' => 8.5,
    'comentarios' => 'Del respaldo del telefono',
];
// El administrador corrige un solo campo de los que vio.
$aplicados8 = posteFormApplyFields($db, 8, ['estado_acceso' => 'mal_estado'], $delRespaldo);
sort($aplicados8);
verificar(
    'se consolida todo el formulario del respaldo, no solo lo tocado',
    ['comentarios', 'distancia_vertical', 'estado_acceso', 'puesta_tierra', 'tipo_torre'],
    $aplicados8,
    $fallos,
    $ok
);
$valores8 = posteFormValues($db, 8);
verificar('el campo corregido gana sobre el respaldo', 'mal_estado', $valores8['estado_acceso'], $fallos, $ok);
verificar('los demas campos del respaldo quedaron en la base', 'alineamiento', $valores8['tipo_torre'], $fallos, $ok);
verificar('tambien los numericos', '8.500', $valores8['distancia_vertical'], $fallos, $ok);
verificar('y el comentario', 'Del respaldo del telefono', $valores8['comentarios'], $fallos, $ok);

// Un valor invalido arrastrado del respaldo no puede impedir la correccion.
$aplicados9 = posteFormApplyFields($db, 8, ['retenida' => 'retemplar'], ['tipo_torre' => 'valor_que_no_existe']);
verificar('un valor invalido del respaldo se omite sin romper', ['retenida'], $aplicados9, $fallos, $ok);
verificar('y el campo invalido conserva lo anterior', 'alineamiento', posteFormValues($db, 8)['tipo_torre'], $fallos, $ok);

// Pero si el administrador escribe el valor invalido, si debe fallar.
esperaError(
    'un valor invalido escrito por el administrador si falla',
    static fn() => posteFormApplyFields($db, 8, ['tipo_torre' => 'valor_que_no_existe'], []),
    $fallos,
    $ok
);

// Lo mismo con las marcas R/S/T del respaldo.
$rstRespaldo = [
    ['seccion' => 'conductores_fase', 'atributo' => 'hebras_rotas', 'fase' => 'R'],
    ['seccion' => 'conductores_fase', 'atributo' => 'encanastillado', 'fase' => 'S'],
    ['seccion' => 'estado_aisladores', 'atributo' => 'mal_estado', 'fase' => 'T'],
];
$rstAplicado = posteFormApplyRst(
    $db,
    8,
    [['seccion' => 'estado_aisladores', 'fase' => 'T', 'atributo' => 'buen_estado']],
    $rstRespaldo
);
verificar('se consolidan las 3 marcas del respaldo', 3, count($rstAplicado), $fallos, $ok);
$marcas8 = posteFormMarks($db, 8);
verificar(
    'la marca corregida gana y las demas se conservan',
    ['conductores_fase|encanastillado|S', 'conductores_fase|hebras_rotas|R', 'estado_aisladores|buen_estado|T'],
    $marcas8,
    $fallos,
    $ok
);

echo $fallos
    ? "FALLOS (" . count($fallos) . "):\n  - " . implode("\n  - ", $fallos) . "\n"
    : '';
echo ($fallos ? 'FALLÓ' : 'OK') . ": {$ok} comprobaciones del formulario administrativo.\n";
exit($fallos ? 1 : 0);
