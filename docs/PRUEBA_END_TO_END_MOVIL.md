# Prueba end-to-end de la app móvil contra el NAS

Procedimiento reproducible para validar el ciclo completo
**oficina → campo → oficina** con un celular físico y el servidor del NAS.

- Servidor: `http://192.168.1.3:8082`
- API: `http://192.168.1.3:8082/api`
- Duración estimada: 45–60 min

> **Nada de lo que hace esta prueba puede perder datos.** Si algo falla a mitad,
> lo capturado sigue en el teléfono y se recupera al reintentar. Ese es
> precisamente uno de los puntos que se verifican.

---

## 0. Preparación

### 0.1 Comprobar que el servidor está sano

Desde cualquier PC de la oficina:

```bash
curl -s http://192.168.1.3:8082/api/health.php
```

Debe responder:

```json
{"status":"ok","service":"ECOING API","checks":{"database":"ok","storage_imagenes":"ok","storage_pdf":"ok","storage_logs":"ok"},"time":"..."}
```

- [ ] `"status":"ok"` y los cuatro `checks` en `ok`

Si algún `check` dice `error`, **parar aquí** y resolverlo con
[`DESPLIEGUE_QNAP_DOCKER.md`](DESPLIEGUE_QNAP_DOCKER.md) §11. La app está hecha
para negarse a sincronizar en ese estado, así que la prueba no avanzaría.

### 0.2 Comprobarlo desde el propio teléfono

En el navegador del celular, conectado al Wi-Fi de la oficina, abrir:

```
http://192.168.1.3:8082/api/health.php
```

- [ ] Se ve el JSON

Si el PC lo ve y el teléfono no, el problema es de red (aislamiento de clientes
en el Wi-Fi, o una VLAN de invitados), no de la app.

### 0.3 Dejar los logs del NAS a la vista

En una terminal del NAS, para ir viendo la prueba desde el otro lado:

```bash
cd /share/CACHEDEV1_DATA/InspeccionLigera/app-data/logs
tail -f sincronizacion.log rendimiento.log
```

### 0.4 Instalar la app apuntando al NAS

El valor por defecto de `API_BASE_URL` **sigue siendo el hosting anterior**, a
propósito. Para la prueba se pasa por línea de comandos:

```powershell
Set-Location C:\xampp82\htdocs\INSPEECIONLIGERAECOING\pruebaoffline

flutter devices          # confirmar que el celular aparece

flutter run --release `
  --dart-define=API_BASE_URL=http://192.168.1.3:8082/api `
  --dart-define=ENTORNO=pruebas `
  --dart-define=REGISTRO_DETALLADO=true
```

`--release` y no `--debug`: en debug la compresión de imágenes es varias veces
más lenta y las mediciones de tiempo no valdrían para nada.

Para dejar el APK instalado y trabajar sin el cable:

```powershell
flutter build apk --release `
  --dart-define=API_BASE_URL=http://192.168.1.3:8082/api `
  --dart-define=ENTORNO=pruebas `
  --dart-define=REGISTRO_DETALLADO=true

# El APK queda en build\app\outputs\flutter-apk\app-release.apk
adb install -r build\app\outputs\flutter-apk\app-release.apk
```

Con `ENTORNO=pruebas` la pantalla de login muestra la etiqueta «pruebas»: es la
forma de no confundir este APK con el de producción.

Para ver los registros sin el cable conectado por USB:

```powershell
adb logcat -s flutter
```

---

## A · OFICINA — preparar el trabajo

### A1 · Estado del servidor en la app

- [ ] El indicador de la barra superior dice **Wi-Fi** en verde
- [ ] El panel de inicio muestra **«Servidor ECOING disponible.»**

Si dijera «No se encuentra el servidor de la oficina», la app está apuntando a
otra URL o el teléfono está en otra red.

### A2 · Login

- [ ] Iniciar sesión con un usuario **técnico** (no administrador): así se
      comprueba de paso que los roles funcionan
- [ ] Entra a la lista de proyectos

### A3 · Descargar estructuras

- [ ] Aparece el proyecto real
- [ ] Entrar y descargar la línea que se va a inspeccionar
- [ ] Volver al inicio

El panel del inicio debe decir ahora:

- [ ] **«Datos preparados para trabajo offline»**
- [ ] `N estructura(s) en M línea(s)` con N y M coherentes
- [ ] «Sincronizado hace instantes»

