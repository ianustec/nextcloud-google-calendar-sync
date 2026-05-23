#!/bin/bash
set -euo pipefail

# Google Workspace Calendar Sync for Nextcloud — deploy script
# https://github.com/ianustec/nextcloud-google-calendar-sync
# © 2026 IANUSTEC s.r.l. — AGPL-3.0-or-later
#
# Supports three deployment modes:
#
#   Kubernetes (k8s):
#     ./deploy.sh k8s <namespace> <deployment>
#     ./deploy.sh k8s my-namespace nextcloud
#
#   Docker / Docker Compose:
#     ./deploy.sh docker <container-name>
#     ./deploy.sh docker nextcloud
#
#   Bare metal (local):
#     ./deploy.sh local <nextcloud-path>
#     ./deploy.sh local /var/www/html
#     sudo ./deploy.sh local /var/www/nextcloud   # if occ needs root
#
# Environment:
#   GOOGLE_WORKSPACE_DOMAIN  set google_domain occ config key after deploy (optional)
#   KUBECTL                  kubectl binary (default: kubectl)
#   DOCKER                   docker binary (default: docker)

APP_ID="neura_google_calendar_sync"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MODE="${1:-}"

log()  { echo "[deploy] $*"; }
warn() { echo "[deploy] WARNING: $*" >&2; }
die()  { echo "[deploy] ERROR: $*" >&2; exit 1; }

usage() {
  cat >&2 <<EOF
Usage:
  $0 k8s     <namespace> <deployment>
  $0 docker  <container>
  $0 local   <nextcloud-path>
EOF
  exit 1
}

# ── Composer ────────────────────────────────────────────────────────────────
build_vendor() {
  if command -v composer >/dev/null 2>&1; then
    (cd "$SCRIPT_DIR" && composer install --no-dev --optimize-autoloader --quiet) \
      || warn "composer install failed"
  elif command -v docker >/dev/null 2>&1; then
    docker run --rm -v "$SCRIPT_DIR:/app" -w /app composer:2 \
      install --no-dev --optimize-autoloader --quiet \
      || warn "docker composer install failed"
  else
    warn "composer not found; ensure vendor/ exists"
  fi
}

# ── Build tarball ────────────────────────────────────────────────────────────
build_tar() {
  TMP_TAR="/tmp/${APP_ID}.tar"
  rm -f "$TMP_TAR"
  (
    cd "$SCRIPT_DIR"
    COPYFILE_DISABLE=1 tar cf "$TMP_TAR" \
      --exclude vendor \
      --exclude .git \
      --exclude .github \
      --exclude .DS_Store \
      appinfo lib templates js css img composer.json composer.lock README.md CHANGELOG.md LICENSE
  )
  if [[ -d "$SCRIPT_DIR/vendor" ]]; then
    (cd "$SCRIPT_DIR" && COPYFILE_DISABLE=1 tar rf "$TMP_TAR" vendor)
  fi
}

# ════════════════════════════════════════════════════════════════════════════
# MODE: k8s
# ════════════════════════════════════════════════════════════════════════════
deploy_k8s() {
  local NS="${1:-}" DEPLOY="${2:-}"
  [[ -z "$NS" || -z "$DEPLOY" ]] && usage
  KUBECTL="${KUBECTL:-kubectl}"

  $KUBECTL get deployment -n "$NS" "$DEPLOY" >/dev/null 2>&1 \
    || die "deployment/$DEPLOY not found in namespace $NS"

  POD=$($KUBECTL get pod -n "$NS" -l "app.kubernetes.io/name=$DEPLOY" \
    -o jsonpath='{.items[0].metadata.name}' 2>/dev/null || true)
  [[ -z "$POD" ]] && POD=$($KUBECTL get pod -n "$NS" -l "app=$DEPLOY" \
    -o jsonpath='{.items[0].metadata.name}' 2>/dev/null || true)
  [[ -z "$POD" ]] && die "Nextcloud pod not found in namespace $NS"

  build_vendor
  build_tar

  log "Copying app to pod $POD..."
  $KUBECTL cp "$TMP_TAR" "$NS/$POD:/tmp/${APP_ID}.tar" -c nextcloud
  $KUBECTL -n "$NS" exec "deployment/$DEPLOY" -c nextcloud -- sh -c "
    rm -rf /var/www/html/custom_apps/${APP_ID}
    mkdir -p /var/www/html/custom_apps/${APP_ID}
    tar xf /tmp/${APP_ID}.tar -C /var/www/html/custom_apps/${APP_ID}
    rm -f /tmp/${APP_ID}.tar
    chown -R 33:33 /var/www/html/custom_apps/${APP_ID}
  "
  $KUBECTL -n "$NS" exec "deployment/$DEPLOY" -c nextcloud -- \
    php occ app:disable "$APP_ID" 2>/dev/null || true
  $KUBECTL -n "$NS" exec "deployment/$DEPLOY" -c nextcloud -- \
    php occ app:enable "$APP_ID"
  $KUBECTL -n "$NS" exec "deployment/$DEPLOY" -c nextcloud -- \
    php occ upgrade
  $KUBECTL -n "$NS" exec "deployment/$DEPLOY" -c nextcloud -- \
    apachectl graceful 2>/dev/null || true

  [[ -n "${GOOGLE_WORKSPACE_DOMAIN:-}" ]] && \
    $KUBECTL -n "$NS" exec "deployment/$DEPLOY" -c nextcloud -- \
      php occ config:app:set "$APP_ID" google_domain --value="$GOOGLE_WORKSPACE_DOMAIN"
}

