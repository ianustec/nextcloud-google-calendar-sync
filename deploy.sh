#!/bin/bash
set -euo pipefail

# Google Workspace Calendar Sync for Nextcloud — Kubernetes deploy script
# https://github.com/ianustec/nextcloud-google-calendar-sync
# © 2026 IANUSTEC s.r.l. — AGPL-3.0-or-later
#
# Usage:
#   ./deploy.sh <namespace> <deployment>
#
# Example:
#   ./deploy.sh my-namespace nextcloud
#
# Environment:
#   GOOGLE_WORKSPACE_DOMAIN  set google_domain occ config key after deploy (optional)
#   KUBECTL                  kubectl binary path (default: kubectl)

if [[ -z "${1:-}" || -z "${2:-}" ]]; then
  echo "Usage: $0 <namespace> <deployment>" >&2
  exit 1
fi

NS="${1}"
DEPLOY="${2}"
KUBECTL="${KUBECTL:-kubectl}"
APP_ID="neura_google_calendar_sync"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log() { echo "[deploy] $*"; }
warn() { echo "[deploy] WARNING: $*" >&2; }

if ! $KUBECTL get deployment -n "$NS" "$DEPLOY" >/dev/null 2>&1; then
  warn "deployment/$DEPLOY not found in namespace $NS"
  exit 1
fi

if command -v composer >/dev/null 2>&1; then
  (cd "$SCRIPT_DIR" && composer install --no-dev --optimize-autoloader --quiet) || warn "composer install failed"
elif command -v docker >/dev/null 2>&1; then
  docker run --rm -v "$SCRIPT_DIR:/app" -w /app composer:2 install --no-dev --optimize-autoloader --quiet || warn "docker composer install failed"
else
  warn "composer not found; ensure vendor/ exists"
fi

POD=$($KUBECTL get pod -n "$NS" -l "app.kubernetes.io/name=$DEPLOY" -o jsonpath='{.items[0].metadata.name}' 2>/dev/null || true)
if [[ -z "$POD" ]]; then
  POD=$($KUBECTL get pod -n "$NS" -l "app=$DEPLOY" -o jsonpath='{.items[0].metadata.name}' 2>/dev/null || true)
fi
if [[ -z "$POD" ]]; then
  warn "Nextcloud pod not found"
  exit 1
fi

TMP="/tmp/${APP_ID}.tar"
rm -f "$TMP"
(
  cd "$SCRIPT_DIR"
  COPYFILE_DISABLE=1 tar cf "$TMP" \
    --exclude vendor \
    --exclude .git \
    --exclude .DS_Store \
    appinfo lib templates js css img composer.json composer.lock README.md LICENSE
)
if [[ -d "$SCRIPT_DIR/vendor" ]]; then
  (cd "$SCRIPT_DIR" && COPYFILE_DISABLE=1 tar rf "$TMP" vendor)
fi

$KUBECTL cp "$TMP" "$NS/$POD:/tmp/${APP_ID}.tar" -c nextcloud
$KUBECTL -n "$NS" exec "deployment/$DEPLOY" -c nextcloud -- sh -c "
  rm -rf /var/www/html/custom_apps/${APP_ID} /var/www/html/apps/${APP_ID}
  mkdir -p /var/www/html/custom_apps/${APP_ID}
  tar xf /tmp/${APP_ID}.tar -C /var/www/html/custom_apps/${APP_ID}
  rm -f /tmp/${APP_ID}.tar
  chown -R 33:33 /var/www/html/custom_apps/${APP_ID}
"

$KUBECTL -n "$NS" exec "deployment/$DEPLOY" -c nextcloud -- php occ app:disable "$APP_ID" 2>/dev/null || true
$KUBECTL -n "$NS" exec "deployment/$DEPLOY" -c nextcloud -- php occ app:enable "$APP_ID"
$KUBECTL -n "$NS" exec "deployment/$DEPLOY" -c nextcloud -- php occ upgrade
$KUBECTL -n "$NS" exec "deployment/$DEPLOY" -c nextcloud -- apachectl graceful 2>/dev/null || true

if [[ -n "${GOOGLE_WORKSPACE_DOMAIN:-}" ]]; then
  $KUBECTL -n "$NS" exec "deployment/$DEPLOY" -c nextcloud -- php occ config:app:set "$APP_ID" google_domain --value="$GOOGLE_WORKSPACE_DOMAIN"
fi

log "App $APP_ID deployed. Configure SA key in Nextcloud admin settings."
