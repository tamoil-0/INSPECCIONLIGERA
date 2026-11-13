# Checklist de pruebas — ECOING en QNAP

Lista para validar el despliegue antes de retirar XAMPP. Se recomienda
imprimirla o copiarla y marcar cada casilla con fecha y resultado.

Convenciones de los comandos:

```bash
export DATA=/share/CACHEDEV1_DATA/InspeccionLigera
export REPO=$DATA/app-data/repo
export URL=http://192.168.1.3:8082
cd $REPO
```

Y el token de un administrador para las pruebas autenticadas:

```bash
TOKEN=$(curl -s -X POST -H 'Content-Type: application/json' \
  --data '{"nombre_usuario":"admin","contrasena":"..."}' \
  $URL/api/usuarios/login.php | grep -o '"token":"[^"]*"' | cut -d'"' -f4)
echo "${TOKEN:0:12}..."
```

---

## A · Infraestructura

### A1 · Los contenedores arrancan

```bash
docker compose up -d
docker compose ps
```

- [ ] `ecoing-db` en estado `running` y `healthy`
- [ ] `ecoing-app` en estado `running` y `healthy`
- [ ] `docker compose logs ecoing-app | grep -i aviso` no muestra avisos de permisos
- [ ] `docker exec ecoing-app php -m` incluye `gd`, `zip`, `exif`, `pdo_mysql`, `mbstring`, `fileinfo`, `Zend OPcache`

### A2 · El backend conecta con la base

```bash
curl -s $URL/api/health.php
```

- [ ] Responde HTTP 200
- [ ] `"status":"ok"`
- [ ] `"database":"ok"`
- [ ] `"storage_imagenes":"ok"`, `"storage_pdf":"ok"`, `"storage_logs":"ok"`
- [ ] La respuesta **no** contiene versiones, rutas internas ni credenciales

### A3 · MariaDB persiste después de reiniciar el contenedor

```bash
docker exec -i -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" ecoing-db \
  mariadb -u root ecoing_inspeccion -e "SELECT COUNT(*) FROM postes;"    # anotar N

docker compose restart ecoing-db
sleep 45

docker exec -i -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" ecoing-db \
  mariadb -u root ecoing_inspeccion -e "SELECT COUNT(*) FROM postes;"    # debe ser N
```

- [ ] El número de postes es el mismo antes y después

### A4 · Persistencia al recrear los contenedores

Es la prueba importante: `down` destruye los contenedores.

```bash
docker compose down
docker compose up -d
sleep 60
curl -s $URL/api/health.php
```

- [ ] `"status":"ok"`
- [ ] El número de postes sigue siendo N
- [ ] Las fotografías siguen en `$DATA/imagenes`
- [ ] `ls $DATA/app-data/mariadb | head` muestra los archivos de la base

### A5 · Persistencia al reiniciar el NAS

```bash
# Reiniciar desde QTS: Panel de control -> Sistema -> Reiniciar
# Al volver:
docker compose ps
curl -s $URL/api/health.php
```

- [ ] Los dos contenedores arrancaron solos (`restart: unless-stopped`)
- [ ] `"status":"ok"`
- [ ] El número de postes es el mismo
- [ ] El login sigue funcionando con las mismas credenciales

### A6 · El puerto de la base NO está expuesto a la LAN

Desde un PC de la oficina, **no** desde el NAS:

```powershell
Test-NetConnection -ComputerName 192.168.1.3 -Port 3306
Test-NetConnection -ComputerName 192.168.1.3 -Port 8082
```

- [ ] El 3306 **no** responde
- [ ] El 8082 sí responde

---

## B · Web administrativa

### B1 · La web abre

- [ ] `http://192.168.1.3:8082` redirige a `/web/`
- [ ] `http://192.168.1.3:8082/web/` carga con estilos e iconos
- [ ] La consola del navegador (F12) no muestra errores de red
- [ ] El indicador de conexión aparece en verde

### B2 · El JavaScript construye bien la URL del API

En la consola del navegador:

```javascript
console.log(API_BASE);   // debe imprimir http://192.168.1.3:8082/api
```

- [ ] `API_BASE` es exactamente esa URL, sin `localhost` y sin puerto duplicado

### B3 · Login

