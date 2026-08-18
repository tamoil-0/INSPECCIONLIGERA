# Registro de cambios

## 1.1.0 — 18/08/2026

Revisión completa del aplicativo móvil. El punto de partida está documentado en
[`ANALISIS_APP_MOVIL.md`](ANALISIS_APP_MOVIL.md).

### Pérdida de datos corregida

- **Las fotografías ya no se pueden perder.** Con internet, la versión anterior
  solo las subía: el guardado en SQLite vivía en la rama del caso sin conexión.
  Un fallo de red dejaba las 22 fotos de una torre únicamente en la caché de la
  cámara, sin registro y sin posibilidad de reintentar. Ahora cada captura se
  copia a almacenamiento permanente y se registra en el momento de tomarla.
- **Las fotos salen de la caché del sistema.** Android puede vaciarla en
  cualquier momento, y la ruta guardada apuntaba ahí.
- **Nada se marca como sincronizado sin confirmación del servidor.** Antes se
  ignoraba el valor de retorno de la subida y se marcaba todo: una foto que
  fallaba quedaba invisible para siempre y nunca se reintentaba.
- **«Editar» ya no borra la inspección anterior.** `cargarDesdeMap` estaba
  escrita y completa pero no se llamaba desde ningún sitio, así que el formulario
  se abría en blanco y al guardarlo sobrescribía lo anterior con los valores por
  defecto.
- **Se recuperan las fotos ya tomadas** al reabrir una estructura. Antes había
  que repetir las 22.
- **El trabajo interrumpido vuelve a la cola** al arrancar la app.
- **Los borradores duplicados se consolidaron sin borrar nada**: se archivan en
  `formularios_pendientes_historial`.
- **Reemplazar una foto es seguro**: se escribe a un temporal, se verifica tamaño
  y checksum, y solo entonces se sustituye la anterior.

### Interfaz bloqueada corregida

Tres estados de carga que nunca se liberaban:

- el botón «Enviar formulario» quedaba en spinner permanente si fallaba la
  validación del estado de placas;
- el botón de enviar fotos quedaba deshabilitado tras el primer envío;
- la pantalla de sincronización quedaba tras un overlay modal para siempre si
  faltaba el token, y la única salida era matar la app.

También se retiraron los dos `Navigator.pop()` incondicionales dentro de un
`Future.delayed`, que cerraban el diálogo de error en lugar de la pantalla.

### Mensajes honestos

Se eliminaron los «Envío exitoso» y «Sincronización completada con éxito» que se
mostraban aunque el envío hubiera fallado. Ahora se distingue explícitamente
«Guardado en este teléfono» de «Confirmado por el servidor», y se informa del
recuento real: «Servidor confirmó 8 de 22 fotografías».

### Nuevo

- **Optimización de imágenes en el teléfono** con códecs nativos: de unos 14 MB a
  unos 2 MB por foto, conservando el detalle que hace legible el número de una
  placa.
- **Servicio central de sincronización**: por estructura, línea, proyecto o todo,
  con backoff exponencial, límite de intentos y reintento de solo lo fallido.
  Antes solo existía «sincronizar esta página» de 10 postes.
- **Disparo automático al recuperar la conexión**, con la app en uso.
- **Preferencia de solo Wi-Fi** para las fotografías.
- **Formulario en 5 pasos** con autoguardado, progreso y resumen final.
- **«No revisado» por defecto**: 19 de los 22 ítems llegaban precargados con
  `bueno`, `buen_estado` o `n_a`, indistinguibles de una inspección real. Ver
  [`BACKEND_CONTRATO.md`](BACKEND_CONTRATO.md).
- **Búsqueda tolerante**: `0025` encuentra `25`, y orden natural (`2` antes de
  `10`).
- **Sistema de diseño** con semántica estricta de color: el rojo institucional era
  el color del botón «Ir», indistinguible de un error.
- **Cliente HTTP único** con 14 tipos de error clasificados y timeouts.
- **Token en el almacén seguro** del sistema, con expiración comprobada.
- **150 pruebas** donde antes `flutter test` no compilaba.
- **Captura fotográfica agrupada** por torre, aisladores y conductores, con
  selección desde galería, visor a pantalla completa con zoom y avance a la
  siguiente vista pendiente.
