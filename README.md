# ECOING — Inspección ligera

Aplicación local para administrar proyectos, postes, formularios de inspección, imágenes y reportes de líneas de transmisión.

La configuración completa del cliente de campo está en [docs/CONEXION_Y_SINCRONIZACION_MOVIL.md](docs/CONEXION_Y_SINCRONIZACION_MOVIL.md).

## Estado local

- URL web: `http://localhost/INSPEECIONLIGERAECOING/web/`
- API: se calcula automáticamente desde la URL de la web.
- Base de datos: `ecoing_inspeccion`, cargada en MariaDB de XAMPP con el padrón base de 1,235 estructuras y sin inspecciones anteriores.
- PHP requerido: 8.2 con `pdo_mysql`, `mbstring`, `gd`, `zip`, `fileinfo` y extensiones XML.

## Primera ejecución

1. Inicie Apache y MySQL desde XAMPP.
2. Abra la URL web indicada arriba.
3. Entre en **Configurar el primer administrador** y cree la cuenta inicial.
4. Inicie sesión y cree el primer proyecto.

La creación inicial de administrador solo funciona desde `127.0.0.1`/`::1` y únicamente cuando la tabla `usuarios` está vacía. Los siguientes usuarios se crean mediante `api/usuarios/register.php` con un token de administrador.

## Instalación completa con el padrón de estructuras

El archivo local recomendado para comenzar las pruebas es `database/ecoing_inspeccion_con_estructuras.sql`. Elimina y recrea exclusivamente la base `ecoing_inspeccion`, carga el proyecto real y sus 1,235 estructuras, pero deja vacíos usuarios, formularios, comentarios, imágenes y resultados de inspecciones anteriores.

Este archivo permanece solo en el equipo local y está ignorado por Git mientras el repositorio sea público, porque contiene coordenadas UTM reales. Puede versionarse cuando el repositorio se cambie a privado.

En phpMyAdmin seleccione **Importar** y elija ese archivo. También puede importarlo desde PowerShell:

```powershell
Get-Content -LiteralPath database\ecoing_inspeccion_con_estructuras.sql -Raw |
  C:\xampp82\mysql\bin\mysql.exe --host=127.0.0.1 --user=root --default-character-set=utf8mb4
```

Después de importarlo, cree el primer administrador desde la web. El proyecto y las estructuras ya aparecerán disponibles para las búsquedas del cliente móvil.

## Reinstalar el esquema vacío

El archivo [database/schema.sql](database/schema.sql) elimina y recrea exclusivamente la base `ecoing_inspeccion`. En PowerShell, desde la raíz del proyecto:

```powershell
Get-Content -LiteralPath database\schema.sql -Raw |
  C:\xampp82\mysql\bin\mysql.exe --host=127.0.0.1 --user=root --default-character-set=utf8mb4
```

No contiene usuarios, proyectos, postes ni imágenes de muestra.

## Configuración central

La configuración se lee desde `.env`. El archivo local ya apunta al MariaDB de XAMPP; `.env.example` documenta todas las variables:

- `APP_URL` y zona horaria.
- Orígenes CORS permitidos.
- Host, puerto, base y credenciales de MariaDB.
- Clave y duración de JWT.
- Nombres/CIP opcionales para firmas del PDF.

No coloque credenciales de producción en el repositorio. Genere un `JWT_SECRET` distinto para cada instalación productiva.

## Dependencias

Todas las librerías PHP están centralizadas en `api/vendor`:

```powershell
Set-Location api
composer install --no-dev --optimize-autoloader
composer check-platform-reqs
composer audit --locked
```

Se usan `firebase/php-jwt`, `phpoffice/phpspreadsheet` y `setasign/fpdf` desde un único `composer.json`/`composer.lock`.

## Rutas principales para el cliente móvil

Todas las rutas protegidas reciben `Authorization: Bearer <token>`; los tokens no se aceptan en la URL.

- `POST api/usuarios/login.php`
- `GET|POST api/proyectos/*`
- `GET|POST api/postes/crear.php`, `listar.php`, `buscar_por_linea.php`
- `PUT api/postes/actualiza-datos.php?poste_id=ID`
- `POST api/postes/agregar-seccion-rst.php?poste_id=ID`
- `POST api/postes/imagenes-poste.php?poste_id=ID`
- `GET api/postes/sincronizacion_estado.php?poste_id=ID`
- `GET api/ver_pdf_poste.php?poste_id=ID&proyecto_id=ID`
- `GET api/exportar_excel_linea.php?proyecto_id=ID&linea=LINEA`
- `GET api/exportar_linea_pdf.php?proyecto_id=ID&linea=LINEA`

Las imágenes se verifican por MIME/contenido, se redimensionan, se normalizan a JPEG y se guardan en `storage/images`. El indicador `imagenes_subidas` solo pasa a verdadero cuando existen los 28 tipos requeridos.

## Respaldo

El estado recibido antes del saneamiento está preservado en el repositorio Git **local** de este equipo:

```text
commit: d4cc36a
tag:    backup-antes-saneamiento-20260818
```

Ese historial original no se publica en GitHub porque contiene exportaciones de más de 100 MB y datos históricos. La rama remota `main` parte de un commit limpio con el sistema saneado.
