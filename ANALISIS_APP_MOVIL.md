# Análisis técnico — App móvil `pruebaoffline` (Ecoing)

> **Documento**: auditoría funcional y técnica completa del proyecto Flutter ubicado en `d:\pruebaoffline`.
> **Fecha de análisis**: 18 de agosto de 2026
> **Alcance**: 28 archivos Dart, 5 230 líneas en `lib/`, configuración Android/iOS, dependencias y base de datos local.
> **Método**: lectura línea por línea de todo `lib/`, `pubspec.yaml`, `AndroidManifest.xml`, `build.gradle.kts`, `assets/` y `test/`.

---

## 1. Resumen ejecutivo

La app es un **inventario de campo de estructuras de líneas eléctricas de alta tensión** para inspectores de *Ecoing Contratistas Generales S.R.L.*. Cada estructura (poste/torre) se documenta con **23 ítems de formulario técnico**, un **tablero RST** (3 fases × 3 secciones) y **22 fotografías obligatorias georreferenciadas** en coordenadas UTM.

**Lo que funciona hoy:** el ciclo completo de captura en campo (login → proyecto → línea → estructura → fotos → formulario), el almacenamiento local en SQLite, la conversión GPS→UTM propia, la subida por lotes al backend PHP y una pantalla de sincronización manual con tabla comparativa local/servidor.

**El problema central:** la app se presenta como *offline-first* (se llama `pruebaoffline`, tiene modo offline manual, base local de 6 tablas) pero **el circuito offline no cierra**. Hay tres puntos donde el trabajo del inspector se puede perder de forma silenciosa:

| # | Escenario | Consecuencia |
|---|---|---|
| 1 | Se toman las 22 fotos **con internet** y la subida falla | Las fotos **nunca se guardan en SQLite** → se pierden por completo (solo aparece un *snackbar* de error) |
| 2 | Se sincroniza una página y la subida de imágenes falla | Las imágenes se marcan como `sincronizada = 1` **sin verificar el resultado** → nunca se reintentan |
| 3 | Las fotos viven en la caché de `image_picker` | Android puede purgar la caché → rutas huérfanas en la base de datos |

Además hay **3 bloqueos de UI** (estados de carga que nunca se liberan), **modales de éxito que se muestran aunque el envío haya fallado**, y **el formulario guardado nunca se vuelve a leer** (editar una estructura ya inventariada la sobrescribe con los valores por defecto).

**Veredicto**: funcional como prototipo avanzado / piloto controlado. **No apto para producción sin resolver los 8 hallazgos críticos** de la sección 8.

---

## 2. Ficha técnica

| Aspecto | Valor |
|---|---|
| Nombre del paquete | `pruebaoffline` |
| Nombre visible (Android) | `Ecoing` |
| Versión | `1.0.0+1` |
| SDK Dart | `^3.8.0` |
| Canal Flutter | `stable` |
| `applicationId` | `com.example.pruebaoffline` ⚠️ *placeholder sin cambiar* |
| Firma release | `signingConfigs.getByName("debug")` ⚠️ *firma de depuración* |
| Backend | `https://virrgoecoing.com/api` (PHP, hardcodeado en `ApiConfig`) |
| Base local | SQLite (`app_local.db`), 6 tablas, `version: 1` |
| Plataformas con carpeta | android, ios, web, linux, macos, windows |
| Plataformas realmente soportadas | **solo Android** (ver §10) |
| Tests | 1 archivo, es el contador por defecto de Flutter → **no compila** |
| Gestión de estado | `setState` puro (no se usa `provider` aunque está declarado) |
| Arquitectura | Capas informales: `screens` → `services` → `http`, y `screens` → `database` |

### Dependencias

**Usadas (11):** `http`, `shared_preferences`, `sqflite`, `path`, `path_provider`, `connectivity_plus`, `geolocator`, `permission_handler`, `image_picker`, `intl`, `exif` (esta última parcialmente rota, ver §8.7).

**Declaradas pero nunca importadas (6):** `dio`, `flutter_dotenv`, `provider`, `flutter_svg`, `jwt_decoder`, `location`.

> `location` y `geolocator` son plugins redundantes que hacen lo mismo; `location` añade permisos propios al APK sin aportar nada. `flutter_dotenv` sugiere una intención abandonada de sacar la URL a un `.env` (no existe ningún `.env` en el repo). `jwt_decoder` sugiere una intención abandonada de validar la expiración del token (ver §9.2).

---

## 3. Estructura del proyecto

```
lib/
├── main.dart                          # Arranque, rutas, locale es_PE
├── api/
│   └── api_config.dart                # baseUrl + 9 endpoints como constantes
├── database/
│   └── database_helper.dart           # (609 L) Singleton SQLite, 6 tablas, ~30 métodos
├── models/
│   ├── formulario_modal.dart          # (222 L) Modelo del formulario + catálogos de opciones
│   └── datos_formulario_model.dart    # ⚠️ NO es un modelo: es un diálogo. Código muerto.
├── screens/
│   ├── login_screen.dart              # Login + descarga masiva inicial
│   ├── proyecto_screen.dart           # Lista de proyectos + toggle offline + drawer
│   ├── buscar_linea_screen.dart       # Lista/filtro de líneas del proyecto
│   ├── detalle_proyecto_screen.dart   # Búsqueda por estructura + estado inventariado
│   ├── imagenesPoste_screen.dart      # (453 L) 26 slots de foto + GPS→UTM + subida
│   ├── formulario_screen.dart         # (575 L) 23 ítems + tablero RST + envío
│   ├── sincronizacion.dart            # Selector proyecto → líneas
│   ├── detalle_linea_screen.dart      # (521 L) Tabla de estados + sync + exportar fotos
│   └── 1.dart                         # ⚠️ EditarPosteScreen — huérfana, nunca navegada
├── services/
│   ├── auth_service.dart              # login / logout / usuario actual
│   ├── proyecto_service.dart          # listar proyectos
│   ├── poste_service.dart             # buscar por estructura / por línea / listar
│   ├── poste_datos_service.dart       # PUT datos, POST RST, estado sync, conectividad
│   ├── imagenesPoste_service.dart     # subida multipart por lotes + EXIF
│   └── linea_service.dart             # ⚠️ Duplicado exacto de PosteService. Código muerto.
├── storage/
│   └── preference.s.dart              # ⚠️ Nombre con typo. Clase Preferences nunca usada.
├── utils/
│   ├── formatos.dart                  # Fecha bonita en es_PE (con 8 prints)
│   └── dialogs_util.dart              # ⚠️ Contiene un DebugInfoWidget duplicado
└── widgets/
    ├── formulario_dropdowns.dart      # buildDropdown con validación opcional
    ├── formulario_chips.dart          # buildDropdownMultiple (FilterChips)
    ├── formulario_estado_placas.dart  # Ítem 11 (6 sub-dropdowns)
    ├── tablero_rst.dart               # Ítem 22: tabla 3 secciones × fases R/S/T
    └── debug_info_widget.dart         # Panel de depuración en el formulario
```

