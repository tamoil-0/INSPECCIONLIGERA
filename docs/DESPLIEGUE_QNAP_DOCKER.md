# Despliegue en QNAP TS-932PX con Container Station

Guía para poner ECOING — Inspección Ligera a correr **íntegramente dentro del
NAS**, accesible solo desde la LAN de la oficina.

- IP del NAS: `192.168.1.3`
- URL del sistema: `http://192.168.1.3:8082`
- Carpeta compartida de datos: `InspeccionLigera` en `DataVol1`

---

## 1. Arquitectura final

```text
QNAP TS-932PX  (ARM64 · Annapurna AL324)
│
├── Container Station
│   │
│   ├── ecoing-app            :8082 -> :80    red: ecoing-red
│   │   ├── Apache 2.4 (mod_php)
│   │   ├── PHP 8.2  + gd, zip, exif, pdo_mysql, opcache
│   │   ├── /var/www/html/api   -> API REST
│   │   └── /var/www/html/web   -> web administrativa
│   │
│   └── ecoing-db             (sin puerto publicado)  red: ecoing-red
│       └── MariaDB 10.11
│
└── DataVol1/InspeccionLigera
    ├── imagenes/         -> ecoing-app:/data/imagenes    fotografías
    ├── pdf/              -> ecoing-app:/data/pdf         caché de reportes
    ├── backups/
    │   └── db/           -> ecoing-app:/data/backups     dumps mysqldump
    └── app-data/
        ├── mariadb/      -> ecoing-db:/var/lib/mysql     datos de la base
        ├── logs/         -> ecoing-app:/data/logs        logs del aplicativo
        └── exports/      -> ecoing-app:/data/exports
```

La app Flutter habla **solo** con `http://192.168.1.3:8082/api`. No se conecta a
MariaDB, no monta SMB y no lleva credenciales del NAS.

Nada se publica a Internet. El único puerto abierto es el 8082 en la LAN.

---

## 2. Imágenes Docker y compatibilidad ARM64

El TS-932PX es **ARM64** (`linux/arm64/v8`). Todas las imágenes usadas son
multiarquitectura y publican ese target:

| Componente | Imagen | Versión fijada | `linux/arm64/v8` |
|---|---|---|---|
| Apache + PHP | `php:8.2.35-apache-bookworm` | 8.2.35 sobre Debian 12 (parche PHP2Shell) | sí |
| Dependencias PHP | `composer:2.8` | 2.8 | sí |
| Base de datos | `mariadb:10.11` | 10.11 LTS | sí |

MariaDB **10.11** y no 11.x: es la serie LTS más cercana al MariaDB 10.4 de
XAMPP, así que los dumps existentes se importan sin sorpresas de sintaxis.

Comprobar la arquitectura del NAS antes de empezar:

```bash
uname -m          # debe decir aarch64
```

Y que una imagen soporte ARM64 antes de fijarla:

```bash
docker buildx imagetools inspect mariadb:10.11 | grep -i arm64
```

---

## 3. Preparar el NAS

### 3.1 Averiguar la ruta real de la carpeta compartida

QTS monta los volúmenes con nombres tipo `CACHEDEV1_DATA`. Hay que confirmar
cuál es antes de nada:

```bash
ls -d /share/*/InspeccionLigera
# Salida habitual: /share/CACHEDEV1_DATA/InspeccionLigera
```

Ese valor es el `ECOING_DATA_ROOT` de los comandos siguientes.

### 3.2 Crear el árbol de carpetas

```bash
export ECOING_DATA_ROOT=/share/CACHEDEV1_DATA/InspeccionLigera

mkdir -p "$ECOING_DATA_ROOT"/{imagenes,pdf}
mkdir -p "$ECOING_DATA_ROOT"/backups/db
mkdir -p "$ECOING_DATA_ROOT"/app-data/{mariadb,logs,exports}

ls -la "$ECOING_DATA_ROOT"
```

### 3.3 Averiguar el usuario dueño del share

