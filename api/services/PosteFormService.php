<?php
declare(strict_types=1);

/**
 * Lectura y escritura del formulario de inspección de una estructura.
 *
 * El catálogo de opciones NO se escribe aquí a mano: se lee de
 * INFORMATION_SCHEMA. Si mañana se agrega un valor a un `enum` de la base, el
 * editor lo ofrece solo, sin tocar este archivo ni el JavaScript. Duplicar las
 * listas fue lo que hizo que el PDF y el Excel se desfasaran en su momento.
 */

// Sin requires de configuración a propósito: todas las funciones reciben el PDO
// por parámetro, así el servicio se puede probar contra cualquier base.

/** Campos numéricos: no son enum, se validan por rango. */
const POSTE_FORM_NUMERIC = [
    'distancia_acceso' => ['decimales' => 3, 'max' => 999999],
    'cantidad_pat' => ['decimales' => 0, 'max' => 9999],
    'distancia_poste_anterior' => ['decimales' => 3, 'max' => 999999],
    'distancia_vertical' => ['decimales' => 3, 'max' => 999999],
    'distancia_horizontal' => ['decimales' => 3, 'max' => 999999],
];

/** Agrupación del editor. El orden es el del formulario en papel. */
const POSTE_FORM_GROUPS = [
    'Faja de servidumbre' => [
        'obstaculos_faja', 'estado_cuencas', 'marcado_arboles',
        'criticidad_tala', 'criticidad_contacto', 'notificacion_propietario',
    ],
    'Estructura y acceso' => [
        'tipo_torre', 'ubicacion', 'acceso_torre', 'estado_acceso', 'distancia_acceso',
    ],
    'Señalización y peligros' => [
        'estado_placas_torre', 'estado_placas_linea', 'estado_placas_fases',
        'peligro_cerco', 'peligro_torre',
    ],
    'Puesta a tierra y retenida' => [
        'puesta_tierra', 'cantidad_pat', 'retenida', 'conductor_bajada_pat',
    ],
    'Estado de la torre' => [
        'estado_base', 'limpiar_base', 'oxidos_base', 'crucetas_mensuales',
        'perfiles_angulares', 'malla_antiescalamiento',
    ],
    'Aisladores y conductores' => [
        'cadena_aisladores', 'tipo_aislador', 'conductor_guarda',
    ],
    'Distancias' => [
        'distancia_poste_anterior', 'distancia_vertical', 'distancia_horizontal',
    ],
    'Observaciones' => ['comentarios'],
];

/** Las tres tablas R/S/T, con las mismas claves que el PDF y el Excel. */
const POSTE_FORM_RST = [
    'conductores_fase' => [
        'titulo' => 'Conductores de Fase',
        'atributos' => ['hebras_rotas', 'encanastillado', 'empalme_deformado', 'objetos_extranos'],
    ],
    'conductores_cuellos' => [
        'titulo' => 'Conductores de Cuellos',
        'atributos' => ['hebras_rotas', 'encanastillado', 'empalme_deformado', 'objetos_extranos'],
    ],
    'estado_aisladores' => [
        'titulo' => 'Estado de Aisladores',
        'atributos' => ['buen_estado', 'rotos_suspension', 'rotos_anclaje_adelante', 'rotos_anclaje_atras', 'mal_estado'],
    ],
];

const POSTE_FORM_PHASES = ['R', 'S', 'T'];

/** `objetos_extranos` -> «Objetos extraños». Sin tildes no se puede adivinar, */
/** así que las que importan se listan; el resto sale de la propia clave. */
const POSTE_FORM_LABELS = [
    'objetos_extranos' => 'Objetos extraños',
    'rotos_suspension' => 'Rotos suspensión',
    'rotos_anclaje_atras' => 'Rotos anclaje atrás',
    'n_a' => 'No aplica',
    'obstaculos_faja' => 'Obstáculos en la faja',
    'criticidad_tala' => 'Criticidad de tala',
    'criticidad_contacto' => 'Criticidad de contacto',
    'notificacion_propietario' => 'Notificación al propietario',
    'ubicacion' => 'Ubicación de la torre',
    'estado_placas_torre' => 'Placas de torre',
    'estado_placas_linea' => 'Placas de línea',
    'estado_placas_fases' => 'Placas de fases',
    'malla_antiescalamiento' => 'Malla antiescalamiento',
    'oxidos_base' => 'Óxidos en la base',
    'cantidad_pat' => 'Cantidad de PAT',
    'conductor_bajada_pat' => 'Conductor de bajada a PAT',
    'conductor_guarda' => 'Conductor de guarda',
    'distancia_acceso' => 'Distancia de acceso (m)',
    'distancia_poste_anterior' => 'Distancia a la estructura anterior (m)',
    'distancia_vertical' => 'Distancia vertical (m)',
    'distancia_horizontal' => 'Distancia horizontal (m)',
    'crucetas_mensuales' => 'Crucetas y ménsulas',
    'senalizacion' => 'Señalización',
];

