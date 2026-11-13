# Plantillas de inspección dinámicas

Un proyecto puede tener una **plantilla** (`proyectos.plantilla_id`). La plantilla define:

- el formulario: pasos, campos, tipos, obligatorio u opcional y opciones;
- las fotografías que se piden;
- el diseño del PDF.

Todo se edita desde el panel admin, en la pestaña **Plantillas**.

Si `plantilla_id` es `NULL`, el proyecto sigue el **flujo fijo de siempre**: `poste_datos`, `imagenes_poste`, las 28 fotos y el PDF histórico de Electro Puno. Ese flujo no se modificó.

## Tablas nuevas

Se crean solas la primera vez que se usa la API. También está la migración `database/migrations/20261015_plantillas.sql`.

| Tabla | Para qué |
|---|---|
| `plantillas` | Configuración actual (JSON en `config`) y `version`. La versión sube en cada guardado. |
| `plantilla_versiones` | Historial de cada versión guardada. |
| `poste_respuestas` | Respuestas de una estructura (JSON `clave → valor`). |
| `imagenes_plantilla` | Fotos de una estructura. Pueden ser varias por tipo (`foto_clave` + `indice`). |

## Estructura de la configuración

```json
{
  "esquema": 1,
  "formulario": { "pasos": [
    { "id": "faja", "titulo": "Faja y vegetación", "campos": [
      { "clave": "estado_cuencas", "etiqueta": "Estado de cuencas", "tipo": "seleccion",
        "obligatorio": false, "ayuda": "",
        "opciones": [ { "valor": "seguimiento", "texto": "Seguimiento" } ] }
    ] }
  ] },
  "fotos": [
    { "clave": "foto_panoramica", "nombre": "Panorámica", "obligatoria": true, "cantidad": 1,
      "categoria": "Torre y entorno", "ayuda": "", "panoramica": true }
  ],
  "pdf": {
    "encabezado": { "titulo": "...", "subtitulo": "...", "logo_izquierdo": "logo_izquierdo_<16hex>.png",
                    "logo_derecho": null, "color_barra": "#0A2D64", "color_texto_barra": "#FFFFFF",
                    "etiqueta_codigo": "IL" },
    "hoja1": { "columnas": 24, "filas": 48, "cuadros": [
      { "id": "faja", "tipo": "campos", "titulo": "...", "color": "#305496",
        "x": 0, "y": 0, "w": 8, "h": 6, "campos": ["obstaculos_faja"], "columnas": 2 },
      { "id": "panoramica", "tipo": "panoramica", "titulo": "Foto Panorámica", "x": 14, "y": 0, "w": 10, "h": 25 },
      { "id": "nota", "tipo": "texto", "titulo": "Nota", "texto": "..." }
    ] },
    "paneles": [ { "titulo": "Aisladores", "color": "#C65911", "columnas": 3,
                   "alto_foto": 0, "fotos": ["aisladores_fase_r_atras"], "mostrar_nombres": true, "nueva_pagina": false } ],
    "comentarios": { "campo": "comentarios", "titulo": "COMENTARIOS / OBSERVACIONES" },
    "firmas": [ { "nombre": "...", "cargo": "..." } ]
  }
}
```

### Tipos de campo y formato de cada respuesta

| `tipo` | Qué ve el inspector | Valor guardado |
|---|---|---|
| `texto` | Una línea de texto (`max_largo`) | `"texto"` |
| `parrafo` | Varias líneas | `"texto"` |
| `numero` | Número con `unidad`, `decimales`, `min` y `max` | `12.5` |
| `fecha` | Selector de fecha | `"2026-10-15"` |
| `si_no` | Sí / No | `"si"` o `"no"` |
| `seleccion` | Una opción de `opciones` | `"valor"` |
| `multiple` | Varias opciones | `["valor1", "valor2"]` |
| `rst` | Tabla: opciones (filas) × `fases` (columnas, por defecto R/S/T) | `{"valor": ["R", "T"]}` |

Un campo vacío no se envía, o se envía como `null`. Si un campo `obligatorio` está vacío, la app no deja guardar y el servidor lo rechaza.

### Rejilla de la hoja 1