**Observación de altitud:** las pantallas hablan directamente con `DatabaseHelper` y con los `services` sin capa de repositorio, y `detalle_linea_screen.dart` contiene lógica de negocio de sincronización (521 líneas mezclando UI, orquestación de red y acceso a disco). No hay inyección de dependencias ni interfaces, lo que hace el código **no testeable** en su forma actual.

---

## 4. Modelo de datos local (SQLite)

`app_local.db`, `version: 1`, sin `onUpgrade`.

| Tabla | Propósito | Claves |
|---|---|---|
| `proyectos` | Catálogo de proyectos descargados | PK `id` |
| `postes` | Estructuras del proyecto + flags de estado servidor | PK `id`, `UNIQUE(codigo, proyecto_id)` |
| `poste_datos` | Respuestas del formulario (28 columnas) | PK `poste_id`, FK → `postes` |
| `poste_secciones_rst` | Marcas del tablero RST | `UNIQUE(poste_id, seccion, fase)` |
| `formularios_pendientes` | Cola de formularios por enviar (JSON) | PK autoincrement, **sin UNIQUE en `poste_id`** ⚠️ |
| `imagenes_poste_local` | Cola de fotos por subir + metadatos UTM | `UNIQUE(poste_id, nombre_foto)` |

### Campos declarados y nunca escritos
- `postes.fecha_subida` — columna creada, nunca poblada.
- `postes.sincronizado` — se escribe en la descarga, nunca se usa para decidir nada.
- `formularios_pendientes.enviado` — **siempre queda en `0`**. No existe ni un `UPDATE` en todo el código. Consecuencia directa: un formulario ya enviado sigue contando como pendiente para siempre y se reenvía en cada sincronización.

### Métodos definidos y nunca llamados (código muerto)
`marcarPosteComoSincronizado`, `verificarPostePerteneceAProyecto`, `getFormulariosPendientes`, `obtenerImagenesNoSincronizadas`, `buscarPostesPorEstructuraLocal`, `deleteAllProyectos`, `eliminarBaseDeDatos` (solo comentada en `main.dart`).

---

## 5. API consumida

| Método | Endpoint | Usado en | Auth |
|---|---|---|---|
| `POST` | `/usuarios/login.php` | `AuthService.login` | — |
| `GET` | `/proyectos/listar.php` | `ProyectoService` | Bearer |
| `GET` | `/postes/listar.php?proyecto_id=` | `PosteService` | Bearer |
| `GET` | `/postes/buscar_por_linea.php?linea=` | `PosteService`, `LineaService` (muerto) | Bearer |
| `GET` | `/postes/buscar_estructura.php?estructura=` | `PosteService` (nunca invocado desde la UI) | Bearer |
| `PUT` | `/postes/actualiza-datos.php?poste_id=` | `PosteDatosService` | Bearer |
| `POST` | `/postes/agregar-seccion-rst.php?poste_id=` | `PosteDatosService` | Bearer |
| `POST` | `/postes/imagenes-poste.php?poste_id=` | `ImagenesPosteService` (multipart) | Bearer |
| `GET` | `/postes/sincronizacion_estado.php?poste_id=` | `PosteDatosService` | Bearer |
| `GET` | `/postes` | `verificarConexion()` — usado como *ping*, sin auth | — |

La búsqueda por estructura que ve el usuario **no llama al endpoint**: filtra en memoria la lista ya descargada de la línea.

---

## 6. Flujo de usuario completo

```
                    ┌──────────────────────────────────────┐
                    │ main.dart: ¿existe token en prefs?   │
                    └───────────┬──────────────┬───────────┘
                            no  │              │ sí
                    ┌───────────▼──────┐   ┌───▼─────────────────┐
                    │ /login           │   │ /proyectos          │
                    └───────┬──────────┘   └───┬─────────────────┘
   POST login.php + descarga│ masiva           │
   (proyectos → postes con │ línea)            │
                    ┌───────▼──────────────────▼─────────────┐
                    │ ProyectosScreen                        │
                    │ · lista (servidor o SQLite)            │
                    │ · toggle Modo Offline (persistido)     │
                    │ · drawer: Sincronización / Perfil /    │
                    │   Ajustes / Editar Postes / Logout     │
                    └───────┬───────────────────┬────────────┘
                     tap    │                   │ drawer → Sincronización
                    ┌───────▼──────────┐   ┌────▼─────────────────────┐
                    │ BuscarLineaScreen│   │ SincronizacionScreen     │
                    │ líneas + filtro  │   │ elegir proyecto → líneas │
                    └───────┬──────────┘   └────┬─────────────────────┘
                    ┌───────▼──────────────┐ ┌──▼──────────────────────────┐
                    │ DetalleProyecto      │ │ DetalleLineaScreen          │
                    │ buscar estructura    │ │ · tabla 4 estados × poste   │
                    │ "Ya inventariado" /  │ │ · paginación 10/pág         │
                    │ "Sin inventariar"    │ │ · filtros                   │
                    └───────┬──────────────┘ │ · Sincronizar esta página   │
                            │ botón Ir/Editar│ · Exportar imágenes         │
                    ┌───────▼──────────────┐ └─────────────────────────────┘
                    │ ImagenesPosteScreen  │
                    │ 22 obligatorias +    │
                    │ 4 opcionales, GPS→UTM│
                    │ ENVIAR TODO          │
                    └───────┬──────────────┘
                            │ .then() automático (incondicional ⚠️)
                    ┌───────▼──────────────┐
                    │ FormularioPostePage  │
                    │ 23 ítems + RST       │
                    │ ENVIAR FORMULARIO    │
                    └──────────────────────┘
```

