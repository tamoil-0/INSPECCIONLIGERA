# ECOING — Inspección Ligera · Resumen general

Sistema para inspeccionar estructuras (postes/torres) de las líneas de transmisión de **60 kV de Electro Puno S.A.A**, operado por el contratista **INTESEL**. El inspector recorre las torres en campo con una app Android, llena un formulario técnico, toma 28 fotografías obligatorias por estructura y todo eso termina en un reporte PDF por torre y en un Excel por línea.

El problema central del negocio es que **en campo no hay internet**, así que la app guarda todo en el teléfono y sincroniza cuando encuentra señal.

---

## 1. Las tres piezas

```
┌───────────────────────────┐        ┌───────────────────────────┐
│  APP MÓVIL (Flutter)      │        │  WEB (HTML + JS puro)     │
│  pruebaoffline/           │        │  web/                     │
│  Inspección en campo      │        │  Administración y reportes│
│  SQLite offline + fotos   │        │  Proyectos, postes, PDF   │
└─────────────┬─────────────┘        └─────────────┬─────────────┘
              │  HTTP + JSON, Authorization: Bearer <JWT>
              └──────────────────┬───────────────────────────────┘
                                 ▼
                  ┌────────────────────────────────┐
                  │  API REST (PHP 8.2, sin        │
                  │  framework)   api/             │
                  └───────┬──────────────┬─────────┘
                          ▼              ▼
             ┌────────────────────┐  ┌──────────────────────┐
             │ MariaDB/MySQL      │  │ storage/images/      │
             │ ecoing_inspeccion  │  │ fotos en disco (JPEG)│
             └────────────────────┘  └──────────────────────┘
```

Todo corre sobre **XAMPP** en local (`http://localhost/INSPEECIONLIGERAECOING/web/`) y el mismo código se publica en hosting/VPS.

---

## 2. Backend — API en PHP

Sin framework: cada endpoint es un archivo PHP que valida, consulta con **PDO** (siempre sentencias preparadas) y responde JSON.

| Carpeta | Qué hace |
|---|---|
| `api/config/` | `.env` (`app.php`), conexión PDO (`database.php`), JWT (`jwt.php`), autenticación y roles (`auth.php`), reglas de dominio e imágenes (`domain.php`) |
| `api/usuarios/` | `login.php`, `register.php`, `register_admin.php`, `listar.php` |
| `api/proyectos/` | crear, listar, obtener, actualizar, cambiar estado, eliminar, contar |
| `api/postes/` | crear, listar, obtener, buscar por línea/estructura, `actualiza-datos.php` (formulario), `agregar-seccion-rst.php` (fases R/S/T), `imagenes-poste.php` (subida de fotos), `sincronizacion_estado.php` |
| `api/clases/` + `api/services/` | generación del PDF con **FPDF** |
| `api/vendor/` | `firebase/php-jwt`, `phpoffice/phpspreadsheet`, `setasign/fpdf` |

**Seguridad:** login con `password_hash`, token **JWT** en el header `Authorization: Bearer` (nunca en la URL), roles `administrador / supervisor / tecnico / invitado`, bloqueo por intentos fallidos (`login_intentos`), CORS por lista blanca y el primer administrador solo se puede crear desde `127.0.0.1` con la tabla `usuarios` vacía.

**Reportes:**

- `ver_pdf_poste.php` → PDF de una estructura.
- `exportar_linea_pdf.php` → ZIP con el PDF de todas las estructuras de una línea.
- `exportar_excel_linea.php` → Excel de la línea (PhpSpreadsheet).

---

## 3. Base de datos (`ecoing_inspeccion`, MariaDB, utf8mb4)

| Tabla | Contenido |
|---|---|
| `usuarios` | cuentas, rol, `dispositivo_id`, última sincronización |
| `login_intentos` | control de fuerza bruta |
| `proyectos` | nombre, contratista, ubicación, estado |
| `postes` | la estructura: `codigo`, `linea`, `estructura`, **`utm_x` / `utm_y` / `zona`**, `fecha_inspeccion`, y banderas `formulario_subido`, `imagenes_subidas`, `sincronizado` |
| `poste_datos` | el formulario técnico completo, 1 fila por poste (obstáculos de faja, criticidades, tipo de torre, accesos, estado de placas, puesta a tierra, retenida, aisladores, distancias, comentarios…) |
| `poste_secciones_rst` | estado por fase **R / S / T** (conductores, cuellos, cable de guarda, aisladores), único por poste + sección + fase |
| `imagenes_poste` | 1 fila por tipo de foto: `ruta_archivo`, `fecha_captura`, `utm_este`, `utm_norte`, `zona`, `sincronizada` |