- La zona útil es de 190 mm de ancho bajo el encabezado, dividida en **24 columnas × 48 filas** de 5 mm.
- Cada cuadro ocupa `x, y, w, h` en celdas de esa rejilla.
- Un cuadro de un solo campo `seleccion`, `multiple` o `si_no` se dibuja como en el PDF histórico: una fila «opción | X» por cada opción, repartidas en `columnas`.
- Un campo `rst` se dibuja como tabla con R, S y T.
- Varios campos en un mismo cuadro se dibujan como «Etiqueta: valor».

## API

Todas las rutas requieren `Authorization: Bearer <jwt>`.

### Para la app móvil (roles administrador, supervisor y técnico)

| Método y ruta | Descripción |
|---|---|
| `GET /api/proyectos/listar.php` | Cada proyecto trae además `plantilla_id` y `plantilla_version` (`null` = flujo fijo). |
| `GET /api/plantillas/obtener.php?proyecto_id=N` | `{success, data: null \| {plantilla_id, version, nombre, formulario, fotos}}` <br>Cada foto trae `formato: {ancho_mm, alto_mm, ancho_px, alto_px}` (su recuadro del PDF, a 300 ppp con lado mayor ≥ 1600 px) o `null`. La app encuadra la foto con esa proporción y la guarda con esos píxeles. |
| `GET /api/postes/respuestas.php?poste_id=N` | `{success, data: {respuestas, plantilla_version, fotos: [{foto_clave, indice, uuid, checksum, fecha_captura}]}}` |
| `PUT /api/postes/respuestas.php?poste_id=N` | Cuerpo `{plantilla_id, plantilla_version, respuestas: {...}, fecha_inspeccion: "Y-m-d H:i:s"}`. Devuelve `{success, message, estado}`. Un error de validación devuelve 422 `{success:false, error}`. |
| `POST /api/postes/fotos-plantilla.php?poste_id=N` | Multipart, con `i` de 0 a 29: `imagen_i` (archivo), `foto_clave_i`, `indice_i` (empieza en 1), `utm_este_i`, `utm_norte_i`, `zona_i`, `fecha_captura_i`, `uuid_i`, `checksum_i`. Devuelve `{success, resultados: {imagen_i: {success, foto_clave, indice}}, estado}`. |

### Para el panel admin (rol administrador)

| Método y ruta | Descripción |
|---|---|
| `GET /api/admin/plantillas.php` | Lista de plantillas. |
| `GET /api/admin/plantillas.php?id=N` | Detalle con su configuración. |
| `POST /api/admin/plantillas.php` | Cuerpo `{nombre, descripcion, config?, duplicar_de?}`. |
| `PATCH /api/admin/plantillas.php` | Cuerpo `{id, accion: actualizar \| desactivar \| reactivar, nombre?, descripcion?, config?}`. |
| `POST /api/admin/plantilla_logo.php` | Multipart `plantilla_id`, `lado` (`izquierdo` o `derecho`) y `archivo`. Devuelve `{archivo}`. |
| `GET /api/admin/plantilla_logo.php?plantilla_id=N&archivo=...` | Devuelve el PNG. |
| `POST /api/admin/plantilla_vista_previa.php` | Cuerpo `{plantilla_id, config}`. Devuelve un PDF de ejemplo con datos de muestra. |
| `POST` y `PATCH /api/admin/proyectos.php` | Aceptan `plantilla_id` (`null` = flujo fijo). |

### Paneles de fotos: `alto_foto`

Alto de cada foto del panel, en mm (25–120). `0` = automático: `min(70, ancho_celda × 0,79)`. El editor web muestra las hojas 2+ a escala con la misma paginación que `plantillaPdfPaneles()`.

### El PDF sigue al formulario y a las fotos