---

## 7. ✅ LO QUE SÍ HACE

### 7.1 Autenticación y sesión
- Login por usuario/contraseña contra `login.php`, con validación de campos vacíos y mensaje de error en pantalla.
- Guarda `token` (JWT) y el objeto `usuario` completo en `SharedPreferences`.
- **Sesión persistente**: al abrir la app, si hay token va directo a `/proyectos`.
- Logout: borra `token` y `user` de preferencias y vuelve a login.
- Muestra nombre completo y correo del usuario en la cabecera del drawer.

### 7.2 Descarga masiva inicial (tras login)
- Descarga **todos** los proyectos del usuario y los persiste en SQLite.
- Para **cada** proyecto, descarga **todos** sus postes y los persiste.
- Filtra los postes que tengan `linea` no vacía antes de guardar.
- Muestra spinner con "Cargando datos, por favor espera…".

### 7.3 Modo Offline manual
- Interruptor en la barra de `ProyectosScreen` (icono nube) que persiste `modo_offline` en preferencias.
- **Respetado en 5 pantallas**: proyectos, buscar línea, detalle de proyecto, imágenes y formulario consultan la bandera antes de tocar la red.
- Banner amarillo permanente en `ProyectosScreen` cuando está activo.
- Iconos de estado (nube tachada / wifi) con tooltips en casi todas las pantallas.
- Verificación de conectividad real con `connectivity_plus` (y en la pantalla de fotos con un `InternetAddress.lookup('google.com')`).

### 7.4 Navegación por jerarquía Proyecto → Línea → Estructura
- Lista de proyectos con tarjeta, contratista, ubicación y *chip* de estado con color (activo/completado/cancelado).
- Lista de líneas distintas del proyecto, obtenida de SQLite, con su ubicación, y **filtro incremental por texto**.
- Búsqueda de estructura dentro de la línea (coincidencia exacta) con mensaje de "no encontrado".
- **Indicador "Ya inventariado" / "Sin inventariar"** por estructura, calculado combinando la fecha del formulario local pendiente y la `fecha_inspeccion` del servidor. El botón cambia de "Ir" (rojo) a "Editar" (naranja).
- Formateo de fecha en español peruano: *"18 de agosto de 2026 a horas 3:42:10 pm"*.

### 7.5 Captura fotográfica georreferenciada
- **26 slots nominados** (placa, torre inferior/superior, base, ménsulas, crucetas, perfiles angulares, antiescalamiento, aisladores R/S/T adelante y atrás, ferretería R/S/T, cable de guarda, conductor, puesta a tierra, retenida, faja de servidumbre, ubicación de acceso, otros): **22 obligatorios + 4 opcionales**.
- Codificación visual por estado: verde (tomada), azul (opcional), rojo (obligatoria pendiente), con miniatura, tamaño en MB y check.
- Captura directa desde cámara, un slot a la vez; volver a tocar reemplaza la foto.
- **Botón "ENVIAR TODO" solo aparece cuando las 22 obligatorias están completas** — validación efectiva.
- **GPS de alta precisión** con `Geolocator` (timeout 10 s) y *fallback* a `getLastKnownPosition()`.
- **Conversión propia lat/lon → UTM WGS-84** implementada a mano (elipsoide, k0, meridiano central, easting/northing con corrección de hemisferio sur y **letra de zona**). Es matemáticamente correcta.
- Solicitud de permiso de ubicación al entrar a la pantalla.
- Metadatos por foto: `utm_este`, `utm_norte`, `zona`, `fecha` ISO-8601.
- **Subida multipart por lotes**: si hay >20 fotos, las parte en lotes de 15 con pausa de 500 ms entre lotes.
- **Si no hay conexión o está en modo offline: guarda las fotos en SQLite** con `sincronizada = 0`.

### 7.6 Formulario técnico de inspección
- **22 ítems desplegables** con catálogos cerrados de opciones + **ítem 23 de comentarios libres**:
  1. Obstáculos en Faja *(selección múltiple con chips, 9 opciones)*
  2. Estado de Cuencas · 3. Marcado de Árboles · 4. Criticidad de Tala · 5. Criticidad de Contacto · 6. Notificación de Propietario · 7. Tipo de Torre · 8. Ubicación · 9. Acceso Torre · 10. Estado de Acceso
  11. **Estado de Placas** *(sub-sección de 6 dropdowns: placas torre/línea/fases, peligro cerco, peligro torre, puesta a tierra)*
  12. Retenida · 13. Estado de Base · 14. Limpiar Base · 15. Crucetas Mensuales · 16. Perfiles Angulares · 17. Malla Antiescalamiento · 18. Óxidos Base · 19. Cadena Aisladores · 20. Tipo Aislador · 21. Conductor Bajada PAT · 22. Conductor Guarda
- **Ítem 22 "Tablero RST"**: tabla de checkboxes con 3 secciones (conductores de fase, conductores cuellos, estado de aisladores) × 4-5 atributos × 3 fases (R/S/T) = **39 casillas**, con cabecera en degradado institucional.
- **Valores por defecto precargados** en 19 de los 22 ítems (`n_a`, `bueno`, `buen_estado`, `no`…).
- **Tres capas de validación**:
  1. Campos obligatorios (`isRequired`) en ítems 7, 8 y 9, con borde rojo y mensaje.
  2. `_validarEstadoPlacas()`: los 6 sub-campos del ítem 11 no pueden quedar vacíos.
  3. `_validarSoloUnAtributoPorFase()`: en el tablero RST, **solo un atributo por sección y fase**, con diálogo explicativo del error.
