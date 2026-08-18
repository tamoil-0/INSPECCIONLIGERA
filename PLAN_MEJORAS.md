# Plan de modernización — App móvil ECOING

> Documento de trabajo. Diagnóstico ejecutado el 18/08/2026 sobre el commit `9f44314` (estado original).
> Auditoría previa: [`ANALISIS_APP_MOVIL.md`](ANALISIS_APP_MOVIL.md).
> Estado: **P0 completado** (commits `a9c2c59`…`8e62998`). P1 en espera de tu confirmación.

---

## 1. Resumen del estado actual (verificado, no supuesto)

### Entorno

```
Flutter 3.41.4 · stable · Dart 3.11.1
Android SDK 36.0.0 · JDK 21 · NDK 27.0.12077973
Dispositivo real disponible: Samsung SM-N970F (Galaxy Note 10), Android 12, API 31
```

### Resultados de los comandos de la Fase 0

| Comando | Antes de P0 | Después de P0 |
|---|---|---|
| `flutter pub get` | OK (9 dependencias actualizadas, 79 con versiones incompatibles disponibles) | OK |
| `flutter analyze` | **106 issues: 1 error, 18 warnings, 87 info** | **68 issues: 0 errores, 0 warnings, 68 info** |
| `flutter test` | **FALLA** — `test/widget_test.dart` no compila (`MyApp` exige `initialRoute`) | **51/51 OK** |
| `dart format --set-exit-if-changed` | 26 de 29 archivos sin formatear | Sin cambios (formato no aplicado todavía a propósito: generaría un diff enorme que taparía los cambios funcionales) |
| `flutter build apk --release` | OK — 52.0 MB | OK — 51.6 MB |
| `flutter doctor` | 1 aviso: licencias de Android no aceptadas | Igual (no bloquea) |

### Hallazgos confirmados en el código

Los 8 hallazgos críticos de la auditoría se verificaron uno por uno leyendo el código, no aceptando el documento. **Todos eran reales.** Además aparecieron cuatro cosas que la auditoría no recogía:

| Hallazgo nuevo | Dónde | Gravedad |
|---|---|---|
| `ConversionUtm.bandaDe` lanzaba `RangeError` entre 80° y 84° de latitud | función pura de conversión UTM | Baja (no afecta a Perú) pero es un fallo real |
| Repetir una foto **destruía la anterior antes de verificar la nueva** — el nombre del archivo deriva del UUID, que se conserva, así que la ruta de destino coincide | `AlmacenamientoFotos` (introducido y corregido en P0) | Alta si se hubiera enviado así |
| `_posteActualSincronizando` se escribía en un `setState` dentro del bucle de carga y no se leía nunca → una reconstrucción de pantalla por poste, gratis | `detalle_linea_screen.dart` | Media (rendimiento) |
| APK universal de **52 MB** — incluye todas las ABI y 6 dependencias sin usar | build | Media (descarga en campo con datos móviles) |

### Riesgos que quedan abiertos (no resueltos en P0)

1. **Datos heredados dudosos.** En la v1, `sincronizada = 1` se marcaba sin confirmar. Puede haber fotos marcadas como enviadas que el servidor nunca recibió. Requiere una reconciliación contra el servidor (P2).
2. **Contrato del backend no verificado.** No tuve acceso al PHP. Ver §9.
3. **PDF de ejemplo ausente.** Busqué en todo el proyecto (`find . -iname "*.pdf"`): no hay ninguno. Ver §9.

---

## 2. Arquitectura propuesta

### Estado actual

Las pantallas hablan directamente con `DatabaseHelper` y con los `services`. `detalle_linea_screen.dart` tiene 521 líneas mezclando UI, orquestación de red y acceso a disco. Sin inyección de dependencias ni interfaces: el código no era testeable.

### Objetivo (se llega por fases, no de golpe)

