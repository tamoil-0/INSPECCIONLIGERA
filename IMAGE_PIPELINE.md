# Pipeline de imágenes

Código: [`lib/servicios/imagenes/`](lib/servicios/imagenes/) y [`lib/core/dimensiones_imagen.dart`](lib/core/dimensiones_imagen.dart).
Pruebas: [`test/servicios/imagenes_test.dart`](test/servicios/imagenes_test.dart) — 19 casos.

---

## El problema

Un teléfono moderno saca fotos de 12, 48 o 108 MP. Una estructura pide 28 fotografías obligatorias. Sin optimización:

| Cámara | Peso por foto | 28 fotos | 10 estructuras |
|---|---|---|---|
| 12 MP | ~5 MB | 140 MB | 1,4 GB |
| 48 MP | ~14 MB | 392 MB | 3,9 GB |
| 108 MP | ~28 MB | 784 MB | 7,8 GB |

Con 3G rural a ~200 kB/s, subir 392 MB son **más de 30 minutos** por torre — y basta un corte para volver a empezar. El teléfono se llena en una jornada.

---

## El flujo

```
   captura (image_picker → caché del sistema)
        │
        ▼
   ① COPIA PERMANENTE VERIFICADA          ← P0, ya en producción
        │  tamaño + SHA-256, escritura a .tmp y rename
        │  la foto ya está a salvo aquí
        ▼
   ② registro en SQLite  estado = pending  ← P0
        │
        │   ── el inspector ya puede seguir con la siguiente foto ──
        ▼
   ③ LECTURA DE CABECERA (sin decodificar)
        │  ancho, alto, formato
        ▼
   ④ ¿HACE FALTA PROCESAR?
        │   · lado mayor > límite del perfil        → sí
        │   · peso > objetivo del perfil            → sí
        │   · formato HEIC / WebP / PNG             → sí (normalizar a JPEG)
        │   · dimensiones desconocidas              → sí (por si acaso)
        │   · nada de lo anterior                   → NO: se deja intacta
        ▼
   ⑤ COLA con concurrencia 1-2 según el teléfono
        │
        ▼
   ⑥ flutter_image_compress (códec NATIVO)
        │  · aplica la rotación EXIF a los píxeles
        │  · escala conservando proporción
        │  · normaliza a JPEG
        ▼
   ⑦ MINIATURA independiente (~320 px)
        │
        ▼
   ⑧ UPDATE de la fila
           ruta_archivo      → la versión que se sube
           ruta_original     → el original, si la política lo conserva
           ruta_miniatura    → para las listas
           tamano_optimizado, ancho, alto
           checksum          → RECALCULADO sobre lo que se va a subir
```

**El paso ① ocurre siempre y primero.** Todo lo demás es mejora: si falla cualquier paso del ③ al ⑧, se sube el original tal cual. Una compresión fallida no puede costarle una visita a la torre al inspector.

---

## Decisiones y por qué

### Códecs nativos, no Dart

`flutter_image_compress` delega en las librerías del sistema (Android `Bitmap`/`libjpeg`, iOS `Core Graphics`).

Se descartó el paquete `image` (Dart puro) por dos razones:
1. Descomprimir un JPEG de 108 MP en Dart tarda decenas de segundos y reserva ~324 MB de bitmap: inviable en gama baja.
2. `image` solo está en el árbol como dependencia **de desarrollo** (vía `flutter_launcher_icons`). Usarlo en `lib/` rompería la compilación de release.

No hace falta un `Isolate`: la compresión ocurre en un hilo nativo y el hilo de UI de Dart solo espera el `Future`.

### Leer las dimensiones sin decodificar

[`LectorDimensiones`](lib/core/dimensiones_imagen.dart) recorre las cabeceras del archivo saltando cada segmento por su longitud declarada:

- **JPEG**: busca el marcador SOF (`FFC0`…`FFCF`), saltando el APP1/EXIF que puede ocupar decenas de kB con una miniatura incrustada.
- **PNG**: bloque IHDR, desplazamiento 16.
- **WebP**: variante VP8X, desplazamiento 24.
- **HEIC** y desconocidos: devuelve `null`, que significa "no lo sé, procésala por si acaso".

Lee unos pocos kilobytes. `decodeImageFromList` habría cargado la imagen entera solo para saber su tamaño.

### `minWidth`/`minHeight` son cotas INFERIORES

Trampa del plugin que costaría caro pasar por alto: **no son un techo**. El escalado se calcula para que la imagen resultante no sea *más pequeña* que esos valores. Pasar `minWidth: 3072, minHeight: 3072` a una foto de 4000×3000 escalaría el lado **corto** a 3072 y dejaría el largo en 4096 — más grande que el límite pretendido.

Por eso se calcula el objetivo exacto a partir de las dimensiones leídas de la cabecera:

```dart
final escala = ladoMaximo / origen.ladoMayor;
minWidth  = (origen.ancho * escala).round();
minHeight = (origen.alto  * escala).round();
```

### Orientación EXIF: se aplica a los píxeles

`autoCorrectionAngle: true` rota la imagen realmente, y `keepExif: false` no arrastra la etiqueta de orientación. Así ni el backend ni el generador de PDF dependen de que alguien lea el EXIF, y no hay riesgo de doble rotación en visores que sí lo leen.

El GPS y la fecha **no se pierden** por descartar el EXIF: se guardan en SQLite en la captura y viajan como campos propios del multipart. (La lectura EXIF de GPS del código original nunca funcionó: `double.parse` sobre razones tipo `11/1` siempre lanzaba.)

### No recomprimir lo que ya está bien