- **Panel de depuración** accesible desde el icono de insecto: ID del poste, nº de obstáculos, estado de cuencas y total de RST marcados.
- **Guardado local SIEMPRE primero** (`formularios_pendientes` + `poste_secciones_rst`), y solo después intento de envío al servidor. Este orden es correcto y es lo mejor del diseño.
- Si hay conexión: `PUT actualiza-datos.php` y, si responde OK, `POST agregar-seccion-rst.php`.
- Diálogos diferenciados: éxito, "Guardado local" (error de envío) y "Sin conexión".

### 7.7 Pantalla de sincronización
- Selector de proyecto mediante diálogo con lista de tarjetas y contador de proyectos.
- Listado de líneas del proyecto con ubicación (consulta SQL `DISTINCT` optimizada).
- Por línea, **tabla comparativa de 5 columnas**: Código · Formulario Local · Formulario Servidor · Imágenes Local · Imágenes Servidor, con ✅/❌ por celda.
- **Paginación de 10 postes** por página, con botones Anterior/Siguiente y número de página.
- **Interruptor "Servidor"**: cuando se activa, consulta `sincronizacion_estado.php` **en paralelo** para los 10 postes de la página. Cuando está apagado, no gasta datos.
- **4 filtros**: Todos · Locales · Diferencias · Sincronizados.
- **"Sincronizar esta página"**: selecciona solo los postes con pendientes reales (local sí / servidor no), y los procesa **en lotes de 3 en paralelo**. Por cada poste envía formulario, RST e imágenes.
- Overlay modal bloqueante con barra de progreso y el aviso "Por favor, no cierre la aplicación".
- **Exportación de imágenes a almacenamiento externo**, organizadas en `Download/imagenes_poste_ecoing/Proyecto_X/Poste_Y/`, saltando archivos inexistentes o menores a 1 KB, con reporte final de exportadas/fallidas.

### 7.8 Identidad visual
- Degradado institucional rojo `#B71C1C` → azul `#0D47A1` consistente en todas las pantallas.
- Amarillo `#FBC02D` para acciones primarias, tarjetas redondeadas de 16 px, sombras suaves.
- Logo y nombre "App-Ecoing / Contratistas-Generales S.R.L." en el login.
- Iconos de lanzador configurados vía `flutter_launcher_icons` para Android e iOS.

---

## 8. ❌ HALLAZGOS CRÍTICOS (pérdida de datos o bloqueo)

> Estos 8 puntos son defectos verificados en el código, no observaciones de estilo. Están ordenados por gravedad.

### 8.1 🔴 Pérdida total de fotos si la subida online falla
`lib/screens/imagenesPoste_screen.dart:255-262`

Cuando hay internet, las fotos se suben **y nada más**. La rama que guarda en SQLite (`guardarImagenPosteLocal`) está en el `else`, solo para el caso sin conexión. Si `subirImagenBatch` devuelve `false` (timeout, 500 del servidor, red que se cae a mitad), el único efecto es un *snackbar* "Error al subir." y **las 22 fotos quedan solo en la caché de la cámara, sin registro en la base**. No hay forma de recuperarlas ni de reintentar: la pantalla de sincronización no las ve porque nunca se insertaron.

> **Impacto real en campo**: el inspector se desplaza a una torre remota, toma 22 fotos, la red móvil rural falla a mitad de la subida, y tiene que repetir toda la visita.

**Corrección**: guardar siempre en SQLite primero (igual que hace el formulario) y subir después; marcar como sincronizada solo con confirmación del servidor.

### 8.2 🔴 Imágenes marcadas como sincronizadas sin verificar el resultado
`lib/screens/detalle_linea_screen.dart:514-517`

```dart
await _imagenService.subirImagenBatch(posteId, archivos, metadatos);  // ← retorno ignorado
for (final img in imagenes) {
  await _db.marcarImagenComoSincronizada(img['id']);                  // ← marca todas igual
}
```

`subirImagenBatch` devuelve `bool` y ese valor se descarta. Falle o no, las imágenes pasan a `sincronizada = 1`. Como `obtenerImagenesDePoste` solo devuelve las que tienen `sincronizada = 0`, **quedan invisibles para siempre**: no se reintentan y la tabla de la pantalla de sincronización muestra ✅ en "Imágenes Local" mientras el servidor no tiene nada.

El mismo patrón aparece con el formulario en la línea 487: `actualizarDatosPoste` se llama ignorando su retorno y a continuación `guardarFormularioCompleto` graba `sincronizado: 1`.

### 8.3 🔴 El formulario guardado nunca se vuelve a leer → "Editar" borra el trabajo anterior
`lib/models/formulario_modal.dart:172`

`FormularioModal.cargarDesdeMap()` existe, está completo y correctamente escrito… y **no se invoca desde ningún lugar del proyecto** (verificado por búsqueda global). `FormularioPostePage` siempre arranca con `final _modelo = FormularioModal()`, es decir, con los valores por defecto.

Consecuencia: cuando `DetalleProyectoScreen` muestra "Ya inventariado" y el usuario pulsa **"Editar"**, ve un formulario en blanco. Si lo envía, se inserta un nuevo registro con los valores por defecto y se sobrescribe `poste_datos` (que usa `ConflictAlgorithm.replace` sobre PK `poste_id`). **La inspección original se pierde.**

Lo mismo ocurre con las fotos: `_imagenesSubidas` arranca vacío y nunca se cargan las imágenes locales ya existentes, así que reabrir una estructura ya fotografiada exige tomar las 22 fotos de nuevo.

### 8.4 🔴 Tres estados de carga que nunca se liberan (UI bloqueada)

| Ubicación | Causa | Efecto |
|---|---|---|
| `formulario_screen.dart:121-127` | `setState(_isLoading = true)` en línea 121; si `_validarEstadoPlacas()` falla se hace `return` **antes** del `try/finally` que lo restauraría | El botón "ENVIAR FORMULARIO" queda deshabilitado con spinner permanente. Hay que salir y volver a entrar, perdiendo lo escrito. |
| `imagenesPoste_screen.dart:248` | `setState(() => _enviando = true)` y **nunca** se pone en `false` en ninguna rama | El FAB queda en spinner y deshabilitado tras el primer envío. |
| `detalle_linea_screen.dart:441-442` | `if (token == null) return;` con `_sincronizando = true` ya activado | Overlay modal bloqueante permanente con "no cierre la aplicación". La única salida es matar la app. |