- [ ] Un usuario válido entra y llega a `proyectos.html`
- [ ] Una contraseña incorrecta da «Credenciales inválidas», no un error 500
- [ ] Cinco intentos fallidos seguidos dan HTTP 429 (bloqueo por intentos)
- [ ] Tras el bloqueo, el usuario correcto sigue sin poder entrar hasta que pase el tiempo
- [ ] Cerrar sesión devuelve a la pantalla de login
- [ ] Abrir `proyectos.html` sin sesión redirige a `index.html`

### B4 · Rutas protegidas

```bash
curl -s -o /dev/null -w '%{http_code}\n' $URL/api/proyectos/listar.php
curl -s -o /dev/null -w '%{http_code}\n' -H 'Authorization: Bearer basura' $URL/api/proyectos/listar.php
curl -s -o /dev/null -w '%{http_code}\n' $URL/api/config/database.php
curl -s -o /dev/null -w '%{http_code}\n' $URL/api/vendor/autoload.php
curl -s -o /dev/null -w '%{http_code}\n' $URL/.env
```

- [ ] Sin token: 401
- [ ] Token inválido: 401
- [ ] `api/config/database.php`: 403
- [ ] `api/vendor/autoload.php`: 403
- [ ] `.env`: 403 o 404 (nunca 200)

### B5 · Navegación completa

- [ ] Lista de proyectos con sus contadores
- [ ] Detalle de proyecto → líneas
- [ ] Detalle de línea → estructuras
- [ ] Detalle de estructura → estado de formulario e imágenes
- [ ] Formulario de una estructura ya inspeccionada aparece relleno

---

## C · Reportes

### C1 · PDF de una estructura

```bash
curl -s -D /tmp/h1 -o /tmp/p1.pdf -H "Authorization: Bearer $TOKEN" \
  "$URL/api/ver_pdf_poste.php?poste_id=452&proyecto_id=1"
grep -i 'x-ecoing-pdf-cache' /tmp/h1
head -c 5 /tmp/p1.pdf
```

- [ ] Empieza por `%PDF-`
- [ ] Primera llamada: `X-Ecoing-Pdf-Cache: miss`
- [ ] **Abierto en un visor, las 28 fotografías se ven** (ninguna en blanco)
- [ ] El sello con UTM y fecha aparece sobre las fotos
- [ ] Las firmas salen al final

### C2 · La caché de PDF funciona

```bash
time curl -s -o /dev/null -D /tmp/h2 -H "Authorization: Bearer $TOKEN" \
  "$URL/api/ver_pdf_poste.php?poste_id=452&proyecto_id=1"
grep -i 'x-ecoing-pdf-cache' /tmp/h2
ls -la $DATA/pdf/poste_452/
```

- [ ] Segunda llamada: `X-Ecoing-Pdf-Cache: hit`
- [ ] Es claramente más rápida que la primera
- [ ] Existe el archivo en `$DATA/pdf/poste_452/`

### C3 · La caché se invalida al cambiar el formulario

- [ ] Editar y guardar el formulario de ese poste desde la web
- [ ] `ls $DATA/pdf/poste_452/` queda vacío
- [ ] La siguiente descarga vuelve a dar `miss`
- [ ] El PDF nuevo refleja el cambio

### C4 · La caché se invalida al sustituir una imagen

- [ ] Volver a subir una fotografía de ese poste desde la app
- [ ] El PDF siguiente da `miss` y muestra la imagen nueva

### C5 · Excel de una línea

```bash
curl -s -o /tmp/l.xlsx -H "Authorization: Bearer $TOKEN" \
  "$URL/api/exportar_excel_linea.php?proyecto_id=1&linea=L-6021"
ls -la /tmp/l.xlsx
```

- [ ] Se descarga con tamaño > 0
- [ ] Abre en Excel sin avisos de archivo corrupto
- [ ] Una fila por estructura, con los campos del formulario

### C6 · ZIP de PDF de una línea

```bash
time curl -s -o /tmp/l.zip -H "Authorization: Bearer $TOKEN" \
  "$URL/api/exportar_linea_pdf.php?proyecto_id=1&linea=L-6021"
unzip -l /tmp/l.zip | tail -3
```

