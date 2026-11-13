#!/usr/bin/env bash
# ECOING - Inspección Ligera · importar un dump a `ecoing-db`
# ===========================================================
#
# Importa un `.sql` o `.sql.gz` dentro del contenedor de MariaDB.
#
# USO
#   ./scripts/import-dump.sh database/ecoing_inspeccion_con_estructuras.sql
#   ./scripts/import-dump.sh /ruta/respaldo.sql.gz
#   ./scripts/import-dump.sh --force respaldo.sql.gz      # sin preguntar
#
# POR QUÉ EXISTE ESTE SCRIPT Y NO SE USA database/init/
#   `database/init/` solo se ejecuta cuando el directorio de datos está vacío, y
#   además volcaría producción en cada instalación nueva. La importación de datos
#   reales es una operación puntual, consciente y verificada: eso es esto.
#
# SEGURIDAD
#   · Antes de importar informa de cuántas filas hay YA en la base y pide
#     confirmación escrita si no está vacía. Importar sobre datos existentes es
#     la forma habitual de perder una jornada de inspecciones.
#   · Si el dump trae `DROP DATABASE` o `CREATE DATABASE`, avisa: esas sentencias
#     recrean la base entera y descartan lo que hubiera.
#   · No borra nada por su cuenta.
#
# CÓDIGOS DE SALIDA
#   0 importación correcta
#   1 error de uso o de configuración
#   2 el contenedor de la base no está disponible
#   3 el usuario canceló
#   4 la importación falló

set -uo pipefail

RAIZ="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$RAIZ/docker/.env"
CONTENEDOR="${ECOING_DB_CONTAINER:-ecoing-db}"
FORZAR=0
ARCHIVO=""

while [ $# -gt 0 ]; do
    case "$1" in
        --env-file)  ENV_FILE="${2:-}"; shift 2 ;;
        --container) CONTENEDOR="${2:-}"; shift 2 ;;
        --force|-f)  FORZAR=1; shift ;;
        -h|--help)   sed -n '2,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)           ARCHIVO="$1"; shift ;;
    esac
done

if [ -z "$ARCHIVO" ]; then
    printf 'Indique el archivo .sql o .sql.gz a importar.\n' >&2
    exit 1
fi
if [ ! -f "$ARCHIVO" ]; then
    printf 'No existe el archivo: %s\n' "$ARCHIVO" >&2
    exit 1
fi
if [ ! -f "$ENV_FILE" ]; then
    printf 'No existe el archivo de entorno: %s\n' "$ENV_FILE" >&2
    exit 1
fi

leer_env() {
    sed -n "s/^[[:space:]]*${1}[[:space:]]*=[[:space:]]*//p" "$ENV_FILE" \
        | tail -n 1 \
        | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//" -e 's/[[:space:]]*$//'
}

BD="$(leer_env DB_DATABASE)"
[ -n "$BD" ] || BD="$(leer_env MARIADB_DATABASE)"
[ -n "$BD" ] || BD="ecoing_inspeccion"
CLAVE_ROOT="$(leer_env MARIADB_ROOT_PASSWORD)"
if [ -z "$CLAVE_ROOT" ]; then
    printf 'MARIADB_ROOT_PASSWORD no está definida en %s\n' "$ENV_FILE" >&2
    exit 1
fi

if ! docker inspect --format '{{.State.Running}}' "$CONTENEDOR" 2>/dev/null | grep -q true; then
    printf 'El contenedor %s no está en ejecución.\n' "$CONTENEDOR" >&2
    exit 2
fi

ejecutar_sql() {
    docker exec -i -e MYSQL_PWD="$CLAVE_ROOT" "$CONTENEDOR" \
        mariadb --user=root --default-character-set=utf8mb4 --batch --skip-column-names "$@"
}

# ---------------------------------------------------------------------------
# Estado actual de la base
# ---------------------------------------------------------------------------
printf '\n=== Estado ANTES de importar ===\n'
EXISTE="$(ejecutar_sql -e "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$BD'" 2>/dev/null | tr -d '[:space:]')"
if [ "${EXISTE:-0}" = "0" ]; then
    printf 'La base "%s" todavía no existe. Se creará.\n' "$BD"
    ejecutar_sql -e "CREATE DATABASE IF NOT EXISTS \`$BD\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" || {
        printf 'No se pudo crear la base.\n' >&2; exit 4; }
    TOTAL=0