```
lib/
├── core/                    ← utilidades sin dependencias de UI ni de datos
│   ├── estados_sync.dart            ✅ hecho en P0
│   ├── conversion_utm.dart          ✅ hecho en P0
│   ├── almacenamiento_fotos.dart    ✅ hecho en P0
│   ├── resultado.dart               ⧗ P3 — Result<T, Error> tipado
│   ├── entorno.dart                 ⧗ P5 — configuración por entorno
│   └── log.dart                     ⧗ P5 — logger con niveles
├── data/
│   ├── local/               ← SQLite: DatabaseHelper + migraciones
│   ├── remoto/              ← cliente HTTP único + servicios
│   └── repositorios/                ✅ iniciado en P0
│       ├── fotos_repositorio.dart       ✅
│       ├── borradores_repositorio.dart  ✅
│       ├── proyectos_repositorio.dart   ⧗ P3
│       └── postes_repositorio.dart      ⧗ P3
├── dominio/                 ← modelos inmutables + reglas de inspección
├── servicios/
│   ├── sincronizacion/              ⧗ P2 — servicio central + cola
│   ├── imagenes/                    ⧗ P1 — pipeline de optimización
│   └── conectividad/                ⧗ P2 — un único listener
└── presentacion/
    ├── diseno/                      ⧗ P4 — sistema de diseño
    ├── comunes/                     ⧗ P4 — componentes reutilizables
    └── pantallas/           ← las pantallas, ya adelgazadas
```

### Decisiones tomadas y justificadas

| Decisión | Por qué |
|---|---|
| **No introducir Riverpod ni Bloc** por ahora | El proyecto tiene 9 pantallas y estado casi todo local a cada una. `setState` + repositorios cubre el caso. Meter un gestor de estado ahora mezclaría un refactor grande con las correcciones de integridad. Se reevalúa en P3, cuando exista un servicio de sincronización con estado global real (ahí sí puede justificarse `ValueNotifier`/`InheritedNotifier`, que no añaden dependencia). |
| **No introducir `dio`** | Está declarado y sin usar. `http` cubre todo lo que hace la app. `dio` aportaría interceptores y progreso de subida; se reevalúa en P2 **solo** si el progreso real por archivo lo exige. |
| **Retirar `flutter_dotenv`** y usar `--dart-define` | Un `.env` empaquetado en el APK no es un secreto: se extrae con `unzip`. `--dart-define` en el build es más simple y no añade dependencia. |
| **Retirar `location`** | Duplica `geolocator` y añade permisos propios al manifiesto sin aportar nada. |
| **Retirar `provider`, `flutter_svg`, `jwt_decoder`** | `provider` y `flutter_svg` no se usan. `jwt_decoder` sí hará falta en P5, pero se añadirá cuando se use. |
| **`crypto` y `uuid` como dependencias directas** | Ya estaban en el árbol transitivo: impacto cero en tamaño. Necesarias para checksum e idempotencia. |
| **`sqflite_common_ffi` como dependencia de desarrollo** | Sin ella no se puede probar una migración ni un repositorio sin emulador. Es la que hace posible las 35 pruebas de datos de P0. |

### Repositorios entre UI, SQLite y API

`DatabaseHelper` se mantiene (rule: no romper lo que funciona) pero deja de ser la puerta de entrada de las pantallas. El acceso pasa por repositorios que hacen cumplir los invariantes:

```
Pantalla  ──►  Repositorio  ──►  DatabaseHelper (SQLite)
                    │
                    └──────────►  Servicio HTTP
```

El repositorio es el único que puede escribir `estado = 'synced'`, y solo lo hace con confirmación en la mano. Ese es el mecanismo que impide que vuelva a aparecer el bug 8.2.

---

## 3. Diseño del almacenamiento permanente de fotografías

### El problema

`image_picker` devuelve la foto en el **directorio de caché** de la app. Android lo vacía cuando quiere: poco espacio, limpieza del sistema, "borrar caché" desde Ajustes. Guardar esa ruta en SQLite significaba que una foto pendiente podía desaparecer dejando una fila huérfana. La versión anterior ya tenía código para saltar archivos inexistentes al exportar — síntoma de que el problema se había visto y no se había resuelto.

### La solución

```
<documentos privados de la app>/
└── inspecciones/
    └── proyecto_7/
        └── poste_101/
            ├── placa__6f1c9e...a3.jpg
            ├── base_torre__b02d41...77.jpg
            └── mensulas__c9ee30...12.jpg
```

- **Directorio privado y permanente**: sobrevive al reinicio y a la limpieza de caché; solo se borra al desinstalar o por decisión explícita nuestra.
- **Nombre = `<vista>__<uuid>.<ext>`**: rastreable hasta su fila en SQLite; repetir una vista reutiliza el UUID, así que no proliferan archivos.
- **Jerarquía por proyecto/poste**: hace trivial exportar, calcular ocupación y limpiar por proyecto.