Los `.sql` están en `database/`: `schema.sql` (esquema vacío) y `ecoing_inspeccion_con_estructuras.sql` (padrón real de **1,235 estructuras** con sus coordenadas UTM, sin inspecciones).

Las claves foráneas van con `ON DELETE CASCADE`: al borrar un proyecto se va todo lo que cuelga de él.

---

## 4. Cómo se guardan las imágenes

Las fotos **no se guardan en la base de datos**. En la BD solo va la ruta; el archivo vive en disco.

Flujo al subir (`api/postes/imagenes-poste.php` → `saveUploadedImage()` en `api/config/domain.php`):

1. Se validan hasta 30 archivos por petición (`imagen_0`…`imagen_29`), máx. **10 MB** cada uno y **100 MB** por petición.
2. Se verifica que sea imagen real por **contenido/MIME** (JPEG o PNG), no por la extensión, y que no exceda 40 MP.
3. Se corrige la **orientación EXIF** (fotos verticales del celular).
4. Se redimensiona al lado mayor de **2400 px** y se normaliza todo a **JPEG calidad 85**.
5. Se guarda como `storage/images/{poste_id}/{tipo}_{16 bytes aleatorios}.jpg`. El nombre aleatorio evita colisiones y que se adivinen rutas.
6. En `imagenes_poste` se hace `INSERT … ON DUPLICATE KEY UPDATE`: **un solo archivo vigente por tipo de foto**; al reemplazar, el archivo anterior se borra del disco.
7. Cuando existen los **28 tipos** requeridos, `imagenes_subidas = 1`; si además el formulario está subido, el poste queda `sincronizado = 1`.

Los 28 tipos están definidos en un solo lugar (`photoTypes()`) y como `ENUM` en la BD: panorámica, placa, torre inferior/superior, base, ménsulas, crucetas, perfiles angulares, antiescalamiento, otros, aisladores y ferretería por fase R/S/T (adelante/atrás), cable de guarda, conductor, puesta a tierra (×2), retenida, faja de servidumbre y ubicación de acceso.

En el PDF cada foto lleva estampado, abajo a la izquierda, el **sello UTM** (zona, este/norte) y la **fecha y hora de inspección**, con fondo blanco semitransparente configurable en `api/clases/PDFPoste.php`.

---

## 5. Web de administración

HTML + CSS + JavaScript sin framework (`web/`): `index.html` (login), `setup.html` (primer admin), `proyectos.html`, `detalle_proyecto.html`, `detalle_linea.html`, `detalle_poste.html`, `formulario_poste.html`.

Toda la lógica compartida está en `web/js/app.js`: calcula la URL del API desde la URL de la página, guarda el token, lo adjunta en cada `fetch`, controla timeouts y descarga el PDF/Excel/ZIP mandando el token por header (no por URL).

---

## 6. App móvil (Flutter, `pruebaoffline/`)

Android/iOS, arquitectura por capas: `presentacion` (pantallas y diseño) → `servicios` (sincronización, imágenes, conectividad) → `repositorios` (invariantes de datos) → `database` (SQLite con migraciones) y `data/remoto` (cliente HTTP).

**Regla de oro offline-first:** *guardar en disco primero, sincronizar después, marcar como enviado solo con la confirmación del servidor.* Cada foto y cada borrador pasa por los estados `pending → uploading → synced`; si falla, conserva su registro y su contador de intentos, nada se descarta. Solo el repositorio puede escribir `synced`, y solo con respuesta del servidor en mano.

**Local:** SQLite (`sqflite`) para postes, borradores del formulario y cola de fotos; token de sesión en almacenamiento cifrado (Keystore/Keychain); las fotos se copian a almacenamiento permanente con verificación de tamaño y **SHA-256** antes de intentar subirlas.