else
    RESUMEN="$(ejecutar_sql "$BD" -e "
        SELECT 'usuarios', COUNT(*) FROM usuarios
        UNION ALL SELECT 'proyectos', COUNT(*) FROM proyectos
        UNION ALL SELECT 'postes', COUNT(*) FROM postes
        UNION ALL SELECT 'poste_datos', COUNT(*) FROM poste_datos
        UNION ALL SELECT 'imagenes_poste', COUNT(*) FROM imagenes_poste" 2>/dev/null)"
    if [ -n "$RESUMEN" ]; then
        printf '%s\n' "$RESUMEN" | awk '{ printf "  %-16s %s\n", $1, $2 }'
        TOTAL="$(printf '%s\n' "$RESUMEN" | awk '{ s += $2 } END { print s+0 }')"
    else
        printf '  (la base existe pero aún no tiene las tablas del sistema)\n'
        TOTAL=0
    fi
fi

# ---------------------------------------------------------------------------
# Advertencias sobre el contenido del dump
# ---------------------------------------------------------------------------
leer_dump() {
    case "$ARCHIVO" in
        *.gz) gzip -dc "$ARCHIVO" ;;
        *)    cat "$ARCHIVO" ;;
    esac
}

printf '\n=== Archivo a importar ===\n'
printf '  %s (%s)\n' "$ARCHIVO" "$(du -h "$ARCHIVO" | cut -f1)"
if leer_dump | head -n 400 | grep -qiE '^[[:space:]]*(DROP[[:space:]]+DATABASE|CREATE[[:space:]]+DATABASE)'; then
    printf '  AVISO: el dump contiene DROP/CREATE DATABASE. Recreará la base completa.\n'
fi

# ---------------------------------------------------------------------------
# Confirmación
# ---------------------------------------------------------------------------
if [ "${TOTAL:-0}" -gt 0 ] && [ "$FORZAR" -eq 0 ]; then
    printf '\nLa base ya contiene %s filas en las tablas principales.\n' "$TOTAL"
    printf 'Importar puede sobrescribirlas o duplicarlas.\n'
    printf 'Escriba exactamente IMPORTAR para continuar: '
    read -r RESPUESTA
    if [ "$RESPUESTA" != "IMPORTAR" ]; then
        printf 'Cancelado. No se tocó nada.\n'
        exit 3
    fi
fi

# ---------------------------------------------------------------------------
# Importación
# ---------------------------------------------------------------------------
printf '\nImportando...\n'
INICIO="$(date +%s)"
set -o pipefail
leer_dump | docker exec -i -e MYSQL_PWD="$CLAVE_ROOT" "$CONTENEDOR" \
    mariadb --user=root --default-character-set=utf8mb4 "$BD"
ESTADO=$?

if [ "$ESTADO" -ne 0 ]; then
    printf 'La importación falló (código %s). La base puede haber quedado a medias:\n' "$ESTADO" >&2
    printf 'revise el estado antes de reintentar.\n' >&2
    exit 4
fi

printf 'Importación terminada en %s segundos.\n' "$(( $(date +%s) - INICIO ))"

printf '\n=== Estado DESPUÉS de importar ===\n'
ejecutar_sql "$BD" -e "
    SELECT 'usuarios', COUNT(*) FROM usuarios
    UNION ALL SELECT 'proyectos', COUNT(*) FROM proyectos
    UNION ALL SELECT 'postes', COUNT(*) FROM postes
    UNION ALL SELECT 'poste_datos', COUNT(*) FROM poste_datos
    UNION ALL SELECT 'poste_secciones_rst', COUNT(*) FROM poste_secciones_rst
    UNION ALL SELECT 'imagenes_poste', COUNT(*) FROM imagenes_poste" \
    | awk '{ printf "  %-22s %s\n", $1, $2 }'

printf '\nSiguiente paso: comprobar el servicio con\n'
printf '  curl -s http://127.0.0.1:8081/api/health.php\n\n'
exit 0