El `www-data` del contenedor es uid 33, que en el NAS no existe. Si las carpetas
pertenecen a otro usuario, hay que decírselo al contenedor:

```bash
stat -c '%u %g %U %G' "$ECOING_DATA_ROOT"
id admin
```

Anotar el uid y el gid: son `ECOING_PUID` y `ECOING_PGID`. Si el arranque no se
queja de permisos, no hace falta ponerlos.

### 3.4 Copiar el proyecto al NAS

```bash
mkdir -p "$ECOING_DATA_ROOT"/app-data/repo
cd "$ECOING_DATA_ROOT"/app-data/repo
git clone <url-del-repositorio> .
```

O copiarlo por SMB desde el PC a `InspeccionLigera/app-data/repo`.

---

## 4. Configurar

```bash
cd "$ECOING_DATA_ROOT"/app-data/repo
cp docker/.env.example docker/.env
```

Editar `docker/.env` y cambiar **como mínimo** estas cinco líneas:

```env
APP_URL=http://192.168.1.3:8082

DB_PASSWORD=<contraseña fuerte>
MARIADB_PASSWORD=<la MISMA contraseña>
MARIADB_ROOT_PASSWORD=<otra contraseña fuerte, distinta>

JWT_SECRET=<64 caracteres hexadecimales aleatorios>
```

Generar valores aleatorios en el NAS:

```bash
openssl rand -hex 32      # para JWT_SECRET
openssl rand -base64 24   # para las contraseñas
```

Reglas que no conviene romper:

- `DB_PASSWORD` y `MARIADB_PASSWORD` **deben coincidir**. Son la misma cuenta
  vista desde la aplicación y desde la base.
- `MARIADB_*` solo se aplican la **primera** vez, con el datadir vacío.
  Cambiarlas después no cambia nada en la base.
- `JWT_SECRET` necesita 32 caracteres como mínimo: `api/config/jwt.php` aborta
  si no llega. Cambiarlo invalida las sesiones abiertas (no se pierde ningún
  dato: los inspectores vuelven a iniciar sesión).
- `docker/.env` está en `.gitignore`. Nunca se sube al repositorio.

---

## 5. Levantar los contenedores

### Comandos exactos, producción QNAP

```bash
cd /share/CACHEDEV1_DATA/InspeccionLigera/app-data/repo

# 1. Exportar las rutas y el puerto (o editar los valores por defecto del
#    docker-compose.yml si se va a lanzar desde la interfaz de Container Station)
export ECOING_DATA_ROOT=/share/CACHEDEV1_DATA/InspeccionLigera
export ECOING_HTTP_PORT=8082
export ECOING_PUID=$(stat -c '%u' "$ECOING_DATA_ROOT")
export ECOING_PGID=$(stat -c '%g' "$ECOING_DATA_ROOT")

# 2. Construir la imagen (requiere que el NAS tenga salida a Internet una vez)
docker compose build

# 3. Arrancar
docker compose up -d

# 4. Ver el estado
docker compose ps
docker compose logs -f ecoing-app     # Ctrl+C para salir

# 5. Comprobar el servicio
curl -s http://127.0.0.1:8082/api/health.php
```

La primera vez, `ecoing-db` tarda ~60 s en inicializarse: crea la base, el
usuario y aplica `database/init/01-esquema.sql`. `ecoing-app` espera a que
responda antes de arrancar Apache.

Respuesta esperada del health check:

```json
{"status":"ok","service":"ECOING API","checks":{"database":"ok","storage_imagenes":"ok","storage_pdf":"ok","storage_logs":"ok"},"time":"..."}
```

### Desde la interfaz de Container Station

1. **Aplicaciones → Crear → Crear aplicación**.
2. Pegar el contenido de `docker-compose.yml`.
3. Sustituir en el YAML los `${ECOING_DATA_ROOT:-...}` por la ruta real y
   `${ECOING_HTTP_PORT:-8082}` por `8082`. La interfaz no exporta variables del
   shell.