### 8.5 🔴 Modales de éxito que se muestran aunque el envío falle

- `imagenesPoste_screen.dart:284`: `_mostrarModalExito("¡Envío exitoso!", …)` se ejecuta **fuera de todo condicional**, después tanto de la rama online (haya fallado o no) como de la offline.
- `detalle_linea_screen.dart:471-475`: el snackbar verde "✅ Sincronización completada con éxito." se muestra siempre al terminar el bucle, incluso si los 10 postes fallaron o si no había nada que enviar.
- `formulario_screen.dart:180-183`: un `Future.delayed(3s)` hace **dos `Navigator.pop()` incondicionales**, sin comprobar `mounted` ni si el guardado tuvo éxito. Si el usuario está leyendo el diálogo de error, el `pop` cierra el diálogo en lugar de la pantalla y lo deja en un estado inconsistente; si ya navegó a otro sitio, se cierran pantallas que no correspondían.

**El inspector no tiene ninguna forma fiable de saber si sus datos llegaron al servidor.**

### 8.6 🟠 Los formularios pendientes se acumulan y se reenvían para siempre
`lib/database/database_helper.dart:115-122, 414-425, 450-460`

`formularios_pendientes` tiene PK autoincremental y **ninguna restricción `UNIQUE` sobre `poste_id`**, aunque el insert usa `ConflictAlgorithm.replace` (que sin restricción no hace nada). Cada envío **inserta una fila nueva**.

- `getFormularioPorPoste` usa `limit: 1` **sin `ORDER BY`** → puede devolver la fila más antigua y resincronizar datos obsoletos.
- La columna `enviado` **nunca se actualiza a 1** en todo el proyecto → el poste cuenta como pendiente indefinidamente.
- `obtenerPostesConEstadoPorLinea` (línea 436) hace `COUNT(i.id)` con dos `LEFT JOIN` encadenados → **producto cartesiano**: el conteo de imágenes se multiplica por el número de formularios duplicados.

### 8.7 🟠 Los metadatos UTM se envían como `"null"` en el camino de respaldo
`lib/services/imagenesPoste_service.dart:103-108` y `22-64`

```dart
final meta = metadatos[nombre] ?? await _parseExifData(file);
request.fields['utm_este_$i'] = meta['utm_este'].toString();   // _parseExifData no devuelve esa clave
```

`_parseExifData` devuelve `{fecha, lat, lon}` mientras `_subirLote` lee `utm_este`, `utm_norte`, `zona`. Cuando el mapa de metadatos no trae la foto (caso de sincronización diferida donde falte una entrada), **se envía la cadena literal `"null"` como coordenada**.

Peor: `_parseGps` (línea 22) hace `double.parse(parts[0])` sobre la salida del paquete `exif`, que devuelve razones tipo `11/1` → lanza excepción → se captura → devuelve `0.0`. **La extracción de GPS desde EXIF nunca ha funcionado**; es código muerto que da falsa sensación de respaldo.

### 8.8 🟠 Las fotos viven en la caché del sistema
`lib/screens/imagenesPoste_screen.dart:149`

`_picker.pickImage(source: ImageSource.camera)` deja el archivo en el directorio de **caché temporal** de la app, y esa ruta es la que se guarda en `imagenes_poste_local.ruta_archivo`. Android puede purgar la caché cuando el almacenamiento se llena o al pasar el tiempo. Las fotos pendientes de subir pueden desaparecer dejando **registros huérfanos** en la base (de hecho, `_exportarImagenesOrganizadas` ya tiene lógica para saltar archivos inexistentes, síntoma de que el problema se detectó en su día).

Tampoco se aplica compresión: no se pasan `imageQuality`, `maxWidth` ni `maxHeight`, de modo que se suben fotos de varios MB × 22 por estructura, sobre red móvil rural.

---

## 9. ❌ LO QUE NO HACE (funcionalidad ausente)

### 9.1 Sincronización
- **No hay sincronización automática ni en segundo plano.** No hay `WorkManager`, ni servicio *foreground*, ni escucha del stream de conectividad (`Connectivity().onConnectivityChanged` no se usa en ningún sitio). Todo lo pendiente exige que el usuario recuerde ir a Drawer → Sincronización → elegir proyecto → elegir línea → paginar → pulsar "Sincronizar esta página". **Página por página, línea por línea.**
- **No hay reintentos ni *backoff*.** Ningún contador de intentos, ninguna marca de error, ninguna cola con prioridad.
- **No hay resolución de conflictos.** Si el registro cambió en el servidor, el cliente lo sobrescribe sin avisar. No hay marcas de tiempo de modificación ni control de versiones optimista.
- **No hay "sincronizar todo".** Ni por proyecto, ni global.
- **No hay limpieza post-sincronización**: los datos y las fotos locales nunca se purgan, la base crece indefinidamente.

### 9.2 Sesión y seguridad
- **No valida la expiración del token.** `jwt_decoder` está en `pubspec.yaml` pero nunca se importa. `main.dart` solo comprueba que la cadena exista.
- **No maneja el 401.** Ningún servicio distingue "no autorizado" de otro error; el usuario ve un mensaje genérico y sigue en una sesión muerta con las subidas fallando en silencio.
- **No hay refresh token** ni renovación de sesión.
- **No hay recuperación de contraseña**, ni registro, ni cambio de contraseña.
- **El logout no borra la base local**: el siguiente usuario que entre en el dispositivo verá los proyectos, postes y formularios pendientes del anterior.
- El token se guarda en `SharedPreferences` **en texto plano** (sin `flutter_secure_storage`).

