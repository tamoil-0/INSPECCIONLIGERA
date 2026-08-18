# Despliegue de ECOING y compilación de la app móvil

Esta guía deja el backend PHP, la web, la base de datos y la app Flutter apuntando al mismo servidor. Los ejemplos usan `https://inspecciones.ejemplo.com`; reemplácelo por el dominio definitivo.

## 1. Requisitos del servidor

- Apache 2.4 o Nginx con PHP 8.2 o superior.
- MariaDB 10.6+ o MySQL 8.
- Extensiones PHP: `pdo_mysql`, `mbstring`, `gd`, `fileinfo`, `zip`, `xml`, `dom`, `simplexml` y `curl`.
- HTTPS válido. La compilación Android de producción bloquea HTTP sin cifrar.
- Acceso de escritura del proceso PHP a `storage/images`, `storage/exports` y `storage/logs`.
- Límites efectivos mínimos: `upload_max_filesize=12M`, `post_max_size=80M`, `max_file_uploads=10`, `memory_limit=512M` y `max_execution_time=300`.

El archivo `.user.ini` incluido configura esos límites en alojamientos que admiten configuración PHP por directorio. En un VPS deben colocarse en `php.ini` o en el pool PHP-FPM y reiniciar PHP.

## 2. Qué se debe subir

Subir conservando exactamente la estructura:

```text
.htaccess
.user.ini
.env                         <- creado en el servidor, nunca desde Git
api/
  composer.json
  composer.lock
  *.php y subcarpetas PHP
  vendor/                    <- solo si no se ejecutará Composer en el servidor
web/
storage/
  .htaccess
  images/.gitkeep
  exports/.gitkeep
  logs/.gitkeep
```

Si el servidor permite Composer, no subir `api/vendor`; ejecutar:

```bash
cd api
composer install --no-dev --classmap-authoritative
composer check-platform-reqs
```

La app móvil no se instala dentro de Apache. Se distribuye como APK/AAB compilado y se conecta por HTTPS al API.

## 3. Qué no se debe subir al servidor web

```text
.git/
.idea/
.vscode/
.composer-cache/
data/
database/
docs/
pruebaoffline/
api/vendor/                  <- si Composer lo instalará en el servidor
pruebaoffline/build/
pruebaoffline/.dart_tool/
storage/images/*             <- datos locales o históricos
storage/exports/*
storage/logs/*
*.sql, *.zip, *.log
.env.example
.env.production.example
```

El SQL inicial se importa desde el panel o consola y se elimina del servidor inmediatamente después. No debe quedar accesible en el directorio público.

## 4. Base de datos inicial

1. Crear una base vacía UTF-8, por ejemplo `ecoing_inspeccion`.
2. Crear un usuario exclusivo con permisos sobre esa base; no usar `root`.
3. Seleccionar la base creada.
4. Importar `database/ecoing_inspeccion_servidor.sql`.
5. Verificar: 1 proyecto, 1,235 estructuras y 863 estructuras con ambos UTM.

El SQL de servidor no contiene `CREATE DATABASE`, `DROP DATABASE` ni credenciales. Sí recrea las tablas ECOING de la base seleccionada; se utiliza para una instalación inicial vacía, no para actualizar una base productiva con inspecciones. Antes de cualquier reinstalación hacer respaldo de la base y de `storage/images`.

Consulta de control:

```sql
SELECT COUNT(*) AS proyectos FROM proyectos;
SELECT COUNT(*) AS estructuras,
       SUM(utm_x IS NOT NULL AND utm_y IS NOT NULL) AS con_utm
FROM postes;
SELECT COUNT(*) AS usuarios FROM usuarios;
```

Resultado inicial esperado: `1`, `1235`, `863` y `0` usuarios.

## 5. Configuración privada `.env`

Copiar `.env.production.example` como `.env` solamente en el servidor y completar:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://inspecciones.ejemplo.com
APP_TIMEZONE=America/Lima
CORS_ALLOWED_ORIGINS=https://inspecciones.ejemplo.com

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ecoing_inspeccion
DB_USERNAME=ecoing_app
DB_PASSWORD=UNA_CLAVE_LARGA_Y_UNICA