function posteFormLabel(string $key): string
{
    return POSTE_FORM_LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key));
}

/**
 * Tipo y opciones de cada columna de `poste_datos`, leídos del esquema.
 *
 * @return array<string, array{tipo: string, opciones: list<string>}>
 */
function posteFormSchema(PDO $db): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $query = $db->prepare(
        'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
           FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
          ORDER BY ORDINAL_POSITION'
    );
    $query->execute(['poste_datos']);

    $schema = [];
    foreach ($query->fetchAll() as $column) {
        $name = (string) $column['COLUMN_NAME'];
        if ($name === 'poste_id') {
            continue;
        }
        $dataType = strtolower((string) $column['DATA_TYPE']);
        $options = [];
        if ($dataType === 'enum' || $dataType === 'set') {
            // COLUMN_TYPE llega como enum('a','b','c'): se extraen los literales.
            if (preg_match_all("/'((?:[^']|'')*)'/", (string) $column['COLUMN_TYPE'], $matches)) {
                $options = array_map(static fn(string $v): string => str_replace("''", "'", $v), $matches[1]);
            }
        }
        $tipo = match (true) {
            $dataType === 'enum' => 'enum',
            $dataType === 'set' => 'set',
            $dataType === 'text' || $dataType === 'varchar' => 'texto',
            default => 'numero',
        };
        // `cantidad_pat` es NOT NULL DEFAULT 0. Vaciar ese campo en el editor no
        // puede escribir NULL: con sql_mode estricto (el del NAS) la sentencia
        // falla entera y el administrador solo ve un error genérico. Para las
        // columnas obligatorias, «vacío» significa su valor por defecto.
        $schema[$name] = [
            'tipo' => $tipo,
            'opciones' => $options,
            'admite_nulo' => strtoupper((string) $column['IS_NULLABLE']) === 'YES',
            'defecto' => $column['COLUMN_DEFAULT'] === null ? null : (string) $column['COLUMN_DEFAULT'],
        ];
    }
    return $cache = $schema;
}

/** Valores actuales de `poste_datos`, sin la clave. */
function posteFormValues(PDO $db, int $postId): array
{
    $query = $db->prepare('SELECT * FROM poste_datos WHERE poste_id = ?');
    $query->execute([$postId]);
    $row = $query->fetch() ?: [];
    unset($row['poste_id']);
    return $row;
}

/**
 * El formulario completo, listo para pintar: grupo, etiqueta, tipo, opciones
 * con su etiqueta legible y el valor vigente.
 *
 * `$values` son los valores YA RESUELTOS, no los de `poste_datos` a secas. El
 * PDF muestra el formulario del respaldo del móvil cuando la base todavía no lo
 * tiene (ver `resolveStructureResources`), así que el editor tiene que partir de
 * lo mismo: si leyera solo la tabla, al administrador le aparecería vacía una
 * inspección que en el informe sale llena.
 */
function posteFormFields(PDO $db, array $values): array
{
    $schema = posteFormSchema($db);
    $groups = [];

    foreach (POSTE_FORM_GROUPS as $groupTitle => $fields) {
        $entries = [];
        foreach ($fields as $field) {
            if (!isset($schema[$field])) {
                continue; // La columna ya no existe: no se inventa un control.
            }
            $definition = $schema[$field];
            $current = $values[$field] ?? null;
            $entries[] = [
                'campo' => $field,
                'etiqueta' => posteFormLabel($field),
                'tipo' => $definition['tipo'],
                'opciones' => array_map(
                    static fn(string $option): array => ['valor' => $option, 'etiqueta' => posteFormLabel($option)],
                    $definition['opciones']
                ),
                'valor' => $current === null ? '' : (string) $current,
                'relleno' => $current !== null && (string) $current !== '',
            ];
        }
        if ($entries !== []) {
            $groups[] = ['titulo' => $groupTitle, 'campos' => $entries];
        }
    }
    return $groups;
}