### Escritura verificada y reemplazo seguro

```
1. comprobar que el origen existe y no está vacío
2. copiar a  <destino>.tmp
3. verificar tamaño de .tmp == tamaño del origen
4. verificar SHA-256 de .tmp == SHA-256 del origen
5. rename(.tmp → destino)        ← recién aquí se reemplaza lo anterior
   si algo falla en 2-4: borrar .tmp; el destino NO se toca
```

El paso 5 es lo que hace seguro repetir una foto: la anterior sigue intacta hasta que la nueva está verificada. El SHA-256 se calcula **por bloques** (`sha256.bind(file.openRead())`), no cargando el archivo en memoria — importa con fotos de 48 o 108 MP en un teléfono de gama baja.

### Metadatos registrados por foto

`uuid` · `poste_id` · `proyecto_id` · `linea` · `nombre_foto` · `ruta_archivo` · `ruta_original` · `ruta_miniatura` · `tamano_original` · `tamano_optimizado` · `ancho` · `alto` · `formato` · `fecha_captura` · `creado_en` · `latitud` · `longitud` · `utm_este` · `utm_norte` · `zona` · `precision_gps` · `estado` · `intentos` · `ultimo_error` · `fecha_ultimo_intento` · `checksum` · `id_remoto`

Las columnas de optimización (`ruta_miniatura`, `tamano_optimizado`, `ancho`, `alto`) ya están creadas y se poblarán en P1.

### Política de retención

Por defecto: **se conserva localmente la versión optimizada de alta calidad y nunca se borra en silencio.** Configurable en P1 entre tres modos (conservar original / solo optimizada / borrar original tras confirmar). Antes de liberar espacio, el inspector elige: conservar, liberar o exportar respaldo.

---

## 4. Diseño del pipeline de optimización (P1)

```
captura
   │
   ├─► copia permanente verificada         (ya implementado en P0)
   │
   ├─► lectura de cabecera: dimensiones, orientación EXIF, formato
   │
   ├─► ¿hace falta procesar?
   │      · ¿lado mayor > límite adaptativo?
   │      · ¿peso > objetivo?
   │      · ¿formato incompatible (HEIC)?
   │      · ¿orientación EXIF != normal?
   │      └─ si nada de eso: NO recomprimir. Se registra y se sale.
   │
   ├─► cola de procesamiento (concurrencia 1-2 según el dispositivo)
   │      └─ flutter_image_compress (códec NATIVO, fuera del hilo de UI)
   │             · corrige orientación
   │             · redimensiona conservando proporción
   │             · normaliza a JPEG
   │
   ├─► miniatura independiente (~256 px de lado mayor)
   │
   └─► UPDATE de la fila: ruta_miniatura, tamano_optimizado, ancho, alto
```

### Por qué `flutter_image_compress` y no el paquete `image`

El paquete `image` es Dart puro: descomprimir un JPEG de 108 MP en Dart tarda decenas de segundos y reserva cientos de MB — inviable en un Note 10, y catastrófico en gama baja. `flutter_image_compress` usa los códecs nativos de Android/iOS. Además, el paquete `image` solo está en el árbol como dependencia **de desarrollo** (vía `flutter_launcher_icons`): usarlo en `lib/` rompería el build de release.

### Parámetros de partida (a validar con mediciones, no a fijar a ciegas)

| Parámetro | Valor inicial |
|---|---|
| Lado mayor máximo | 2560 px en gama baja · 3072 px en gama media/alta |
| Calidad JPEG | 88 (dentro del rango 85-92 pedido) |
| Objetivo de peso | ≈ 1,5-2,5 MB por foto |
| Concurrencia | 1 en gama baja · 2 en gama media/alta |
| Umbral de "no tocar" | lado mayor ≤ límite **y** peso ≤ objetivo **y** ya es JPEG con orientación normal |

### Matriz de medición obligatoria antes de fijar valores

Se medirá **peso antes/después, tiempo de proceso, resolución final, pico de memoria y legibilidad visual** sobre: foto pequeña · 12 MP · 48 MP · 108 MP · vertical · horizontal · con texto pequeño (una placa de identificación, que es el caso crítico: si el número de la placa no se lee, la compresión es inaceptable por muy ligera que sea).

