# Offline-first y sincronización

Estado: **P0 completado.** La integridad de datos está garantizada. El servicio central de sincronización (cola persistente con reintentos, backoff y disparo automático) es la fase siguiente; lo que hay hoy se describe abajo con sus límites reales.

---

## 1. El principio

> **Guardar localmente primero. Sincronizar después. Confirmar solo con respuesta del servidor.**

Se aplica **siempre**, exista o no internet. No hay ninguna ruta de código en la que un dato viaje a la red antes de estar en disco.

### Por qué era el problema número uno

La versión anterior aplicaba este principio al formulario pero **no a las fotografías**:

```dart
// ANTES — lib/screens/imagenesPoste_screen.dart
if (_hayInternet && !_modoOffline) {
  final success = await _service.subirImagenBatch(...);   // solo sube
  // ...y nada más. Si success == false, las fotos no existen en ninguna parte.
} else {
  await db.guardarImagenPosteLocal(...);                  // solo aquí se guardaba
}
```

Con internet, un fallo de red (timeout, 500 del servidor, señal que se cae a mitad) dejaba las fotos de una torre únicamente en la caché de la cámara, sin fila en SQLite. La pantalla de sincronización no las veía porque nunca se insertaron. El inspector tenía que repetir la visita completa a una torre remota.

### Cómo está ahora

```dart
// AHORA — cada captura, en el momento de tomarla:
final registrada = await _fotos.registrarCaptura(...);
//   1. copia verificada a almacenamiento permanente
//   2. fila en SQLite con estado = 'pending'
// Recién después, y como operación separada, se intenta enviar.
```

"Enviar" pasa a ser una operación sobre datos que ya están a salvo: se gana o se pierde el intento, nunca el dato.

---

## 2. Máquina de estados

```
                  ┌──────────────────────────────────────┐
                  │                                      │
  captura ──► pending ──► uploading ──► confirmado? ──sí──► synced
                  ▲            │            │
                  │            │            no
                  │            │            ▼
                  └────────── failed ◄──────┘
                  (reintento)  │
                               └──► conflict  (el servidor ya lo tenía)
```

| Estado | Qué significa | Qué se le muestra al inspector |
|---|---|---|
| `local` | En el teléfono, sin marcar para envío | "Guardado en el teléfono" |
| `pending` | En el teléfono y en cola | "Pendiente de enviar" |
| `uploading` | Envío en curso | "Subiendo…" |
| `synced` | **Confirmado por el servidor** | "Sincronizado" |
| `failed` | Falló el intento; el dato sigue íntegro | "Error al enviar — sigue guardada" |
| `conflict` | Divergencia con el servidor | "Requiere revisión" |

Definición: [`lib/core/estados_sync.dart`](lib/core/estados_sync.dart).

**`synced` tiene un único camino de entrada** en todo el código: `FotosRepositorio.marcarSincronizada()` y `BorradoresRepositorio.marcarSincronizado()`, y ambos se llaman exclusivamente después de comprobar la respuesta del servidor.

---

## 3. Confirmación real del servidor

`ImagenesPosteService.subirImagenBatch` ya no devuelve `bool`. Devuelve:

```dart
class ResultadoSubida {
  final Set<String> confirmadas;  // nombre_foto que el servidor confirmó
  final Set<String> rechazadas;   // los que no llegaron
  final String? error;            // mensaje real, para ultimo_error
  final int? codigoHttp;
}
```

Eso permite decir "se confirmaron 8 de 28" en lugar de "✅ Envío exitoso".

### Contrato asumido con el backend PHP

> ⚠️ **No verificado contra el código PHP** — no se tuvo acceso al backend. Lo que sigue se dedujo del cliente y está implementado de forma defensiva.

`POST /api/postes/imagenes-poste.php?poste_id=N`, multipart, con campos indexados por foto (`nombre_foto_0`, `utm_este_0`, `imagen_0`, …).

Se acepta como confirmación:
- `{"success": true}`, o
- `{"status": "success"}`

Si además llega un arreglo (`fotos`, `imagenes` o `data`) con `nombre_foto` en cada elemento, se confirma **foto por foto**. Mientras el backend no lo devuelva, la granularidad máxima es **por lote** (15 fotos).

**Regla ante la ambigüedad: se asume NO confirmado.** Es preferible reintentar una foto ya subida —el servidor puede deduplicar por `uuid`/`checksum`— que dar por enviada una que no llegó.

### Campos nuevos que se envían (opcionales para el backend)

| Campo | Contenido | Si el backend lo ignora |
|---|---|---|
| `uuid_N` | UUID local estable de la foto | No cambia nada |
| `checksum_N` | SHA-256 del archivo | No cambia nada |

Son la base de la idempotencia. **Cambio de backend recomendado (opcional, no obligatorio):** guardar `uuid` y `checksum`, y ante un reenvío con el mismo par devolver éxito sin duplicar la fila. Sin esto, un reintento tras un timeout puede crear una foto duplicada en el servidor.

### Manejo diferenciado de errores

| Situación | Tratamiento |
|---|---|
| Sin conexión (`SocketException`) | `failed` + mensaje "Sin conexión con el servidor" |
| Timeout (4 min por lote) | `failed` + "La subida no completó" |
| 401 / 403 | `failed` + "Sesión vencida o sin permiso. Vuelve a iniciar sesión; tus fotos siguen guardadas" |
| 413 (payload demasiado grande) | `failed` + aviso de reintento en lotes menores |
| Respuesta que no es JSON (HTML de error PHP) | `failed` + los primeros 160 caracteres del cuerpo, para poder diagnosticar |
| JSON inválido | `failed` + recorte del cuerpo |
| 200 pero `success != true` | `failed` + el `error`/`message` del servidor |