- [ ] Un PDF por estructura de la línea
- [ ] No contiene `errores.txt` (o si lo contiene, se revisa)
- [ ] La **segunda** descarga es mucho más rápida (todo desde caché)
- [ ] `grep 'exporte de PDF por linea' $DATA/app-data/logs/rendimiento.log` muestra `desde_cache` alto

---

## D · Sincronización desde la app

### D1 · Descarga de estructuras

- [ ] La app inicia sesión contra el NAS
- [ ] Descarga las estructuras de una línea
- [ ] Se guardan en SQLite y siguen visibles tras cerrar y abrir la app

### D2 · Subida de una fotografía

- [ ] Una sola foto sube y queda `Sincronizado`
- [ ] Aparece en `$DATA/imagenes/proyecto_N/linea_X/poste_M/`
- [ ] `sincronizacion.log` registra `confirmadas=1`

### D3 · Subida de las 28 fotografías

```bash
tail -f $DATA/app-data/logs/rendimiento.log
```

- [ ] Las 28 quedan `Sincronizado`
- [ ] `imagenes_completas` es `true` en la respuesta
- [ ] Las 28 están en disco, en la carpeta del poste
- [ ] Anotar el tiempo total: ______ s
- [ ] En el log, la mayoría con `reprocesada=no` (no se recodificaron)
- [ ] Ninguna petición dio 413

### D4 · Formulario

- [ ] El formulario se envía y queda `Sincronizado`
- [ ] Los valores aparecen en la web
- [ ] Las secciones RST (fases R/S/T) aparecen en el PDF

### D5 · Sustitución de una imagen

- [ ] Volver a tomar una foto ya sincronizada y reenviarla
- [ ] La base mantiene **una sola** fila para ese `nombre_foto`
- [ ] El archivo anterior desapareció del NAS (no quedan huérfanos)
- [ ] El PDF muestra la nueva

### D6 · Flutter sin conexión

- [ ] Con modo avión: se llena el formulario y se toman las 28 fotos
- [ ] Todo queda `Pendiente de enviar`, nunca `Sincronizado`
- [ ] Cerrar y abrir la app conserva lo pendiente
- [ ] La app **no** dice «enviado» en ningún momento

### D7 · Sincronización al volver al WiFi

- [ ] Al recuperar el WiFi, la sincronización arranca (o se lanza a mano)
- [ ] El progreso avanza y los estados pasan a `Sincronizado`
- [ ] Ninguna foto ya confirmada se reenvía
- [ ] `sincronizacion.log` refleja las mismas cuentas que la app

### D8 · Un fallo aislado solo reintenta esa foto

- [ ] Cortar el WiFi a mitad de una sincronización de 28
- [ ] Las confirmadas quedan `Sincronizado`; el resto, `Error al enviar`
- [ ] Al reintentar, **solo** se reenvían las fallidas
- [ ] El resultado final son 28 confirmadas, sin duplicados en la base

---

## E · Robustez

### E1 · Fallo de la base de datos

```bash
docker compose stop ecoing-db
curl -s -o /dev/null -w '%{http_code}\n' $URL/api/health.php
curl -s $URL/api/health.php
curl -s -o /dev/null -w '%{http_code}\n' $URL/api/info.php
```

- [ ] `health.php` responde 503 con `"database":"error"`
- [ ] `info.php` responde 503 (la app lo interpreta como «sin servidor»)
- [ ] El mensaje **no** revela host, usuario ni error del driver
- [ ] La app Flutter muestra «sin conexión con el servidor» y **no** marca nada como sincronizado
- [ ] `docker compose start ecoing-db` y, tras ~30 s, `health.php` vuelve a `ok`
- [ ] Lo pendiente en la app sigue intacto y sincroniza al reintentar

### E2 · Fallo de escritura en el almacenamiento

```bash
# Simular un almacén no escribible
docker exec -u 0 ecoing-app chmod 0555 /data/imagenes
curl -s $URL/api/health.php
# Restaurar
docker exec -u 0 ecoing-app chmod 0775 /data/imagenes
```

- [ ] `health.php` marca `"storage_imagenes":"error"` y devuelve 503
- [ ] Al subir una foto, el servidor la **rechaza** con error claro
- [ ] La app la deja `Error al enviar`, **nunca** `Sincronizado`
- [ ] `almacenamiento.log` registra el fallo
- [ ] Al restaurar los permisos, el reintento la sube correctamente