Recomprimir un JPEG degrada la imagen sin ganar nada. Si el lado mayor cabe en el límite **y** el peso está bajo el objetivo **y** ya es JPEG, se deja intacta y solo se genera la miniatura.

También se descarta el resultado si el "optimizado" pesa **más** que el original: se conserva el original y se anota el motivo.

### Concurrencia limitada

28 compresiones simultáneas agotan la memoria en gama baja: cada códec nativo reserva su búfer. [`ColaProcesamiento`](lib/servicios/imagenes/cola_procesamiento.dart) procesa de 1 en 1 o de 2 en 2 según el perfil.

La cola es **en memoria**. Si la app se cierra, las fotos pendientes de optimizar siguen en disco con su registro completo y son perfectamente subibles; al reabrir la estructura, `sinOptimizar()` las detecta y se reencolan. Perder la cola no pierde nada.

Un fallo individual no arrastra al resto: `encolarTodas` devuelve `null` en las posiciones que fallaron.

---

## Perfiles por dispositivo

La gama se estima con `Platform.numberOfProcessors`. Es una aproximación —lo exacto sería leer la RAM— pero evita añadir `device_info_plus` solo para esto, y el inspector puede forzar el perfil desde Ajustes.

| Perfil | Núcleos | Lado mayor | Calidad | Peso objetivo | Concurrencia |
|---|---|---|---|---|---|
| Baja | ≤ 4 | 2560 px | 86 | 1,6 MB | 1 |
| Media | 5-6 | 3072 px | 88 | 2,2 MB | 2 |
| Alta | ≥ 7 | 3072 px | 90 | 2,6 MB | 2 |

Miniatura: 320 px, calidad 72, en todos los perfiles.

### Por qué estos valores

**La legibilidad del detalle técnico manda sobre el peso.** Un inspector fotografía el número de una placa, una grieta fina, óxido en un perno, hebras rotas de un conductor. A 2560 px de lado mayor, una placa que ocupe un 15 % del encuadre sigue teniendo ~380 px de ancho: legible. Bajando a 1920 px empieza a ser dudoso.

La calidad se mantiene entre 85 y 92 — hay una prueba que lo verifica y falla si alguien la baja. Por debajo de 85 los artefactos JPEG empiezan a comerse los bordes finos.

Reducción esperada: de ~14 MB (48 MP) a ~2 MB, es decir **1/7 del peso** manteniendo el detalle relevante.

> ⚠️ **Estos valores son un punto de partida documentado, no una medición.** Están elegidos con criterio pero **todavía no se han validado con fotos reales de campo**, porque eso requiere el teléfono y estructuras reales. La tabla de la sección siguiente está pendiente de rellenar.

---

## Matriz de medición (pendiente de ejecutar en dispositivo)

Hay que medir con fotos **reales de una torre**, no sintéticas, y mirar la imagen resultante, no solo la gráfica.

| Caso | Peso antes | Peso después | Tiempo | Resolución final | Pico de memoria | ¿Se lee la placa? |
|---|---|---|---|---|---|---|
| Foto pequeña (2 MP) | | | | | | |
| 12 MP horizontal | | | | | | |
| 12 MP vertical | | | | | | |
| 48 MP | | | | | | |
| 108 MP | | | | | | |
| Placa con número pequeño | | | | | | **criterio decisivo** |
| Grieta fina en hormigón | | | | | | |
| Óxido en perno | | | | | | |
| HEIC de iPhone | | | | | | |
| Ya optimizada (2 MP, 1 MB) | | | ~0 | sin cambios | | debe quedar intacta |

**Criterio de aceptación:** si el número de la placa no se lee en la versión optimizada, la compresión es inaceptable por muy ligera que quede. Ante la duda, subir el límite antes que bajarlo.

Procedimiento: activar el perfil a mano desde Ajustes, capturar cada caso, y leer el detalle que la lista ya muestra por foto (`2,1 MB · 3072×2304 · UTM 19K · ±4 m`).

---

## Política de retención

Configurable en Ajustes. Por defecto **`soloOptimizada`**.

| Política | Qué conserva | Espacio por estructura (48 MP) |
|---|---|---|
| `conservarOriginal` | Original + optimizada + miniatura | ~350 MB |
| **`soloOptimizada`** (defecto) | Optimizada + miniatura | ~45 MB |
| `liberarTrasSincronizar` | Ambas hasta que el servidor confirme, luego solo la optimizada | ~350 MB temporalmente |

### Reglas

1. **La versión optimizada nunca se borra automáticamente.** Es la que se sube y la que queda como registro local.
2. **El original solo se retira con la copia optimizada ya verificada en disco.** Nunca antes.
3. Al **repetir** una foto se borran las tres variantes de la captura anterior (original, optimizada y miniatura), y solo después de que la nueva esté verificada.
4. Antes de liberar espacio de fotos ya sincronizadas, el inspector elige: conservar · liberar · exportar respaldo. Nunca se hace en silencio.

El espacio ocupado se muestra en la propia pantalla de fotos: `Fotos en el teléfono: 412 MB · calidad media · optimizando 3`.

---

## Completado en la interfaz

- Vista a pantalla completa con zoom.
- Cámara o galería por cada vista fotográfica.
- Agrupación por torre/entorno, aisladores y conductores/ferretería.
- Aviso de precisión GPS mayor de 25 m y reintento sin repetir la imagen.
- Ajustes de perfil, retención y solo Wi-Fi.
- Limpieza explícita de originales ya confirmados; nunca toca pendientes.

## Qué falta

| Pendiente | Fase |
|---|---|
| Rellenar la matriz de medición con fotos reales | P6 |
| Espacio **libre** del volumen (requiere canal nativo; hoy solo se mide el ocupado) | P6 |