Resultados y valores definitivos irán a `IMAGE_PIPELINE.md`.

---

## 5. Diseño de la cola de sincronización (P2)

### Servicio central, fuera de las pantallas

```dart
class ServicioSincronizacion {
  Stream<EstadoSincronizacion> get estado;   // para que la UI observe

  Future<ResumenSincronizacion> sincronizarEstructura(int posteId);
  Future<ResumenSincronizacion> sincronizarLinea(int proyectoId, String linea);
  Future<ResumenSincronizacion> sincronizarProyecto(int proyectoId);
  Future<ResumenSincronizacion> sincronizarTodo();
  Future<ResumenSincronizacion> reintentarFallidos();

  void cancelar();
}
```

### Cola persistente

No hay estructura nueva: la cola **son** las filas de `imagenes_poste_local` y `formularios_pendientes` con `estado IN ('pending','failed')`, ordenadas por antigüedad. Persistente por construcción, sobrevive al cierre de la app, y ya está indexada (`idx_img_estado`, `idx_form_estado`).

### Reintentos con backoff exponencial

```
intento 1 → inmediato
intento 2 → 30 s
intento 3 → 2 min
intento 4 → 8 min
intento 5 → 30 min
intento 6+ → 2 h, con techo
```

Calculado a partir de `intentos` y `fecha_ultimo_intento`, ambos ya en la base. Tras N intentos (configurable, por defecto 8) el elemento pasa a requerir atención manual pero **nunca se descarta**.

### Política de red

| Preferencia | Comportamiento |
|---|---|
| Wi-Fi y datos (por defecto) | Sincroniza con cualquier conexión |
| Solo Wi-Fi | Formularios sí (son kilobytes), fotografías solo con Wi-Fi |
| Manual | Nada automático |

### Conectividad: un único listener

Hoy cada pantalla llama a `checkConnectivity()` por su cuenta, y el formulario lanza una petición HTTP **en cada reconstrucción** del AppBar (es decir, en cada cambio de cualquiera de los 22 desplegables). Se centraliza en un servicio que escucha `onConnectivityChanged` una sola vez y expone un `ValueListenable`.

**Wi-Fi conectado ≠ internet real.** Se validará con una petición ligera a un endpoint propio antes de declarar conexión, con caché de unos segundos para no repetirla.

### Idempotencia

`uuid` + `checksum` viajan ya en cada subida. Con el cambio opcional de backend descrito en §9, un reintento tras timeout deja de poder crear duplicados.

### Lo que no se va a prometer

Sincronización permanente en segundo plano. Android (Doze, límites desde API 26, matanza agresiva en Xiaomi/Samsung) e iOS (BGTaskScheduler sin garantías) no lo permiten de forma fiable. Se implementará: sincronización en primer plano con progreso real, disparo automático al recuperar conexión con la app en uso, reanudación al abrir, y un servicio *foreground* con notificación para sincronizaciones largas que el usuario inicia. Los límites quedan escritos en `OFFLINE_SYNC.md`.

---

## 6. Migraciones necesarias

| Versión | Estado | Contenido |
|---|---|---|
| v1 | original | Esquema base |
| **v2** | ✅ **aplicada en P0** | Estados explícitos, trazabilidad de fotos, `UNIQUE(poste_id)` en borradores, 7 índices, tabla de historial |
| v3 | ⧗ P4 | `revisados_json` en `formularios_pendientes` — registra qué campos confirmó el inspector, para poder distinguir "No revisado" de una respuesta real |
| v4 | ⧗ P5 | `usuario_id` en `proyectos`, `postes`, `formularios_pendientes`, `imagenes_poste_local` — separación de datos entre usuarios sin borrar pendientes al cerrar sesión |
| v5 | ⧗ P2 | `prioridad` y `proximo_intento` en la cola, si el backoff calculado no basta |

Detalle completo, reglas y advertencias en [`MIGRATIONS.md`](MIGRATIONS.md).

---

## 7. Propuesta visual, pantalla por pantalla (P4)

### Sistema de diseño