**Fotos en el teléfono:** compresión con códecs nativos (`flutter_image_compress`) fuera del hilo de UI, porque 28 fotos de una cámara de 48 MP serían ~390 MB por torre. GPS con `geolocator` (de ahí salen las coordenadas UTM del sello).

Documentación propia de la app: `ARCHITECTURE.md`, `OFFLINE_SYNC.md`, `IMAGE_PIPELINE.md`, `BACKEND_CONTRATO.md`, `TESTING.md`.

---

## 7. Configuración y puesta en marcha

Todo se lee de `.env` (ver `.env.example`): `APP_URL`, zona horaria, orígenes CORS, credenciales de MariaDB, `JWT_SECRET` / `JWT_TTL` y los nombres/CIP que aparecen en las firmas del PDF.

Requisitos: PHP **8.2** con `pdo_mysql`, `mbstring`, `gd`, `zip`, `fileinfo` y XML; MariaDB 10.4+ / MySQL 8+.

Arranque local: iniciar Apache y MySQL en XAMPP → importar el `.sql` → abrir la web → crear el primer administrador → crear el proyecto.

Guías detalladas: [docs/CONEXION_Y_SINCRONIZACION_MOVIL.md](docs/CONEXION_Y_SINCRONIZACION_MOVIL.md) y [docs/DESPLIEGUE_SERVIDOR_Y_APP_MOVIL.md](docs/DESPLIEGUE_SERVIDOR_Y_APP_MOVIL.md).

---

## 8. Despliegue en el NAS de la oficina (QNAP TS-932PX)

El destino de producción es el NAS de la oficina, con **Container Station**, en la
LAN y sin nada expuesto a Internet. El código es el mismo: cambia solo dónde
corre y de dónde salen la configuración y el almacenamiento.

```text
QNAP 192.168.1.3  (ARM64 · Annapurna AL324)
│
├── ecoing-app  :8082 → :80    Apache 2.4 + PHP 8.2 (web/ y api/)
├── ecoing-db                  MariaDB 10.11 (sin puerto publicado)
│
└── DataVol1/InspeccionLigera
    ├── imagenes/     fotografías          → /data/imagenes
    ├── pdf/          caché de reportes    → /data/pdf
    ├── backups/db/   dumps mysqldump      → /data/backups
    └── app-data/     base, logs, exports
```

Lo que cambió respecto a XAMPP:

| Antes | Ahora |
|---|---|
| Fotos en `storage/images/{poste_id}/` | Raíz configurable (`IMAGE_STORAGE_PATH`) con jerarquía `proyecto_/linea_/poste_/`. En la base se guarda siempre la ruta **relativa**. |
| Logs en `storage/logs` fijo | `LOG_STORAGE_PATH`, persistente en el NAS: `api.log`, `sincronizacion.log`, `rendimiento.log`, `almacenamiento.log`. |
| Toda imagen se recodificaba con GD | `IMAGE_REENCODE_MODE=auto`: lo que el teléfono ya dejó bien se archiva tal cual. La validación de contenido no cambió. |
| El PDF se regeneraba en cada descarga | Caché en `/data/pdf` con huella de contenido: se regenera solo si cambió el formulario o alguna fotografía. |
| Subida de fotos en 5 lotes secuenciales | Hasta 3 peticiones en vuelo (`SUBIDA_SIMULTANEA`), con reintento por fotografía. |
| — | `api/health.php` sin autenticación, para Container Station y para la LAN. |

Lo que **no** cambió: los endpoints, el contrato con la app, los estados
`pending → uploading → synced`, el JWT, los roles, las sentencias preparadas, la
validación MIME y el bloqueo por intentos.

Documentación: [docs/DESPLIEGUE_QNAP_DOCKER.md](docs/DESPLIEGUE_QNAP_DOCKER.md),
[docs/MIGRACION_XAMPP_A_QNAP.md](docs/MIGRACION_XAMPP_A_QNAP.md) y
[docs/CHECKLIST_PRUEBAS_QNAP.md](docs/CHECKLIST_PRUEBAS_QNAP.md).
