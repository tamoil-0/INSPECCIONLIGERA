<?php
declare(strict_types=1);

/**
 * Plantillas de inspección configurables desde el panel administrativo.
 *
 * ## Qué resuelve
 *
 * El proyecto original (Electro Puno, L-6021) tiene el formulario, las 28
 * fotografías y el PDF escritos en el código. Una plantilla describe esas tres
 * cosas como datos, para que un proyecto nuevo se arme desde el panel:
 *
 *   formulario  pasos → campos (texto, número, fecha, sí/no, selección,
 *               selección múltiple, tabla de fases) con obligatorio/opcional;
 *   fotos       qué fotografías se piden, cuántas, si son obligatorias y cuál
 *               es la panorámica;
 *   pdf         encabezado (dos logos, título, colores), cuadros de la hoja 1
 *               sobre una rejilla de 48×96 y paneles de fotografías.
 *
 * ## Convivencia con el flujo fijo
 *
 * `proyectos.plantilla_id` NULL significa «flujo de siempre»: poste_datos,
 * imagenes_poste y el PDF histórico. Un proyecto con plantilla guarda sus
 * respuestas en `poste_respuestas` (JSON) y sus fotos en `imagenes_plantilla`.
 * Ninguna tabla histórica se modifica.
 *
 * ## Esquema
 *
 * Las tablas se crean solas la primera vez que se usa el servicio
 * (`plantillasAsegurarEsquema`), igual que hace la migración
 * `database/migrations/20261015_plantillas.sql`. Así desplegar al NAS no exige
 * un paso manual en la base.
 */

require_once __DIR__ . '/../config/domain.php';

const PLANTILLA_TIPOS_CAMPO = ['texto', 'parrafo', 'numero', 'fecha', 'si_no', 'seleccion', 'multiple', 'rst'];
// Rejilla de la hoja 1: celdas de 3,96 × 2,5 mm. Hasta 2026-10-15 era de 24×48
// (el doble de grande); las configuraciones de entonces traen `columnas: 24` o
// no traen `columnas`, y plantillaNormalizar() las pasa a esta.
const PLANTILLA_GRID_COLUMNAS = 48;
const PLANTILLA_GRID_FILAS = 96;
const PLANTILLA_GRID_COLUMNAS_ANTES = 24;
const PLANTILLA_LOGO_REGEX = '/^(logo_(izquierdo|derecho)|firma)_[a-f0-9]{16}\.png$/';

// ---------------------------------------------------------------------------
// Esquema
// ---------------------------------------------------------------------------