| Elemento | Decisión |
|---|---|
| Modo | Claro, prioridad absoluta (uso a pleno sol) |
| Rojo `#B71C1C` | **Solo errores y estados críticos.** Hoy se usa para acciones normales ("Ir"), lo que se confunde con error |
| Azul `#0D47A1` | Acciones primarias y navegación |
| Verde `#2E7D32` | Solo confirmaciones reales del servidor |
| Naranja `#EF6C00` | Pendientes y advertencias |
| Degradados | Solo en la barra superior. Hoy cubren pantallas completas y bajan el contraste del contenido |
| Objetivo táctil | Mínimo 48 × 48 (uso con guantes) |
| Tipografía | Mínimo 15 sp en contenido; respeta el escalado del sistema |
| Indicador offline | Persistente, discreto, siempre en el mismo sitio |

### Login

```
┌──────────────────────────────────┐
│                                  │
│         [ logo ECOING ]          │
│            App-Ecoing            │
│   Contratistas Generales S.R.L   │
│                                  │
│  👤 Usuario                       │
│  ┌────────────────────────────┐  │
│  └────────────────────────────┘  │
│  🔒 Contraseña            👁     │  ← mostrar/ocultar
│  ┌────────────────────────────┐  │
│  └────────────────────────────┘  │
│                                  │
│  ┌────────────────────────────┐  │
│  │      INICIAR SESIÓN        │  │  ← 56 px de alto
│  └────────────────────────────┘  │
│                                  │
│  ⚠ Sin conexión. Puedes entrar   │  ← estado claro cuando no hay red
│    con la sesión guardada.        │
│                                  │
│  v1.1.0 · producción              │  ← versión y entorno visibles
└──────────────────────────────────┘
```

Cambios: mostrar/ocultar contraseña · validación inmediata · versión y entorno · comportamiento explícito sin conexión · **la descarga masiva pasa a segundo plano con progreso real** (hoy bloquea el login descargando todos los proyectos y todos sus postes en serie, sin progreso ni reintento).

### Inicio / Proyectos

```
┌──────────────────────────────────────────┐
│ Hola, Juan Pérez            🔌 Offline   │
│ ┌──────────────────────────────────────┐ │
│ │ ⬆ 34 pendientes por enviar           │ │  ← estado global, no oculto
│ │   Última sincronización: hoy 09:12   │ │     en un menú lateral
│ │            [ SINCRONIZAR ]           │ │
│ └──────────────────────────────────────┘ │
│                                          │
│ 🔎 Buscar proyecto                        │
│                                          │
│ ┌──────────────────────────────────────┐ │
│ │ LT 220kV Zona Sur          ● Activo  │ │
│ │ Arequipa · Ecoing                    │ │
│ │ ▓▓▓▓▓▓▓▓▓▓▓▓▓░░░░░░░  118/180        │ │  ← progreso real
│ │ ✔ 118 completas  ⬆ 34 pend  ✖ 2 err  │ │
│ └──────────────────────────────────────┘ │
└──────────────────────────────────────────┘
```

### Líneas y estructuras

Búsqueda **normalizada**: `0025` = `25` = ` 25 ` = `25a` → hoy la comparación es `==` exacta sobre cadena y `0025` no encuentra `25`. Ordenamiento numérico natural de estructuras (hoy no hay `ORDER BY` en ninguna consulta). Estados por estructura: No iniciada · Borrador · Completa local · Pendiente · Sincronizada · Error. Confirmación antes de reemplazar algo ya sincronizado.

### Captura fotográfica

```
┌──────────────────────────────────────────┐
│ ← Fotos · Estructura 25       ☁ 📶       │
│ ✔ 18 de 22 obligatorias                  │
│ ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓░░░  4 por enviar         │
├──────────────────────────────────────────┤
│ ── TORRE ────────────────────────────────│  ← agrupado por categoría
│ [img] PLACA                              │
│       ✔ Sincronizada                     │
│       1.8 MB · UTM 19K · ±4 m      🔄 🗑  │
│ [img] BASE TORRE                          │
│       ⬆ Guardada — por enviar            │
│       2.1 MB · UTM 19K · ±6 m      🔄 🗑  │
│ [ 📷 ] MÉNSULAS                           │
│       Obligatoria — pendiente        📷   │
│ ── AISLADORES ───────────────────────────│
│ ...                                       │
├──────────────────────────────────────────┤
│        [ ⬆ ENVIAR 4 PENDIENTES ]         │
└──────────────────────────────────────────┘
```