### A4 · Anotar el punto de partida

En el NAS:

```bash
docker exec -i -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" ecoing-db \
  mariadb -u root ecoing_inspeccion -e "
    SELECT COUNT(*) AS imagenes FROM imagenes_poste;
    SELECT COUNT(*) AS formularios FROM poste_datos WHERE comentarios IS NOT NULL;"

find /share/CACHEDEV1_DATA/InspeccionLigera/imagenes -type f | wc -l
```

- [ ] Anotar los tres números: ______ / ______ / ______

---

## B · CAMPO SIMULADO — sin red

### B1 · Cortar la red de verdad

- [ ] Activar **modo avión**
- [ ] Confirmar que además el Wi-Fi queda apagado
- [ ] En la app, el indicador pasa a **«Sin conexión»**
- [ ] El panel sigue diciendo «Datos preparados para trabajo offline»

### B2 · Ninguna pantalla depende del servidor

Recorrer sin red, comprobando que cada pantalla abre y muestra datos:

- [ ] Lista de proyectos
- [ ] Líneas del proyecto
- [ ] Lista de estructuras de la línea
- [ ] Buscar una estructura por código
- [ ] Abrir el detalle de una estructura
- [ ] Abrir el formulario
- [ ] Abrir la pantalla de fotografías

Ninguna debe quedarse girando ni mostrar un error de red.

### B3 · Completar el formulario

- [ ] Rellenar los campos obligatorios
- [ ] Rellenar el tablero RST (fases R / S / T)
- [ ] Escribir un comentario reconocible, por ejemplo
      `PRUEBA E2E 2026-08-20 offline`
- [ ] Guardar

El estado debe quedar **«Pendiente de enviar»**. Si dijera «Sincronizado» sin
red, sería un fallo grave: anotarlo y parar.

### B4 · Capturar las 28 fotografías

- [ ] Tomar las 28 fotografías de los tipos requeridos
- [ ] Comprobar que el GPS registra coordenadas (aparecen UTM en cada foto)
- [ ] Al terminar, el contador dice `28 de 28 obligatorias`
- [ ] Todas quedan en **«Pendiente de enviar»**

En el registro (`adb logcat -s flutter`) debe verse una línea por fotografía:

```
· Optimización placa: 4820 KB -> 1740 KB (2400x1800, 640 ms, comprimida)
```

- [ ] El **lado mayor nunca pasa de 2400 px**. Es lo que permite que el NAS
      archive la imagen sin reprocesarla.
- [ ] El peso final ronda 1–2,5 MB por fotografía

### B5 · Sobrevivir al cierre de la app

- [ ] Cerrar la app por completo (deslizarla fuera de recientes)
- [ ] Volver a abrirla, **todavía sin red**
- [ ] El formulario sigue con sus datos
- [ ] Las 28 fotografías siguen ahí, en «Pendiente de enviar»
- [ ] Las miniaturas se ven (se leen del disco local, no del servidor)

### B6 · No intentar sincronizar en una red ajena

- [ ] Desactivar modo avión y conectarse a un Wi-Fi **que no sea el de la
      oficina** (o compartir datos desde otro teléfono)
- [ ] El indicador dice **«Otra red Wi-Fi»**, no «En línea»
- [ ] El panel dice **«No se encuentra el servidor de la oficina. Conéctate al
      Wi-Fi de ECOING para sincronizar.»**
- [ ] Pulsar «Sincronizar pendientes» devuelve ese mismo mensaje y **no** deja
      nada en estado `uploading`
- [ ] La app **no** se queda colgada: la respuesta llega en unos 3 segundos

Este punto es el que distingue «tengo Wi-Fi» de «el NAS está accesible».

---

## C · REGRESO A LA OFICINA — sincronizar

### C1 · Reconectar

- [ ] Conectar el celular al Wi-Fi de la oficina
- [ ] El indicador pasa a **Wi-Fi** verde
- [ ] Aparece **«Servidor ECOING disponible.»**
- [ ] Con la app abierta, la sincronización arranca sola (disparo automático) o
      queda disponible el botón

### C2 · Sincronizar y medir

- [ ] Anotar la hora de inicio: ______
- [ ] Pulsar «Sincronizar pendientes»
- [ ] Mientras corre, la capa de progreso muestra el detalle por estructura:
      `Poste N · fotografías 12 de 28`