4. Crear. Container Station construye la imagen y arranca los dos servicios.

### Otros comandos de operación

```bash
docker compose ps                       # estado y salud
docker compose logs --tail=100 ecoing-app
docker compose logs --tail=100 ecoing-db
docker compose restart ecoing-app       # reiniciar solo la app
docker compose down                     # detener (NO borra datos del NAS)
docker compose up -d --build            # reconstruir tras actualizar el código
docker exec -it ecoing-app bash         # entrar al contenedor de la app
docker stats ecoing-app ecoing-db       # CPU y memoria en vivo
```

`docker compose down` **no borra nada**: la base está en
`app-data/mariadb` y las fotografías en `imagenes/`, ambas en el NAS.

---

## 6. Comandos exactos para desarrollo (PC con XAMPP)

La pila de desarrollo usa volúmenes Docker con nombre y el puerto 8080, así que
**no toca XAMPP ni su MySQL**.

```powershell
Set-Location C:\xampp82\htdocs\INSPEECIONLIGERAECOING

# 1. Configuración de los contenedores
Copy-Item docker\.env.example docker\.env
#    Editar docker\.env: APP_URL=http://localhost:8080, contraseñas y JWT_SECRET

# 2. Puerto de desarrollo
$env:ECOING_HTTP_PORT = "8080"

# 3. Levantar
docker compose -f docker-compose.yml -f docker-compose.dev.yml up -d --build

# 4. Comprobar
curl.exe http://localhost:8080/api/health.php
Start-Process http://localhost:8080/web/

# 5. Base de datos accesible en 127.0.0.1:3307 (HeidiSQL, phpMyAdmin...)

# 6. Detener
docker compose -f docker-compose.yml -f docker-compose.dev.yml down

# 7. Detener Y borrar los datos de prueba
docker compose -f docker-compose.yml -f docker-compose.dev.yml down -v
```

Con la sobrecarga de desarrollo, `api/` y `web/` se montan desde el
repositorio: un cambio en un `.php` se ve al recargar, sin reconstruir.

---

## 7. Crear el primer administrador

`api/usuarios/register_admin.php` solo acepta peticiones desde `127.0.0.1` o
`::1`, y solo cuando la tabla `usuarios` está vacía. Esa restricción **se
mantiene a propósito**: en la LAN de una oficina, un endpoint que crea
administradores sin autenticar tiene que estar cerrado.

Dentro del contenedor, `127.0.0.1` es el propio Apache, así que la forma correcta
es lanzar la petición desde ahí:

```bash
docker exec -i ecoing-app curl -s -X POST \
  -H 'Content-Type: application/json' \
  --data '{
    "nombre_completo": "Administrador ECOING",
    "nombre_usuario": "admin",
    "correo_electronico": "admin@grupoecoing.com",
    "contrasena": "una-contrasena-de-al-menos-10-caracteres"
  }' \
  http://127.0.0.1/api/usuarios/register_admin.php
```

Respuesta esperada: `{"success":true,"message":"Administrador inicial creado.",...}`

A partir de ahí, los siguientes usuarios se crean desde la web o con
`api/usuarios/register.php` usando el token del administrador.

Si se importó `database/usuarios_iniciales_servidor.sql`, este paso no hace
falta: las cuentas ya existen.

---

## 8. Respaldos

```bash
cd /share/CACHEDEV1_DATA/InspeccionLigera/app-data/repo
chmod +x scripts/*.sh

# Respaldo manual
./scripts/backup-db.sh

# Verificar el último respaldo sin generar uno nuevo
./scripts/backup-db.sh --verify-only

# Ver el historial
cat /share/CACHEDEV1_DATA/InspeccionLigera/backups/db/backup.log
```

El script genera
`backups/db/ecoing_inspeccion_AAAA-MM-DD_HHMMSS.sql.gz`, comprueba el código de
salida de `mariadb-dump`, verifica la integridad del gzip y que el volcado
termine con su marcador, y registra el resultado. **No borra ningún respaldo.**

