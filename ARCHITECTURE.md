# Arquitectura

## Punto de partida

Las pantallas hablaban directamente con `DatabaseHelper` y con los servicios HTTP.
`detalle_linea_screen.dart` tenía 521 líneas mezclando interfaz, orquestación de
red y acceso a disco, y era la que decidía marcar los registros como
sincronizados. Sin inyección de dependencias ni interfaces, el código no era
testeable: `flutter test` fallaba y no había ni una prueba real.

## Capas

```
┌──────────────────────────────────────────────────────────────┐
│  presentacion/                                               │
│    diseno/     sistema de diseño (colores, tema, espaciado)  │
│    comunes/    componentes reutilizables                     │
│  screens/      pantallas                                     │
└────────────────────────┬─────────────────────────────────────┘
                         │ solo lee y pide; no orquesta
┌────────────────────────▼─────────────────────────────────────┐
│  servicios/                                                  │
│    sincronizacion/  servicio central + política de reintentos│
│    imagenes/        optimización, cola, perfiles             │
│    conectividad/    un único observador de red               │
└────────────────────────┬─────────────────────────────────────┘
                         │
┌────────────────────────▼─────────────────────────────────────┐
│  repositorios/    hacen cumplir los invariantes de datos     │
│    fotos_repositorio       · borradores_repositorio          │
└──────────┬──────────────────────────────┬────────────────────┘
           │                              │
┌──────────▼───────────┐      ┌───────────▼─────────────────────┐
│  database/           │      │  data/remoto/cliente_api        │
│  SQLite + migraciones│      │  + services/ por recurso        │
└──────────────────────┘      └─────────────────────────────────┘
           ▲                              ▲
           └──────────┬───────────────────┘
                      │
              ┌───────▼────────┐
              │  core/         │  sin dependencias de UI ni datos
              │  storage/      │  almacén seguro
              └────────────────┘
```

**La regla que importa:** el repositorio es el **único** que puede escribir
`estado = 'synced'`, y solo lo hace con una confirmación del servidor en la mano.
Ese es el mecanismo que impide que vuelva a aparecer el defecto de marcar como
enviado lo que no llegó — no la disciplina de quien escriba la próxima pantalla.

## Invariantes

| Invariante | Dónde se hace cumplir |
|---|---|
| La foto se copia a disco permanente y se registra ANTES de subirla | `FotosRepositorio.registrarCaptura` |
| Un solo borrador vigente por poste | Índice único de la migración v2 + `BorradoresRepositorio` |
| Solo el servidor lleva a `synced` | `marcarSincronizada` / `marcarSincronizado` |
| Nada se descarta al fallar | `marcarFallida` conserva el registro y suma un intento |
| Lo interrumpido vuelve a la cola | `recuperar*Interrumpidos()` en `main.dart` |
| Las migraciones no borran datos | `migraciones.dart`, con historial para lo que se consolida |

## Decisiones sobre dependencias

Cada dependencia añade tamaño al APK, superficie de fallo y trabajo de
mantenimiento. Las que se rechazaron y por qué:

| Paquete | Decisión | Motivo |
|---|---|---|
| `provider`, `riverpod`, `bloc` | **No** | 9 pantallas con estado casi todo local. `setState` más repositorios cubre el caso. `provider` estaba declarado y nunca se importó. Si el estado global crece, `ValueNotifier` (ya en uso para conectividad y progreso) no añade dependencia. |
| `dio` | **No** | `http` cubre todo lo que hace la app. `dio` aportaría interceptores y progreso de subida; se reevaluará solo si el progreso real por archivo lo exige. |
| `flutter_dotenv` | **Retirado** | Un archivo `.env` dentro del APK no es un secreto: se extrae con `unzip`. Se usa `--dart-define`. |
| `location` | **Retirado** | Duplicaba `geolocator` y añadía permisos propios al manifiesto. |
| `flutter_svg` | **Retirado** | Nunca se importó. |
| `exif` | **Retirado** | La lectura EXIF de GPS nunca funcionó: `double.parse` sobre razones tipo `11/1` siempre lanzaba. El GPS viene de `geolocator`. |
| `image` (Dart puro) | **No** | Descomprimir un JPEG de 108 MP tardaría decenas de segundos y reservaría unos 324 MB. Además solo está en el árbol como dependencia de desarrollo: usarlo en `lib/` rompería el release. |
| `device_info_plus` | **No** | Solo se necesitaba para estimar la gama del teléfono. Se usa `Platform.numberOfProcessors` como aproximación, con opción de forzar el perfil a mano. |
| `flutter_image_compress` | **Sí** | Códecs nativos. Sin esto no hay optimización viable en gama baja. |
| `flutter_secure_storage` | **Sí** | El token estaba en `SharedPreferences` en texto plano. |
| `crypto`, `uuid` | **Sí** | Checksum e identificadores para integridad e idempotencia. Ya estaban en el árbol transitivo: impacto nulo en tamaño. |
| `jwt_decoder` | **Sí** | Ya estaba declarado sin usar. Ahora comprueba la expiración de la sesión. |
| `sqflite_common_ffi` (dev) | **Sí** | Sin ella no se puede probar una migración ni un repositorio sin emulador. Es la que hace posibles 60 de las 147 pruebas. |

## Manejo de errores

`ClienteApi` traduce cada fallo a un `ErrorApi` con tipo, código HTTP y el detalle
real. Antes todo era un mapa con `success: false` y el error como cadena
concatenada, y era imposible distinguir «no hay red» de «la sesión venció» o «el
servidor devolvió HTML».

Con la clasificación, dos cosas se vuelven posibles:

- la interfaz le dice al inspector qué pasó y si su trabajo está a salvo;
- la cola de sincronización decide si merece la pena reintentar
  (`ErrorApi.esTransitorio`).

`respuestaNoJson` guarda los primeros 200 caracteres del cuerpo. Es lo único que
permite diagnosticar un error de PHP desde un teléfono en el campo.

## Flujo de una captura

```
ImagenesPosteScreen
   │  1. image_picker → archivo en caché del sistema
   ▼
FotosRepositorio.registrarCaptura
   │  2. AlmacenamientoFotos.persistir  (copia + verificación + SHA-256)
   │  3. INSERT en imagenes_poste_local, estado = pending
   │     ── el dato ya está a salvo aquí ──
   ▼
ColaProcesamiento (concurrencia 1-2)
   │  4. OptimizadorImagenes  (códec nativo, hilo nativo)
   │  5. UPDATE ruta_archivo / miniatura / medidas / checksum
   ▼
ServicioSincronizacion  (cuando el inspector lo pide, o al recuperar red)
   │  6. marcarSubiendo
   │  7. ImagenesPosteService.subirImagenBatch → ResultadoSubida
   │  8. confirmadas → marcarSincronizada · resto → marcarFallida
   ▼
Arranque de la app
      9. lo que quedó en uploading vuelve a pending
```

Cada paso del 4 al 8 puede fallar sin consecuencias: el dato quedó a salvo en el
paso 3.

## Qué queda pendiente

| Pendiente | Nota |
|---|---|
| Repositorios de proyectos y postes | Las pantallas todavía llaman a `DatabaseHelper` para leer proyectos y postes |
| `PRAGMA foreign_keys` | Las tablas declaran claves ajenas que nunca se han aplicado; activarlas convertiría inserciones que hoy funcionan en fallos nuevos. Requiere revisar cada ruta de inserción con pruebas |
| Modelos inmutables de poste y proyecto | Hoy circulan como `Map<String, dynamic>` |
| Mover `services/` bajo `data/remoto/` | Cosmético; se dejó fuera para no inflar el diff |