- [ ] Anotar la hora de fin: ______  → **duración: ______ s**

### C3 · Verificar los estados en la app

- [ ] Formulario: **Sincronizado**
- [ ] Fotografías: **28 de 28 confirmadas**
- [ ] La estructura aparece como sincronizada
- [ ] No queda nada en «Pendiente» ni en «Error al enviar»

### C4 · Leer las métricas del teléfono

En `adb logcat -s flutter`:

```
› Poste 452 sincronizado en 18.4 s · formulario 320 ms · fotos 28/28 ·
  47320 KB · concurrencia 3 · reintentos 0 · reprocesadas por el servidor 0/28
› Poste 452 reparto del tiempo · servidor 6.1 s · red y espera 41.2 s
  (suma de peticiones, no tiempo de pared: hubo 3 en paralelo)
```

- [ ] **`reprocesadas por el servidor` es 0 o casi 0.** Si sale 28/28, el perfil
      de compresión del teléfono está entregando imágenes por encima de
      `IMAGE_MAX_DIMENSION` y el NAS está haciendo el trabajo caro. Es la causa
      del problema histórico del minuto por estructura.
- [ ] `reintentos` es 0
- [ ] Anotar el reparto servidor / red: ______ / ______

### C5 · Contrastar con las métricas del NAS

```bash
tail -40 /share/CACHEDEV1_DATA/InspeccionLigera/app-data/logs/rendimiento.log
```

Debe verse una línea por fotografía:

```
2026-08-20T... [rendimiento] imagen almacenada | poste=452 tipo=placa kb=1698
  px=2400x1800 reprocesada=no ms_imagen=41.2 ms_bd=3.8
```

- [ ] `reprocesada=no` en la gran mayoría
- [ ] `ms_imagen` de decenas de milisegundos, no de segundos

Y el resumen por lote:

```bash
grep 'Lote de fotografías' /share/CACHEDEV1_DATA/InspeccionLigera/app-data/logs/sincronizacion.log | tail -5
```

---

## D · NAS — comprobar los archivos

```bash
# Las fotografías nuevas, con la jerarquía nueva
find /share/CACHEDEV1_DATA/InspeccionLigera/imagenes/proyecto_1 \
  -type f -newermt '-2 hours' | head -30

# Cuántas hay ahora en total (comparar con A4)
find /share/CACHEDEV1_DATA/InspeccionLigera/imagenes -type f | wc -l

# Que sean JPEG de verdad y del tamaño esperado
ls -la /share/CACHEDEV1_DATA/InspeccionLigera/imagenes/proyecto_1/linea_*/poste_*/ | head -30
```

- [ ] Aparecen 28 archivos nuevos bajo
      `imagenes/proyecto_N/linea_X/poste_M/`
- [ ] El total creció exactamente en 28 respecto a A4
- [ ] Cada archivo pesa entre ~0,5 y ~3 MB
- [ ] Los nombres son `tipo_<24 hex>.jpg`, sin rastro del nombre original

---

## E · Base de datos

```bash
docker exec -i -e MYSQL_PWD="$MARIADB_ROOT_PASSWORD" ecoing-db \
  mariadb -u root ecoing_inspeccion -e "
    SELECT id, codigo, formulario_subido, imagenes_subidas, sincronizado,
           fecha_inspeccion, fecha_subida
      FROM postes WHERE id = <POSTE_ID>;

    SELECT COUNT(*) AS fotos FROM imagenes_poste WHERE poste_id = <POSTE_ID>;

    SELECT nombre_foto, ruta_archivo, utm_este, utm_norte, zona, sincronizada
      FROM imagenes_poste WHERE poste_id = <POSTE_ID> ORDER BY nombre_foto;

    SELECT comentarios, distancia_acceso, tipo_torre
      FROM poste_datos WHERE poste_id = <POSTE_ID>;

    SELECT seccion, fase, atributo
      FROM poste_secciones_rst WHERE poste_id = <POSTE_ID> ORDER BY seccion, fase;"
```

- [ ] `postes`: `formulario_subido = 1`, `imagenes_subidas = 1`,
      `sincronizado = 1`
- [ ] `imagenes_poste`: exactamente **28** filas, ni una duplicada
- [ ] `ruta_archivo` es **relativa** (`proyecto_1/linea_.../poste_.../x.jpg`),
      nunca `/data/imagenes/...` ni `C:\...`