- **Reintento de GPS sin repetir la foto** cuando la precisión supera 25 m.
- **Pantalla de Ajustes** para modo offline, solo Wi-Fi, perfil de imagen,
  política de retención y limpieza explícita de originales sincronizados.

### Corregido

- `RangeError` en la conversión UTM entre 80° y 84° de latitud.
- Los metadatos UTM viajaban como la cadena literal `"null"` en el camino de
  respaldo, porque el lector EXIF devolvía claves distintas de las que se
  enviaban.
- La exportación de imágenes no funcionaba en Android 13+: pedía
  `Permission.storage`, obsoleto y denegado siempre.
- Los parámetros de consulta no se escapaban: una línea con `&` o espacios rompía
  la petición.
- `jsonDecode` a ciegas: un error 500 de PHP con HTML producía `FormatException`.
- El formulario lanzaba una petición HTTP en cada reconstrucción del AppBar, es
  decir en cada cambio de cualquiera de los 22 desplegables.
- Los postes sin línea se descartaban en el login y eran invisibles para siempre,
  sin ningún aviso.
- `_ubicacionesPorLinea` era una variable global de archivo: se compartía entre
  proyectos.
- Ninguna consulta tenía `ORDER BY`.
- El menú tenía «Editar Postes» apuntando a una ruta comentada, lo que producía
  la pantalla de error de Flutter.
- Dos desbordamientos de interfaz con el texto del sistema ampliado, detectados
  por las nuevas pruebas de widget.
- Se retiraron 31 llamadas a `print()`, incluidas 8 por cada formateo de fecha
  (que con 180 estructuras eran 1440 líneas de log por repintado).
- Código muerto eliminado: `linea_service`, `datos_formulario_model`,
  `dialogs_util`, `screens/1.dart`, `storage/preference.s.dart`,
  `debug_info_widget` y los alias obsoletos de `ApiConfig`.

### Base de datos

- **v2** — estados de sincronización explícitos, trazabilidad de fotografías
  (27 columnas nuevas), 7 índices, un solo borrador por poste, historial de
  duplicados.
- **v3** — registro de qué campos revisó el inspector.

Todas las migraciones son aditivas e idempotentes, y hay pruebas de que una
instalación nueva y una actualizada acaban con el mismo esquema exacto.

### Seguridad y publicación

- Retirados `MANAGE_EXTERNAL_STORAGE` (estaba declarado dos veces),
  `READ_EXTERNAL_STORAGE`, `WRITE_EXTERNAL_STORAGE`,
  `ACCESS_BACKGROUND_LOCATION` (no se usaba nada en segundo plano) y
  `usesCleartextTraffic`, que ahora solo existe en la variante de depuración.
- `allowBackup=false` y reglas de extracción de datos: la base contiene
  inspecciones sin sincronizar y el almacén el token de sesión.
- `Info.plist` de iOS con las claves de cámara, ubicación y fotos que faltaban.
  Sin ellas iOS mataba la app al pedir permiso.
- Configuración por entorno con `--dart-define`.
- Firma de release desde `android/key.properties`, que no se versiona.
- **APK: de 52 MB a 17-19 MB** por arquitectura.
- `minSdk` 23, que es lo que exige el almacenamiento seguro cifrado.

### Pendiente

- Medir la compresión con fotografías reales de campo y fijar los valores
  definitivos.
- Cerrar el contrato con el backend: ver
  [`BACKEND_CONTRATO.md`](BACKEND_CONTRATO.md).
- Cambiar el `applicationId` con un procedimiento acordado. Cambiarlo sin más
  haría que los teléfonos con inspecciones pendientes no recibieran la
  actualización.
- Generar la keystore de release.
- Compilar y probar en iOS (requiere un Mac).
- Separar el almacenamiento por usuario sin ocultar los datos heredados cuya
  cuenta de origen no puede deducirse con seguridad.
- Reconciliar el histórico de fotos marcadas como sincronizadas que el servidor
  pudo no haber recibido.

---

## 1.0.0+1 — estado original

Versión de partida. Auditoría completa en
[`ANALISIS_APP_MOVIL.md`](ANALISIS_APP_MOVIL.md).
