#!/bin/bash
# Instala en el NAS la actualizacion "Plantillas de inspeccion" (15-oct-2026).
# Lo ejecuta subir_plantillas_nas.ps1 por SSH. Se puede volver a ejecutar.
#
# No supone rutas: las toma del contenedor ecoing-app que ya esta corriendo
# (carpeta del repo, archivo compose, carpeta de datos, puerto y usuario).
#
#   1. respaldo de la base (mariadb-dump comprimido)
#   2. copia de los archivos que se van a reemplazar (para volver atras)
#   3. descomprime el codigo nuevo sobre el repo
#   4. migracion SQL (solo AGREGA tablas y una columna; no borra nada)
#   5. reconstruye y reinicia ecoing-app
#   6. comprueba health.php
set -euo pipefail

paso()  { printf '\n\033[1;34m== %s\033[0m\n' "$*"; }
falla() { printf '\n\033[1;31mERROR: %s\033[0m\n' "$*" >&2; exit 1; }

AQUI="$(cd "$(dirname "$0")" && pwd)"
PAQUETE="$AQUI/release_plantillas_20261015.tar.gz"
SELLO=$(date +%Y%m%d_%H%M)
[ -f "$PAQUETE" ] || falla "No encuentro $PAQUETE"

# Docker de Container Station no siempre esta en el PATH de una sesion SSH.
export PATH="$PATH:/share/CACHEDEV1_DATA/.qpkg/container-station/bin:/usr/local/bin"
command -v docker >/dev/null || falla "No encuentro el comando docker en el NAS."
docker inspect ecoing-app >/dev/null 2>&1 \
  || falla "No puedo ver el contenedor ecoing-app. ¿Esta corriendo? ¿Este usuario ($(id -un)) puede usar docker?"
if docker compose version >/dev/null 2>&1; then DC="docker compose"; else DC="docker-compose"; fi

paso "0/6 Detectando la instalacion actual"
etiqueta() { docker inspect ecoing-app --format "{{ index .Config.Labels \"$1\" }}" 2>/dev/null || true; }
montaje()  { docker inspect ecoing-app --format "{{range .Mounts}}{{if eq .Destination \"$1\"}}{{.Source}}{{end}}{{end}}"; }
REPO=$(etiqueta com.docker.compose.project.working_dir)
PROYECTO=$(etiqueta com.docker.compose.project)
CONFIG=$(etiqueta com.docker.compose.project.config_files | cut -d, -f1)
if [ -z "$REPO" ] || [ ! -d "$REPO" ]; then
  CONFIG=$(grep -l "ecoing-app" $(find /share/CACHEDEV1_DATA -maxdepth 6 -name 'docker-compose.yml' 2>/dev/null) 2>/dev/null | head -1 || true)
  [ -n "$CONFIG" ] || falla "No encuentro la carpeta del proyecto (docker-compose.yml con ecoing-app)."
  REPO=$(dirname "$CONFIG")
fi
[ -n "$CONFIG" ] && [ -f "$CONFIG" ] || CONFIG="$REPO/docker-compose.yml"
[ -n "$PROYECTO" ] || PROYECTO=$(basename "$REPO")
IMAGENES=$(montaje /data/imagenes)
[ -n "$IMAGENES" ] || falla "No pude leer la carpeta de datos del contenedor."
DATA=$(dirname "$IMAGENES")
ELPU=$(montaje /data/elpu)
PUERTO=$(docker port ecoing-app 80/tcp 2>/dev/null | head -1 | sed 's/.*://')
export ECOING_DATA_ROOT="$DATA"
export ECOING_HTTP_PORT="${PUERTO:-8082}"
[ -n "$ELPU" ] && export ECOING_ELPU_ROOT="$ELPU"
# Mismo PUID/PGID que el contenedor actual (vacio = sin remapeo, como hoy).
export ECOING_PUID=$(docker exec ecoing-app printenv PUID 2>/dev/null || true)
export ECOING_PGID=$(docker exec ecoing-app printenv PGID 2>/dev/null || true)
echo "Repo:     $REPO"
echo "Compose:  $CONFIG  (proyecto $PROYECTO)"
echo "Datos:    $DATA"
echo "ELPU:     ${ELPU:-(sin montar)}"
echo "Puerto:   $ECOING_HTTP_PORT   PUID/PGID: ${ECOING_PUID:-(sin remapeo)}/${ECOING_PGID:-}"
[ -f "$REPO/docker/.env" ] || falla "Falta $REPO/docker/.env"
cd "$REPO"

# docker-compose.yml NO se reemplaza: el del NAS puede tener ajustes propios.
# Solo se le agrega el volumen de report_config (paso 3).
EXCLUIR="docker-compose.yml"

# Antes de tocar nada: ¿este usuario puede escribir donde hace falta?
SIN_PERMISO=""
for f in $(tar tzf "$PAQUETE" | grep -v '/$' | grep -vx "$EXCLUIR") "$EXCLUIR"; do
  if [ -e "$f" ]; then
    [ -w "$f" ] || SIN_PERMISO="$SIN_PERMISO\n  $REPO/$f"
  else
    d=$(dirname "$f"); while [ ! -d "$d" ]; do d=$(dirname "$d"); done
    [ -w "$d" ] || SIN_PERMISO="$SIN_PERMISO\n  $REPO/$d/ (para crear $f)"
  fi