### 9.3 Pantallas incompletas o rotas
- **"Ver Perfil"** (drawer): solo cierra el drawer. No hace nada.
- **"Ajustes"** (drawer): solo cierra el drawer. No hace nada.
- **"Editar Postes"** (drawer, `proyecto_screen.dart:284`): navega a `/editar_postes`, ruta que está **comentada** en `main.dart:44` → excepción de ruta desconocida / pantalla de error de Flutter. **Botón visiblemente roto en el menú principal.**
- `EditarPosteScreen` (`lib/screens/1.dart`) existe, requiere 4 parámetros y **no se navega desde ningún sitio**.
- No hay pantalla de perfil, ni de ajustes, ni de configuración de servidor (la `baseUrl` es una constante compilada: cambiar de entorno exige recompilar).
- No hay pantalla de ayuda, onboarding, ni "acerca de" / versión.

### 9.4 Gestión de fotos
- **No se pueden revisar las fotos ya tomadas** de una estructura: no se cargan desde la base al abrir la pantalla.
- **No se puede eliminar** una foto (solo reemplazarla volviendo a tomarla).
- **No se puede ver a pantalla completa** ni hacer zoom.
- **No se puede elegir de la galería** (`ImageSource.gallery` nunca se usa) → imposible recuperar una foto tomada con la cámara nativa.
- **No hay compresión ni redimensionado** (ver §8.8).
- **No hay progreso real de subida**: `_progress` solo pasa de `0` a `1.0` de golpe; la `LinearProgressIndicator` es decorativa.
- **No se muestran las coordenadas capturadas** al usuario: se calcula el UTM pero no se ve en pantalla, así que no hay forma de detectar un GPS erróneo antes de enviar.

### 9.5 Datos y consultas
- **No descarga los formularios ya existentes del servidor**: solo trae los booleanos `formulario_subido` / `imagenes_subidas` y la `fecha_inspeccion`. Una estructura inventariada por otro inspector u otro dispositivo aparece como "Ya inventariado" pero **sin ninguna de sus respuestas**.
- **Los postes sin línea son invisibles**: `login_screen.dart:53-64` filtra `postesConLinea` antes de guardar. Un poste con `linea` vacía en el servidor **nunca existirá en la app**, sin aviso alguno.
- **Búsqueda de estructura solo por coincidencia exacta de cadena** (`==`), sin normalización ni tolerancia: `"0025"` no encuentra `"25"`.
- **No se puede buscar por código de poste**, solo por estructura.
- **Ninguna consulta tiene `ORDER BY`**: líneas, postes y estructuras aparecen en orden arbitrario que SQLite puede cambiar entre versiones.
- El indicador "Estructuras disponibles: desde X hasta Y" **solo se calcula en la rama offline** (`detalle_proyecto_screen.dart:79-86`) → nunca aparece cuando hay conexión.
- `DetalleProyectoScreen` inserta en la base los postes que devuelve `buscar_por_linea.php` **sin filtrar por el proyecto actual**: si dos proyectos comparten nombre de línea, se mezclan.
- **No hay mapa** ni visualización geográfica, pese a que `coordenadas_utm` se descarga por poste.
- **No hay exportación a Excel/CSV/PDF** del inventario (solo copia de imágenes a `Download/`).
- **No hay estadísticas ni resumen de avance** (cuántas estructuras inventariadas de cuántas).

### 9.6 Calidad del dato de inspección
19 de los 22 ítems llegan **precargados** con valores como `bueno`, `buen_estado`, `n_a`, `no`, y **solo 3 son obligatorios** (tipo de torre, ubicación, acceso). Un inspector puede enviar un formulario completo en dos toques y el servidor recibirá 19 respuestas que **nadie miró**, indistinguibles de una inspección real. No hay campo "no revisado", ni marca de qué ítems fueron efectivamente tocados por el usuario.

### 9.7 Robustez
- **No hay comprobación de `mounted`** antes de usar `context` después de un `await` en `login_screen`, `imagenesPoste_screen` (`initState` → `_pedirPermisosUbicacion` usa `context` directamente) y `formulario_screen` → excepciones si el usuario navega mientras algo carga.
- **No verifica el `Content-Type` de las respuestas**: todos los servicios hacen `jsonDecode(response.body)` a ciegas. Un error 500 de PHP que devuelva HTML produce `FormatException`, que en el mejor caso se muestra como "Error de conexión: FormatException…".
- **`verificarConexion()` es defectuoso** (`poste_datos_service.dart:57-64`): hace un `GET` sin autenticación a `/postes` y considera conectado cualquier respuesta `< 500`; un **404 cuenta como éxito**. Además se llama desde un `FutureBuilder` en el `AppBar` del formulario (`formulario_screen.dart:294`) → **se lanza una petición HTTP en cada reconstrucción**, es decir, en cada cambio de cualquiera de los 22 dropdowns (más otra lectura de `SharedPreferences`). Es un derroche notable de batería y datos.
- **`version: 1` sin `onUpgrade`** en la base (`database_helper.dart:26`): cualquier cambio de esquema futuro requerirá borrar los datos del dispositivo.
- **Variable de estado global**: `_ubicacionesPorLinea` está declarada **a nivel de archivo, fuera de la clase State** (`buscar_linea_screen.dart:21`) → se comparte entre instancias y entre proyectos, filtrando ubicaciones de un proyecto a otro.
- **31 llamadas a `print()`** repartidas en 7 archivos, incluyendo el volcado completo de cada poste descargado y 8 prints por cada formateo de fecha. Se ejecutan también en release: ruido en logcat y fuga de datos operativos.
- **No hay reporte de errores ni analítica** (sin Sentry/Crashlytics): un fallo en campo es invisible para el equipo.

### 9.8 Pruebas, build y calidad
- **No hay ni una sola prueba real.** `test/widget_test.dart` es la plantilla del contador de Flutter y además **no compila**, porque `MyApp` requiere el parámetro `initialRoute`. `flutter test` falla en este repo.
- **No hay CI/CD**, ni linter en pipeline, ni pre-commit.
- **`applicationId` = `com.example.pruebaoffline`** (el placeholder de Flutter): con ese ID no se puede publicar en Google Play.
- **La build de release se firma con la keystore de depuración** (`build.gradle.kts`, con el `TODO` original intacto).
- **No hay ofuscación** ni `--split-debug-info`.
- El repo tiene **artefactos de build versionados** (`build/`, `.dart_tool/`, `.idea/`, `flutter_01.png`…`flutter_03.png` en la raíz) y una carpeta llamada literalmente `" -"` que contiene `" copia"/build` — basura de una copia accidental.
- `README.md` sigue siendo la plantilla por defecto de Flutter: **no hay documentación de proyecto alguna**.