- [ ] `utm_este` / `utm_norte` / `zona` tienen valores reales
- [ ] `poste_datos`: aparece el comentario `PRUEBA E2E ...`
- [ ] `poste_secciones_rst`: las filas de las fases R/S/T

### E1 · Y que el PDF salga bien

```bash
TOKEN=$(curl -s -X POST -H 'Content-Type: application/json' \
  --data '{"nombre_usuario":"admin01","contrasena":"..."}' \
  http://192.168.1.3:8082/api/usuarios/login.php \
  | grep -o '"token":"[^"]*"' | cut -d'"' -f4)

curl -s -D - -o /tmp/e2e.pdf -H "Authorization: Bearer $TOKEN" \
  "http://192.168.1.3:8082/api/ver_pdf_poste.php?poste_id=<POSTE_ID>&proyecto_id=1" \
| grep -i 'x-ecoing-pdf-cache'
```

- [ ] La primera llamada da `miss`, la segunda `hit`
- [ ] **Abrir el PDF y mirarlo**: las 28 fotografías se ven, con su sello UTM y
      su fecha
- [ ] El texto de las placas se lee (es lo que valida que la compresión a
      2400 px no degradó el detalle técnico)

---

## F · Fallos, que también hay que probar

### F1 · Una fotografía falla y solo esa se reintenta

- [ ] Volver a capturar 28 fotografías en otra estructura, sin red
- [ ] Conectar al Wi-Fi y empezar a sincronizar
- [ ] **A mitad**, activar modo avión
- [ ] Las confirmadas quedan «Sincronizado»; el resto, «Error al enviar»
- [ ] Ninguna se perdió: el total sigue siendo 28
- [ ] Reconectar y pulsar «Sincronizar pendientes»
- [ ] **Solo se reenvían las fallidas** (verlo en `sincronizacion.log`: los
      lotes nuevos no repiten las ya confirmadas)
- [ ] En la base sigue habiendo **28** filas, sin duplicados

### F2 · Sesión vencida en campo

- [ ] Con trabajo pendiente, invalidar la sesión (esperar el vencimiento del
      JWT, o cambiar `JWT_SECRET` en el NAS y reiniciar `ecoing-app`)
- [ ] Intentar sincronizar
- [ ] La app lleva al login con el aviso de que el trabajo sigue guardado
- [ ] **Nada se borró**: el formulario y las fotografías siguen ahí
- [ ] Volver a iniciar sesión y sincronizar: termina correctamente

Si se cambió `JWT_SECRET` para esta prueba, **restaurarlo** después.

### F3 · Servidor con la base caída

```bash
docker compose stop ecoing-db
```

- [ ] La app dice **«El servidor de la oficina responde pero tiene un problema.
      Avisa a soporte...»**, no «sin conexión»
- [ ] No marca nada como sincronizado
- [ ] `curl http://192.168.1.3:8082/api/health.php` devuelve 503 con
      `"database":"error"`

```bash
docker compose start ecoing-db
```

- [ ] Tras ~30 s la app vuelve a «Servidor ECOING disponible» y sincroniza

---

## G · PRUEBA DE COPIA VISIBLE EN DESCARGAS

Esta prueba valida una copia **secundaria**. La fuente principal sigue siendo
`getApplicationDocumentsDirectory()` más SQLite y la sincronización continúa
leyendo únicamente de allí.

1. [ ] Instalar el APK de pruebas en un teléfono físico.
2. [ ] Conectarse e iniciar sesión.
3. [ ] Descargar las estructuras de una línea.
4. [ ] Salir offline.
5. [ ] Capturar la foto `Placa` en una estructura.
6. [ ] Abrir el explorador de archivos de Android.
7. [ ] Verificar la ruta:

   ```text
   Download/Inspeccion Ligera 2026/
     Proyecto_<ID>/
       Linea_<CODIGO>/
         Estructura_<CODIGO>/
           Fotos/02_Placa.jpg
   ```

8. [ ] Repetir la foto `Placa` con una imagen distinta.
9. [ ] Confirmar que queda **un solo** `02_Placa.jpg`, con el contenido nuevo;
       no debe existir `02_Placa (1).jpg` ni otro duplicado.
10. [ ] Finalizar/guardar la inspección.
11. [ ] Abrir `Formulario/formulario.json`; confirmar que contiene el borrador
        local, RST, estado y metadatos de fotos, pero no token, URL NAS ni rutas
        `/data/user` o `app_flutter`.
