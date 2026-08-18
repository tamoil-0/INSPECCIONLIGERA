# Contrato con el backend

> **Estado: pendiente de verificar.** No se tuvo acceso al código PHP. Todo lo
> que sigue está **deducido del cliente** y de lo que el código anterior enviaba.
> El backend se está actualizando; este documento es lo que hay que revisar
> juntos cuando esté listo.
>
> Nada de lo implementado rompe el contrato actual: los cambios que sí lo
> cambiarían están detrás de interruptores apagados por defecto (§4).

---

## 1. Autenticación

### `POST /usuarios/login.php`

Cuerpo (JSON):
```json
{ "nombre_usuario": "jperez", "contrasena": "…" }
```

Respuesta que la app espera:
```json
{
  "token": "eyJhbGciOi…",
  "usuario": {
    "id": 4,
    "nombre_completo": "Juan Pérez",
    "correo_electronico": "jperez@ecoing.com"
  }
}
```

**Qué hace la app ahora:**
- Guarda el token en el **almacén seguro** del sistema (Keystore/Keychain), no en `SharedPreferences` en claro.
- Comprueba la expiración con `jwt_decoder`. **Si el token no es un JWT estándar y no se puede decodificar, se considera válido** y se deja que el servidor responda 401: expulsar al inspector por no poder leer el token sería peor.
- `usuario.id` se usa para separar datos entre cuentas.

**A confirmar:**
1. ¿El token es un JWT con `exp`? Si no lo es, la comprobación local de expiración no hace nada y todo depende del 401.
2. ¿Cuánto dura la sesión? Determina cada cuánto hay que reautenticar en campo.
3. En un login con credenciales incorrectas, ¿qué código devuelve? La app trata `401` en el login como «usuario o contraseña incorrectos» y no como sesión vencida.

---

## 2. Consultas

Todas con `Authorization: Bearer <token>` y respuesta:
```json
{ "success": true, "data": [ … ] }
```

| Endpoint | Parámetros | Uso |
|---|---|---|
| `GET /proyectos/listar.php` | `estado?`, `busqueda?` | Lista de proyectos |
| `GET /postes/listar.php` | `proyecto_id` | Todas las estructuras de un proyecto |
| `GET /postes/buscar_por_linea.php` | `linea` | Estructuras de una línea |
| `GET /postes/buscar_estructura.php` | `estructura` | Búsqueda por número |
| `GET /postes/sincronizacion_estado.php` | `poste_id` | `formulario_subido`, `imagenes_subidas` |

**Cambios en el cliente:**
- Los parámetros ahora se **escapan** (`Uri.replace(queryParameters:)`). Antes se concatenaban: `?linea=$linea` rompía la petición con líneas que llevaran `&` o espacios.
- Ya no se hace `jsonDecode` a ciegas. Si la respuesta no empieza por `{` o `[` se clasifica como `respuestaNoJson` y se guardan los primeros 200 caracteres del cuerpo en `ultimo_error` — es lo único que permite diagnosticar un error de PHP desde el teléfono.
- Hay timeout (30 s configurable). Antes no había ninguno.

**A confirmar:**
4. **`buscar_por_linea.php` no filtra por proyecto.** Dos proyectos pueden tener una línea con el mismo nombre. La app ahora filtra en el cliente por `proyecto_id`, pero **la respuesta debería incluir `proyecto_id` en cada poste**; si no viene, el filtro no puede aplicarse y se acepta el registro.
5. ¿`data` puede venir `null` o ausente cuando no hay resultados? La app lo trata como lista vacía.
6. `listar.php` — ¿tiene paginación? Con proyectos grandes, la descarga inicial trae todo de una vez.

**Ya no se usa:** `GET /postes` como ping de conectividad. Era un endpoint inexistente y cualquier respuesta `< 500` (incluido un 404) se tomaba como «hay conexión».

---

## 3. Envío del formulario

### `PUT /postes/actualiza-datos.php?poste_id=N`

Cuerpo: JSON con las claves de `poste_datos`, más los metadatos de calidad:

```json
{
  "obstaculos_faja": ["arboles", "cercos_vallas"],
  "estado_cuencas": "seguimiento",
  "marcado_arboles": "no",
  "…": "…",
  "comentarios": "Óxido en la base",
  "fecha_inspeccion": "2026-08-18T15:42:10.123",

  "campos_revisados": ["estado_base", "tipo_torre", "…"],
  "campos_sin_revisar": ["oxidos_base", "…"],
  "total_campos": 27
}
```

