# App-Ecoing · Inspección de líneas de alta tensión

Aplicativo móvil de campo para inspectores de **Ecoing Contratistas Generales S.R.L.**
Documenta estructuras de líneas eléctricas de alta tensión: 23 ítems de formulario
técnico, tablero RST y 28 fotografías obligatorias georreferenciadas en UTM.

Diseñado para funcionar **sin cobertura**, en teléfonos de gama baja, a pleno sol
y durante jornadas completas.

---

## Principio de diseño

> **Guardar en el teléfono primero. Sincronizar después. Confirmar solo con
> respuesta del servidor.**

Ninguna fotografía ni formulario viaja a la red antes de estar en disco, y nada
se marca como sincronizado sin que el servidor lo confirme. Si algo falla, se
pierde el intento de envío, nunca el trabajo del inspector.

---

## Arranque rápido

```bash
flutter pub get
flutter run                    # servidor de producción
flutter test                   # 156 pruebas
flutter analyze                # 0 errores, 0 warnings
```

### Contra un servidor local

```bash
flutter run \
  --dart-define=API_BASE_URL=http://192.168.18.28/INSPEECIONLIGERAECOING/api \
  --dart-define=ENTORNO=local \
  --dart-define=REGISTRO_DETALLADO=true
```

HTTP sin cifrar solo se permite en compilaciones de depuración
(`android/app/src/debug/AndroidManifest.xml`). Ver [`.env.example`](.env.example)
para todas las claves de configuración.

---

## Compilar

```bash
# APK universal (52 MB — solo para pruebas internas)
flutter build apk --release

# APK por arquitectura (17-19 MB — para reparto directo)
flutter build apk --release -PsplitApks=true

# App Bundle (para Google Play)
flutter build appbundle --release
```

El tamaño importa: los inspectores descargan la app con datos móviles en campo.

---

## Estructura

```
lib/
├── core/                    Utilidades sin dependencias de UI ni de datos
│   ├── entorno.dart              Configuración por --dart-define
│   ├── estados_sync.dart         Máquina de estados de sincronización
│   ├── almacenamiento_fotos.dart Copia permanente verificada de fotografías
│   ├── conversion_utm.dart       lat/lon → UTM WGS-84
│   ├── dimensiones_imagen.dart   Lee ancho/alto sin decodificar la imagen
│   ├── normalizar.dart           Búsqueda tolerante y orden natural
│   ├── preferencias_app.dart     Preferencias tipadas
│   └── log.dart                  Registro con niveles
├── data/remoto/
│   └── cliente_api.dart          Cliente HTTP único, errores tipados
├── database/
│   ├── database_helper.dart      SQLite
│   └── migraciones.dart          Historial de esquema (v4)
├── models/
│   └── formulario_modal.dart     Formulario técnico + «No revisado»
├── presentacion/
│   ├── diseno/tema_ecoing.dart   Sistema de diseño
│   └── comunes/componentes.dart  Componentes reutilizables
├── repositorios/
│   ├── fotos_repositorio.dart       Fotografías (invariante de integridad)
│   └── borradores_repositorio.dart  Borradores del formulario
├── servicios/
│   ├── conectividad/             Un solo observador de red
│   ├── imagenes/                 Optimización local, cola, perfiles
│   └── sincronizacion/           Servicio central + política de reintentos
├── services/                     Servicios de API por recurso
├── screens/                      Pantallas
├── storage/almacen_seguro.dart   Token en Keystore/Keychain
└── widgets/                      Piezas del formulario
```

---

## Documentación

| Documento | Contenido |
|---|---|
| [`ARCHITECTURE.md`](ARCHITECTURE.md) | Capas, decisiones y por qué de cada dependencia |
| [`OFFLINE_SYNC.md`](OFFLINE_SYNC.md) | Máquina de estados, cola, reintentos y límites reales |
| [`IMAGE_PIPELINE.md`](IMAGE_PIPELINE.md) | Optimización local, perfiles y política de retención |
| [`MIGRATIONS.md`](MIGRATIONS.md) | Historial de esquema y cómo añadir una migración |
| [`BACKEND_CONTRATO.md`](BACKEND_CONTRATO.md) | **Lo que hay que revisar con el backend** |
| [`TESTING.md`](TESTING.md) | Qué se prueba y qué hay que probar a mano |
| [`PLAN_MEJORAS.md`](PLAN_MEJORAS.md) | Diagnóstico y plan por fases |
| [`ANALISIS_APP_MOVIL.md`](ANALISIS_APP_MOVIL.md) | Auditoría del estado original |
| [`CHANGELOG.md`](CHANGELOG.md) | Registro de cambios |

---

## Flujo de la aplicación