### E3 · NAS con poco espacio

```bash
df -h $DATA
```

- [ ] Anotar el espacio libre: ______
- [ ] Estimar el consumo: ~2 MB × 28 fotos × estructuras previstas
- [ ] Con el disco casi lleno (o simulándolo con una cuota), una subida falla con error explícito
- [ ] La app la marca `Error al enviar` y **no** la pierde
- [ ] El error queda en `almacenamiento.log`
- [ ] Al liberar espacio, el reintento funciona

### E4 · Reinicio de los contenedores a mitad de trabajo

- [ ] Lanzar una sincronización de 28 fotos y hacer `docker compose restart ecoing-app` a mitad
- [ ] Las confirmadas antes del corte siguen `Sincronizado` y están en disco
- [ ] Las demás quedan `Error al enviar`, no perdidas
- [ ] El reintento las completa sin duplicar filas

### E5 · Carga simultánea de dos inspectores

- [ ] Dos teléfonos sincronizando estructuras distintas a la vez
- [ ] Las dos terminan correctamente
- [ ] `docker stats` no muestra el contenedor al 100 % de forma sostenida
- [ ] Ninguna petición da timeout

---

## F · Seguridad

- [ ] El 3306 no responde desde la LAN (ver A6)
- [ ] Todos los endpoints de datos exigen `Authorization: Bearer` (ver B4)
- [ ] `register_admin.php` desde la LAN devuelve 403
- [ ] `.env`, `composer.json`, `api/vendor/`, `api/config/` no se sirven por HTTP
- [ ] `curl -sI $URL/web/` no muestra la versión de Apache ni `X-Powered-By`
- [ ] Un usuario con rol `invitado` no puede crear ni modificar
- [ ] `grep -riE 'eyJ|Bearer [A-Za-z0-9]|contrasena=' $DATA/app-data/logs/` no encuentra tokens ni contraseñas
- [ ] Subir un `.php` renombrado a `.jpg` es rechazado por la validación de contenido
- [ ] Un `poste_id` de otro proyecto no devuelve datos ajenos

---

## G · Respaldos

```bash
./scripts/backup-db.sh
./scripts/backup-db.sh --verify-only
ls -la $DATA/backups/db/
cat $DATA/backups/db/backup.log
```

- [ ] Se crea `ecoing_inspeccion_AAAA-MM-DD_HHMMSS.sql.gz`
- [ ] El archivo pesa > 0 y `gzip -t` lo valida
- [ ] `backup.log` registra `RESPALDO CORRECTO`
- [ ] `--verify-only` da OK sobre el último respaldo
- [ ] Con `ecoing-db` detenido, el script sale con código 2 y **no** crea un archivo vacío
- [ ] El respaldo se restaura en un entorno de pruebas y las cuentas coinciden
- [ ] La tarea programada del NAS lo ejecuta a su hora

---

## H · Rendimiento (referencia)

Medir y anotar; sirve para comparar en el futuro.

| Operación | Objetivo | Medido |
|---|---|---|
| `health.php` | < 300 ms | |
| Login | < 500 ms | |
| Listar 1.235 estructuras | < 2 s | |
| Subir 1 fotografía (~2 MB) | < 3 s | |
| Subir 28 fotografías | < 40 s | |
| PDF de una estructura (primera vez) | < 15 s | |
| PDF de una estructura (desde caché) | < 1 s | |
| Excel de una línea | < 20 s | |

```bash
grep 'imagen almacenada' $DATA/app-data/logs/rendimiento.log | tail -30
grep 'Lote de fotografías' $DATA/app-data/logs/sincronizacion.log | tail -10
```

Si `ms_total` de un lote es alto y la mayoría de imágenes traen
`reprocesada=si`, es que llegan por encima de `IMAGE_MAX_DIMENSION` o de
`IMAGE_REENCODE_MAX_BYTES`: hay que revisar el perfil de compresión de la app
antes de tocar el servidor.

---

## Cierre

- [ ] Todo lo anterior pasa
- [ ] El sistema operó una jornada completa de inspecciones reales en el NAS
- [ ] Hay un respaldo verificado del día
- [ ] Recién ahora: retirar XAMPP (paso 15 de `MIGRACION_XAMPP_A_QNAP.md`)

Fecha de validación: ____________  Responsable: ____________