JWT_SECRET=UN_SECRETO_ALEATORIO_DE_64_CARACTERES_O_MAS
JWT_TTL=604800
```

Generar el secreto, por ejemplo, con `openssl rand -hex 32`. No reutilizar el secreto local y nunca agregar `.env` a Git.

Si el sistema se publica dentro de una subcarpeta, usar la URL completa:

```dotenv
APP_URL=https://ejemplo.com/INSPEECIONLIGERAECOING
CORS_ALLOWED_ORIGINS=https://ejemplo.com
```

## 6. Permisos y protección

- Archivos: `640` o `644` según el hosting.
- Directorios de código: `750` o `755`.
- `storage/images`, `storage/exports` y `storage/logs`: escritura para el usuario de PHP; normalmente `750`, `770` o `775` según propietario/grupo.
- No usar `777` salvo diagnóstico temporal.
- Confirmar que `.env`, `.user.ini`, SQL, logs y `storage` no se descargan por HTTP.
- En Nginx replicar las reglas de `.htaccess`: denegar archivos ocultos/sensibles y toda la ruta `/storage/`.
- Forzar redirección HTTP a HTTPS y mantener copias de seguridad fuera del directorio público.

## 7. Comprobación del servidor

Abrir:

```text
https://inspecciones.ejemplo.com/api/info.php
```

Debe responder:

```json
{"success":true,"service":"ECOING API","status":"ok"}
```

Esta ruta comprueba PHP y la conexión real a MySQL. Después:

1. Abrir `https://inspecciones.ejemplo.com/web/`.
2. Crear el primer administrador. El endpoint solo acepta conexiones originadas
   en `127.0.0.1` o `::1`. En un VPS, conectarse por SSH y ejecutar desde el
   propio servidor (ajustando la ruta si el proyecto vive en una subcarpeta):

   ```bash
   umask 077
   printf '%s' '{"nombre_completo":"Administrador ECOING","nombre_usuario":"admin","correo_electronico":"admin@empresa.com","contrasena":"CAMBIAR-CLAVE-MUY-SEGURA"}' > /tmp/ecoing-admin.json
   curl --fail-with-body -H 'Content-Type: application/json' --data-binary @/tmp/ecoing-admin.json http://127.0.0.1/api/usuarios/register_admin.php
   rm -f /tmp/ecoing-admin.json
   ```

   Cambiar todos los valores del ejemplo antes de ejecutarlo. No relajar la
   restricción del endpoint ni dejar el JSON con la contraseña en el servidor.
3. Iniciar sesión y confirmar que aparecen 7 líneas y 1,235 estructuras.
4. Probar un informe PDF y un Excel.
5. Revisar que `storage/logs/api.log` no tenga errores y que no sea descargable.

## 8. Probar la app contra XAMPP

Teléfono y PC deben estar en la misma Wi-Fi. Para el teléfono físico actual:

```powershell
cd C:\xampp82\htdocs\INSPEECIONLIGERAECOING\pruebaoffline
flutter run --dart-define=API_BASE_URL=http://192.168.18.28/INSPEECIONLIGERAECOING/api --dart-define=ENTORNO=local --dart-define=REGISTRO_DETALLADO=true
```

Para Android Emulator:

```powershell
flutter run --dart-define=API_BASE_URL=http://10.0.2.2/INSPEECIONLIGERAECOING/api --dart-define=ENTORNO=local
```

HTTP local se permite únicamente en la variante `debug`. Antes de ejecutar, abrir desde el navegador del teléfono la misma ruta `/api/info.php`.

## 9. Compilar la app para producción

La URL queda centralizada en `API_BASE_URL`; no se edita ningún archivo Dart:

```powershell
cd C:\xampp82\htdocs\INSPEECIONLIGERAECOING\pruebaoffline
flutter clean
flutter pub get
flutter test
flutter analyze
flutter build appbundle --release --dart-define=API_BASE_URL=https://inspecciones.ejemplo.com/api --dart-define=ENTORNO=produccion --dart-define=REGISTRO_DETALLADO=false
```

Para distribución interna por APK:

```powershell
flutter build apk --release --dart-define=API_BASE_URL=https://inspecciones.ejemplo.com/api --dart-define=ENTORNO=produccion --dart-define=REGISTRO_DETALLADO=false
```

Antes de publicar debe configurarse una keystore privada en `android/key.properties`. El archivo `.jks` y sus contraseñas no se suben ni a Git ni al servidor web. El `applicationId` aún debe acordarse antes de la primera publicación; cambiarlo después crea otra aplicación y puede dejar datos offline atrapados en la instalación anterior.

## 10. Prueba de aceptación obligatoria

En un poste de prueba:

1. Descargar el padrón y comprobar UTM offline.
2. Activar modo avión.
3. Completar y guardar formulario, RST y los 28 tipos de fotografía.
4. Cerrar la app a la fuerza y abrirla; todo debe seguir local.
5. Reconectar y sincronizar.
6. Cortar la red durante un lote; solo las fotos confirmadas deben salir de la cola.
7. Reanudar hasta que `sincronizacion_estado.php` entregue ambos indicadores en `true`.
8. Confirmar que con 27 fotos el poste sigue incompleto y con 28 pasa a completo.
9. Confirmar que los originales no se liberan antes de la verificación final.

No declarar la instalación lista sin esta prueba en un dispositivo Android real y en la red/dominio definitivos.