/** Marcas R/S/T vigentes en la base, como claves "seccion|atributo|fase". */
function posteFormMarks(PDO $db, int $postId): array
{
    $query = $db->prepare(
        'SELECT seccion, atributo, fase FROM poste_secciones_rst WHERE poste_id = ? AND activo = 1'
    );
    $query->execute([$postId]);
    return posteFormMarksFromRows($query->fetchAll());
}

/** Las mismas claves, a partir de filas ya resueltas (base o respaldo). */
function posteFormMarksFromRows(array $rows): array
{
    $marks = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $marks[] = $row['seccion'] . '|' . $row['atributo'] . '|' . strtoupper((string) $row['fase']);
    }
    sort($marks);
    return $marks;
}

/**
 * La matriz R/S/T entera: todas las casillas, marcadas y sin marcar, para que
 * el administrador vea también lo que quedó vacío.
 *
 * `seleccion` dice qué atributo tiene marcada cada fase. Es lo que gobierna de
 * verdad: la clave única de la tabla es (poste_id, seccion, fase), así que por
 * cada tabla y cada fase hay como mucho UN atributo. La cuadrícula se pinta con
 * controles de opción única por columna, no con casillas independientes.
 */
function posteFormRstMatrix(array $rstRows): array
{
    $selection = posteFormSelectionFromRows($rstRows);
    $sections = [];
    foreach (POSTE_FORM_RST as $sectionKey => $section) {
        $rows = [];
        foreach ($section['atributos'] as $attribute) {
            $phases = [];
            foreach (POSTE_FORM_PHASES as $phase) {
                $phases[] = [
                    'fase' => $phase,
                    'marcado' => ($selection[$sectionKey][$phase] ?? null) === $attribute,
                ];
            }
            $rows[] = [
                'atributo' => $attribute,
                'etiqueta' => posteFormLabel($attribute),
                'fases' => $phases,
            ];
        }
        $sections[] = [
            'seccion' => $sectionKey,
            'titulo' => $section['titulo'],
            'filas' => $rows,
            'seleccion' => array_map(
                static fn(string $phase): string => $selection[$sectionKey][$phase] ?? '',
                array_combine(POSTE_FORM_PHASES, POSTE_FORM_PHASES)
            ),
        ];
    }
    return $sections;
}

/**
 * Atributo vigente de cada (sección, fase).
 *
 * @return array<string, array<string, string>>
 */
function posteFormSelection(PDO $db, int $postId): array
{
    $query = $db->prepare(
        'SELECT seccion, atributo, fase FROM poste_secciones_rst WHERE poste_id = ? AND activo = 1'
    );
    $query->execute([$postId]);
    return posteFormSelectionFromRows($query->fetchAll());
}

/** La misma selección, a partir de filas ya resueltas (base o respaldo). */
function posteFormSelectionFromRows(array $rows): array
{
    $selection = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $selection[(string) $row['seccion']][strtoupper((string) $row['fase'])] = (string) $row['atributo'];
    }
    return $selection;
}

/**
 * Normaliza y valida un valor contra el esquema. Devuelve null para «vacío»,
 * que en esta tabla significa «no respondido» y es un estado legítimo: el
 * administrador debe poder dejar un campo en blanco.
 */
