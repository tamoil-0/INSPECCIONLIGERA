#!/usr/bin/env bash
# ECOING - Inspección Ligera · arranque de `ecoing-app`
#
# Qué hace, en orden:
#   1. Remapea el UID/GID de www-data si se piden PUID/PGID (patrón habitual en
#      NAS: las carpetas compartidas del QNAP pertenecen a `admin`, no a uid 33).
#   2. Crea las carpetas persistentes que declara el .env y comprueba que se
#      pueda escribir en ellas.
#   3. Espera —de forma acotada— a que MariaDB acepte conexiones.
#   4. Entrega el control a Apache.
#
# Nunca aborta por no poder ajustar permisos: informa y sigue. Un contenedor que
# no arranca es más difícil de diagnosticar que uno que arranca quejándose.

set -euo pipefail

log()  { printf '[ecoing] %s\n' "$*"; }
warn() { printf '[ecoing] AVISO: %s\n' "$*" >&2; }

# ---------------------------------------------------------------------------
# 1. Identidad del proceso web
# ---------------------------------------------------------------------------
PUID="${PUID:-}"
PGID="${PGID:-}"

if [ -n "$PGID" ] && [ "$PGID" != "$(id -g www-data)" ]; then
    log "Reasignando el grupo www-data a GID $PGID"
    groupmod -o -g "$PGID" www-data || warn "no se pudo cambiar el GID de www-data"
fi
if [ -n "$PUID" ] && [ "$PUID" != "$(id -u www-data)" ]; then
    log "Reasignando el usuario www-data a UID $PUID"
    usermod -o -u "$PUID" www-data || warn "no se pudo cambiar el UID de www-data"
fi

# ---------------------------------------------------------------------------
# 2. Almacenamiento persistente
# ---------------------------------------------------------------------------
IMAGE_STORAGE_PATH="${IMAGE_STORAGE_PATH:-/data/imagenes}"
PDF_STORAGE_PATH="${PDF_STORAGE_PATH:-/data/pdf}"
BACKUP_STORAGE_PATH="${BACKUP_STORAGE_PATH:-/data/backups}"
LOG_STORAGE_PATH="${LOG_STORAGE_PATH:-/data/logs}"
EXPORT_STORAGE_PATH="${EXPORT_STORAGE_PATH:-/data/exports}"
REPORT_CONFIG_STORAGE_PATH="${REPORT_CONFIG_STORAGE_PATH:-/data/report_config}"

for directorio in \
    "$IMAGE_STORAGE_PATH" \
    "$PDF_STORAGE_PATH" \
    "$BACKUP_STORAGE_PATH" \
    "$BACKUP_STORAGE_PATH/db" \
    "$LOG_STORAGE_PATH" \
    "$EXPORT_STORAGE_PATH" \
    "$REPORT_CONFIG_STORAGE_PATH"
do
    if [ ! -d "$directorio" ]; then
        mkdir -p "$directorio" 2>/dev/null || warn "no se pudo crear $directorio"
    fi
    # En un bind mount del QNAP el dueño es el del share. Se intenta ajustar
    # solo el directorio raíz (no -R: un recursivo sobre decenas de miles de
    # fotografías retrasaría el arranque varios minutos).
    chown www-data:www-data "$directorio" 2>/dev/null || true
    chmod 0775 "$directorio" 2>/dev/null || true

    if ! su -s /bin/sh -c "test -w '$directorio'" www-data; then
        warn "www-data NO puede escribir en $directorio"
        warn "  Solución en el QNAP: ajustar el propietario del share o pasar"
        warn "  PUID/PGID con el usuario dueño de /share/.../InspeccionLigera."
    fi
done

log "Almacenamiento: imagenes=$IMAGE_STORAGE_PATH pdf=$PDF_STORAGE_PATH logs=$LOG_STORAGE_PATH"

# ---------------------------------------------------------------------------
# 3. Espera acotada a MariaDB
# ---------------------------------------------------------------------------
DB_HOST="${DB_HOST:-ecoing-db}"
DB_PORT="${DB_PORT:-3306}"
DB_WAIT_SECONDS="${DB_WAIT_SECONDS:-60}"

if [ "$DB_WAIT_SECONDS" -gt 0 ] 2>/dev/null; then
    log "Esperando a $DB_HOST:$DB_PORT (máximo ${DB_WAIT_SECONDS}s)"
    esperado=0
    while [ "$esperado" -lt "$DB_WAIT_SECONDS" ]; do
        if (exec 3<>"/dev/tcp/$DB_HOST/$DB_PORT") 2>/dev/null; then
            log "MariaDB responde en $DB_HOST:$DB_PORT"
            break
        fi
        sleep 2
        esperado=$((esperado + 2))
    done
    if [ "$esperado" -ge "$DB_WAIT_SECONDS" ]; then
        # No es fatal: Apache arranca igual y /api/health.php reportará
        # "database": "error" para que se vea desde la LAN qué falta.
        warn "MariaDB no respondió en ${DB_WAIT_SECONDS}s; se arranca Apache de todos modos"
    fi
fi

# ---------------------------------------------------------------------------
# 4. Apache
# ---------------------------------------------------------------------------
log "Iniciando $*"
exec "$@"