---

## 10. Soporte multiplataforma (real vs. aparente)

Existen las carpetas `android/`, `ios/`, `web/`, `linux/`, `macos/`, `windows/`, pero **solo Android funciona**:

| Plataforma | Estado | Motivo |
|---|---|---|
| **Android** | ✅ Funciona | Permisos declarados en el manifest |
| **iOS** | ❌ Crashea | `Info.plist` **no tiene** `NSCameraUsageDescription`, `NSLocationWhenInUseUsageDescription` ni `NSPhotoLibraryUsageDescription`. iOS mata la app al pedir cámara o ubicación. Verificado: cero claves de uso en el plist. |
| **Web** | ❌ No aplica | `sqflite` sin soporte web, `dart:io` (`File`, `InternetAddress`) no existe en web |
| **Escritorio** | ❌ No aplica | `image_picker` con cámara y `geolocator` no operativos |

Además, la exportación de imágenes usa la ruta **hardcodeada** `/storage/emulated/0/Download/…` (`detalle_linea_screen.dart:72`), exclusiva de Android, y solicita `Permission.storage`, que en **Android 13+ está obsoleto y se deniega siempre** → la función de exportar imágenes **está rota en los dispositivos actuales**.

---

## 11. Seguridad

| Hallazgo | Ubicación | Riesgo |
|---|---|---|
| `android:usesCleartextTraffic="true"` | AndroidManifest | Permite HTTP sin cifrar; anula la protección por defecto de Android 9+ |
| Token JWT en `SharedPreferences` en claro | `auth_service.dart:34-37` | Extraíble en dispositivo rooteado o vía backup |
| Objeto `usuario` completo serializado en preferencias | `auth_service.dart:36` | Datos personales sin cifrar |
| `MANAGE_EXTERNAL_STORAGE` + `requestLegacyExternalStorage` | AndroidManifest | Permiso de máximo alcance; **motivo de rechazo en Google Play** salvo justificación excepcional |
| `ACCESS_BACKGROUND_LOCATION` solicitado | AndroidManifest | **No se usa nada en segundo plano**; permiso de alto riesgo que Play audita expresamente |
| Sin *certificate pinning* | Servicios HTTP | MITM viable en redes hostiles |
| Sin validación de expiración de token | Global | Sesiones zombis |
| `print()` de datos operativos en release | 7 archivos | Fuga por logcat |
| Firma release con clave de debug | `build.gradle.kts` | APK no distribuible de forma segura |
| Parámetros de consulta sin codificar | `poste_service.dart:22`, `linea_service.dart:23` | `?linea=$linea` sin `Uri.encodeComponent`: una línea con `&` o espacios rompe la petición |

---

## 12. Código muerto y deuda técnica

| Elemento | Ubicación | Nota |
|---|---|---|
| `LineaService` (50 L) | `services/linea_service.dart` | Duplicado exacto de `PosteService.buscarPostesPorLinea`. Nunca instanciado. |
| `EnvioExitosoDialog` (64 L) | `models/datos_formulario_model.dart` | Un diálogo con cuenta atrás dentro de la carpeta `models`. Nunca usado. |
| `DebugInfoWidget` duplicado (91 L) | `utils/dialogs_util.dart` | Segunda definición de la misma clase que `widgets/debug_info_widget.dart`. El import con alias `as dialogs` en `formulario_screen.dart:15` **no se usa**. |
| `EditarPosteScreen` (57 L) | `screens/1.dart` | Nombre de archivo `1.dart`. Huérfana. |
| `Preferences` (21 L) | `storage/preference.s.dart` | Nombre de archivo con typo (`preference.s`). Los servicios usan `SharedPreferences` directo; la clase nunca se llama. |
| `cargarDesdeMap` (50 L) | `models/formulario_modal.dart:172` | Ver §8.3 — la funcionalidad de edición está escrita pero desconectada. |
| Rama RST de `guardarFormularioCompleto` | `database_helper.dart:246-270` | Espera claves con prefijo `RST_` que **el modelo nunca produce** (`toMap()` no incluye RST y `seleccionados` usa el formato `seccion\|atributo\|fase`). Bucle que nunca entra. |
| 7 métodos de `DatabaseHelper` | ver §4 | Definidos, nunca llamados. |
| 6 dependencias | ver §2 | `dio`, `flutter_dotenv`, `provider`, `flutter_svg`, `jwt_decoder`, `location`. |
| `assets/images/google.svg` | assets | Login social nunca implementado (y `flutter_svg` sin usar). |
| `flutter_01/02/03.png` | raíz del repo | Capturas sueltas versionadas. |
| Carpeta `" -"/" copia"` | raíz del repo | Copia accidental con artefactos de build. |
| Typos visibles al usuario | `buscar_linea_screen.dart:231` → "Buscar lí**ssea**"; `sincronizacion.dart:129` → "Seleccionar Proyecto**ss**" | Visibles en producción |

---

## 13. Matriz de cobertura funcional