function posteFormNormalize(string $field, array $definition, mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    if (is_array($value)) {
        $value = implode(',', array_map('strval', $value));
    }
    $value = trim((string) $value);
    if ($value === '') {
        // Una columna obligatoria no puede quedar en NULL: se repone su valor
        // por defecto, que para `cantidad_pat` es 0.
        if (($definition['admite_nulo'] ?? true) === false) {
            $default = $definition['defecto'];
            return $default === null ? '0' : trim($default, "'");
        }
        return null;
    }

    if ($definition['tipo'] === 'enum') {
        if (!in_array($value, $definition['opciones'], true)) {
            throw new InvalidArgumentException("Valor no válido para «" . posteFormLabel($field) . "».");
        }
        return $value;
    }

    if ($definition['tipo'] === 'set') {
        $parts = array_values(array_unique(array_filter(array_map('trim', explode(',', $value)), 'strlen')));
        foreach ($parts as $part) {
            if (!in_array($part, $definition['opciones'], true)) {
                throw new InvalidArgumentException("Opción no válida en «" . posteFormLabel($field) . "».");
            }
        }
        // Se guarda en el orden del esquema: así dos ediciones con las mismas
        // casillas producen exactamente la misma cadena y no hay falsos cambios.
        $ordered = array_values(array_filter(
            $definition['opciones'],
            static fn(string $option): bool => in_array($option, $parts, true)
        ));
        return $ordered === [] ? null : implode(',', $ordered);
    }

    if ($definition['tipo'] === 'numero') {
        $normalized = str_replace(',', '.', $value);
        if (!is_numeric($normalized)) {
            throw new InvalidArgumentException("«" . posteFormLabel($field) . "» debe ser un número.");
        }
        $number = (float) $normalized;
        $rules = POSTE_FORM_NUMERIC[$field] ?? ['decimales' => 3, 'max' => 999999];
        if ($number < 0) {
            throw new InvalidArgumentException("«" . posteFormLabel($field) . "» no puede ser negativo.");
        }
        if ($number > $rules['max']) {
            throw new InvalidArgumentException("«" . posteFormLabel($field) . "» supera el máximo permitido.");
        }
        return number_format($number, $rules['decimales'], '.', '');
    }

    if (mb_strlen($value) > 2000) {
        throw new InvalidArgumentException('El comentario supera los 2000 caracteres.');
    }
    return $value;
}

/**
 * Escribe solo los campos que realmente cambian de valor.
 *
 * @return list<string> los campos efectivamente actualizados
 */
function posteFormApplyFields(PDO $db, int $postId, array $changes, array $baseline = []): array
{
    $schema = posteFormSchema($db);
    // Lo que hay REALMENTE en la tabla, que puede no ser lo que el
    // administrador vio si el formulario venía del respaldo del móvil.
    $actual = posteFormValues($db, $postId);

    foreach (array_keys($changes) as $field) {
        if (!isset($schema[(string) $field])) {
            throw new InvalidArgumentException("El campo «{$field}» no existe en el formulario.");
        }
    }

    $applied = [];
    $assignments = [];
    $parameters = [];
    foreach ($schema as $field => $definition) {
        $modified = array_key_exists($field, $changes);
        if (!$modified && !array_key_exists($field, $baseline)) {
            continue; // Ni se modificó ni había nada que consolidar.
        }
        $raw = $modified ? $changes[$field] : $baseline[$field];

        try {
            $desired = posteFormNormalize($field, $definition, $raw);
        } catch (InvalidArgumentException $exception) {
            // Un valor que el administrador escribió tiene que fallar a la vista.
            // Uno que solo viene arrastrado del respaldo se omite: un dato viejo
            // inválido no puede impedir corregir un campo distinto.
            if ($modified) {
                throw $exception;
            }
            continue;
        }

        $before = $actual[$field] ?? null;
        $before = $before === null || $before === '' ? null : (string) $before;
        if ($desired === $before) {
            continue; // Sin cambio real: no se escribe.
        }
        $assignments[] = "`{$field}` = ?";
        $parameters[] = $desired;
        $applied[] = $field;
    }
    if ($assignments === []) {
        return [];
    }
    $parameters[] = $postId;
    $db->prepare('UPDATE poste_datos SET ' . implode(', ', $assignments) . ' WHERE poste_id = ?')
        ->execute($parameters);
    return $applied;
}

