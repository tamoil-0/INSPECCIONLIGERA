# Migración de XAMPP al QNAP

Procedimiento para pasar el sistema de `C:\xampp82\htdocs\INSPEECIONLIGERAECOING`
al NAS, **sin apagar ni modificar XAMPP** hasta que el NAS esté verificado.

> **Regla que gobierna todo este documento.** El entorno XAMPP original no se
> toca en ningún paso: no se detiene, no se borra, no se reconfigura. Es la copia
> de seguridad viva mientras el NAS se prueba. Solo se retira al final, y solo
> después de que los 15 pasos hayan pasado.

Los 15 pasos, en orden. No saltarse ninguno.

---

## 1. Generar el dump de la base actual

En el PC con XAMPP, con MySQL arrancado:

```powershell
Set-Location C:\xampp82\htdocs\INSPEECIONLIGERAECOING
New-Item -ItemType Directory -Force migracion | Out-Null

C:\xampp82\mysql\bin\mysqldump.exe `
  --host=127.0.0.1 --user=root `
  --single-transaction --quick --routines --events --triggers `
  --default-character-set=utf8mb4 `
  --databases ecoing_inspeccion `
  --result-file=migracion\ecoing_inspeccion_migracion.sql

# Comprobar que el volcado terminó bien
Select-String -Path migracion\ecoing_inspeccion_migracion.sql -Pattern 'Dump completed' | Select-Object -Last 1
(Get-Item migracion\ecoing_inspeccion_migracion.sql).Length
```

Si no aparece `Dump completed`, el volcado se cortó. **No continuar.**

Anotar las cuentas de referencia para comparar al final:

```powershell
C:\xampp82\mysql\bin\mysql.exe --host=127.0.0.1 --user=root ecoing_inspeccion -e `
 "SELECT 'usuarios',COUNT(*) FROM usuarios
  UNION ALL SELECT 'proyectos',COUNT(*) FROM proyectos
  UNION ALL SELECT 'postes',COUNT(*) FROM postes
  UNION ALL SELECT 'poste_datos',COUNT(*) FROM poste_datos
  UNION ALL SELECT 'poste_secciones_rst',COUNT(*) FROM poste_secciones_rst
  UNION ALL SELECT 'imagenes_poste',COUNT(*) FROM imagenes_poste;"
```

Guardar esa tabla. Es el patrón de comparación del paso 8.

## 2. Respaldar las fotografías

```powershell
# Cuántas hay y cuánto pesan
(Get-ChildItem storage\images -Recurse -File).Count
"{0:N2} GB" -f ((Get-ChildItem storage\images -Recurse -File | Measure-Object Length -Sum).Sum / 1GB)

# Copia local de seguridad, aparte de la que irá al NAS
robocopy storage\images migracion\images_respaldo /E /R:2 /W:2 /NFL /NDL
```

`robocopy` **copia**, no mueve: `storage\images` queda intacto.

## 3. Levantar los contenedores en el NAS

Seguir [`DESPLIEGUE_QNAP_DOCKER.md`](DESPLIEGUE_QNAP_DOCKER.md) secciones 3 a 5.

Al terminar, desde el NAS:

```bash
docker compose ps                                  # los dos "healthy"
curl -s http://127.0.0.1:8082/api/health.php
```

En este punto la base está **vacía** (solo el esquema). Es lo esperado.

## 4. Importar el dump

Copiar `migracion\ecoing_inspeccion_migracion.sql` a la carpeta compartida
`InspeccionLigera` (por SMB desde el PC) y desde el NAS:

```bash
cd /share/CACHEDEV1_DATA/InspeccionLigera/app-data/repo
./scripts/import-dump.sh /share/CACHEDEV1_DATA/InspeccionLigera/ecoing_inspeccion_migracion.sql
```

El script muestra el estado antes y después, y pide confirmación escrita si la
base ya tenía filas.

> El dump de XAMPP trae `CREATE DATABASE` / `USE`: recrea la base entera. Eso es
> correcto en una migración inicial y es la razón de que este paso vaya antes de
> cualquier prueba con datos nuevos.

Si el dump se generó **con** `--databases`, el usuario `ecoing_user` puede perder
sus permisos al recrearse la base. Restaurarlos:

```bash
docker exec -i -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" ecoing-db \
  mariadb -u root -e "GRANT ALL PRIVILEGES ON ecoing_inspeccion.* TO 'ecoing_user'@'%'; FLUSH PRIVILEGES;"
```

## 5. Copiar las imágenes

Las filas existentes guardan rutas del tipo `storage/images/452/placa_xxx.jpg`.
El destino correcto es que `452/placa_xxx.jpg` quede colgando de la raíz de
imágenes, es decir: **se copia el contenido de `storage\images`, no la carpeta**.

Desde el PC, con la carpeta compartida montada (por ejemplo en `Z:`):

