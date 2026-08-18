# Pruebas

```bash
flutter test                                     # todo
flutter test test/repositorios/                  # integridad de datos
flutter test test/database/migraciones_test.dart # migraciones
flutter test --coverage                          # con cobertura
```

Estado actual: **51 pruebas, todas en verde.**
Antes de este trabajo `flutter test` **fallaba**: el único archivo era la plantilla del contador de Flutter y no compilaba.

## Qué hay

| Archivo | Casos | Qué protege |
|---|---|---|
| [`test/database/migraciones_test.dart`](test/database/migraciones_test.dart) | 11 | Que actualizar la app no pierda ni corrompa datos |
| [`test/repositorios/integridad_p0_test.dart`](test/repositorios/integridad_p0_test.dart) | 24 | Que no se pierda ninguna foto ni formulario, y que nada se marque como sincronizado sin confirmación |
| [`test/core/conversion_utm_test.dart`](test/core/conversion_utm_test.dart) | 14 | Que la conversión GPS→UTM siga siendo correcta |
| [`test/widget_test.dart`](test/widget_test.dart) | 2 | Que la app arranque y el login valide |

## Cómo se prueban SQLite y los archivos sin emulador

`sqflite` necesita el plugin de plataforma, que no existe en el entorno de pruebas de Dart. La solución es `sqflite_common_ffi`, que ejecuta SQLite real en el proceso de pruebas:

```dart
sqfliteFfiInit();
databaseFactory = databaseFactoryFfi;
```

Cada caso trabaja contra su propia base en un directorio temporal:

```dart
temporal = await Directory.systemTemp.createTemp('ecoing_p0_');
AlmacenamientoFotos.baseDePruebas = temporal;      // sustituye a path_provider
await databaseFactory.setDatabasesPath(temporal.path);
DatabaseHelper.nombreArchivo = 'prueba_$contador.db';
await DatabaseHelper.reiniciarParaPruebas();
```

Dos ganchos marcados `@visibleForTesting` en `DatabaseHelper` (`nombreArchivo`, `reiniciarParaPruebas`) y uno en `AlmacenamientoFotos` (`baseDePruebas`) hacen esto posible sin inyección de dependencias completa.

## Escenarios de campo cubiertos

Las pruebas de integridad no son casos sintéticos: cada una reproduce algo que pasa en una torre a tres horas de la carretera.

| Escenario | Verificación |
|---|---|
| **Android purga la caché** tras la captura | La foto sobrevive en almacenamiento permanente |
| Archivo capturado de **0 bytes** | Se rechaza y **no** deja fila en la base |
| **Repetir una foto** | Reemplazo en su sitio, un solo archivo, sin `.tmp` abandonados |
| **La copia de reemplazo falla** | La foto anterior sigue intacta, con su checksum original |
| Checksum | Coincide con el contenido real recalculado desde disco |
| Subida devuelve **500** | `failed`, sigue en la cola, `intentos = 1`, error guardado |
| Servidor responde **HTML** en vez de JSON | `failed` con el cuerpo recortado en `ultimo_error` |
| **Fallos repetidos** | `intentos` se acumula: 1, 2, 3… |
| **La app muere durante la subida** | Al arrancar vuelve de `uploading` a `pending` |
| **Archivo borrado externamente** | `failed` con "ya no está en el teléfono", no se finge éxito |
| Eliminar una foto | Se borran fila y archivo |
| **Guardar el formulario 5 veces** | Un solo borrador, la última versión |
| UUID entre guardados | Se conserva (idempotencia) |
| Tablero RST reguardado | Se reemplaza, no se acumula |
| Fallo de envío del formulario | `failed`, datos intactos, no `synced` |
| **`datos_json` corrupto** | El formulario abre igual, sin perder la fila |
| **Reeditar una inspección sincronizada** | Se recupera el contenido anterior |
| Migración con **borradores duplicados** | Se consolidan y los duplicados quedan archivados |
| Migración aplicada **dos veces** | Idempotente, no duplica nada |
| Instalación nueva vs. actualizada | Esquemas idénticos, columna por columna |
| UTM en el meridiano central del ecuador | E = 500000, N = 0 exactos (identidad matemática) |
| UTM entre 80° y 84° | No lanza `RangeError` (fallo del código original) |

## Cobertura automatizada actual

El pipeline de imágenes, el backoff, el cliente HTTP, «No revisado», las
migraciones, pantalla pequeña y texto ampliado ya están cubiertos. La limpieza
de originales verifica que una foto pendiente nunca se borra, y el reintento de
GPS verifica que conserva el archivo y devuelve la foto a la cola.

## Qué falta

| Pendiente | Fase |
|---|---|
| Pruebas de integración en dispositivo real (`integration_test`) | P6 |

## Qué hay que probar a mano en el teléfono

Lo que ninguna prueba automatizada cubre. Dispositivo disponible: **Samsung SM-N970F, Android 12**.

1. Instalar **encima de la versión anterior** (no desinstalar) con datos existentes → la migración v2 debe correr sin perder nada.
2. Tomar 22 fotos **en modo avión** → cerrar la app a la fuerza → reabrir → las 22 siguen ahí.
3. Tomar fotos **con Wi-Fi** y cortar la red a mitad del envío → deben quedar como "Error al enviar — sigue guardada", nunca desaparecer.
4. Abrir una estructura **ya fotografiada** → deben aparecer las fotos, no una lista vacía.
5. Pulsar "Editar" en una estructura **ya inventariada** → debe salir el banner de borrador recuperado con los valores anteriores.
6. Dejar el ítem 11 (Estado de placas) incompleto y enviar → el botón debe volver a estar disponible, no quedar en spinner.
7. Sincronizar una página **sin sesión válida** → mensaje claro, sin pantalla bloqueada.
8. Ajustes de Android → Almacenamiento → **Borrar caché** de la app → las fotos pendientes deben seguir ahí.