Todos guardan el mensaje real en `ultimo_error`, visible en la pantalla de fotos.

---

## 4. Recuperación de trabajo interrumpido

Al arrancar la app ([`lib/main.dart`](lib/main.dart)):

```dart
await FotosRepositorio().recuperarSubidasInterrumpidas();
await BorradoresRepositorio().recuperarEnviosInterrumpidos();
```

Todo lo que quedó en `uploading` (porque la app se cerró, el sistema la mató o el teléfono se reinició a mitad de una subida) vuelve a `pending` con `ultimo_error = "Subida interrumpida: la app se cerró durante el envío."`.

Sin esto, un cierre a mitad de sincronización dejaba registros colgados que nadie iba a reintentar nunca.

### Archivos desaparecidos

`FotosRepositorio.verificarArchivos()` se ejecuta al abrir la pantalla de fotos de una estructura. Detecta filas cuyo archivo ya no existe en disco (caché purgada en versiones anteriores, borrado externo) y las marca `failed` con "El archivo de la fotografía ya no está en el teléfono. Hay que volver a tomarla." — en lugar de fingir que están bien y fallar en la subida.

La pantalla muestra un banner naranja con el recuento.

---

## 5. Lo que hay hoy y lo que falta

### Funciona

| Capacidad | Dónde |
|---|---|
| Guardado local garantizado de fotos y formulario | `FotosRepositorio`, `BorradoresRepositorio` |
| Confirmación foto a foto / lote a lote | `ResultadoSubida` |
| Reintento manual desde la pantalla de fotos | Botón "Enviar N pendientes" |
| Sincronización manual por página de línea | `DetalleLineaScreen` |
| Recuento honesto de lo confirmado | `ResumenSincronizacion` |
| Recuperación tras cierre inesperado | `main.dart` |
| Contador de intentos y último error por registro | Columnas `intentos`, `ultimo_error` |
| Subida por lotes de 6 con pausa de 500 ms y división automática ante 413 | `ImagenesPosteService._subirPorLotes` |
| Timeout de 4 min por lote | `ImagenesPosteService.timeoutLote` |

### Implementado en la fase de sincronización

| Capacidad | Estado |
|---|---|
| Servicio central por estructura, línea, proyecto o todo | `ServicioSincronizacion` |
| Reintentos con espera incremental y acción manual | `PoliticaReintentos` |
| Disparo al recuperar la API con la app abierta | `ServicioConectividad` |
| Preferencia de fotografías solo con Wi-Fi | `PreferenciasApp` |
| Confirmación parcial por `resultados.imagen_N` | `ImagenesPosteService` |
| Verificación final formulario + 28 fotos | `sincronizacion_estado.php` |

### Límite honesto sobre el segundo plano

**No se va a prometer sincronización permanente en segundo plano.** Ni Android (Doze, límites de background desde API 26, matanza agresiva de OEM en Xiaomi/Samsung) ni iOS (BGTaskScheduler, sin garantía de ejecución) lo permiten de forma fiable.

Lo que sí se puede garantizar y es lo que se implementará:
- sincronización en primer plano con la app abierta, con progreso real;
- disparo automático al detectar recuperación de conexión mientras la app está en uso;
- reanudación al abrir la app;
- un servicio *foreground* con notificación persistente para sincronizaciones largas, que el usuario inicia explícitamente.

---

## 6. Escenarios de campo verificados por pruebas

[`test/repositorios/integridad_p0_test.dart`](test/repositorios/integridad_p0_test.dart) — 24 casos:

| Escenario | Resultado esperado |
|---|---|
| Android purga la caché tras la captura | La foto sobrevive en almacenamiento permanente |
| Archivo capturado vacío (0 bytes) | Se rechaza y **no** deja fila en la base |
| Repetir una foto | Reemplazo en su sitio, un solo archivo, sin temporales |
| La copia de reemplazo falla | **La foto anterior sigue intacta** |
| Subida devuelve 500 | `failed`, sigue en la cola, `intentos = 1` |
| Servidor responde HTML en vez de JSON | `failed` con el cuerpo recortado en `ultimo_error` |
| La app muere durante la subida | Al arrancar vuelve a `pending` |
| Archivo borrado externamente | `failed` con mensaje claro, no se finge éxito |
| Guardar el formulario 5 veces | Un solo borrador, la última versión |
| `datos_json` corrupto | El formulario abre igual, sin perder la fila |
| Reeditar una inspección sincronizada | Se recupera el contenido anterior |

---

## 7. Diagrama del flujo completo

```
   ┌─ CAPTURA ─────────────────────────────────────────────┐
   │ 1. image_picker devuelve un archivo en la CACHÉ       │
   │ 2. copia verificada (tamaño + SHA-256) a              │
   │    documentos/inspecciones/proyecto_N/poste_M/         │
   │ 3. INSERT en imagenes_poste_local  estado = pending    │
   │    (si 2 falla → excepción, NO hay fila)              │
   └───────────────────────┬───────────────────────────────┘
                           │  el dato ya está a salvo
   ┌─ ENVÍO ───────────────▼───────────────────────────────┐
   │ 4. UPDATE estado = uploading                          │
   │ 5. multipart en lotes de 6, timeout 4 min             │
   │ 6. interpretar la respuesta                           │
   │      confirmada  → estado = synced   (+ id_remoto)    │
   │      no confirmada → estado = failed (+ intentos++,   │
   │                      ultimo_error)                    │
   └───────────────────────┬───────────────────────────────┘
                           │
   ┌─ ARRANQUE DE LA APP ──▼───────────────────────────────┐
   │ 7. todo lo que quedó en uploading → pending           │
   │ 8. al abrir un poste: verificarArchivos()             │
   └───────────────────────────────────────────────────────┘
```