**Respuesta esperada:** `{"success": true}`.

**Importante:** la app marca el formulario como sincronizado **solo** si `success` es `true`. Antes se ignoraba la respuesta y se marcaba siempre.

### `POST /postes/agregar-seccion-rst.php?poste_id=N`

```json
{
  "registros": [
    { "seccion": "conductores_fase", "atributo": "hebras_rotas", "fase": "R" },
    { "seccion": "estado_aisladores", "atributo": "buen_estado", "fase": "T" }
  ]
}
```

**A confirmar:**
7. **`campos_revisados`, `campos_sin_revisar` y `total_campos` son nuevos.** ¿Los ignora sin error, o hay que añadirlos al backend? Son la base de la mejora de calidad del dato (§4).
8. `obstaculos_faja` se envía como **arreglo JSON**. La versión anterior enviaba a veces un arreglo y a veces una cadena, según la ruta de código. ¿Cuál acepta el servidor?
9. ¿`agregar-seccion-rst.php` **reemplaza** las marcas del poste o las **añade**? La app envía siempre el conjunto completo, así que si añade, se acumularán duplicados. La restricción local es `UNIQUE(poste_id, seccion, fase)`.
10. ¿El PDF consume `poste_datos` directamente? Si sí, cualquier cambio de valores le afecta.

---

## 4. Cambio pendiente: «No revisado»

### El problema

19 de los 22 ítems del formulario llegaban **precargados**:

| Campo | Valor que se enviaba sin que nadie lo mirara |
|---|---|
| `estado_placas_torre`, `estado_placas_linea`, `estado_placas_fases` | `bueno` |
| `peligro_torre`, `puesta_tierra` | `bueno` |
| `estado_base`, `crucetas_mensuales`, `perfiles_angulares`, `malla_antiescalamiento` | `buen_estado` |
| `marcado_arboles`, `limpiar_base`, `oxidos_base` | `no` |
| `estado_cuencas`, `criticidad_tala`, `criticidad_contacto`, `retenida`, `conductor_bajada_pat`, `conductor_guarda` | `n_a` |
| `cadena_aisladores` | `en_suspension` |
| `tipo_aislador` | `porcelana` |
| `estado_acceso` | `mal_estado` |
| `peligro_cerco` | `no_existe` |

Con solo 3 campos obligatorios, un inspector podía enviar una inspección completa en dos toques y el servidor recibía 19 respuestas **indistinguibles de una inspección hecha con cuidado**.

### Lo que ya está hecho (sin tocar el contrato)

- Los ítems arrancan en `no_revisado` **en la interfaz**: el inspector ve en naranja lo que le falta y tiene un contador «14/27 revisados».
- Se registra qué campos confirmó de verdad y se envía en `campos_revisados`.
- El paso de resumen enumera lo que queda sin revisar antes de finalizar.
- Migración v3: columnas `revisados_json` y `sin_revisar`.

### Lo que falta y necesita el backend

Con `ENVIAR_NO_REVISADO=false` (por defecto), un campo sin revisar viaja con **su valor heredado** — es decir, el servidor sigue recibiendo `bueno` para una placa que nadie miró. La lista `campos_revisados` permite detectarlo, pero el valor del campo miente.

Para arreglarlo de verdad, el backend debe aceptar `no_revisado` como valor en esos 19 campos. Cuando lo acepte:

```bash
flutter build apk --release --dart-define=ENVIAR_NO_REVISADO=true
```

**Decisiones que hay que tomar con el equipo de backend:**
11. ¿`no_revisado` como valor de texto, o `null`, o el campo ausente?
12. ¿Qué debe salir en el PDF para un campo no revisado? (Sugerencia: «No revisado», no una casilla vacía que se lea como «bueno».)
13. ¿Se rechaza una inspección con demasiados campos sin revisar, o se acepta marcándola como incompleta? Es una decisión de proceso, no técnica.

---

## 5. Subida de fotografías

### `POST /postes/imagenes-poste.php?poste_id=N`

`multipart/form-data`, campos indexados por foto (`_0`, `_1`, …), en lotes de 15 cuando hay más de 20:

| Campo | Contenido | ¿Nuevo? |
|---|---|---|
| `imagen_N` | El archivo | No |
| `nombre_foto_N` | `placa`, `base_torre`, … | No |
| `utm_este_N`, `utm_norte_N`, `zona_N` | Coordenada UTM (`19K`) | No |
| `fecha_captura_N` | ISO-8601 | No |
| **`uuid_N`** | Identificador local estable de la foto | **Sí** |
| **`checksum_N`** | SHA-256 del archivo que se sube | **Sí** |

### Cómo interpreta la app la respuesta

Se acepta como confirmación `{"success": true}` o `{"status": "success"}`.

Si además llega un arreglo (`fotos`, `imagenes` o `data`) con `nombre_foto` en cada elemento, se confirma **foto por foto**. Sin él, la confirmación es **por lote completo** de 15.

**Regla implementada: ante cualquier ambigüedad se asume NO confirmado.** Es preferible reintentar una foto ya subida que dar por enviada una que no llegó.

**A confirmar:**
14. **Confirmación foto por foto.** Lo ideal sería:
    ```json
    {
      "success": true,
      "fotos": [
        { "nombre_foto": "placa", "id_remoto": "9931", "recibida": true },
        { "nombre_foto": "base_torre", "recibida": false, "error": "tamaño" }
      ]
    }
    ```
    Con esto la app dejaría de reintentar lotes completos por una sola foto fallida. **Es la mejora de mayor impacto en consumo de datos móviles.**
15. **Idempotencia por `uuid` + `checksum`.** Si el backend los guarda, un reenvío tras un timeout puede devolver éxito sin duplicar la fila. Sin esto, un reintento **puede crear fotos duplicadas en el servidor** — riesgo real, porque el timeout de 4 minutos puede saltar con una subida que sí llegó.
16. `max_file_uploads` y `post_max_size` del PHP. El lote de 15 se eligió por el código anterior; con las fotos ya optimizadas (~2 MB) un lote de 15 son ~30 MB. Si `post_max_size` es menor, hay que bajar el tamaño de lote.
17. ¿Se puede reanudar una subida por partes? Si no, un corte a mitad de un archivo obliga a repetirlo entero. No es bloqueante pero importa con señal débil.
18. La app envía las fotos **ya optimizadas** (2560-3072 px, JPEG q86-90, orientación EXIF aplicada a los píxeles y **sin** metadatos EXIF). ¿El PDF depende de algún dato EXIF? El GPS y la fecha van como campos del multipart, no en el EXIF.

---

## 6. Reconciliación pendiente

En la versión anterior, `imagenes_poste_local.sincronizada = 1` se marcaba **sin comprobar la respuesta del servidor**. La migración v2 respeta ese valor para no reenviar de golpe todo el histórico de cada teléfono, lo que significa que:

> **Puede haber fotos marcadas como sincronizadas en los teléfonos que el servidor nunca recibió.**

Para detectarlas hace falta poder preguntar al servidor qué tiene:

19. **Endpoint de inventario por poste**, algo como:
    ```
    GET /postes/imagenes-inventario.php?poste_id=N
    → { "success": true, "fotos": [ {"nombre_foto":"placa","checksum":"…"} ] }
    ```
    Con eso la app compararía contra lo local y devolvería a la cola lo que falte de verdad. Sin eso, la única alternativa es reenviar todo por si acaso, lo que en campo significa gigabytes de datos móviles.

---

## 7. Resumen de lo que se pide al backend

| # | Petición | Prioridad | Sin ello |
|---|---|---|---|
| 14 | Confirmación foto por foto en la respuesta de subida | **Alta** | Se reintentan lotes de 15 por una foto fallida |
| 15 | Deduplicación por `uuid` + `checksum` | **Alta** | Los reintentos pueden duplicar fotos |
| 19 | Endpoint de inventario de fotos por poste | **Alta** | No se puede detectar el histórico dudoso |
| 7 | Aceptar `campos_revisados` / `campos_sin_revisar` | Media | Se pierde la trazabilidad de qué se revisó |
| 11-13 | Aceptar `no_revisado` y decidir su salida en el PDF | Media | El servidor sigue recibiendo valores que nadie miró |
| 4 | Incluir `proyecto_id` en `buscar_por_linea.php` | Media | Se pueden mezclar líneas homónimas de dos proyectos |
| 9 | Aclarar si el RST reemplaza o añade | Media | Riesgo de marcas duplicadas |
| 16 | Confirmar límites de PHP para el tamaño de lote | Baja | Posibles 413 evitables |
| 17 | Subida reanudable | Baja | Reenvío completo del archivo tras un corte |
