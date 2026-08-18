# Migraciones de la base local

Base: SQLite (`sqflite`), archivo `app_local.db`.
Código: [`lib/database/migraciones.dart`](lib/database/migraciones.dart), conectado desde [`lib/database/database_helper.dart`](lib/database/database_helper.dart).
Pruebas: [`test/database/migraciones_test.dart`](test/database/migraciones_test.dart) — 11 casos.

## Reglas invariables

1. **Nunca se borra información del inspector.** Todas las migraciones son aditivas (`ALTER TABLE ADD COLUMN`, `CREATE INDEX`). Cuando hay que consolidar filas, primero se copian a una tabla de historial.
2. **Toda migración es idempotente.** Cada paso comprueba si ya está aplicado. Si la app muere a mitad de una migración, el arranque siguiente la termina sin romperse.
3. **Instalación nueva y actualización convergen.** `onCreate` crea el esquema v1 y después aplica todas las migraciones, de modo que un teléfono recién instalado y uno que viene de la versión anterior acaban con el mismo esquema exacto. Hay una prueba que compara columna por columna.
4. **Sin `PRAGMA foreign_keys`.** Ver la nota al final.

## Versión actual: 4

| Versión | Fecha | Contenido |
|---|---|---|
| 1 | (original) | Esquema base: `proyectos`, `postes`, `poste_datos`, `poste_secciones_rst`, `formularios_pendientes`, `imagenes_poste_local` |
| **2** | 2026-08-18 | Estados de sincronización explícitos y trazabilidad de fotografías |
| **3** | 2026-08-18 | Registro de campos revisados del formulario |
| **4** | 2026-08-18 | Coordenadas `utm_x`, `utm_y` y `zona` del padrón en SQLite |

---

## v1 → v2 · Estados explícitos y trazabilidad

### Problema que resuelve

| Defecto en v1 | Consecuencia en campo |
|---|---|
| `imagenes_poste_local.sincronizada` era un booleano que se ponía a 1 **sin comprobar la respuesta del servidor** | Una foto que falló quedaba marcada como enviada, invisible para siempre: nunca se reintentaba |
| No había forma de saber si una foto falló, cuántas veces se intentó ni por qué | Imposible diagnosticar nada desde el teléfono |
| `formularios_pendientes` sin `UNIQUE(poste_id)` | Cada envío insertaba una fila nueva; la cola crecía sin límite |
| `formularios_pendientes.enviado` nunca se actualizaba a 1 | Un formulario ya enviado contaba como pendiente para siempre |
| `getFormularioPorPoste` usaba `LIMIT 1` sin `ORDER BY` | Podía devolver la versión más antigua y resincronizar datos obsoletos |
| Sin identificador estable ni checksum | Imposible detectar reenvíos duplicados o verificar integridad del archivo |
| Sin índices | Las listas hacían recorrido completo de tabla |

### Cambios de esquema

**`imagenes_poste_local` — 20 columnas nuevas**

| Columna | Tipo | Para qué |
|---|---|---|
| `uuid` | TEXT | Identificador local estable; permite idempotencia en reintentos |
| `proyecto_id` | INTEGER | Evita un JOIN en cada consulta de resumen |
| `linea` | TEXT | Idem |
| `ruta_original` | TEXT | Ruta del original, si la política de retención lo conserva |
| `ruta_miniatura` | TEXT | Miniatura para la cuadrícula (se usará en la fase de imágenes) |
| `tamano_original` | INTEGER | Bytes antes de optimizar |
| `tamano_optimizado` | INTEGER | Bytes tras optimizar |
| `ancho`, `alto` | INTEGER | Dimensiones, para decidir si hace falta recomprimir |
| `formato` | TEXT | jpg / heic / png |
| `latitud`, `longitud` | REAL | Coordenada geográfica cruda |
| `precision_gps` | REAL | Metros de incertidumbre; permite avisar de un GPS malo |
| `estado` | TEXT | Máquina de estados (ver abajo) |
| `intentos` | INTEGER | Nº de intentos de envío |
| `ultimo_error` | TEXT | Mensaje real del último fallo |
| `fecha_ultimo_intento` | TEXT | Cuándo se intentó por última vez |
| `checksum` | TEXT | SHA-256 del archivo; integridad + deduplicación |
| `id_remoto` | TEXT | Identificador que devuelva el servidor |
| `creado_en` | TEXT | Fecha de creación del registro local |

**`formularios_pendientes` — 7 columnas nuevas**
`uuid`, `estado`, `intentos`, `ultimo_error`, `fecha_ultimo_intento`, `actualizado_en`, `id_remoto`.

**`poste_datos`** — `actualizado_en`.

**Tabla nueva: `formularios_pendientes_historial`**
`id`, `poste_id`, `datos_json`, `creado_en`, `archivado_en`, `motivo`.
Guarda los borradores duplicados antes de consolidarlos. Nada se pierde.