```powershell
# Mapear el share si no lo está
net use Z: \\192.168.1.3\InspeccionLigera /persistent:no

# Copiar el CONTENIDO de storage\images a Z:\imagenes
robocopy C:\xampp82\htdocs\INSPEECIONLIGERAECOING\storage\images Z:\imagenes /E /R:3 /W:5 /MT:8 /TEE /LOG+:C:\xampp82\htdocs\INSPEECIONLIGERAECOING\migracion\robocopy_imagenes.log

# Verificar que las cuentas coinciden
(Get-ChildItem C:\xampp82\htdocs\INSPEECIONLIGERAECOING\storage\images -Recurse -File).Count
(Get-ChildItem Z:\imagenes -Recurse -File).Count
```

Los dos números deben ser **iguales**. Si no, revisar
`migracion\robocopy_imagenes.log`.

Después, ajustar el propietario en el NAS para que `www-data` pueda leer:

```bash
chmod -R u+rwX,g+rwX /share/CACHEDEV1_DATA/InspeccionLigera/imagenes
```

### Por qué no hace falta ningún `UPDATE` en la base

`resolveStoredImagePath()` (`api/config/storage.php`) resuelve las tres formas
que pueden aparecer en `ruta_archivo`:

| Lo que hay en la base | Dónde lo busca |
|---|---|
| `proyecto_1/linea_L-6021/poste_452/placa_ab.jpg` (nuevo) | `/data/imagenes/proyecto_1/linea_L-6021/poste_452/placa_ab.jpg` |
| `storage/images/452/placa_ab.jpg` (heredado) | `/data/imagenes/452/placa_ab.jpg` |
| `imagenespostes/...` (muy antiguo) | igual, quitando el prefijo |

Las fotografías nuevas se guardarán con la jerarquía
`proyecto_/linea_/poste_/`; las antiguas seguirán resolviendo donde están. Las
dos convivien indefinidamente. **No se modifica ni una fila.**

## 6. Verificar las rutas

```bash
# Cuántas filas de imágenes hay
docker exec -i -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" ecoing-db \
  mariadb -u root ecoing_inspeccion -e "SELECT COUNT(*) FROM imagenes_poste;"

# Comprobar que una muestra existe realmente en disco
docker exec -i -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" ecoing-db \
  mariadb -u root ecoing_inspeccion -N -e \
  "SELECT ruta_archivo FROM imagenes_poste ORDER BY RAND() LIMIT 20" \
| while read -r ruta; do
    limpia="${ruta#storage/images/}"
    if [ -f "/share/CACHEDEV1_DATA/InspeccionLigera/imagenes/$limpia" ]; then
        echo "OK    $ruta"
    else
        echo "FALTA $ruta"
    fi
  done
```

Si aparece algún `FALTA`, el paso 5 quedó incompleto. Repetir el robocopy antes
de seguir.

## 7. Comprobar el login

```bash
curl -s -X POST -H 'Content-Type: application/json' \
  --data '{"nombre_usuario":"admin","contrasena":"..."}' \
  http://192.168.1.3:8082/api/usuarios/login.php
```

Debe devolver `"success":true` y un `token`. Guardarlo para los pasos
siguientes:

```bash
TOKEN="<el token devuelto>"
```

Si devuelve 401 con las credenciales correctas, revisar que `APP_URL` en
`docker/.env` sea exactamente `http://192.168.1.3:8082`.

## 8. Comprobar los proyectos

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  http://192.168.1.3:8082/api/proyectos/listar.php
```

Comparar el número de proyectos con la tabla anotada en el paso 1.

## 9. Comprobar las estructuras

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://192.168.1.3:8082/api/postes/listar.php?proyecto_id=1" | head -c 400

# La cuenta completa
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://192.168.1.3:8082/api/postes/listar.php?proyecto_id=1" \
  | grep -o '"count":[0-9]*'
```

Debe coincidir con el `postes` del paso 1 (1.235 en la instalación de
referencia).

## 10. Comprobar el formulario

Desde la web: `http://192.168.1.3:8082/web/` → iniciar sesión → un proyecto →
una línea → una estructura ya inspeccionada. Los campos del formulario deben
aparecer rellenos con lo que había en XAMPP.

O por API:

```bash
curl -s -H "Authorization: Bearer $TOKEN" \
  "http://192.168.1.3:8082/api/postes/poste_datos.php?poste_id=452"
```

## 11. Comprobar las imágenes

La respuesta anterior incluye el array `imagenes`. Deben ser 28 en una
estructura completa, cada una con su `ruta_archivo`.

## 12. Comprobar el PDF

```bash
curl -s -D - -o /tmp/prueba.pdf -H "Authorization: Bearer $TOKEN" \
  "http://192.168.1.3:8082/api/ver_pdf_poste.php?poste_id=452&proyecto_id=1" \
| grep -Ei 'HTTP/|X-Ecoing-Pdf-Cache'

ls -la /tmp/prueba.pdf
head -c 5 /tmp/prueba.pdf     # debe empezar por %PDF-
```