**Ya implementado en P0**: contador de obligatorias, estado por foto con el error real, miniaturas con `cacheWidth`, UTM y precisión visibles, repetir y eliminar con confirmación, aviso de archivos perdidos, sin bloqueo del envío.
**Falta en P4**: agrupación por categorías, vista a pantalla completa con zoom, elegir de galería, aviso de precisión GPS mala con botón de reintento, y volver automáticamente a la siguiente vista pendiente tras capturar.

### Formulario técnico

De una pantalla interminable de 23 ítems a **5 pasos** con indicador de progreso:

```
┌──────────────────────────────────────────┐
│ ← Formulario · Estructura 25              │
│ ●━━━●━━━○━━━○━━━○   Paso 2 de 5           │
│ Faja y vegetación · Torre y accesos ·     │
│ Placas y seguridad · Tablero RST · Resumen│
├──────────────────────────────────────────┤
│ 7. Tipo de torre                    *    │
│ ┌──────────────────────────────────────┐ │
│ │ ○ No revisado   ← valor inicial      │ │
│ │ ○ Alineamiento                       │ │
│ │ ○ Ángulo                             │ │
│ │ ○ Fin de línea                       │ │
│ └──────────────────────────────────────┘ │
├──────────────────────────────────────────┤
│ 💾 Guardado automático · hace 12 s        │
│  [ ANTERIOR ]          [ SIGUIENTE ]     │
└──────────────────────────────────────────┘
```

**El cambio de fondo:** hoy 19 de los 22 ítems llegan precargados con `bueno` / `buen_estado` / `n_a` / `no`, y solo 3 son obligatorios. Un inspector puede enviar una inspección completa en dos toques y el servidor recibe 19 respuestas que nadie miró, indistinguibles de una inspección real. Pasan a `No revisado` y se registra qué campos confirmó de verdad (migración v3).

> ⚠️ **Esto cambia lo que recibe el backend en 19 campos.** Necesita tu decisión y probablemente un cambio de servidor. Ver §9.

Pantalla de resumen antes de finalizar: fotos completas · campos sin revisar · GPS disponible · estado local · estado de sincronización.

### Mensajes

| En lugar de | Decir |
|---|---|
| "✅ Envío exitoso" (mostrado incluso al fallar) | "Guardado en este teléfono · Pendiente de enviar" |
| "✅ Sincronización completada con éxito" | "Servidor confirmó 8 formularios y 176 fotografías" |
| "Error al subir." (snackbar de 2 s) | "3 fotografías no pudieron enviarse. Tu información sigue segura en el teléfono." + botón Reintentar |

**Ya implementado en P0.** Se eliminaron los modales que se cierran solos, los dos `Navigator.pop()` automáticos y los spinners infinitos.

---

## 8. Plan de implementación por commits

### ✅ P0 · Integridad de datos — COMPLETADO

| Commit | Contenido |
|---|---|
| `9f44314` | Punto de restauración: estado original intacto |
| `a9c2c59` | Reparar la suite de pruebas + dependencias de integridad |
| `7a4600a` | Migración v2: estados explícitos, índices, historial |
| `fc80ffb` | Almacenamiento permanente + repositorios |
| `248865f` | Extraer conversión UTM + corregir desbordamiento + reemplazo seguro |
| `2471334` | La subida informa qué confirmó el servidor |
| `2febebc` | Eliminar la pérdida de las 22 fotos; recuperar las ya tomadas |
| `b1779e6` | Recuperar el borrador; desbloquear el botón de envío |
| `5daa7f3` | No marcar sincronizado sin confirmación; desbloquear la UI |
| `6cc69b9` | Recuperar trabajo interrumpido al arrancar |
| `8e62998` | 24 pruebas de integridad + documentación |

### ⧗ P1 · Imágenes y almacenamiento

1. `feat(imagenes)`: servicio de optimización con `flutter_image_compress`, fuera del hilo de UI
2. `feat(imagenes)`: generación de miniaturas + `cacheWidth` en todas las listas
3. `perf(imagenes)`: cola de procesamiento con concurrencia adaptativa
4. `feat(imagenes)`: política de retención configurable + control de espacio
5. `docs`: `IMAGE_PIPELINE.md` con la matriz de mediciones reales
6. `test`: orientación EXIF, no recomprimir lo ya optimizado, 108 MP, imagen corrupta

### ⧗ P2 · Sincronización