### Programarlo en el NAS

**Panel de control → Programador de tareas** (o `crontab`):

```bash
# Respaldo diario a las 22:15
15 22 * * * cd /share/CACHEDEV1_DATA/InspeccionLigera/app-data/repo && ./scripts/backup-db.sh >> /share/CACHEDEV1_DATA/InspeccionLigera/backups/db/cron.log 2>&1
```

En QTS, tras editar el crontab hay que recargarlo:

```bash
crontab -l > /etc/config/crontab && /etc/init.d/crond.sh restart
```

Las **fotografías** no las respalda este script: son decenas de GB. Se protegen
con **Hybrid Backup Sync** del propio QNAP, replicando la carpeta `imagenes/` a
un disco externo o a otro NAS.

---

## 9. Logs

Los del aplicativo son persistentes y viven en el NAS:

```bash
cd /share/CACHEDEV1_DATA/InspeccionLigera/app-data/logs
ls -la

tail -f api.log              # errores de PHP y de la base
tail -f sincronizacion.log   # resultado de cada lote de fotografías
tail -f rendimiento.log      # duración de subidas y de generación de PDF
tail -f almacenamiento.log   # fallos de escritura o de resolución de rutas
```

Los de Apache van a la salida estándar del contenedor y los recoge Docker:

```bash
docker compose logs --tail=200 ecoing-app
```

Ningún log contiene tokens JWT completos ni contraseñas: `redactSensitive()`
(`api/config/log.php`) los recorta antes de escribir.

Para dejar de registrar tiempos cuando ya no interese medir:
`UPLOAD_METRICS_ENABLED=false` en `docker/.env` y `docker compose up -d`.

---

## 10. Actualizar el sistema

```bash
cd /share/CACHEDEV1_DATA/InspeccionLigera/app-data/repo

./scripts/backup-db.sh          # 1. respaldo ANTES de tocar nada
git pull                        # 2. traer el código nuevo
docker compose up -d --build    # 3. reconstruir y recrear
curl -s http://127.0.0.1:8082/api/health.php   # 4. comprobar
```

Las fotografías y la base no se ven afectadas: viven fuera de los contenedores.

---

## 11. Diagnóstico

| Síntoma | Qué mirar |
|---|---|
| `health.php` dice `"database":"error"` | `docker compose logs ecoing-db`. Contraseña distinta entre `DB_PASSWORD` y `MARIADB_PASSWORD`, o el datadir se inicializó con otra. |
| `health.php` dice `"storage_imagenes":"error"` | Permisos del share. Ver el aviso del arranque: `docker compose logs ecoing-app | grep AVISO`. Poner `ECOING_PUID`/`ECOING_PGID`. |
| Las fotografías suben pero el PDF sale con huecos | Las filas antiguas apuntan a rutas que no se copiaron. Ver el paso de imágenes de `MIGRACION_XAMPP_A_QNAP.md`. |
| La web abre pero el login falla con 401 | `APP_URL` no coincide con la URL por la que se entra. El JWT lleva `APP_URL` como emisor. |
| La app Flutter no ve el servidor | Comprobar desde el móvil `http://192.168.1.3:8082/api/health.php` en el navegador. Si no responde, es el firewall del NAS o la VLAN del WiFi. |
| El contenedor reinicia en bucle | `docker compose logs --tail=100 ecoing-app`. Casi siempre `JWT_SECRET` con menos de 32 caracteres. |
| Todo va lento al sincronizar | `tail -f app-data/logs/rendimiento.log`. Si `reprocesada=si` en la mayoría, revisar `IMAGE_REENCODE_MODE=auto`. |

Comprobaciones rápidas dentro del contenedor:

```bash
docker exec ecoing-app php -m                       # extensiones cargadas
docker exec ecoing-app php -i | grep -i upload_max  # límites efectivos
docker exec ecoing-app ls -la /data                 # montajes y permisos
docker exec ecoing-app df -h /data                  # espacio libre
docker exec ecoing-db mariadb --version
```