/**
 * Fija qué atributo queda marcado en cada (sección, fase).
 *
 * Cada cambio es `{seccion, fase, atributo}`. Un `atributo` vacío deja esa fase
 * sin marcar. Marcar un atributo REEMPLAZA al que hubiera en esa misma fase: la
 * clave única de la tabla es (poste_id, seccion, fase) y el endpoint del móvil
 * (`agregar-seccion-rst.php`) se comporta igual. No es un juego de casillas
 * independientes aunque lo parezca al verlo impreso.
 *
 * Quitar la marca archiva la fila (`activo = 0`) en vez de borrarla, como hace
 * el resto del panel: el PDF y el Excel ya ignoran lo archivado y el historial
 * de la inspección no se pierde.
 *
 * @return list<string> las casillas efectivamente cambiadas
 */
function posteFormApplyRst(PDO $db, int $postId, array $changes, array $baselineRows = []): array
{
    // Lo que el administrador vio: la selección resuelta (base o respaldo) con
    // sus cambios encima. Si venía del respaldo hay que consolidarla entera; si
    // solo se escribiera la casilla tocada, `resolveStructureResources` dejaría
    // de recurrir al respaldo —porque la tabla ya no estaría vacía— y el PDF
    // perdería el resto de las marcas.
    $deseada = posteFormSelectionFromRows($baselineRows);
    foreach ($changes as $change) {
        if (!is_array($change)) {
            throw new InvalidArgumentException('Formato de casilla R/S/T no válido.');
        }
    }
    if ($changes === [] && $deseada === []) {
        return [];
    }
    $selection = posteFormSelection($db, $postId);

    $activate = $db->prepare(
        'INSERT INTO poste_secciones_rst (poste_id, seccion, atributo, fase, activo)
         VALUES (?, ?, ?, ?, 1)
         ON DUPLICATE KEY UPDATE atributo = VALUES(atributo), activo = 1, archivado_en = NULL'
    );
    $archive = $db->prepare(
        'UPDATE poste_secciones_rst SET activo = 0, archivado_en = NOW()
          WHERE poste_id = ? AND seccion = ? AND fase = ? AND activo = 1'
    );

    // Se validan los cambios y se superponen a la selección de partida.
    $seen = [];
    foreach ($changes as $change) {
        $section = (string) ($change['seccion'] ?? '');
        $phase = strtoupper((string) ($change['fase'] ?? ''));
        $attribute = trim((string) ($change['atributo'] ?? ''));

        if (!isset(POSTE_FORM_RST[$section])) {
            throw new InvalidArgumentException("Sección R/S/T no válida: «{$section}».");
        }
        if (!in_array($phase, POSTE_FORM_PHASES, true)) {
            throw new InvalidArgumentException('La fase debe ser R, S o T.');
        }
        if ($attribute !== '' && !in_array($attribute, POSTE_FORM_RST[$section]['atributos'], true)) {
            throw new InvalidArgumentException("Atributo no válido en «" . POSTE_FORM_RST[$section]['titulo'] . "».");
        }

        // Dos valores para la misma casilla en una sola petición serían
        // ambiguos: el resultado dependería del orden del arreglo.
        $cell = $section . '|' . $phase;
        if (isset($seen[$cell])) {
            throw new InvalidArgumentException(
                'Se enviaron dos valores para la fase ' . $phase . ' de «' . POSTE_FORM_RST[$section]['titulo'] . '».'
            );
        }
        $seen[$cell] = true;
        $deseada[$section][$phase] = $attribute;
    }

    $applied = [];
    foreach (POSTE_FORM_RST as $section => $definition) {
        foreach (POSTE_FORM_PHASES as $phase) {
            if (!isset($deseada[$section][$phase])) {
                continue; // Ni se tocó ni había nada que consolidar.
            }
            $attribute = (string) $deseada[$section][$phase];
            // Una marca del respaldo podría traer un atributo que no pertenece
            // a su sección; se descarta en vez de romper toda la operación.
            if ($attribute !== '' && !in_array($attribute, $definition['atributos'], true)) {
                continue;
            }
            $current = $selection[$section][$phase] ?? '';
            if ($current === $attribute) {
                continue; // Ya está como se pide.
            }
            if ($attribute === '') {
                $archive->execute([$postId, $section, $phase]);
            } else {
                $activate->execute([$postId, $section, $attribute, $phase]);
            }
            $applied[] = $section . '|' . $phase . '|' . ($attribute === '' ? '(sin marca)' : $attribute);
        }
    }
    return $applied;
}
