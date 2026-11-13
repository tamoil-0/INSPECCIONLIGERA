# `database/init/` — inicialización de `ecoing-db`

## Regla que gobierna esta carpeta

Todo `.sql` que esté aquí lo ejecuta MariaDB **una sola vez**: cuando el
contenedor arranca y su directorio de datos está **vacío**. En cuanto la base
existe, la carpeta se ignora en cada arranque posterior.

Consecuencia práctica:

- Sirve para dejar lista una instalación **nueva**.
- **No** sirve para migraciones ni para “refrescar” datos. Nada de lo que se
  añada aquí se aplicará a la base que ya está en producción.
- Nunca debe contener `DROP DATABASE`, `CREATE DATABASE`, `USE` ni un dump de
  producción. El montaje es de solo lectura (`:ro`) en `docker-compose.yml`,
  pero la protección real es no poner ahí nada destructivo.

## Contenido

| Archivo | Qué hace |
|---|---|
| `01-esquema.sql` | Crea las 7 tablas del sistema, vacías. Espejo de `database/schema.sql` sin las sentencias de creación de la base. |

La base y el usuario los crea el propio entrypoint de MariaDB a partir de
`MARIADB_DATABASE`, `MARIADB_USER` y `MARIADB_PASSWORD` de `docker/.env`.

## Cargar datos reales

Se hace **a mano y una sola vez**, con el contenedor ya en marcha:

```bash
# Padrón base de estructuras (sin inspecciones previas)
./scripts/import-dump.sh database/ecoing_inspeccion_con_estructuras.sql

# O un dump real de producción
./scripts/import-dump.sh /share/CACHEDEV1_DATA/InspeccionLigera/backups/db/ecoing_inspeccion_2026-08-20_101500.sql.gz
```

El procedimiento completo, con las verificaciones previas y posteriores, está en
[`docs/MIGRACION_XAMPP_A_QNAP.md`](../../docs/MIGRACION_XAMPP_A_QNAP.md).

## Si el esquema cambia en el futuro

Los cambios sobre una instalación existente van en `database/migrations/` como
archivos con fecha (ver `20260818_add_distancias_dms.sql`) y se aplican
explícitamente:

```bash
docker exec -i ecoing-db mariadb -u root -p"$MARIADB_ROOT_PASSWORD" ecoing_inspeccion \
  < database/migrations/2026XXXX_lo_que_sea.sql
```

Y además se refleja en `database/schema.sql` y en `database/init/01-esquema.sql`,
para que una instalación nueva nazca ya con el cambio.