done
if [ -n "$SIN_PERMISO" ]; then
  printf "El usuario $(id -un) no puede escribir en:$SIN_PERMISO\n" >&2
  falla "No se toco nada. Hace falta ejecutarlo con el usuario admin del NAS (ver mensaje en el chat)."
fi
echo "Permisos de escritura: OK"

paso "1/6 Respaldo de la base de datos"
BD=$(docker exec ecoing-db printenv MARIADB_DATABASE)
CLAVE=$(docker exec ecoing-db printenv MARIADB_ROOT_PASSWORD)
[ -n "$BD" ] && [ -n "$CLAVE" ] || falla "No pude leer la base o la clave root del contenedor ecoing-db."
# El respaldo queda junto al instalador: la carpeta backups/ es del usuario
# del contenedor y este usuario SSH no siempre puede escribir ahi.
mkdir -p "$AQUI/respaldos"
RESPALDO="$AQUI/respaldos/antes_plantillas_$SELLO.sql.gz"
docker exec -e MYSQL_PWD="$CLAVE" ecoing-db mariadb-dump -uroot --single-transaction --routines --triggers "$BD" | gzip > "$RESPALDO"
[ "$(gzip -dc "$RESPALDO" | head -c 2000 | wc -c)" -gt 100 ] || falla "El respaldo salio vacio. No se toco nada."
echo "Respaldo: $RESPALDO ($(du -h "$RESPALDO" | cut -f1))"

paso "2/6 Copia de los archivos que se reemplazan"
ANTES="$AQUI/antes_plantillas_$SELLO.tar.gz"
EXISTENTES=$( (tar tzf "$PAQUETE" | grep -v '/$'; echo "$EXCLUIR") | while read -r f; do [ -f "$f" ] && echo "$f"; done || true)
if [ -n "$EXISTENTES" ]; then
  echo "$EXISTENTES" | tar czf "$ANTES" -T -
  echo "Guardado: $ANTES"
fi

paso "3/6 Codigo nuevo"
tar xzf "$PAQUETE" -C "$REPO" --no-same-owner --exclude="$EXCLUIR"
echo "Archivos actualizados: $(tar tzf "$PAQUETE" | grep -v '/$' | grep -vcx "$EXCLUIR")"

# Volumen para los logos de plantillas (antes se perdian al reconstruir).
# El entrypoint del contenedor (root) crea la carpeta y le da permisos.
if grep -q "report_config" "$CONFIG"; then
  echo "El compose ya tiene report_config."
elif grep -q "EXPORT_STORAGE_PATH:" "$CONFIG" && grep -q ":/data/exports" "$CONFIG"; then
  sed -i -e '/EXPORT_STORAGE_PATH:/{' -e 'p' -e 's#EXPORT_STORAGE_PATH:.*#REPORT_CONFIG_STORAGE_PATH: /data/report_config#' -e '}' "$CONFIG"
  sed -i -e '/:\/data\/exports/{' -e 'p' -e 's#exports:/data/exports#report_config:/data/report_config#' -e '}' "$CONFIG"
  if ! $DC -p "$PROYECTO" -f "$CONFIG" config -q; then
    tar xzf "$ANTES" -C "$REPO" "$EXCLUIR"
    falla "El compose modificado no es valido; se restauro el original. No se reconstruyo nada."
  fi
  echo "Compose: agregado el volumen report_config."
  grep -n "report_config" "$CONFIG"
else
  echo "AVISO: no reconoci el compose; los logos seguiran dentro del contenedor (se pierden al reconstruir)."
fi

paso "4/6 Migracion de la base (plantillas)"
docker exec -i -e MYSQL_PWD="$CLAVE" ecoing-db mariadb -uroot "$BD" < database/migrations/20261015_plantillas.sql \
  || falla "La migracion fallo. Para volver atras: tar xzf $ANTES -C $REPO"
docker exec -e MYSQL_PWD="$CLAVE" ecoing-db mariadb -uroot "$BD" \
  -e "SHOW TABLES LIKE 'plantilla%'; SHOW COLUMNS FROM proyectos LIKE 'plantilla_id';"

paso "5/6 Reconstruir y reiniciar la app (tarda unos minutos)"
$DC -p "$PROYECTO" -f "$CONFIG" up -d --build ecoing-app

paso "6/6 Comprobacion"
R=""
for i in $(seq 1 40); do
  R=$(curl -s -m 5 "http://127.0.0.1:$ECOING_HTTP_PORT/api/health.php" || true)
  if echo "$R" | grep -q '"database":"ok"'; then break; fi
  sleep 3
done
echo "$R"
echo "$R" | grep -q '"database":"ok"' || { $DC -p "$PROYECTO" -f "$CONFIG" logs --tail=60 ecoing-app; falla "La app no respondio bien. Revise los logs de arriba."; }
docker exec ecoing-app sh -c 'test -w /data/report_config && echo "report_config: escribible"' \
  || echo "AVISO: /data/report_config no es escribible (los logos no se podran subir)"

printf '\n\033[1;32mListo. Abra http://192.168.1.3:%s/web/plantillas.html\033[0m\n' "$ECOING_HTTP_PORT"
printf 'Para volver atras: tar xzf %s -C %s && %s -p %s -f %s up -d --build ecoing-app\n' "$ANTES" "$REPO" "$DC" "$PROYECTO" "$CONFIG"