# ════════════════════════════════════════════════════════════════════════════
# MODE: docker
# ════════════════════════════════════════════════════════════════════════════
deploy_docker() {
  local CONTAINER="${1:-}"
  [[ -z "$CONTAINER" ]] && usage
  DOCKER="${DOCKER:-docker}"

  $DOCKER inspect "$CONTAINER" >/dev/null 2>&1 \
    || die "container '$CONTAINER' not found or not running"

  # Detect Nextcloud root inside container
  NC_ROOT=$($DOCKER exec "$CONTAINER" sh -c \
    "[ -f /var/www/html/occ ] && echo /var/www/html || \
     [ -f /var/www/nextcloud/occ ] && echo /var/www/nextcloud || echo ''" 2>/dev/null)
  [[ -z "$NC_ROOT" ]] && die "Could not find occ inside container '$CONTAINER'"

  # Detect web server user (www-data=33 on Debian, apache on RHEL)
  WEB_USER=$($DOCKER exec "$CONTAINER" sh -c \
    "id www-data >/dev/null 2>&1 && echo www-data || echo apache" 2>/dev/null || echo www-data)

  build_vendor
  build_tar

  log "Copying app to container $CONTAINER (root: $NC_ROOT)..."
  $DOCKER cp "$TMP_TAR" "$CONTAINER:/tmp/${APP_ID}.tar"
  $DOCKER exec "$CONTAINER" sh -c "
    rm -rf ${NC_ROOT}/custom_apps/${APP_ID}
    mkdir -p ${NC_ROOT}/custom_apps/${APP_ID}
    tar xf /tmp/${APP_ID}.tar -C ${NC_ROOT}/custom_apps/${APP_ID}
    rm -f /tmp/${APP_ID}.tar
    chown -R ${WEB_USER}:${WEB_USER} ${NC_ROOT}/custom_apps/${APP_ID}
  "
  $DOCKER exec --user "$WEB_USER" "$CONTAINER" \
    php ${NC_ROOT}/occ app:disable "$APP_ID" 2>/dev/null || true
  $DOCKER exec --user "$WEB_USER" "$CONTAINER" \
    php ${NC_ROOT}/occ app:enable "$APP_ID"
  $DOCKER exec --user "$WEB_USER" "$CONTAINER" \
    php ${NC_ROOT}/occ upgrade
  # Reload web server (Apache or nginx+fpm)
  $DOCKER exec "$CONTAINER" sh -c \
    "apachectl graceful 2>/dev/null || kill -USR2 1 2>/dev/null || true"

  [[ -n "${GOOGLE_WORKSPACE_DOMAIN:-}" ]] && \
    $DOCKER exec --user "$WEB_USER" "$CONTAINER" \
      php ${NC_ROOT}/occ config:app:set "$APP_ID" google_domain \
      --value="$GOOGLE_WORKSPACE_DOMAIN"
}

# ════════════════════════════════════════════════════════════════════════════
# MODE: local
# ════════════════════════════════════════════════════════════════════════════
deploy_local() {
  local NC_PATH="${1:-}"
  [[ -z "$NC_PATH" ]] && usage
  [[ ! -f "${NC_PATH}/occ" ]] && die "occ not found in '${NC_PATH}' — check the Nextcloud path"

  build_vendor

  APP_DIR="${NC_PATH}/custom_apps/${APP_ID}"
  log "Installing to $APP_DIR..."
  rm -rf "$APP_DIR"
  mkdir -p "$APP_DIR"
  rsync -a \
    --exclude='.git' \
    --exclude='.github' \
    --exclude='.DS_Store' \
    "$SCRIPT_DIR/" "$APP_DIR/"

  php "${NC_PATH}/occ" app:disable "$APP_ID" 2>/dev/null || true
  php "${NC_PATH}/occ" app:enable "$APP_ID"
  php "${NC_PATH}/occ" upgrade

  [[ -n "${GOOGLE_WORKSPACE_DOMAIN:-}" ]] && \
    php "${NC_PATH}/occ" config:app:set "$APP_ID" google_domain \
      --value="$GOOGLE_WORKSPACE_DOMAIN"
}

# ════════════════════════════════════════════════════════════════════════════
# Dispatch
# ════════════════════════════════════════════════════════════════════════════
case "$MODE" in
  k8s)    deploy_k8s    "${2:-}" "${3:-}" ;;
  docker) deploy_docker "${2:-}" ;;
  local)  deploy_local  "${2:-}" ;;
  *)      usage ;;
esac

log "Done. Configure the Service Account key in Nextcloud → Admin → Google Workspace Calendar Sync."