**Índice único nuevo**
`ux_form_pendiente_poste` → `UNIQUE(poste_id)` en `formularios_pendientes`.
A partir de aquí, un solo borrador vigente por poste.

**Índices de consulta**

| Índice | Tabla / columnas |
|---|---|
| `idx_postes_proyecto_linea` | `postes(proyecto_id, linea)` |
| `idx_postes_estructura` | `postes(estructura)` |
| `idx_img_poste_estado` | `imagenes_poste_local(poste_id, estado)` |
| `idx_img_estado` | `imagenes_poste_local(estado)` |
| `idx_img_checksum` | `imagenes_poste_local(checksum)` |
| `idx_form_estado` | `formularios_pendientes(estado)` |
| `idx_rst_poste` | `poste_secciones_rst(poste_id)` |

### Traducción de los datos existentes

```sql
-- Fotografías
UPDATE imagenes_poste_local
   SET estado = CASE WHEN sincronizada = 1 THEN 'synced' ELSE 'pending' END
 WHERE estado IS NULL;

-- Formularios
UPDATE formularios_pendientes
   SET estado = CASE WHEN enviado = 1 THEN 'synced' ELSE 'pending' END
 WHERE estado IS NULL;
```

Las columnas antiguas `sincronizada` y `enviado` **se conservan y se siguen escribiendo** en paralelo, para no romper ninguna consulta existente que las lea.

> ⚠️ **Advertencia honesta sobre los datos heredados.** En la v1, `sincronizada = 1` no garantizaba confirmación del servidor: se marcaba ignorando el resultado de la subida. La migración respeta el valor existente para no reenviar de golpe todo el histórico de cada teléfono, pero eso significa que **puede haber fotos marcadas como `synced` que el servidor nunca recibió**. Es un problema de datos heredados, no del código nuevo. Se resuelve con una reconciliación contra el servidor (pendiente, fase de sincronización).

### Consolidación de duplicados

1. Se copian a `formularios_pendientes_historial` todas las filas que **no** son `MAX(id)` de su `poste_id`, con `motivo = 'duplicado consolidado en migración v2'`.
2. Se borran de `formularios_pendientes`.
3. Se crea el índice único.

Se conserva la fila de `id` mayor, que es la insertada más recientemente.

### Máquina de estados

```
local ──► pending ──► uploading ──► synced
              ▲            │
              └─── failed ◄┘
                           └──► conflict
```

| Estado | Significado |
|---|---|
| `local` | Guardado en el teléfono, aún sin marcar para envío (borrador en edición) |
| `pending` | Guardado y en cola de envío |
| `uploading` | Envío en curso. Si la app muere aquí, el arranque lo devuelve a `pending` |
| `synced` | **El servidor confirmó la recepción.** Único estado que autoriza a decir "Sincronizado" |
| `failed` | El envío falló. El dato sigue íntegro y se reintentará |
| `conflict` | El servidor ya tenía el registro con datos distintos; requiere decisión |

Definidos en [`lib/core/estados_sync.dart`](lib/core/estados_sync.dart).

---

## Cómo añadir una migración v5

1. Subir `Migraciones.version` a `5`.
2. Añadir un `case 5:` en `Migraciones.aplicar` que llame a un método privado nuevo.
3. Escribir ese método usando `_agregarColumnas` (idempotente) y `CREATE ... IF NOT EXISTS`.
4. Si hay que quitar filas, archivarlas primero.
5. Añadir casos a `test/database/migraciones_test.dart`, incluyendo:
   - que se aplica sobre una base v1 (salto 1→5);
   - que se aplica sobre una base v4 (salto 4→5);
   - que es idempotente;
   - que instalación nueva y actualización convergen.
6. Documentar la versión en este archivo.

**No hacer nunca:**
- `DROP COLUMN` / `DROP TABLE` sobre datos del inspector.
- `DELETE` sin archivar antes.
- Cambiar el tipo de una columna existente (SQLite obligaría a recrear la tabla).
- Depender de que la migración se ejecute una sola vez.

## Nota sobre `PRAGMA foreign_keys`

Las tablas `poste_datos`, `poste_secciones_rst` y `formularios_pendientes` declaran `FOREIGN KEY ... REFERENCES postes(id) ON DELETE CASCADE`, pero **la app nunca ha activado la comprobación** (SQLite la trae desactivada por defecto).

Se decidió **no activarla en P0**: hacerlo convertiría inserciones que hoy funcionan en silencio en fallos nuevos en campo, por ejemplo al guardar un formulario de un poste que por cualquier motivo no esté en la tabla `postes`. Es exactamente el tipo de cambio que puede provocar pérdida de trabajo.

Queda como candidato para la fase de arquitectura, con pruebas que verifiquen antes cada ruta de inserción.