- **Editor web.** Un campo nuevo o duplicado recibe su cuadro en la hoja 1. Un dato simple (texto, número, fecha, sí/no) se suma al cuadro de su vecino si ese cuadro ya agrupa datos simples. El primer «párrafo» pasa a ser el campo de comentarios. Al borrar un campo o un paso, sus claves salen de los cuadros y el cuadro que queda vacío se elimina. Renombrar un campo actualiza el título de su cuadro mientras no se haya personalizado.
- Una foto nueva entra al panel de su categoría; si no existe, se crea uno con el estilo del último panel. Al cambiarle la categoría se mueve al panel de la nueva categoría, salvo que se haya movido a mano a otro panel. Al borrarla, sale de los paneles y el panel vacío se quita. La panorámica sale de los paneles y asegura su cuadro en la hoja 1.
- «Armar automáticamente» rehace la hoja 1 (un cuadro por paso con los datos simples y uno por cada pregunta de opciones) y un panel por categoría. Conserva encabezado, comentarios y firmas.
- **Servidor** (`plantillaNormalizar`). Descarta los cuadros `campos` y los paneles que traían claves pero se quedaron sin ninguna válida. Un cuadro creado vacío a propósito se respeta.
- **PDF** (`plantillaPdfPaneles`). Toda foto sale: las que no están en ningún panel se imprimen al final, en «Otras fotografías». Los paneles sin fotos no se imprimen, así que nunca queda una hoja en blanco.

### Respuestas de una versión anterior

`PUT /api/postes/respuestas.php` compara el `plantilla_version` que manda el teléfono con la versión vigente:

- **Misma versión:** validación estricta, con 422 si algo no vale.
- **Versión anterior** (el teléfono llenó el formulario sin señal antes del cambio):
  - se guarda lo que sigue siendo válido;
  - se rescatan las opciones vigentes de «varias opciones» y de las tablas de fases;
  - no se exigen obligatorios nuevos;
  - la respuesta trae `avisos[]`.

Así una inspección no queda atascada en el teléfono por un cambio en la plantilla.

### Tamaño propio de cada foto (hojas 2+)

`pdf.paneles[].tamanos` es un objeto `{ "<clave de foto>": { "w": 2–12, "h": 20–220 } }`:

- **`w`:** ancho en columnas de una rejilla de 12 (cada columna mide 15,83 mm).
- **`h`:** alto de la imagen en mm, múltiplo de 5. La banda del nombre suma 5 mm aparte.
- **0 o ausente:** se usa el tamaño del panel. El ancho es `12 / columnas`; el alto es `alto_foto` o el automático, `min(70, ancho × 0,79)` redondeado a 5 mm.

Cada foto se ubica en el primer hueco libre (filas de 5 mm), así las chicas llenan los espacios que dejan las grandes. Cuando una no cabe, sigue en otra hoja con el título del panel repetido. La lógica es la misma en `plantillaPdfDistribuirFotos()` (PHP) y en `paginarFotos()` (JS), con el inicio fijo en 42,5 mm: la vista previa y el PDF coinciden exactamente.

En el editor:

- clic en una foto de la hoja;
- «Tamaño rápido»: Pequeña, Mediana, Grande, Muy grande o Toda la fila;
- barras de ancho y alto, o arrastrar la esquina azul;
- «Antes» y «Después» para cambiar el orden;
- «Igual para todas» copia el tamaño a todo el panel;
- «Tamaño del panel» quita el tamaño propio.

### Logos y firmas: copia en la base de datos

Los PNG de logos y firmas se guardan en `report_config/plantillas/plantilla_N/` y también en la tabla `plantilla_archivos` (`plantilla_id`, `archivo`, `contenido`). El API crea la tabla si falta; también está la migración `20261016_plantilla_archivos.sql`.

- Si el archivo falta en disco, `plantillaRutaLogo()` lo restaura desde la base. Por ejemplo, cuando la carpeta no es un volumen y se reconstruyó el contenedor.
- Si está en disco pero no en la base, lo respalda en la base.
- `plantilla_logo.php` responde con `Cache-Control: private, max-age=600` (antes era de un año). El editor pide los logos con `&v=2`.

### Firma con imagen

`pdf.firmas[]` tiene estos campos:

- `nombre` y `cargo`;
- `imagen`: `firma_<hex>.png` o `null`;
- `alto`: alto en mm, de 8 a 35;
- `dx`: desplazamiento horizontal, de −30 a 30 mm;
- `dy`: desplazamiento vertical, de −12 a 12 mm (positivo = más abajo).

La imagen se sube por `plantilla_logo.php` con `lado=firma`. El servidor la recorta y vuelve transparente el fondo:

- el tono del papel se estima por percentil;
- el gris neutro claro (por ejemplo, un JPG con cuadriculado falso) también se vuelve transparente.

En el PDF se apoya sobre la línea, centrada en su columna y desplazada `dx`/`dy`. En el editor se arrastra la firma o se usan el control «Tamaño» y los botones ↑ ↓ «Centrar».