La primera llamada responde `X-Ecoing-Pdf-Cache: miss`; la segunda, `hit` y en
mucho menos tiempo.

**Abrir el PDF y mirarlo.** Las fotografías tienen que verse, no salir en
blanco. Un PDF con huecos significa que el paso 5 quedó incompleto.

## 13. Comprobar el Excel

```bash
curl -s -o /tmp/prueba.xlsx -H "Authorization: Bearer $TOKEN" \
  "http://192.168.1.3:8082/api/exportar_excel_linea.php?proyecto_id=1&linea=L-6021"

ls -la /tmp/prueba.xlsx
```

Descargarlo y abrirlo en Excel. Y el ZIP de PDF de una línea:

```bash
curl -s -o /tmp/linea.zip -H "Authorization: Bearer $TOKEN" \
  "http://192.168.1.3:8082/api/exportar_linea_pdf.php?proyecto_id=1&linea=L-6021"
unzip -l /tmp/linea.zip | tail -5
```

## 14. Probar la app Flutter

Sin cambiar todavía la URL de producción: se pasa por línea de comandos.

```powershell
Set-Location C:\xampp82\htdocs\INSPEECIONLIGERAECOING\pruebaoffline

flutter run `
  --dart-define=API_BASE_URL=http://192.168.1.3:8082/api `
  --dart-define=ENTORNO=pruebas `
  --dart-define=REGISTRO_DETALLADO=true
```

Recorrido mínimo, con el teléfono en el WiFi de la oficina:

1. Iniciar sesión.
2. Descargar las estructuras de una línea.
3. **Activar modo avión.**
4. Llenar un formulario, tomar las 28 fotografías. Todo debe quedar
   `Pendiente de enviar`.
5. Cerrar la app y volver a abrirla: lo pendiente sigue ahí.
6. **Desactivar modo avión** y conectar al WiFi.
7. Sincronizar. Observar que el estado pasa a `Sincronizado` **solo** cuando el
   servidor confirma.
8. En el NAS: `tail -f app-data/logs/sincronizacion.log`.

Comprobar en el NAS que los archivos aterrizaron con la jerarquía nueva:

```bash
find /share/CACHEDEV1_DATA/InspeccionLigera/imagenes/proyecto_1 -newermt '-1 hour' -type f | head -30
```

Cuando todo esto pase, generar el APK definitivo:

```powershell
flutter build apk --release `
  --dart-define=API_BASE_URL=http://192.168.1.3:8082/api `
  --dart-define=ENTORNO=produccion
```

El valor por defecto de `Entorno.apiBaseUrl`
(`pruebaoffline/lib/core/entorno.dart`) **sigue apuntando al hosting anterior a
propósito**, para que una compilación hecha por descuido no mande a los
inspectores a un servidor en pruebas. Cambiarlo es editar esa única línea,
cuando se decida.

## 15. Recién ahora: retirar XAMPP

Solo cuando los 14 pasos anteriores hayan pasado **y** el sistema haya operado
en el NAS durante al menos una jornada completa de inspecciones reales.

Orden recomendado:

1. Un respaldo más en el NAS: `./scripts/backup-db.sh`
2. Detener Apache y MySQL en XAMPP, **sin desinstalar nada**.
3. Operar una semana con XAMPP detenido pero instalado.
4. Archivar `C:\xampp82\htdocs\INSPEECIONLIGERAECOING` completo (incluida
   `storage\images`) en un disco externo.
5. Recién entonces, si se decide, desinstalar XAMPP.

No hay ninguna prisa en el paso 5. Un XAMPP detenido no molesta a nadie y es la
última red de seguridad.

---

## Marcha atrás

Si algo va mal en el NAS, volver a XAMPP es inmediato porque nunca se tocó:

1. Arrancar Apache y MySQL en XAMPP.
2. Los inspectores vuelven a la URL anterior (`--dart-define` o el APK previo).
3. `docker compose down` en el NAS.

Lo único que se pierde son las inspecciones cargadas en el NAS durante la
prueba. Para recuperarlas, hacer un dump del NAS e importar solo las filas
nuevas — comparando `fecha_subida`, no importando el dump completo sobre XAMPP.

---

## Anexo · instalación nueva desde cero, sin datos de XAMPP

Para un entorno de pruebas limpio con el padrón de estructuras pero sin
inspecciones anteriores:

```bash
cd /share/CACHEDEV1_DATA/InspeccionLigera/app-data/repo
./scripts/import-dump.sh database/ecoing_inspeccion_con_estructuras.sql

# Cuentas iniciales (contraseñas en data/CREDENCIALES_USUARIOS_SERVIDOR.txt)
./scripts/import-dump.sh --force database/usuarios_iniciales_servidor.sql
```

O directamente el esquema vacío, que ya aplica `database/init/01-esquema.sql`
al arrancar por primera vez, y crear el administrador con el paso 7 de
`DESPLIEGUE_QNAP_DOCKER.md`.