```
Login  →  Proyectos  →  Líneas  →  Estructuras  →  ┬─ Fotografías (28)
                                                   └─ Formulario (5 pasos)
                                                          ↓
                                        Guardado local automático
                                                          ↓
                              Sincronización (estructura / línea / proyecto / todo)
```

La sincronización también se dispara sola al recuperar la conexión, con la app
en uso.

---

## Estados

| Estado | Significado | Qué ve el inspector |
|---|---|---|
| `local` | En el teléfono, sin marcar para envío | Guardado en el teléfono |
| `pending` | En el teléfono y en cola | Pendiente de enviar |
| `uploading` | Envío en curso | Subiendo |
| `synced` | **Confirmado por el servidor** | Sincronizado |
| `failed` | Falló el intento; el dato sigue íntegro | Error al enviar |
| `conflict` | Divergencia con el servidor | Requiere revisión |

`synced` tiene un único camino de entrada en todo el código, y exige
confirmación explícita.

---

## Plataformas

| Plataforma | Estado |
|---|---|
| **Android** | Soportado. minSdk 23 (lo exige el almacenamiento seguro cifrado) |
| **iOS** | Permisos configurados en `Info.plist`. **Sin compilar ni probar**: hace falta un Mac |
| Web / escritorio | No aplica (`sqflite`, `dart:io`, cámara) |

---

## Publicación

### Antes del primer envío a Google Play

1. **Firma de release.** Generar la keystore y crear `android/key.properties` a
   partir de [`android/key.properties.example`](android/key.properties.example).
   Sin ella el build usa la clave de depuración y el APK **no es distribuible**.
   Si se pierde el `.jks` no se pueden publicar más actualizaciones.

2. **`applicationId`.** Sigue siendo `com.example.pruebaoffline`, el ejemplo de
   Flutter. Con ese ID no se puede publicar.

   > ⚠️ **Cambiarlo no es inocuo.** Android trata un `applicationId` distinto
   > como una aplicación **diferente**: los teléfonos que ya tienen la app no
   > recibirían la actualización. Quedaría la versión vieja con sus inspecciones
   > pendientes, y la nueva empezaría con la base vacía.
   >
   > Procedimiento: sincronizar **todo lo pendiente en todos los teléfonos**,
   > confirmar que el servidor lo tiene, y solo entonces migrar. Está anotado en
   > `android/app/build.gradle.kts`.

3. **Aceptar las licencias del SDK** si el equipo de compilación las tiene
   pendientes: `flutter doctor --android-licenses`.

### Permisos que se piden

| Permiso | Para qué |
|---|---|
| `INTERNET`, `ACCESS_NETWORK_STATE` | API y detección de conectividad |
| `ACCESS_FINE_LOCATION`, `ACCESS_COARSE_LOCATION` | Georreferenciar cada foto, con la app en primer plano |
| `CAMERA` | Fotografiar las estructuras |

**No se pide ningún permiso de almacenamiento.** Las fotografías viven en el
directorio privado de la app y la exportación usa el directorio externo propio.

Retirados respecto a la versión anterior: `MANAGE_EXTERNAL_STORAGE` (estaba
declarado dos veces, y es motivo de rechazo en Play),
`READ`/`WRITE_EXTERNAL_STORAGE`, `ACCESS_BACKGROUND_LOCATION` (no se usaba nada
en segundo plano) y `usesCleartextTraffic`.

---

## Qué probar a mano

Las pruebas automatizadas no cubren la cámara, el GPS ni la red real. Ver la
lista completa en [`TESTING.md`](TESTING.md). Lo mínimo:

1. Instalar **encima de la versión anterior** con datos existentes → la migración
   debe correr sin perder nada.
2. Tomar 28 fotos **en modo avión**, cerrar la app a la fuerza, reabrir → las 28
   siguen ahí.
3. Cortar la red a mitad del envío → quedan como «Error al enviar — sigue
   guardada», nunca desaparecen.
4. Ajustes de Android → Almacenamiento → **Borrar caché** → las fotos pendientes
   siguen ahí.
5. Reabrir una estructura ya trabajada → aparecen sus fotos y su formulario.

---

## Estado del trabajo

| Fase | Estado |
|---|---|
| P0 · Integridad de datos | ✅ |
| P1 · Imágenes y almacenamiento | ✅ (valores de compresión pendientes de medir en campo) |
| P2 · Sincronización | ✅ Contrato verificado con el backend PHP |
| P3 · Arquitectura | ✅ |
| P4 · UX/UI | ✅ |
| P5 · Seguridad | ⧗ Parcial: falta separar datos por usuario, keystore y `applicationId` |
| P6 · Rendimiento y publicación | ⧗ Parcial: tamaño del APK resuelto; mediciones en dispositivo pendientes |

Detalle y siguientes pasos en [`PLAN_MEJORAS.md`](PLAN_MEJORAS.md).