12. [ ] Abrir `Resumen/inspeccion.txt`; la ausencia de vistas debe figurar como
        omitida/no registrada, nunca como pérdida de la inspección.
13. [ ] Volver al Wi-Fi de la oficina.
14. [ ] Sincronizar.
15. [ ] Confirmar que JSON y TXT cambian a `Sincronizada` sin modificar la fecha
        ni el contenido de los archivos de `Fotos/`.
16. [ ] Borrar manualmente la carpeta pública de esta estructura.
17. [ ] Confirmar que el formulario, miniaturas, fotos privadas y sincronización
        de la app siguen funcionando.
18. [ ] En la pantalla de fotos pulsar **Actualizar copia**.
19. [ ] Confirmar que se reconstruyen `Fotos/`, `Formulario/` y `Resumen/` y que
        una segunda actualización deja exactamente los mismos archivos.

### Diagnóstico (solo `ENTORNO=pruebas` o debug)

En **Ajustes → Diagnóstico de almacenamiento público**:

- [ ] La pantalla muestra SDK, método, permiso, espacio, ruta relativa y último
      resultado; no muestra rutas privadas ni secretos.
- [ ] **Crear prueba** crea
      `Download/Inspeccion Ligera 2026/Diagnostico/prueba.txt` con
      `ECOING prueba 1`.
- [ ] **Reemplazar prueba** cambia el contenido a `ECOING prueba 2` y muestra
      `Duplicados: 0`.
- [ ] **Eliminar prueba** retira el archivo y muestra `Eliminar: OK`.
- [ ] Repetir a 320×568, 360×640 y 393×852, con tamaño de texto 1.0, 1.6 y
      2.0; no debe aparecer overflow.

### Matriz por versión Android

| Android / API | Prueba requerida | Resultado |
|---|---|---|
| Android 7-9 / 24-28 | Solicita almacenamiento; conceder exporta; rechazar conserva privado; “no volver a preguntar” ofrece **Abrir ajustes**; reintentar posteriormente funciona | |
| Android 10 / 29 | No solicita almacenamiento; usa MediaStore; `RELATIVE_PATH` empieza en `Download/` y no contiene una ruta absoluta | |
| Android 11-12 / 30-32 | Scoped storage, sin permisos amplios, crea/reemplaza/elimina | |
| Android 13-14 / 33-34 | No solicita `READ_MEDIA_IMAGES` ni `WRITE_EXTERNAL_STORAGE`; MediaStore funciona | |
| Android 15-16 / 35-36 | MediaStore funciona con target actual y el diagnóstico termina en OK | |

### Persistencia real

| Acción | Privado + SQLite | Descargas |
|---|---|---|
| Cerrar o reiniciar | Permanece | Permanece |
| Borrar caché | Permanece | Permanece |
| Borrar datos de la app | Se pierde | Permanece |
| Desinstalar | Se pierde | Permanece |

Después de borrar datos o reinstalar, la app **no reconstruye SQLite desde
Descargas**. La carpeta visible es respaldo manual, no origen de datos; una
importación futura tendría que ser una funcionalidad independiente.

## H · Cierre

| Métrica | Objetivo | Medido |
|---|---|---|
| Tiempo de una estructura completa (formulario + 28 fotos) | < 40 s | |
| Fotografías reprocesadas por el servidor | 0 | |
| Reintentos en una sincronización limpia | 0 | |
| Peso medio por fotografía | 1–2,5 MB | |
| Respuesta de `health.php` | < 300 ms | |

- [ ] Todas las casillas anteriores marcadas
- [ ] Los tiempos están dentro de los objetivos
- [ ] Ninguna inspección se perdió en ninguna de las pruebas de fallo

Fecha: ____________  Responsable: ____________  Dispositivo: ____________

---

## Cuando la prueba pase: fijar el NAS como producción

Solo entonces, y es un cambio de **una línea**:

`pruebaoffline/lib/core/entorno.dart`

```dart
static const String apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://192.168.1.3:8082/api',   // antes: hosting
);
```

Y compilar el APK de los inspectores:

```powershell
flutter build apk --release --dart-define=ENTORNO=produccion
```

Mientras ese `defaultValue` no se toque, un `flutter build apk` hecho por
descuido seguirá apuntando al hosting anterior y no mandará a los inspectores a
un servidor en pruebas.