function plantillasAsegurarEsquema(PDO $db): void
{
    static $listo = false;
    if ($listo) {
        return;
    }
    // Antes de cualquier transacción: crear una tabla hace COMMIT implícito.
    plantillaArchivosTabla($db);

    $q = $db->query(
        "SELECT
           (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proyectos' AND COLUMN_NAME = 'plantilla_id') AS col,
           (SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
               ('plantillas','plantilla_versiones','poste_respuestas','imagenes_plantilla')) AS tablas"
    );
    $estado = $q->fetch() ?: [];
    if ((int) ($estado['col'] ?? 0) === 1 && (int) ($estado['tablas'] ?? 0) === 4) {
        // Las tablas pueden venir de la migración SQL o del esquema inicial,
        // que no siembran: la plantilla base se crea la primera vez que el API
        // las encuentra vacías (las plantillas no se borran, solo se desactivan).
        plantillasSembrarBase($db);
        $listo = true;
        return;
    }

    foreach (plantillasSentenciasEsquema() as $sql) {
        $db->exec($sql);
    }
    plantillasSembrarBase($db);
    $listo = true;
}

/** @return list<string> Mismas sentencias que la migración 20261015_plantillas.sql. */
function plantillasSentenciasEsquema(): array
{
    return [
        "CREATE TABLE IF NOT EXISTS `plantillas` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `nombre` VARCHAR(150) NOT NULL,
          `descripcion` VARCHAR(500) DEFAULT NULL,
          `version` INT UNSIGNED NOT NULL DEFAULT 1,
          `config` LONGTEXT NOT NULL,
          `activa` TINYINT(1) NOT NULL DEFAULT 1,
          `creado_por` INT UNSIGNED DEFAULT NULL,
          `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_plantillas_nombre` (`nombre`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `plantilla_versiones` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `plantilla_id` INT UNSIGNED NOT NULL,
          `version` INT UNSIGNED NOT NULL,
          `config` LONGTEXT NOT NULL,
          `creado_por` INT UNSIGNED DEFAULT NULL,
          `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_plantilla_version` (`plantilla_id`, `version`),
          CONSTRAINT `fk_plantilla_versiones_plantilla`
            FOREIGN KEY (`plantilla_id`) REFERENCES `plantillas` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `poste_respuestas` (
          `poste_id` INT UNSIGNED NOT NULL,
          `plantilla_id` INT UNSIGNED NOT NULL,
          `plantilla_version` INT UNSIGNED NOT NULL,
          `respuestas` LONGTEXT NOT NULL,
          `actualizado_por` INT UNSIGNED DEFAULT NULL,
          `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`poste_id`),
          KEY `idx_poste_respuestas_plantilla` (`plantilla_id`),
          CONSTRAINT `fk_poste_respuestas_poste`
            FOREIGN KEY (`poste_id`) REFERENCES `postes` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `imagenes_plantilla` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `poste_id` INT UNSIGNED NOT NULL,
          `foto_clave` VARCHAR(48) NOT NULL,
          `indice` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
          `ruta_archivo` VARCHAR(255) NOT NULL,
          `fecha_captura` DATETIME NOT NULL,
          `utm_este` DECIMAL(15,5) DEFAULT NULL,
          `utm_norte` DECIMAL(15,5) DEFAULT NULL,
          `zona` VARCHAR(10) DEFAULT NULL,
          `uuid` VARCHAR(64) DEFAULT NULL,
          `checksum` VARCHAR(64) DEFAULT NULL,
          `fecha_subida` DATETIME DEFAULT NULL,
          `activo` TINYINT(1) NOT NULL DEFAULT 1,
          `archivado_en` DATETIME DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_imagenes_plantilla` (`poste_id`, `foto_clave`, `indice`),
          CONSTRAINT `fk_imagenes_plantilla_poste`
            FOREIGN KEY (`poste_id`) REFERENCES `postes` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "ALTER TABLE `proyectos` ADD COLUMN IF NOT EXISTS `plantilla_id` INT UNSIGNED DEFAULT NULL",
    ];
}

function plantillasSembrarBase(PDO $db): void
{
    if ((int) $db->query('SELECT COUNT(*) FROM plantillas')->fetchColumn() > 0) {
        return;
    }
    $config = plantillaNormalizar(plantillaBaseElectroPuno());
    $json = plantillaJson($config);
    $db->prepare('INSERT INTO plantillas (nombre, descripcion, version, config) VALUES (?, ?, 1, ?)')
        ->execute([
            'Electro Puno 60 kV (base)',
            'Copia editable del formulario, las 28 fotografías y el PDF del proyecto original.',
            $json,
        ]);
    $id = (int) $db->lastInsertId();

    // Los logos del encabezado histórico entran ya normalizados, para que la
    // plantilla base se vea igual que el PDF de siempre desde el primer día.
    foreach (['izquierdo' => 'electro.png', 'derecho' => 'logo_intesel.png'] as $lado => $archivo) {
        $origen = __DIR__ . '/../clases/images/' . $archivo;
        if (!is_file($origen)) {
            continue;
        }
        try {
            $config['pdf']['encabezado']['logo_' . $lado] = plantillaGuardarLogo(
                ['error' => UPLOAD_ERR_OK, 'size' => filesize($origen), 'tmp_name' => $origen],
                $id,
                $lado,
                $db
            );
        } catch (Throwable $e) {
            appLog('administracion', 'No se pudo copiar un logo a la plantilla base.', ['motivo' => $e->getMessage()]);
        }
    }
    $json = plantillaJson($config);
    $db->prepare('UPDATE plantillas SET config = ? WHERE id = ?')->execute([$json, $id]);
    $db->prepare('INSERT INTO plantilla_versiones (plantilla_id, version, config) VALUES (?, 1, ?)')
        ->execute([$id, $json]);
}

// ---------------------------------------------------------------------------
// Lectura
// ---------------------------------------------------------------------------

function plantillaJson(array $config): string
{
    return (string) json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** @return array{id:int,nombre:string,descripcion:?string,version:int,activa:bool,config:array}|null */
function plantillaPorId(PDO $db, int $id): ?array
{
    plantillasAsegurarEsquema($db);
    $q = $db->prepare('SELECT * FROM plantillas WHERE id = ?');
    $q->execute([$id]);
    $row = $q->fetch();
    return $row ? plantillaDesdeFila($row) : null;
}

function plantillaDesdeFila(array $row): array
{
    $config = json_decode((string) $row['config'], true);
    return [
        'id' => (int) $row['id'],
        'nombre' => (string) $row['nombre'],
        'descripcion' => $row['descripcion'] ?? null,
        'version' => (int) $row['version'],
        'activa' => (bool) $row['activa'],
        'actualizado_en' => $row['actualizado_en'] ?? null,
        'config' => is_array($config) ? $config : plantillaVacia(),
    ];
}

/** Plantilla asignada al proyecto, o null si sigue el flujo fijo de siempre. */
function plantillaDeProyecto(PDO $db, int $projectId): ?array
{
    static $cache = [];
    if (array_key_exists($projectId, $cache)) {
        return $cache[$projectId];
    }
    plantillasAsegurarEsquema($db);
    $q = $db->prepare('SELECT plantilla_id FROM proyectos WHERE id = ?');
    $q->execute([$projectId]);
    $id = (int) ($q->fetchColumn() ?: 0);
    return $cache[$projectId] = ($id > 0 ? plantillaPorId($db, $id) : null);
}

/** Lista plana de campos de la plantilla, indexada por clave. */
function plantillaCampos(array $config): array
{
    $campos = [];
    foreach ($config['formulario']['pasos'] ?? [] as $paso) {
        foreach ($paso['campos'] ?? [] as $campo) {
            $campos[$campo['clave']] = $campo;
        }
    }
    return $campos;
}

/** Fotos indexadas por clave. */
function plantillaFotos(array $config): array
{
    $fotos = [];
    foreach ($config['fotos'] ?? [] as $foto) {
        $fotos[$foto['clave']] = $foto;
    }
    return $fotos;
}

function plantillaVacia(): array
{
    return plantillaNormalizar([
        'formulario' => ['pasos' => [['id' => 'paso_1', 'titulo' => 'Datos generales', 'campos' => []]]],
        'fotos' => [],
        'pdf' => [],
    ]);
}

// ---------------------------------------------------------------------------
// Validación de la configuración
// ---------------------------------------------------------------------------

function plantillaTexto(mixed $valor, int $max, string $campo, bool $obligatorio = false, string $defecto = ''): string
{
    if (!is_scalar($valor) && $valor !== null) {
        throw new InvalidArgumentException("{$campo} no es válido.");
    }
    $texto = trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', (string) ($valor ?? '')) ?? '');
    if ($texto === '') {
        if ($obligatorio) {
            throw new InvalidArgumentException("{$campo} es obligatorio.");
        }
        return $defecto;
    }
    return mb_substr($texto, 0, $max);
}

function plantillaSlug(mixed $valor, string $campo, int $max = 48): string
{
    $texto = mb_strtolower(trim((string) ($valor ?? '')));
    $texto = strtr($texto, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $texto = trim((string) preg_replace('/[^a-z0-9]+/', '_', $texto), '_');
    if ($texto === '' || !preg_match('/^[a-z]/', $texto)) {
        $texto = 'c_' . $texto;
    }
    $texto = substr($texto, 0, $max);
    if ($texto === 'c_') {
        throw new InvalidArgumentException("{$campo}: la clave no es válida.");
    }
    return rtrim($texto, '_');
}

function plantillaColor(mixed $valor, string $defecto): string
{
    $texto = strtoupper(trim((string) ($valor ?? '')));
    if (preg_match('/^#?([0-9A-F]{6})$/', $texto, $m)) {
        return '#' . $m[1];
    }
    return $defecto;
}

function plantillaEntero(mixed $valor, int $min, int $max, int $defecto): int
{
    if ($valor === null || $valor === '' || !is_numeric($valor)) {
        return $defecto;
    }
    return max($min, min($max, (int) round((float) $valor)));
}

function plantillaNumeroONulo(mixed $valor): ?float
{
    if ($valor === null || $valor === '' || !is_numeric($valor) || !is_finite((float) $valor)) {
        return null;
    }
    return (float) $valor;
}

/** Etiqueta legible a partir de una clave: `mal_estado` → «Mal estado». */
function plantillaEtiquetaDeClave(string $clave): string
{
    $especiales = [
        'n_a' => 'N/A', 'si' => 'Sí', 'critico' => 'Crítico', 'angulo' => 'Ángulo',
        'desertico' => 'Desértico', 'polimero' => 'Polímero', 'en_vehiculo' => 'En vehículo',
        'persona_juridica' => 'Persona jurídica', 'objetos_extranos' => 'Objetos extraños',
        'en_suspension' => 'En suspensión',
    ];
    if (isset($especiales[$clave])) {
        return $especiales[$clave];
    }
    $texto = str_replace('_', ' ', $clave);
    return mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
}

function plantillaNormalizarOpciones(mixed $opciones, string $campo): array
{
    if (!is_array($opciones)) {
        throw new InvalidArgumentException("{$campo}: faltan las opciones.");
    }
    $salida = [];
    $vistos = [];
    foreach (array_slice(array_values($opciones), 0, 40) as $opcion) {
        if (is_string($opcion)) {
            $opcion = ['texto' => $opcion];
        }
        if (!is_array($opcion)) {
            continue;
        }
        $texto = plantillaTexto($opcion['texto'] ?? ($opcion['valor'] ?? ''), 80, "{$campo}: opción");
        if ($texto === '') {
            continue;
        }
        $valor = plantillaSlug(($opcion['valor'] ?? '') !== '' ? $opcion['valor'] : $texto, "{$campo}: opción");
        if (isset($vistos[$valor])) {
            continue;
        }
        $vistos[$valor] = true;
        $salida[] = ['valor' => $valor, 'texto' => $texto];
    }
    if ($salida === []) {
        throw new InvalidArgumentException("{$campo}: agrega al menos una opción.");
    }
    return $salida;
}

/**
 * Valida y completa una configuración de plantilla.
 *
 * Nunca confía en lo que llega del navegador: recorta textos, fuerza colores
 * hexadecimales, recoloca los cuadros dentro de la rejilla y descarta
 * referencias a campos o fotos que no existen. Lanza InvalidArgumentException
 * con un mensaje para el administrador cuando algo no se puede arreglar solo.
 */
function plantillaNormalizar(array $c): array
{
    // --- Formulario --------------------------------------------------------
    $pasosEntrada = $c['formulario']['pasos'] ?? [];
    if (!is_array($pasosEntrada) || $pasosEntrada === []) {
        throw new InvalidArgumentException('El formulario necesita al menos un paso.');
    }
    $pasos = [];
    $claves = [];
    $idsPaso = [];
    foreach (array_slice(array_values($pasosEntrada), 0, 20) as $i => $paso) {
        if (!is_array($paso)) {
            continue;
        }
        $titulo = plantillaTexto($paso['titulo'] ?? '', 80, 'Título del paso', false, 'Paso ' . ($i + 1));
        $idPaso = plantillaSlug(($paso['id'] ?? '') !== '' ? $paso['id'] : 'paso_' . ($i + 1), 'Paso');
        while (isset($idsPaso[$idPaso])) {
            $idPaso .= '_b';
        }
        $idsPaso[$idPaso] = true;
        $campos = [];
        foreach (array_slice(array_values(is_array($paso['campos'] ?? null) ? $paso['campos'] : []), 0, 80) as $campo) {
            if (!is_array($campo)) {
                continue;
            }
            $etiqueta = plantillaTexto($campo['etiqueta'] ?? '', 120, 'Nombre del campo', true);
            $clave = plantillaSlug(($campo['clave'] ?? '') !== '' ? $campo['clave'] : $etiqueta, $etiqueta);
            if (isset($claves[$clave])) {
                throw new InvalidArgumentException("La clave «{$clave}» está repetida. Cada campo necesita una clave distinta.");
            }
            $claves[$clave] = true;
            $tipo = (string) ($campo['tipo'] ?? 'texto');
            if (!in_array($tipo, PLANTILLA_TIPOS_CAMPO, true)) {
                throw new InvalidArgumentException("«{$etiqueta}»: tipo de campo no válido.");
            }
            $n = [
                'clave' => $clave,
                'etiqueta' => $etiqueta,
                'tipo' => $tipo,
                'obligatorio' => !empty($campo['obligatorio']),
                'ayuda' => plantillaTexto($campo['ayuda'] ?? '', 200, 'Ayuda'),
            ];
            if ($tipo === 'numero') {
                $n['unidad'] = plantillaTexto($campo['unidad'] ?? '', 12, 'Unidad');
                $n['decimales'] = plantillaEntero($campo['decimales'] ?? 2, 0, 4, 2);
                $n['min'] = plantillaNumeroONulo($campo['min'] ?? 0);
                $n['max'] = plantillaNumeroONulo($campo['max'] ?? null);
                if ($n['min'] !== null && $n['max'] !== null && $n['min'] > $n['max']) {
                    throw new InvalidArgumentException("«{$etiqueta}»: el mínimo es mayor que el máximo.");
                }
            }
            if ($tipo === 'texto' || $tipo === 'parrafo') {
                $n['max_largo'] = plantillaEntero($campo['max_largo'] ?? ($tipo === 'parrafo' ? 2000 : 200), 1, 5000, 200);
            }
            if (in_array($tipo, ['seleccion', 'multiple', 'rst'], true)) {
                $n['opciones'] = plantillaNormalizarOpciones($campo['opciones'] ?? [], $etiqueta);
            }
            if ($tipo === 'rst') {
                $fases = [];
                foreach (is_array($campo['fases'] ?? null) ? $campo['fases'] : ['R', 'S', 'T'] as $fase) {
                    $f = mb_strtoupper(plantillaTexto($fase, 6, 'Fase'));
                    if ($f !== '' && !in_array($f, $fases, true)) {
                        $fases[] = $f;
                    }
                }
                $n['fases'] = array_slice($fases ?: ['R', 'S', 'T'], 0, 6);
            }
            $campos[] = $n;
        }
        $pasos[] = ['id' => $idPaso, 'titulo' => $titulo, 'campos' => $campos];
    }
    if ($pasos === []) {
        throw new InvalidArgumentException('El formulario necesita al menos un paso.');
    }
    $camposPorClave = [];
    foreach ($pasos as $paso) {
        foreach ($paso['campos'] as $campo) {
            $camposPorClave[$campo['clave']] = $campo;
        }
    }

    // --- Fotos --------------------------------------------------------------
    $fotos = [];
    $clavesFoto = [];
    $hayPanoramica = false;
    foreach (array_slice(array_values(is_array($c['fotos'] ?? null) ? $c['fotos'] : []), 0, 80) as $foto) {
        if (!is_array($foto)) {
            continue;
        }
        $nombre = plantillaTexto($foto['nombre'] ?? '', 80, 'Nombre de la foto', true);
        $clave = plantillaSlug(($foto['clave'] ?? '') !== '' ? $foto['clave'] : $nombre, $nombre);
        if (isset($clavesFoto[$clave])) {
            throw new InvalidArgumentException("La foto «{$clave}» está repetida.");
        }
        $clavesFoto[$clave] = true;
        $panoramica = !empty($foto['panoramica']) && !$hayPanoramica;
        $hayPanoramica = $hayPanoramica || $panoramica;
        $fotos[] = [
            'clave' => $clave,
            'nombre' => $nombre,
            'obligatoria' => !empty($foto['obligatoria']),
            'cantidad' => $panoramica ? 1 : plantillaEntero($foto['cantidad'] ?? 1, 1, 10, 1),
            'categoria' => plantillaTexto($foto['categoria'] ?? '', 60, 'Categoría', false, 'General'),
            'ayuda' => plantillaTexto($foto['ayuda'] ?? '', 200, 'Ayuda'),
            'panoramica' => $panoramica,
        ];
    }

    // --- PDF ------------------------------------------------------------------
    $pdf = is_array($c['pdf'] ?? null) ? $c['pdf'] : [];
    $enc = is_array($pdf['encabezado'] ?? null) ? $pdf['encabezado'] : [];
    $logo = static function (mixed $valor): ?string {
        $v = trim((string) ($valor ?? ''));
        return preg_match(PLANTILLA_LOGO_REGEX, $v) ? $v : null;
    };
    $encabezado = [
        'titulo' => plantillaTexto($enc['titulo'] ?? '', 120, 'Título'),
        'subtitulo' => plantillaTexto($enc['subtitulo'] ?? '', 120, 'Subtítulo'),
        'logo_izquierdo' => $logo($enc['logo_izquierdo'] ?? null),
        'logo_derecho' => $logo($enc['logo_derecho'] ?? null),
        'color_barra' => plantillaColor($enc['color_barra'] ?? null, '#0A2D64'),
        'color_texto_barra' => plantillaColor($enc['color_texto_barra'] ?? null, '#FFFFFF'),
        'etiqueta_codigo' => plantillaTexto($enc['etiqueta_codigo'] ?? 'IL', 6, 'Etiqueta del código', false, 'IL'),
    ];

    $cuadros = [];
    $idsCuadro = [];
    $hoja1 = is_array($pdf['hoja1']['cuadros'] ?? null) ? $pdf['hoja1']['cuadros'] : [];
    // Una configuración de la rejilla anterior (24×48) se duplica: queda igual en la hoja.
    $columnasEntrada = (int) ($pdf['hoja1']['columnas'] ?? PLANTILLA_GRID_COLUMNAS_ANTES);
    $factor = $columnasEntrada === PLANTILLA_GRID_COLUMNAS_ANTES ? 2 : 1;
    $celdas = static fn(mixed $v): mixed => is_numeric($v) ? (float) $v * $factor : $v;
    foreach (array_slice(array_values($hoja1), 0, 60) as $i => $cuadro) {
        if (!is_array($cuadro)) {
            continue;
        }
        $tipo = in_array($cuadro['tipo'] ?? '', ['campos', 'panoramica', 'texto'], true) ? $cuadro['tipo'] : 'campos';
        $w = plantillaEntero($celdas($cuadro['w'] ?? 6), 1, PLANTILLA_GRID_COLUMNAS, 12);
        $h = plantillaEntero($celdas($cuadro['h'] ?? 5), 4, PLANTILLA_GRID_FILAS, 10);
        $x = plantillaEntero($celdas($cuadro['x'] ?? 0), 0, PLANTILLA_GRID_COLUMNAS - $w, 0);
        $y = plantillaEntero($celdas($cuadro['y'] ?? 0), 0, PLANTILLA_GRID_FILAS - $h, 0);
        $id = plantillaSlug(($cuadro['id'] ?? '') !== '' ? $cuadro['id'] : 'cuadro_' . ($i + 1), 'Cuadro');
        while (isset($idsCuadro[$id])) {
            $id .= '_b';
        }
        $idsCuadro[$id] = true;
        $n = [
            'id' => $id,
            'tipo' => $tipo,
            'titulo' => plantillaTexto($cuadro['titulo'] ?? '', 80, 'Título del cuadro'),
            'color' => plantillaColor($cuadro['color'] ?? null, '#305496'),
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
        ];
        if ($tipo === 'campos') {
            $lista = [];
            foreach (is_array($cuadro['campos'] ?? null) ? $cuadro['campos'] : [] as $clave) {
                if (is_string($clave) && isset($camposPorClave[$clave]) && !in_array($clave, $lista, true)) {
                    $lista[] = $clave;
                }
            }
            // Un cuadro cuyos campos se borraron del formulario ya no tiene qué
            // imprimir. Uno creado vacío a propósito se respeta.
            if ($lista === [] && is_array($cuadro['campos'] ?? null) && $cuadro['campos'] !== []) {
                continue;
            }
            $n['campos'] = $lista;
            $n['columnas'] = plantillaEntero($cuadro['columnas'] ?? 1, 1, 4, 1);
        }
        if ($tipo === 'texto') {
            $n['texto'] = plantillaTexto($cuadro['texto'] ?? '', 600, 'Texto del cuadro');
        }
        $cuadros[] = $n;
    }

    $paneles = [];
    foreach (array_slice(array_values(is_array($pdf['paneles'] ?? null) ? $pdf['paneles'] : []), 0, 30) as $panel) {
        if (!is_array($panel)) {
            continue;
        }
        $lista = [];
        foreach (is_array($panel['fotos'] ?? null) ? $panel['fotos'] : [] as $clave) {
            if (is_string($clave) && isset($clavesFoto[$clave]) && !in_array($clave, $lista, true)) {
                $lista[] = $clave;
            }
        }
        // Igual que los cuadros: un panel que se quedó sin sus fotos se descarta.
        if ($lista === [] && is_array($panel['fotos'] ?? null) && $panel['fotos'] !== []) {
            continue;
        }
        $paneles[] = [
            'titulo' => plantillaTexto($panel['titulo'] ?? '', 80, 'Título del panel'),
            'color' => plantillaColor($panel['color'] ?? null, '#7B7B7B'),
            'columnas' => plantillaEntero($panel['columnas'] ?? 3, 1, 4, 3),
            // Alto de cada foto en mm. 0 = automático (se ajusta al ancho).
            'alto_foto' => (static function (mixed $v): int {
                $mm = plantillaEntero($v, 0, 120, 0);
                return $mm > 0 ? max(25, (int) round($mm / 5) * 5) : 0;
            })($panel['alto_foto'] ?? 0),
            'fotos' => $lista,
            // Tamaño propio de algunas fotos: ancho en columnas (2–12 de 12) y
            // alto de la imagen en mm (múltiplo de 5). 0 = el del panel.
            'tamanos' => plantillaTamanosPanel($panel['tamanos'] ?? null, $lista),
            'mostrar_nombres' => !array_key_exists('mostrar_nombres', $panel) || !empty($panel['mostrar_nombres']),
            'nueva_pagina' => !empty($panel['nueva_pagina']),
        ];
    }

    $comentario = $pdf['comentarios']['campo'] ?? null;
    $comentarios = [
        'campo' => is_string($comentario) && isset($camposPorClave[$comentario]) ? $comentario : null,
        'titulo' => plantillaTexto($pdf['comentarios']['titulo'] ?? '', 80, 'Título de comentarios', false, 'COMENTARIOS / OBSERVACIONES'),
    ];

    $firmas = [];
    foreach (array_slice(array_values(is_array($pdf['firmas'] ?? null) ? $pdf['firmas'] : []), 0, 3) as $firma) {
        if (!is_array($firma)) {
            continue;
        }
        $nombre = plantillaTexto($firma['nombre'] ?? '', 80, 'Firmante');
        $cargo = plantillaTexto($firma['cargo'] ?? '', 80, 'Cargo');
        $imagen = is_string($firma['imagen'] ?? null) && preg_match(PLANTILLA_LOGO_REGEX, $firma['imagen']) ? $firma['imagen'] : null;
        if ($nombre !== '' || $cargo !== '' || $imagen !== null) {
            $firmas[] = [
                'nombre' => $nombre,
                'cargo' => $cargo,
                // Imagen de la firma (PNG transparente), su alto en mm y su
                // desplazamiento respecto del centro de la línea.
                'imagen' => $imagen,
                'alto' => plantillaEntero($firma['alto'] ?? 16, 8, 35, 16),
                'dx' => max(-30, min(30, (int) round((float) ($firma['dx'] ?? 0)))),
                'dy' => max(-12, min(12, (int) round((float) ($firma['dy'] ?? 0)))),
            ];
        }
    }

    return [
        'esquema' => 1,
        'formulario' => ['pasos' => $pasos],
        'fotos' => $fotos,
        'pdf' => [
            'encabezado' => $encabezado,
            'hoja1' => ['columnas' => PLANTILLA_GRID_COLUMNAS, 'filas' => PLANTILLA_GRID_FILAS, 'cuadros' => $cuadros],
            'paneles' => $paneles,
            'comentarios' => $comentarios,
            'firmas' => $firmas,
        ],
    ];
}

// ---------------------------------------------------------------------------
// Respuestas de una inspección
// ---------------------------------------------------------------------------

function plantillaValorVacio(mixed $v): bool
{
    return $v === null || $v === '' || $v === [] || (is_string($v) && trim($v) === '');
}

/**
 * Valida las respuestas de una estructura contra la plantilla.
 *
 * Devuelve sólo las claves que la plantilla conoce, ya normalizadas. Con
 * `$exigirObligatorios` lanza si falta algún campo obligatorio; el panel
 * admin lo desactiva para poder corregir una inspección a medias.
 */
function plantillaValidarRespuestas(
    array $config,
    array $respuestas,
    bool $exigirObligatorios = true,
    bool $tolerante = false,
    array &$avisos = []
): array {
    $salida = [];
    $faltan = [];
    foreach (plantillaCampos($config) as $clave => $campo) {
        $v = $respuestas[$clave] ?? null;
        $etiqueta = $campo['etiqueta'];
        if (plantillaValorVacio($v)) {
            if ($campo['obligatorio']) {
                $faltan[] = $etiqueta;
            }
            continue;
        }
        $opciones = array_column($campo['opciones'] ?? [], 'valor');
        try {
        switch ($campo['tipo']) {
            case 'texto':
            case 'parrafo':
                if (!is_scalar($v)) {
                    throw new InvalidArgumentException("«{$etiqueta}» debe ser texto.");
                }
                $salida[$clave] = mb_substr(trim((string) $v), 0, (int) ($campo['max_largo'] ?? 2000));
                break;
            case 'numero':
                $texto = str_replace(',', '.', trim((string) (is_scalar($v) ? $v : '')));
                if (!is_numeric($texto) || !is_finite((float) $texto)) {
                    throw new InvalidArgumentException("«{$etiqueta}» debe ser un número.");
                }
                $numero = round((float) $texto, (int) ($campo['decimales'] ?? 2));
                if ($campo['min'] !== null && $numero < $campo['min']) {
                    throw new InvalidArgumentException("«{$etiqueta}» no puede ser menor que {$campo['min']}.");
                }
                if ($campo['max'] !== null && $numero > $campo['max']) {
                    throw new InvalidArgumentException("«{$etiqueta}» no puede ser mayor que {$campo['max']}.");
                }
                $salida[$clave] = $numero;
                break;
            case 'fecha':
                $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $v, 0, 10));
                if (!$fecha) {
                    throw new InvalidArgumentException("«{$etiqueta}» debe ser una fecha.");
                }
                $salida[$clave] = $fecha->format('Y-m-d');
                break;
            case 'si_no':
                if (!in_array($v, ['si', 'no'], true)) {
                    throw new InvalidArgumentException("«{$etiqueta}» debe ser sí o no.");
                }
                $salida[$clave] = $v;
                break;
            case 'seleccion':
                if (!is_string($v) || !in_array($v, $opciones, true)) {
                    throw new InvalidArgumentException("«{$etiqueta}» tiene una opción no válida.");
                }
                $salida[$clave] = $v;
                break;
            case 'multiple':
                $lista = is_array($v) ? array_values(array_unique(array_map('strval', $v))) : [];
                if ($lista === [] || array_diff($lista, $opciones)) {
                    throw new InvalidArgumentException("«{$etiqueta}» tiene opciones no válidas.");
                }
                // Se guarda en el orden de la plantilla, no en el de llegada.
                $salida[$clave] = array_values(array_intersect($opciones, $lista));
                break;
            case 'rst':
                if (!is_array($v)) {
                    throw new InvalidArgumentException("«{$etiqueta}» no es válido.");
                }
                $tabla = [];
                foreach ($v as $opcion => $fases) {
                    if (!in_array((string) $opcion, $opciones, true) || !is_array($fases)) {
                        throw new InvalidArgumentException("«{$etiqueta}» tiene una fila no válida.");
                    }
                    $marcadas = array_values(array_intersect($campo['fases'], array_map('strval', $fases)));
                    if ($marcadas !== []) {
                        $tabla[(string) $opcion] = $marcadas;
                    }
                }
                if ($tabla !== []) {
                    $salida[$clave] = $tabla;
                } elseif ($campo['obligatorio']) {
                    $faltan[] = $etiqueta;
                }
                break;
        }
        } catch (InvalidArgumentException $e) {
            // Modo tolerante (respuestas capturadas con una versión anterior de
            // la plantilla): se guarda lo que sigue siendo válido y se avisa,
            // en lugar de dejar la inspección del teléfono atascada.
            if (!$tolerante) {
                throw $e;
            }
            $avisos[] = $e->getMessage();
            $rescatado = plantillaRescatarValor($campo, $v, $opciones);
            if ($rescatado !== null) {
                $salida[$clave] = $rescatado;
            }
        }
    }
    if ($exigirObligatorios && $faltan !== []) {
        throw new InvalidArgumentException('Faltan campos obligatorios: ' . implode(', ', $faltan) . '.');
    }
    return $salida;
}

/** Normaliza los tamaños propios de las fotos de un panel. Devuelve un objeto JSON. */
function plantillaTamanosPanel(mixed $tamanos, array $claves): object
{
    $salida = [];
    $tamanos = is_object($tamanos) ? (array) $tamanos : $tamanos;
    if (is_array($tamanos)) {
        foreach ($claves as $clave) {
            $t = $tamanos[$clave] ?? null;
            $t = is_object($t) ? (array) $t : $t;
            if (!is_array($t)) {
                continue;
            }
            $w = plantillaEntero($t['w'] ?? 0, 0, 12, 0);
            $h = plantillaEntero($t['h'] ?? 0, 0, 220, 0);
            $w = $w > 0 ? max(2, $w) : 0;
            $h = $h > 0 ? max(20, (int) round($h / 5) * 5) : 0;
            if ($w > 0 || $h > 0) {
                $salida[$clave] = ['w' => $w, 'h' => $h];
            }
        }
    }
    return (object) $salida;
}

/** Parte aún válida de una respuesta de opciones que ya no coincide con la plantilla. */
function plantillaRescatarValor(array $campo, mixed $v, array $opciones): mixed
{
    if ($campo['tipo'] === 'multiple' && is_array($v)) {
        $lista = array_values(array_intersect($opciones, array_map('strval', $v)));
        return $lista !== [] ? $lista : null;
    }
    if ($campo['tipo'] === 'rst' && is_array($v)) {
        $tabla = [];
        foreach ($v as $opcion => $fases) {
            if (in_array((string) $opcion, $opciones, true) && is_array($fases)) {
                $marcadas = array_values(array_intersect($campo['fases'], array_map('strval', $fases)));
                if ($marcadas !== []) {
                    $tabla[(string) $opcion] = $marcadas;
                }
            }
        }
        return $tabla !== [] ? $tabla : null;
    }
    return null;
}

/** Texto legible de una respuesta, para PDF y Excel. */
function plantillaValorLegible(array $campo, mixed $valor): string
{
    if (plantillaValorVacio($valor)) {
        return '';
    }
    $textos = [];
    foreach ($campo['opciones'] ?? [] as $opcion) {
        $textos[$opcion['valor']] = $opcion['texto'];
    }
    return match ($campo['tipo']) {
        'si_no' => $valor === 'si' ? 'Sí' : 'No',
        'seleccion' => $textos[$valor] ?? (string) $valor,
        'multiple' => implode(', ', array_map(static fn($v) => $textos[$v] ?? (string) $v, (array) $valor)),
        'numero' => rtrim(rtrim(number_format((float) $valor, (int) ($campo['decimales'] ?? 2), '.', ''), '0'), '.')
            . (($campo['unidad'] ?? '') !== '' ? ' ' . $campo['unidad'] : ''),
        'fecha' => date('d/m/Y', strtotime((string) $valor) ?: time()),
        'rst' => implode('; ', array_map(
            static fn($op, $fases) => ($textos[$op] ?? $op) . ' (' . implode(',', (array) $fases) . ')',
            array_keys((array) $valor),
            array_values((array) $valor)
        )),
        default => (string) $valor,
    };
}

function plantillaRespuestasDePoste(PDO $db, int $postId): array
{
    plantillasAsegurarEsquema($db);
    $q = $db->prepare('SELECT respuestas FROM poste_respuestas WHERE poste_id = ?');
    $q->execute([$postId]);
    $json = $q->fetchColumn();
    $datos = is_string($json) ? json_decode($json, true) : null;
    return is_array($datos) ? $datos : [];
}

/** Fotos activas de una estructura con plantilla, en el formato que usa el PDF. */
function plantillaFotosDePoste(PDO $db, int $postId): array
{
    plantillasAsegurarEsquema($db);
    $q = $db->prepare(
        'SELECT foto_clave, indice, ruta_archivo, fecha_captura, utm_este, utm_norte, zona, uuid, checksum, fecha_subida
         FROM imagenes_plantilla WHERE poste_id = ? AND activo = 1 ORDER BY foto_clave, indice'
    );
    $q->execute([$postId]);
    return $q->fetchAll();
}

/**
 * Recalcula las banderas de la estructura: inspección completa cuando hay
 * respuestas y todas las fotos obligatorias (con su cantidad) están subidas.
 */
function plantillaRefrescarEstadoPoste(PDO $db, int $postId, array $config): array
{
    $fotos = plantillaFotosDePoste($db, $postId);
    $porClave = [];
    foreach ($fotos as $f) {
        $porClave[$f['foto_clave']] = ($porClave[$f['foto_clave']] ?? 0) + 1;
    }
    $esperadas = 0;
    $faltanObligatorias = 0;
    foreach ($config['fotos'] ?? [] as $foto) {
        $esperadas += (int) $foto['cantidad'];
        if ($foto['obligatoria'] && ($porClave[$foto['clave']] ?? 0) < (int) $foto['cantidad']) {
            $faltanObligatorias++;
        }
    }
    $q = $db->prepare('SELECT formulario_subido FROM postes WHERE id = ?');
    $q->execute([$postId]);
    $formulario = (int) ($q->fetchColumn() ?: 0) === 1;
    $imagenesCompletas = $faltanObligatorias === 0 && count($fotos) > 0;
    $db->prepare('UPDATE postes SET imagenes_subidas = ?, sincronizado = ? WHERE id = ?')
        ->execute([$imagenesCompletas ? 1 : 0, ($formulario && $imagenesCompletas) ? 1 : 0, $postId]);
    // Mismas banderas que `sincronizacion_estado.php`: la app las guarda tal
    // cual, así que `formulario_subido` debe venir siempre.
    return [
        'formulario_subido' => $formulario,
        'fotos_esperadas' => $esperadas,
        'fotos_sincronizadas' => count($fotos),
        'obligatorias_pendientes' => $faltanObligatorias,
        'imagenes_subidas' => $imagenesCompletas,
        'sincronizado' => $formulario && $imagenesCompletas,
    ];
}

// ---------------------------------------------------------------------------
// Logos
// ---------------------------------------------------------------------------

function plantillaDirectorio(int $plantillaId, bool $crear = false): string
{
    $dir = storagePath('report_config', $crear) . DIRECTORY_SEPARATOR . 'plantillas'
        . DIRECTORY_SEPARATOR . 'plantilla_' . max(0, $plantillaId);
    if ($crear) {
        ensureDirectory($dir, 0775);
    }
    return $dir;
}

// ---------------------------------------------------------------------------
// Logos y firmas: copia en la base de datos
// ---------------------------------------------------------------------------
//
// Los PNG viven en report_config, pero esa carpeta puede no ser un volumen
// persistente (en el NAS se perdía al reconstruir el contenedor). Por eso
// cada imagen se guarda también en la tabla `plantilla_archivos`: el disco
// queda como caché y, si el archivo falta, se restaura desde la base.

function plantillaBd(?PDO $db = null): ?PDO
{
    if ($db instanceof PDO) {
        return $db;
    }
    return ($GLOBALS['conn'] ?? null) instanceof PDO ? $GLOBALS['conn'] : null;
}

/** Crea la tabla si falta (consultando antes, para no emitir DDL en cada pedido). */
function plantillaArchivosTabla(?PDO $db = null): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    $db = plantillaBd($db);
    if ($db === null) {
        return false;
    }
    try {
        $existe = (int) $db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plantilla_archivos'"
        )->fetchColumn();
        if ($existe === 0) {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS `plantilla_archivos` (
                  `plantilla_id` INT NOT NULL,
                  `archivo` VARCHAR(64) NOT NULL,
                  `contenido` MEDIUMBLOB NOT NULL,
                  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`plantilla_id`, `archivo`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }
        $ok = true;
    } catch (Throwable $e) {
        appLog('administracion', 'No se pudo preparar la tabla plantilla_archivos.', ['motivo' => $e->getMessage()]);
        $ok = false;
    }
    return $ok;
}

function plantillaArchivoGuardarEnBd(int $plantillaId, string $archivo, string $contenido, ?PDO $db = null): void
{
    $db = plantillaBd($db);
    if ($db === null || $contenido === '' || !plantillaArchivosTabla($db)) {
        return;
    }
    try {
        $db->prepare(
            'INSERT INTO plantilla_archivos (plantilla_id, archivo, contenido) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE contenido = VALUES(contenido)'
        )->execute([$plantillaId, $archivo, $contenido]);
    } catch (Throwable $e) {
        appLog('administracion', 'No se pudo guardar una imagen de plantilla en la base.', ['archivo' => $archivo, 'motivo' => $e->getMessage()]);
    }
}

/**
 * Ruta en disco de un logo o firma. Si falta en disco se restaura desde la
 * base; si está en disco pero no en la base, se respalda.
 */
function plantillaRutaLogo(int $plantillaId, ?string $archivo, ?PDO $db = null): ?string
{
    if ($archivo === null || !preg_match(PLANTILLA_LOGO_REGEX, $archivo)) {
        return null;
    }
    static $revisados = [];
    $ruta = plantillaDirectorio($plantillaId) . DIRECTORY_SEPARATOR . $archivo;
    $db = plantillaBd($db);
    $clave = $plantillaId . '/' . $archivo;

    if (is_file($ruta)) {
        if ($db !== null && !isset($revisados[$clave]) && plantillaArchivosTabla($db)) {
            $revisados[$clave] = true;
            try {
                $q = $db->prepare('SELECT 1 FROM plantilla_archivos WHERE plantilla_id = ? AND archivo = ?');
                $q->execute([$plantillaId, $archivo]);
                if ($q->fetchColumn() === false) {
                    plantillaArchivoGuardarEnBd($plantillaId, $archivo, (string) @file_get_contents($ruta), $db);
                }
            } catch (Throwable) {
                // El respaldo es una ayuda: nunca impide usar el archivo.
            }
        }
        return $ruta;
    }

    if ($db === null || !plantillaArchivosTabla($db)) {
        return null;
    }
    try {
        $q = $db->prepare('SELECT contenido FROM plantilla_archivos WHERE plantilla_id = ? AND archivo = ?');
        $q->execute([$plantillaId, $archivo]);
        $contenido = $q->fetchColumn();
    } catch (Throwable) {
        return null;
    }
    if (!is_string($contenido) || $contenido === '') {
        return null;
    }
    try {
        $dir = plantillaDirectorio($plantillaId, true);
        if (@file_put_contents($dir . DIRECTORY_SEPARATOR . $archivo, $contenido) !== false) {
            @chmod($ruta, 0664);
            return $ruta;
        }
    } catch (Throwable) {
        // Carpeta no escribible: se usa un temporal.
    }
    $temporal = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ecoing_plantilla_' . $plantillaId . '_' . $archivo;
    return @file_put_contents($temporal, $contenido) !== false ? $temporal : null;
}

/**
 * Normaliza un logo subido: lo recorta a su contenido, lo encaja en un lienzo
 * transparente de proporción 35×13 (la del encabezado) y lo guarda como PNG.
 * Así cualquier logo, ancho o alto, se ve centrado y del mismo tamaño visual.
 */
function plantillaGuardarLogo(array $archivo, int $plantillaId, string $lado, ?PDO $db = null): string
{
    if (!in_array($lado, ['izquierdo', 'derecho', 'firma'], true)) {
        throw new InvalidArgumentException('Lado del logo no válido.');
    }
    $esFirma = $lado === 'firma';
    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('No llegó el archivo del logo.');
    }
    if (($archivo['size'] ?? 0) <= 0 || $archivo['size'] > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('El logo debe pesar como máximo 5 MB.');
    }
    $info = @getimagesize((string) $archivo['tmp_name']);
    if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
        throw new InvalidArgumentException('El logo debe ser PNG, JPG o WEBP.');
    }
    if ($info[0] * $info[1] > 25_000_000) {
        throw new InvalidArgumentException('La resolución del logo es demasiado grande.');
    }
    $contenido = (string) file_get_contents((string) $archivo['tmp_name']);
    $origen = @imagecreatefromstring($contenido);
    if (!$origen) {
        throw new InvalidArgumentException('No se pudo leer la imagen del logo.');
    }
    imagealphablending($origen, true);
    imagesavealpha($origen, true);

    // Recorte del margen vacío (transparente o casi blanco).
    [$ow, $oh] = [imagesx($origen), imagesy($origen)];
    // Firma escaneada o fotografiada: el papel rara vez es blanco puro. Se
    // estima su tono (el más frecuente entre los claros) para recortarlo y
    // volverlo transparente.
    $papel = 255;
    if ($esFirma) {
        $muestras = [];
        $pasoM = max(1, (int) floor(max($ow, $oh) / 220));
        for ($y = 0; $y < $oh; $y += $pasoM) {
            for ($x = 0; $x < $ow; $x += $pasoM) {
                $c = imagecolorat($origen, $x, $y);
                if ((($c >> 24) & 0x7F) > 110) {
                    continue;
                }
                $muestras[] = min(($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF);
            }
        }
        if ($muestras !== []) {
            sort($muestras);
            $papel = max(120, $muestras[(int) floor((count($muestras) - 1) * 0.75)]);
        }
    }
    // Transparencia (0 = opaco, 127 = invisible) que corresponde al fondo de
    // una firma: el papel, y también el gris neutro claro (cuadriculado de
    // «PNG falso» o sombra del escaneo). La tinta azul o negra queda opaca.
    $fondo = static function (int $r, int $g, int $b) use ($papel): int {
        $claro = min($r, $g, $b);
        $neutro = max($r, $g, $b) - $claro < 24;
        if ($claro > $papel - 30 || ($neutro && $claro > 175)) {
            return 127;
        }
        $alfa = 0;
        if ($claro > $papel - 95) {
            $alfa = (int) round(($claro - ($papel - 95)) / 65 * 127);
        }
        if ($neutro && $claro > 140) {
            $alfa = max($alfa, (int) round(($claro - 140) / 35 * 127));
        }
        return min(127, $alfa);
    };
    $vacio = static function (int $rgba) use ($esFirma, $fondo): bool {
        $a = ($rgba >> 24) & 0x7F;
        $r = ($rgba >> 16) & 0xFF; $g = ($rgba >> 8) & 0xFF; $b = $rgba & 0xFF;
        if ($esFirma) {
            return $a > 110 || $fondo($r, $g, $b) >= 120;
        }
        return $a > 110 || ($r > 245 && $g > 245 && $b > 245);
    };
    $paso = max(1, (int) floor(max($ow, $oh) / 600));
    [$minX, $minY, $maxX, $maxY] = [$ow, $oh, -1, -1];
    for ($y = 0; $y < $oh; $y += $paso) {
        for ($x = 0; $x < $ow; $x += $paso) {
            if (!$vacio(imagecolorat($origen, $x, $y))) {
                $minX = min($minX, $x); $maxX = max($maxX, $x);
                $minY = min($minY, $y); $maxY = max($maxY, $y);
            }
        }
    }
    if ($maxX < 0) {
        [$minX, $minY, $maxX, $maxY] = [0, 0, $ow - 1, $oh - 1];
    }
    $minX = max(0, $minX - $paso); $minY = max(0, $minY - $paso);
    $maxX = min($ow - 1, $maxX + $paso); $maxY = min($oh - 1, $maxY + $paso);
    $cw = $maxX - $minX + 1;
    $ch = $maxY - $minY + 1;

    // Logo: lienzo 700×260 (35:13) con 4 % de aire. Firma: su propia
    // proporción, como máximo 900×360, sin aire.
    if ($esFirma) {
        $escalaF = min(1.0, 900 / $cw, 360 / $ch);
        $lw = max(1, (int) round($cw * $escalaF)); $lh = max(1, (int) round($ch * $escalaF)); $aire = 0.0;
    } else {
        $lw = 700; $lh = 260; $aire = 0.04;
    }
    $escala = min(($lw * (1 - 2 * $aire)) / $cw, ($lh * (1 - 2 * $aire)) / $ch);
    $dw = max(1, (int) round($cw * $escala));
    $dh = max(1, (int) round($ch * $escala));
    $lienzo = imagecreatetruecolor($lw, $lh);
    imagealphablending($lienzo, false);
    imagesavealpha($lienzo, true);
    imagefill($lienzo, 0, 0, imagecolorallocatealpha($lienzo, 255, 255, 255, 127));
    imagealphablending($lienzo, true);
    imagecopyresampled($lienzo, $origen, (int) (($lw - $dw) / 2), (int) (($lh - $dh) / 2), $minX, $minY, $dw, $dh, $cw, $ch);
    imagedestroy($origen);

    if ($esFirma) {
        // Firma escaneada en papel: el fondo blanco se vuelve transparente
        // para que se apoye limpia sobre la línea.
        imagealphablending($lienzo, false);
        for ($y = 0; $y < $lh; $y++) {
            for ($x = 0; $x < $lw; $x++) {
                $c = imagecolorat($lienzo, $x, $y);
                $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
                $alfa = $fondo($r, $g, $b);
                if ($alfa > 0) {
                    $actual = ($c >> 24) & 0x7F;
                    imagesetpixel($lienzo, $x, $y, imagecolorallocatealpha($lienzo, $r, $g, $b, max($actual, $alfa)));
                }
            }
        }
    }

    $nombre = ($esFirma ? 'firma' : 'logo_' . $lado) . '_' . bin2hex(random_bytes(8)) . '.png';
    $destino = plantillaDirectorio($plantillaId, true) . DIRECTORY_SEPARATOR . $nombre;
    imagesavealpha($lienzo, true);
    if (!imagepng($lienzo, $destino, 6)) {
        imagedestroy($lienzo);
        throw new RuntimeException('No se pudo guardar la imagen.');
    }
    imagedestroy($lienzo);
    @chmod($destino, 0664);
    plantillaArchivoGuardarEnBd($plantillaId, $nombre, (string) @file_get_contents($destino), $db);
    return $nombre;
}

// ---------------------------------------------------------------------------
// Plantilla base: copia del proyecto Electro Puno
// ---------------------------------------------------------------------------

function plantillaOpciones(array $valores): array
{
    return array_map(static fn(string $v): array => ['valor' => $v, 'texto' => plantillaEtiquetaDeClave($v)], $valores);
}

function plantillaBaseElectroPuno(): array
{
    $r = inspectionRules();
    $sel = static fn(string $clave, string $etiqueta, bool $oblig = false): array =>
        ['clave' => $clave, 'etiqueta' => $etiqueta, 'tipo' => 'seleccion', 'obligatorio' => $oblig, 'opciones' => plantillaOpciones($r[$clave])];
    $num = static fn(string $clave, string $etiqueta, string $unidad, int $dec, bool $oblig = false): array =>
        ['clave' => $clave, 'etiqueta' => $etiqueta, 'tipo' => 'numero', 'obligatorio' => $oblig, 'unidad' => $unidad, 'decimales' => $dec, 'min' => 0];
    $rstConductor = plantillaOpciones(['hebras_rotas', 'encanastillado', 'empalme_deformado', 'objetos_extranos']);

    $pasos = [
        ['id' => 'faja', 'titulo' => 'Faja y vegetación', 'campos' => [
            ['clave' => 'obstaculos_faja', 'etiqueta' => 'Obstáculos en la faja de servidumbre', 'tipo' => 'multiple',
             'opciones' => plantillaOpciones($r['obstaculos_faja'])],
            $sel('estado_cuencas', 'Estado de cuencas'),
            ['clave' => 'marcado_arboles', 'etiqueta' => 'Marcado de árboles', 'tipo' => 'si_no'],
            $sel('criticidad_tala', 'Criticidad de tala'),
            $sel('criticidad_contacto', 'Criticidad de contacto'),
            $sel('notificacion_propietario', 'Notificación al propietario'),
        ]],
        ['id' => 'torre', 'titulo' => 'Torre y accesos', 'campos' => [
            $sel('tipo_torre', 'Tipo de torre'),
            $sel('ubicacion', 'Ubicación'),
            $sel('acceso_torre', 'Acceso a la torre'),
            $num('distancia_acceso', 'Distancia de acceso', 'm', 2, true),
            $sel('estado_acceso', 'Estado del acceso'),
        ]],
        ['id' => 'placas', 'titulo' => 'Placas y seguridad', 'campos' => [
            $sel('estado_placas_torre', 'Placa de la torre'),
            $sel('estado_placas_linea', 'Placa de la línea'),
            $sel('estado_placas_fases', 'Placa de fases'),
            $sel('peligro_cerco', 'Peligro en cerco'),
            $sel('peligro_torre', 'Peligro en torre'),
            $sel('puesta_tierra', 'Puesta a tierra'),
            $sel('retenida', 'Retenida'),
        ]],
        ['id' => 'estructura', 'titulo' => 'Estructura y herrajes', 'campos' => [
            $sel('estado_base', 'Estado de la base'),
            ['clave' => 'limpiar_base', 'etiqueta' => 'Limpiar base', 'tipo' => 'si_no'],
            $sel('crucetas_mensuales', 'Crucetas y ménsulas'),
            $sel('perfiles_angulares', 'Perfiles angulares'),
            $sel('malla_antiescalamiento', 'Malla antiescalamiento'),
            $sel('oxidos_base', 'Óxidos en la base'),
            $sel('cadena_aisladores', 'Cadena de aisladores'),
            $sel('tipo_aislador', 'Tipo de aislador'),
            $sel('conductor_bajada_pat', 'Conductor de bajada a PAT'),
            $sel('conductor_guarda', 'Conductor de guarda'),
        ]],
        ['id' => 'rst', 'titulo' => 'Tablero RST y cierre', 'campos' => [
            ['clave' => 'conductores_fase', 'etiqueta' => 'Conductores de fase', 'tipo' => 'rst', 'opciones' => $rstConductor],
            ['clave' => 'conductores_cuellos', 'etiqueta' => 'Conductores de cuellos', 'tipo' => 'rst', 'opciones' => $rstConductor],
            ['clave' => 'estado_aisladores', 'etiqueta' => 'Estado de aisladores', 'tipo' => 'rst',
             'opciones' => plantillaOpciones(['buen_estado', 'rotos_suspension', 'rotos_anclaje_adelante', 'rotos_anclaje_atras', 'mal_estado'])],
            $num('cantidad_pat', 'Cantidad de PAT', '', 0),
            $num('distancia_poste_anterior', 'Distancia al poste anterior', 'm', 3),
            $num('distancia_vertical', 'Distancia vertical (DMS)', 'm', 3),
            $num('distancia_horizontal', 'Distancia horizontal (DMS)', 'm', 3),
            ['clave' => 'comentarios', 'etiqueta' => 'Comentarios', 'tipo' => 'parrafo'],
        ]],
    ];

    $categorias = [
        'Torre y entorno' => ['foto_panoramica', 'placa', 'torre_parte_inferior', 'torre_parte_superior', 'base_torre',
            'mensulas', 'crucetas', 'perfiles_angulares', 'atiescalamiento', 'puesta_tierra', 'puesta_tierra_2',
            'retenida', 'faja_servidumbre', 'ubicacion_acceso', 'otros'],
        'Aisladores' => ['aisladores_fase_r_atras', 'aisladores_fase_s_atras', 'aisladores_fase_t_atras',
            'aisladores_fase_r_adelante', 'aisladores_fase_s_adelante', 'aisladores_fase_t_adelante'],
        'Conductores y ferretería' => ['ferreteria_fase_r', 'ferreteria_fase_s', 'ferreteria_fase_t', 'cable_guarda',
            'ferreteria_de_cable_de_guarda', 'conductor', 'ferreteria_de_conductor'],
    ];
    $nombres = [
        'foto_panoramica' => 'Panorámica', 'placa' => 'Placa', 'torre_parte_inferior' => 'Torre · parte inferior',
        'torre_parte_superior' => 'Torre · parte superior', 'base_torre' => 'Base de la torre', 'mensulas' => 'Ménsulas',
        'crucetas' => 'Crucetas', 'perfiles_angulares' => 'Perfiles angulares', 'atiescalamiento' => 'Malla antiescalamiento',
        'otros' => 'Otros', 'aisladores_fase_r_atras' => 'Aisladores fase R · atrás', 'aisladores_fase_s_atras' => 'Aisladores fase S · atrás',
        'aisladores_fase_t_atras' => 'Aisladores fase T · atrás', 'aisladores_fase_r_adelante' => 'Aisladores fase R · adelante',
        'aisladores_fase_s_adelante' => 'Aisladores fase S · adelante', 'aisladores_fase_t_adelante' => 'Aisladores fase T · adelante',
        'ferreteria_fase_r' => 'Ferretería fase R', 'ferreteria_fase_s' => 'Ferretería fase S', 'ferreteria_fase_t' => 'Ferretería fase T',
        'cable_guarda' => 'Cable de guarda', 'ferreteria_de_cable_de_guarda' => 'Ferretería del cable de guarda',
        'conductor' => 'Conductor', 'ferreteria_de_conductor' => 'Ferretería del conductor', 'puesta_tierra' => 'Puesta a tierra',
        'puesta_tierra_2' => 'Puesta a tierra 2', 'retenida' => 'Retenida', 'faja_servidumbre' => 'Faja de servidumbre',
        'ubicacion_acceso' => 'Ubicación / acceso',
    ];
    $fotos = [];
    foreach (photoTypes() as $tipo) {
        $categoria = 'General';
        foreach ($categorias as $nombreCat => $lista) {
            if (in_array($tipo, $lista, true)) {
                $categoria = $nombreCat;
            }
        }
        $fotos[] = [
            'clave' => $tipo, 'nombre' => $nombres[$tipo] ?? plantillaEtiquetaDeClave($tipo),
            'obligatoria' => $tipo === 'foto_panoramica', 'cantidad' => 1,
            'categoria' => $categoria, 'panoramica' => $tipo === 'foto_panoramica',
        ];
    }

    $azul = '#305496'; $verde = '#548235'; $oro = '#BF8F00'; $azulClaro = '#0070C0';
    $gris = '#7B7B7B'; $naranja = '#C65911'; $oscuro = '#333F4F'; $azulMedio = '#2F75B5';
    $c = static fn(string $id, string $titulo, string $color, int $x, int $y, int $w, int $h, array $campos, int $cols = 1): array =>
        ['id' => $id, 'tipo' => 'campos', 'titulo' => $titulo, 'color' => $color, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
         'campos' => $campos, 'columnas' => $cols];
    $cuadros = [
        $c('faja', 'Obstáculos Faja de Servidumbre', $azul, 0, 0, 8, 6, ['obstaculos_faja'], 2),
        $c('cuencas', 'Estado de Cuencas', $azul, 8, 0, 4, 6, ['estado_cuencas']),
        $c('marcado', 'Marcado de Árboles', $azul, 12, 0, 2, 6, ['marcado_arboles']),
        ['id' => 'panoramica', 'tipo' => 'panoramica', 'titulo' => 'Foto Panorámica', 'color' => '#F0F0F0', 'x' => 14, 'y' => 0, 'w' => 10, 'h' => 25],
        $c('tala', 'Criticidad de Tala', $azul, 0, 6, 3, 6, ['criticidad_tala']),
        $c('contacto', 'Criticidad de Contacto', $azul, 3, 6, 3, 6, ['criticidad_contacto']),
        $c('notificacion', 'Notificación a Propietario', $azul, 6, 6, 4, 6, ['notificacion_propietario']),
        $c('tipo_torre', 'Tipo de Torre', $verde, 10, 6, 4, 6, ['tipo_torre']),
        $c('ubicacion', 'Ubicación', $verde, 0, 12, 7, 6, ['ubicacion'], 2),
        $c('acceso', 'Acceso Torre', $verde, 7, 12, 4, 6, ['acceso_torre', 'distancia_acceso']),
        $c('estado_acceso', 'Estado Acceso', $verde, 11, 12, 3, 6, ['estado_acceso']),
        $c('placas', 'Estado de Placas y Peligros', $oro, 0, 18, 10, 7,
            ['estado_placas_torre', 'estado_placas_linea', 'estado_placas_fases', 'peligro_cerco', 'peligro_torre', 'puesta_tierra'], 2),
        $c('retenida', 'Retenida', $azulClaro, 10, 18, 4, 7, ['retenida']),
        $c('base', 'Estado de la Base', $gris, 0, 25, 4, 6, ['estado_base']),
        $c('limpiar', 'Limpiar Base', $gris, 4, 25, 3, 6, ['limpiar_base']),
        $c('crucetas', 'Crucetas y Ménsulas', $gris, 7, 25, 6, 6, ['crucetas_mensuales']),
        $c('perfiles', 'Perfiles Angulares', $gris, 13, 25, 5, 6, ['perfiles_angulares']),
        $c('malla', 'Malla Antiescalamiento', $gris, 18, 25, 6, 6, ['malla_antiescalamiento']),
        $c('oxidos', 'Óxidos en Base', $gris, 0, 31, 8, 3, ['oxidos_base'], 3),
        $c('cadena', 'Cadena de Aisladores', $naranja, 0, 34, 5, 7, ['cadena_aisladores']),
        $c('tipo_aislador', 'Tipo de Aislador', $naranja, 5, 34, 4, 7, ['tipo_aislador']),
        $c('aisladores', 'Estado de Aisladores', $naranja, 9, 34, 8, 7, ['estado_aisladores']),
        $c('pat', 'Conductor Bajada a PAT', $oscuro, 17, 34, 7, 7, ['conductor_bajada_pat']),
        $c('fase', 'Conductores de Fase', $azulMedio, 0, 41, 7, 7, ['conductores_fase']),
        $c('cuellos', 'Conductores de Cuellos', $azulMedio, 7, 41, 7, 7, ['conductores_cuellos']),
        $c('guarda', 'Conductores de Guarda', $azulMedio, 14, 41, 5, 7, ['conductor_guarda']),
        $c('dms', 'PAT y Distancias DMS', $azulMedio, 19, 41, 5, 7,
            ['cantidad_pat', 'distancia_poste_anterior', 'distancia_vertical', 'distancia_horizontal']),
    ];

    $paneles = [
        ['titulo' => 'Panel fotográfico de la estructura', 'color' => $gris, 'columnas' => 3,
         'fotos' => ['placa', 'torre_parte_inferior', 'torre_parte_superior', 'base_torre', 'mensulas', 'crucetas',
             'perfiles_angulares', 'atiescalamiento', 'otros']],
        ['titulo' => 'Aisladores', 'color' => $naranja, 'columnas' => 3,
         'fotos' => ['aisladores_fase_r_atras', 'aisladores_fase_s_atras', 'aisladores_fase_t_atras',
             'aisladores_fase_r_adelante', 'aisladores_fase_s_adelante', 'aisladores_fase_t_adelante',
             'ferreteria_fase_r', 'ferreteria_fase_s', 'ferreteria_fase_t']],
        ['titulo' => 'Cable de guarda y OPGW', 'color' => $azul, 'columnas' => 2, 'fotos' => ['cable_guarda', 'ferreteria_de_cable_de_guarda']],
        ['titulo' => 'Conductores', 'color' => $verde, 'columnas' => 2, 'fotos' => ['conductor', 'ferreteria_de_conductor']],
        ['titulo' => 'Puesta a tierra', 'color' => $naranja, 'columnas' => 2, 'fotos' => ['puesta_tierra', 'puesta_tierra_2'], 'nueva_pagina' => true],
        ['titulo' => 'Faja de servidumbre / Ubicación acceso', 'color' => $gris, 'columnas' => 2,
         'fotos' => ['faja_servidumbre', 'ubicacion_acceso'], 'mostrar_nombres' => false],
        ['titulo' => 'Retenida', 'color' => $verde, 'columnas' => 2, 'fotos' => ['retenida'], 'mostrar_nombres' => false],
    ];

    return [
        'formulario' => ['pasos' => $pasos],
        'fotos' => $fotos,
        'pdf' => [
            'encabezado' => [
                'titulo' => 'SERVICIO DE MANTENIMIENTO DE LINEAS DE TRANSMISION',
                'subtitulo' => 'DE 60 KV DE ELECTRO PUNO S.A.A',
                'color_barra' => '#0A2D64', 'color_texto_barra' => '#FFFFFF', 'etiqueta_codigo' => 'IL',
            ],
            'hoja1' => ['cuadros' => $cuadros],
            'paneles' => $paneles,
            'comentarios' => ['campo' => 'comentarios', 'titulo' => 'COMENTARIOS / OBSERVACIONES'],
            'firmas' => [
                ['nombre' => 'Victor Raul Ramos Gomez', 'cargo' => 'Supervisor INTESEL'],
                ['nombre' => 'EDUARDO PANCORBO SALAS', 'cargo' => 'Supervisor de obra ELPU'],
            ],
        ],
    ];
}