1. `refactor(sync)`: extraer `ServicioSincronizacion` de las pantallas
2. `feat(sync)`: cola con backoff exponencial y límite de intentos
3. `feat(sync)`: sincronizar estructura / línea / proyecto / todo / reintentar fallidos
4. `feat(conectividad)`: listener único + detección de "Wi-Fi sin internet"
5. `feat(sync)`: disparo automático al recuperar conexión + preferencia solo-Wi-Fi
6. `feat(sync)`: pantalla de resumen con reintento por elemento
7. `fix(sync)`: reconciliación de los `synced` heredados dudosos
8. `test`: token vencido a mitad de cola, red que cae en la foto 10 de 22, doble pulsación

### ⧗ P3 · Arquitectura

1. `refactor`: mover a `core/ data/ dominio/ servicios/ presentacion/`
2. `refactor(http)`: cliente único con manejo tipado de errores
3. `refactor`: repositorios de proyectos y postes
4. `fix(db)`: `ORDER BY` en todas las listas + búsqueda normalizada de estructura
5. `chore`: retirar 6 dependencias sin usar y ~450 líneas de código muerto
6. `docs`: `ARCHITECTURE.md`

### ⧗ P4 · UX/UI

1. `feat(diseno)`: sistema de diseño (colores, tipografía, espaciado, componentes)
2. `feat(ux)`: login, inicio con pendientes y progreso visible
3. `feat(ux)`: líneas y estructuras con estados y búsqueda tolerante
4. `feat(ux)`: captura fotográfica agrupada, pantalla completa, zoom
5. `feat(ux)`: formulario en 5 pasos con autoguardado y resumen
6. `feat(db)`: migración v3 — `revisados_json`, "No revisado" **(requiere tu decisión)**
7. `feat(ux)`: implementar o retirar Perfil, Ajustes y Editar Postes

### ⧗ P5 · Seguridad y configuración

1. `feat(seguridad)`: token en `flutter_secure_storage`, expiración con `jwt_decoder`, manejo de 401
2. `feat(db)`: migración v4 — separación por usuario sin borrar pendientes al cerrar sesión
3. `feat(config)`: entornos con `--dart-define`, sin URL en el código
4. `fix(android)`: retirar `usesCleartextTraffic`, `MANAGE_EXTERNAL_STORAGE`, `ACCESS_BACKGROUND_LOCATION`
5. `fix(android)`: `applicationId` propio + keystore de release **(necesito que generes la keystore)**
6. `fix(ios)`: `Info.plist` con las tres claves de permiso
7. `refactor`: logger con niveles en lugar de `print`

### ⧗ P6 · Rendimiento, pruebas y publicación

1. `perf`: medir arranque, memoria, listas y sincronización en el Note 10; optimizar con datos
2. `perf(build)`: `--split-per-abi` / App Bundle — atacar los 52 MB del APK
3. `test`: pruebas de widget en pantalla pequeña y con texto ampliado
4. `docs`: `README.md`, `TESTING.md`, `CHANGELOG.md`, `.env.example`
5. `chore`: preparar publicación

---

## 9. Lo que necesito de ti

Ninguna de estas cosas bloquea P1, pero tres afectan a decisiones de diseño:

| # | Qué necesito | Por qué | Bloquea |
|---|---|---|---|
| 1 | **El código PHP del backend**, o al menos una respuesta real de `imagenes-poste.php` y de `actualiza-datos.php` | El contrato está deducido del cliente. Sin verlo no puedo confirmar la recepción foto por foto ni saber si `uuid`/`checksum` se pueden usar para deduplicar | Parte de P2 |
| 2 | **El PDF de ejemplo** | No existe ninguno en el proyecto (`find . -iname "*.pdf"` → vacío). Necesito ver el resultado final para no romper nada que el reporte consuma | Nada por ahora |
| 3 | **Decisión sobre "No revisado"** (§7) | Cambia lo que el backend recibe en 19 campos. Es lo correcto para la calidad del dato, pero es una decisión tuya y del equipo de servidor | P4 |
| 4 | **Keystore de release** y `applicationId` definitivo | No puedo generar una keystore de producción por ti: la clave privada debe quedar solo en tus manos | P5 |
| 5 | **Confirmar el orden de fases** | Propongo seguir con P1 (imágenes) porque las fotos de 48-108 MP sin comprimir son el siguiente problema real de campo: llenan el teléfono y hacen imposible subir con señal débil | — |