| Capacidad | Estado |
|---|---|
| Login con token | ✅ Completo |
| Sesión persistente | ✅ Completo |
| Expiración / renovación de token | ❌ Ausente |
| Recuperar contraseña | ❌ Ausente |
| Descarga inicial de proyectos y postes | ✅ Completo (lento, sin progreso, sin reintento) |
| Modo offline manual | ✅ Completo |
| Detección de conectividad | ⚠️ Solo puntual y manual; sin escucha continua |
| Navegación Proyecto → Línea → Estructura | ✅ Completo |
| Búsqueda de línea | ✅ Completo |
| Búsqueda de estructura | ⚠️ Solo coincidencia exacta |
| Estado "inventariado / sin inventariar" | ✅ Completo |
| Formulario de 23 ítems | ✅ Completo |
| Tablero RST | ✅ Completo con validación |
| Validación de obligatorios | ⚠️ Solo 3 de 22 ítems |
| Guardado local del formulario | ✅ Completo |
| **Releer / editar formulario guardado** | ❌ **Roto** (§8.3) |
| Captura de 22 fotos obligatorias | ✅ Completo |
| GPS → UTM con zona | ✅ Completo y correcto |
| Fotos desde galería | ❌ Ausente |
| Revisar / borrar fotos tomadas | ❌ Ausente |
| Compresión de imágenes | ❌ Ausente |
| **Respaldo local de fotos con conexión** | ❌ **Roto** (§8.1) |
| Subida por lotes al servidor | ✅ Completo |
| Tabla comparativa local vs. servidor | ✅ Completo |
| Filtros y paginación de sincronización | ✅ Completo |
| Sincronización manual por página | ⚠️ Funciona pero marca éxito sin verificar (§8.2) |
| Sincronización automática / background | ❌ Ausente |
| Reintentos y cola de errores | ❌ Ausente |
| Resolución de conflictos | ❌ Ausente |
| Exportar imágenes a `Download/` | ⚠️ Roto en Android 13+ |
| Exportar datos (CSV/Excel/PDF) | ❌ Ausente |
| Mapa / visualización geográfica | ❌ Ausente |
| Perfil de usuario | ❌ Ausente (botón inerte) |
| Ajustes | ❌ Ausente (botón inerte) |
| "Editar Postes" del menú | ❌ Roto (ruta inexistente) |
| Estadísticas de avance | ❌ Ausente |
| Pruebas automatizadas | ❌ Ausentes (y el único test no compila) |
| Soporte iOS | ❌ Crashea (sin claves de permiso) |
| Listo para publicar en Play | ❌ No (`com.example.*`, firma debug, `MANAGE_EXTERNAL_STORAGE`) |

---

## 14. Recomendaciones priorizadas

### P0 — Antes de volver a usarla en campo
1. **Guardar siempre las fotos en SQLite antes de intentar subirlas** y marcar `sincronizada = 1` solo con confirmación del servidor (§8.1, §8.2).
2. **Copiar las fotos de la caché de `image_picker` a un directorio permanente** (`getApplicationDocumentsDirectory()`) al capturarlas (§8.8).
3. **Comprobar los valores de retorno** de `subirImagenBatch` y `actualizarDatosPoste` antes de marcar cualquier cosa como sincronizada, y **mostrar éxito solo cuando lo haya** (§8.2, §8.5).
4. **Liberar los tres estados de carga bloqueados** (§8.4).
5. **Conectar `cargarDesdeMap` y la carga de fotos existentes** para que "Editar" no destruya la inspección previa (§8.3).
6. Eliminar los dos `Navigator.pop()` incondicionales del `Future.delayed` y añadir comprobaciones de `mounted` (§8.5).

### P1 — Integridad y confianza
7. Añadir `UNIQUE(poste_id)` a `formularios_pendientes`, marcar `enviado = 1` al confirmar, y ordenar la lectura por `creado_en DESC` (§8.6).
8. Corregir el producto cartesiano de `obtenerPostesConEstadoPorLinea` (§8.6).
9. Unificar las claves de metadatos entre `_parseExifData` y `_subirLote`, o eliminar el camino EXIF muerto (§8.7).
10. Aplicar `imageQuality`/`maxWidth` a `pickImage` — reducirá drásticamente el tiempo de subida en red rural.
11. Reemplazar `verificarConexion()` por `connectivity_plus` + un endpoint real, y **sacarlo del `FutureBuilder`** del AppBar (§9.7).
12. Validar la expiración del token con `jwt_decoder` (ya está en `pubspec`) y manejar el 401 con redirección a login.
13. Borrar la base local en el `logout`.
14. Mover `_ubicacionesPorLinea` dentro de la clase `State` (§9.7).
15. Añadir `ORDER BY` a las consultas de listado.

### P2 — Producto y mantenimiento
16. Sincronización automática al recuperar conexión (`onConnectivityChanged`) + botón "sincronizar todo" + cola con reintentos y contador de fallos.
17. Descargar los formularios existentes del servidor para permitir edición real multi-dispositivo.
18. Implementar o **retirar del menú** "Ver Perfil", "Ajustes" y "Editar Postes".
19. Marcar los 19 valores por defecto como "no revisado" hasta que el inspector los toque (§9.6).
20. Configurar `Info.plist` con las tres claves de permiso, o retirar las carpetas de plataformas no soportadas.
21. Reemplazar los `print()` por un logger con niveles y desactivarlo en release.
22. Cambiar `applicationId`, crear keystore de release, retirar `usesCleartextTraffic`, `MANAGE_EXTERNAL_STORAGE` y `ACCESS_BACKGROUND_LOCATION`.
23. Purgar las 6 dependencias sin usar, los bloques de código muerto, la carpeta `" -"`, los PNG de la raíz y los artefactos de build versionados.
24. Reescribir `test/widget_test.dart` (hoy no compila) y cubrir con pruebas al menos `DatabaseHelper`, `FormularioModal` y la conversión `_latLonToUTM`.
25. Sustituir el `README.md` de plantilla por documentación real del proyecto.

---

## 15. Conclusión

El proyecto tiene un **núcleo funcional sólido y bien pensado para su dominio**: la jerarquía proyecto/línea/estructura es correcta, el formulario de 23 ítems con tablero RST refleja un procedimiento de inspección real, la conversión GPS→UTM está bien implementada a mano, y la decisión de guardar el formulario en local **antes** de intentar enviarlo es exactamente la correcta.

El problema es que **esa buena decisión se aplicó solo al formulario y no a las fotos**, y que en toda la cadena de sincronización **los resultados de red no se comprueban**. El resultado es una app que le dice al inspector "✅ Envío exitoso" en situaciones donde ha perdido el trabajo de una visita completa a una torre en zona remota.

Ninguno de los hallazgos críticos requiere rediseñar la arquitectura: los seis puntos P0 son cambios localizados en tres archivos. Con ellos resueltos, la app pasa de "prototipo con riesgo de pérdida de datos" a herramienta de campo defendible.
