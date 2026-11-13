#!/usr/bin/env bash
# ECOING - Inspección Ligera · respaldo de la base de datos
# =========================================================
#
# Genera un dump comprimido de `ecoing_inspeccion` en:
#
#     <BACKUP_DIR>/ecoing_inspeccion_AAAA-MM-DD_HHMMSS.sql.gz
#
# Se ejecuta EN EL NAS (o en el PC de desarrollo) y habla con el contenedor
# `ecoing-db` por `docker exec`. No hace falta instalar el cliente de MariaDB en
# el host ni publicar el puerto 3306 a la LAN.
#
# USO
#   ./scripts/backup-db.sh
#   ./scripts/backup-db.sh --env-file /ruta/docker/.env
#   ./scripts/backup-db.sh --output /share/CACHEDEV1_DATA/InspeccionLigera/backups/db
#   ./scripts/backup-db.sh --verify-only   # solo comprueba el último respaldo
#
# GARANTÍAS
#   1. Se comprueba el código de salida de mysqldump ANTES de dar nada por
#      bueno. Un dump que falla a la mitad devuelve != 0 y aquí se aborta.
#   2. Se comprime con gzip y se comprueba la integridad con `gzip -t`.
#   3. Se verifica que el archivo exista, pese más de 0 y contenga el marcador
#      de final de volcado que escribe mysqldump.
#   4. El resultado se registra en <BACKUP_DIR>/backup.log.
#   5. NO se borra ningún respaldo. La rotación se hace a mano o con una
#      política explícita; ver el final de este archivo.
#
# CÓDIGOS DE SALIDA
#   0 respaldo correcto y verificado
#   1 error de uso o de configuración
#   2 el contenedor de la base no está disponible
#   3 mysqldump falló
#   4 el archivo resultante no pasó la verificación

set -uo pipefail

# ---------------------------------------------------------------------------
# Parámetros
# ---------------------------------------------------------------------------
RAIZ="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$RAIZ/docker/.env"
CONTENEDOR="${ECOING_DB_CONTAINER:-ecoing-db}"
SALIDA=""
SOLO_VERIFICAR=0

while [ $# -gt 0 ]; do
    case "$1" in
        --env-file)   ENV_FILE="${2:-}"; shift 2 ;;
        --output|-o)  SALIDA="${2:-}"; shift 2 ;;
        --container)  CONTENEDOR="${2:-}"; shift 2 ;;
        --verify-only) SOLO_VERIFICAR=1; shift ;;
        -h|--help)
            sed -n '2,40p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *)
            printf 'Argumento no reconocido: %s\n' "$1" >&2
            exit 1 ;;
    esac
done

# ---------------------------------------------------------------------------
# Configuración
# ---------------------------------------------------------------------------
if [ ! -f "$ENV_FILE" ]; then
    printf 'No existe el archivo de entorno: %s\n' "$ENV_FILE" >&2
    printf 'Copie docker/.env.example a docker/.env, o use --env-file.\n' >&2
    exit 1
fi

# Se leen solo las claves necesarias, sin ejecutar el archivo: así una línea con
# comillas raras no puede convertirse en un comando.
leer_env() {
    local clave="$1"
    sed -n "s/^[[:space:]]*${clave}[[:space:]]*=[[:space:]]*//p" "$ENV_FILE" \
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

if [ -z "$SALIDA" ]; then
    RAIZ_DATOS="${ECOING_DATA_ROOT:-/share/CACHEDEV1_DATA/InspeccionLigera}"
    SALIDA="$RAIZ_DATOS/backups/db"
fi
REGISTRO="$SALIDA/backup.log"

registrar() {
    local linea
    linea="$(date '+%Y-%m-%d %H:%M:%S') $*"
    printf '%s\n' "$linea"
    mkdir -p "$SALIDA" 2>/dev/null || true
    printf '%s\n' "$linea" >> "$REGISTRO" 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# Verificación de un archivo de respaldo
# ---------------------------------------------------------------------------
verificar() {
    local archivo="$1"

    if [ ! -f "$archivo" ]; then
        registrar "ERROR verificación: no existe $archivo"
        return 1
    fi

    local bytes
    bytes="$(wc -c < "$archivo" | tr -d '[:space:]')"
    if [ "${bytes:-0}" -le 0 ]; then
        registrar "ERROR verificación: $archivo pesa 0 bytes"
        return 1
    fi
    # Un dump real de este sistema (7 tablas y el padrón) nunca baja de 1 KB
    # comprimido. Por debajo de eso es un archivo de error, no un respaldo.
    if [ "$bytes" -lt 1024 ]; then
        registrar "ERROR verificación: $archivo solo pesa $bytes bytes"
        return 1
    fi

    if ! gzip -t "$archivo" 2>/dev/null; then
        registrar "ERROR verificación: gzip corrupto en $archivo"
        return 1
    fi

    # mysqldump cierra el volcado con esta línea. Si falta, el dump se cortó.
    if ! gzip -dc "$archivo" | tail -c 2048 | grep -q 'Dump completed'; then
        registrar "ERROR verificación: falta el marcador de fin de volcado en $archivo"
        return 1
    fi

    registrar "OK verificación: $archivo ($bytes bytes)"
    return 0
}

if [ "$SOLO_VERIFICAR" -eq 1 ]; then
    ULTIMO="$(ls -1t "$SALIDA"/ecoing_inspeccion_*.sql.gz 2>/dev/null | head -n 1)"
    if [ -z "$ULTIMO" ]; then
        registrar "ERROR: no hay ningún respaldo en $SALIDA"
        exit 4
    fi
    verificar "$ULTIMO" || exit 4
    exit 0
fi

# ---------------------------------------------------------------------------
# 1. ¿Está la base disponible?
# ---------------------------------------------------------------------------
if ! command -v docker > /dev/null 2>&1; then
    registrar "ERROR: no se encontró el comando docker"
    exit 2
fi

if ! docker inspect --format '{{.State.Running}}' "$CONTENEDOR" 2>/dev/null | grep -q true; then
    registrar "ERROR: el contenedor $CONTENEDOR no está en ejecución"
    exit 2
fi

mkdir -p "$SALIDA" || { registrar "ERROR: no se pudo crear $SALIDA"; exit 1; }

MARCA="$(date '+%Y-%m-%d_%H%M%S')"
DESTINO="$SALIDA/${BD}_${MARCA}.sql.gz"
PARCIAL="$DESTINO.parcial"

registrar "Iniciando respaldo de '$BD' desde $CONTENEDOR hacia $DESTINO"

# ---------------------------------------------------------------------------
# 2 y 3. Volcado + compresión, comprobando el código de salida de mysqldump
# ---------------------------------------------------------------------------
# Se escribe primero en `.parcial` y solo se renombra al final: un fallo a mitad
# nunca deja un archivo con nombre definitivo que parezca un respaldo válido.
#
# --single-transaction: volcado consistente sin bloquear las escrituras de la
#   app. Todas las tablas son InnoDB, así que es aplicable.
# --routines --events --triggers: por si en el futuro se añaden.
# MYSQL_PWD por variable de entorno del proceso remoto y no en la línea de
#   comandos, para que la contraseña no aparezca en `ps` ni en los logs.
set -o pipefail
docker exec -e MYSQL_PWD="$CLAVE_ROOT" "$CONTENEDOR" \
    mariadb-dump \
        --user=root \
        --single-transaction \
        --quick \
        --routines \
        --events \
        --triggers \
        --default-character-set=utf8mb4 \
        --databases "$BD" \
    2> "$SALIDA/.ultimo_error_dump.txt" \
    | gzip -6 > "$PARCIAL"
ESTADO=("${PIPESTATUS[@]}")

if [ "${ESTADO[0]}" -ne 0 ]; then
    registrar "ERROR: mariadb-dump terminó con código ${ESTADO[0]}"
    if [ -s "$SALIDA/.ultimo_error_dump.txt" ]; then
        registrar "Detalle: $(tr '\n' ' ' < "$SALIDA/.ultimo_error_dump.txt" | cut -c1-300)"
    fi
    rm -f "$PARCIAL"
    exit 3
fi
if [ "${ESTADO[1]}" -ne 0 ]; then
    registrar "ERROR: gzip terminó con código ${ESTADO[1]}"
    rm -f "$PARCIAL"
    exit 3
fi

# ---------------------------------------------------------------------------
# 4. Verificación antes de darlo por bueno
# ---------------------------------------------------------------------------
if ! verificar "$PARCIAL"; then
    registrar "ERROR: el respaldo no pasó la verificación; se descarta"
    mv -f "$PARCIAL" "$PARCIAL.rechazado" 2>/dev/null || rm -f "$PARCIAL"
    exit 4
fi

mv -f "$PARCIAL" "$DESTINO" || { registrar "ERROR: no se pudo renombrar a $DESTINO"; exit 4; }
chmod 0640 "$DESTINO" 2>/dev/null || true
rm -f "$SALIDA/.ultimo_error_dump.txt"

TAMANO="$(du -h "$DESTINO" 2>/dev/null | cut -f1)"
registrar "RESPALDO CORRECTO: $DESTINO ($TAMANO)"

# ---------------------------------------------------------------------------
# 5. Rotación: NO se hace aquí, a propósito
# ---------------------------------------------------------------------------
# Este script nunca borra un respaldo. Un `find -mtime +30 -delete` automático es
# la forma más rápida de descubrir, el día que hace falta, que el respaldo bueno
# se eliminó junto con los malos.
#
# Cuando se decida una política, se escribe aparte y se revisa antes de
# programarla. Para ver qué se borraría, sin borrar nada:
#
#   find "$SALIDA" -name 'ecoing_inspeccion_*.sql.gz' -mtime +60 -print
#
exit 0
