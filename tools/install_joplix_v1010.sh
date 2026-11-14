#!/usr/bin/env bash
set -euo pipefail

APP_ID="joplix"
DEFAULT_NC_DIR="/var/www/nextcloud"
DEFAULT_WEBUSER="www-data"
DEFAULT_ZIP=""
DEFAULT_ACTION="upgrade"
DEFAULT_KEEP_BACKUP="no"
DEFAULT_ENABLE="yes"
DEFAULT_REPAIR="yes"
DEFAULT_STRICT="yes"
DEFAULT_ROLLBACK="no"
DEFAULT_BACKUP_RETENTION="3"

NC_DIR="${NC_DIR:-$DEFAULT_NC_DIR}"
WEBUSER="${WEBUSER:-$DEFAULT_WEBUSER}"
ZIP_PATH=""
ACTION=""
KEEP_BACKUP=""
ENABLE_APP=""
RUN_REPAIR=""
STRICT_MODE=""
ROLLBACK_ONLY=""
NON_INTERACTIVE="no"
BACKUP_NAME=""
BACKUP_RETENTION=""
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ORIG_PWD="$(pwd)"

usage() {
  cat <<'EOF'
Joplix 1.0.10 Installer / Upgrader

Usage:
  sudo bash install_joplix_interactive.sh
  sudo bash install_joplix_v1010.sh --zip ./joplix_v1.0.10.zip --non-interactive

Options:
  --zip PATH             Joplix ZIP package path.
  --nextcloud-dir PATH   Nextcloud directory. Default: /var/www/nextcloud
  --web-user USER        Webserver user. Default: www-data
  --action ACTION        install, upgrade, disable, uninstall, rollback. Default: upgrade
  --backup NAME          Backup directory name for rollback, e.g. joplix.backup_20260730_123000
  --keep-backups N       Number of timestamped backups to keep. Default: 3
  --non-interactive      Use defaults and command-line values without asking.
  --help                 Show help.

Environment:
  NC_DIR=/var/www/nextcloud
  WEBUSER=www-data
EOF
}

log(){ echo "[Joplix] $*"; }
warn(){ echo "[Joplix][WARN] $*" >&2; }
fail(){ echo "[Joplix][ERROR] $*" >&2; exit 1; }

ask() {
  local prompt="$1" default="$2" var
  if [ "$NON_INTERACTIVE" = "yes" ]; then
    printf '%s' "$default"
    return
  fi
  read -r -p "$prompt [$default]: " var
  if [ -z "$var" ]; then printf '%s' "$default"; else printf '%s' "$var"; fi
}

ask_yes_no() {
  local prompt="$1" default="$2" var
  if [ "$NON_INTERACTIVE" = "yes" ]; then
    printf '%s' "$default"
    return
  fi
  while true; do
    read -r -p "$prompt [$default]: " var
    var="${var:-$default}"
    case "$var" in
      yes|y|Y|ja|j|J) printf 'yes'; return ;;
      no|n|N|nein) printf 'no'; return ;;
      *) echo "Bitte yes oder no eingeben." ;;
    esac
  done
}

while [ $# -gt 0 ]; do
  case "$1" in
    --zip) ZIP_PATH="${2:-}"; shift 2 ;;
    --nextcloud-dir) NC_DIR="${2:-}"; shift 2 ;;
    --web-user) WEBUSER="${2:-}"; shift 2 ;;
    --action) ACTION="${2:-}"; shift 2 ;;
    --backup) BACKUP_NAME="${2:-}"; shift 2 ;;
    --keep-backups) BACKUP_RETENTION="${2:-}"; shift 2 ;;
    --non-interactive) NON_INTERACTIVE="yes"; shift ;;
    --help|-h) usage; exit 0 ;;
    *) fail "Unbekannter Parameter: $1" ;;
  esac
done

resolve_zip_path() {
  local p="$1"
  [ -n "$p" ] || { echo ""; return; }
  if [ -f "$p" ]; then cd "$(dirname "$p")" && printf '%s/%s
' "$(pwd)" "$(basename "$p")"; return; fi
  case "$p" in
    ./*|../*)
      if [ -f "$SCRIPT_DIR/$p" ]; then cd "$(dirname "$SCRIPT_DIR/$p")" && printf '%s/%s
' "$(pwd)" "$(basename "$p")"; return; fi
      if [ -f "$ORIG_PWD/$p" ]; then cd "$(dirname "$ORIG_PWD/$p")" && printf '%s/%s
' "$(pwd)" "$(basename "$p")"; return; fi
      ;;
  esac
  echo "$p"
}

find_default_zip() {
  if [ -n "$ZIP_PATH" ]; then resolve_zip_path "$ZIP_PATH"; return; fi
  local f
  for f in "$SCRIPT_DIR"/joplix_v1.0.10.zip "$SCRIPT_DIR"/joplix*.zip "$ORIG_PWD"/joplix_v1.0.10.zip "$ORIG_PWD"/joplix*.zip /tmp/joplix*.zip; do
    if [ -f "$f" ]; then cd "$(dirname "$f")" && printf '%s/%s
' "$(pwd)" "$(basename "$f")"; return; fi
  done
  echo ""
}

preflight_base() {
  [ "$(id -u)" -eq 0 ] || fail "Bitte als root ausfuehren, z.B. sudo bash install_joplix_interactive.sh"
  command -v php >/dev/null 2>&1 || fail "php wurde nicht gefunden."
  command -v unzip >/dev/null 2>&1 || fail "unzip wurde nicht gefunden."
  id "$WEBUSER" >/dev/null 2>&1 || fail "Webserver-User existiert nicht: $WEBUSER"
  [ -d "$NC_DIR" ] || fail "Nextcloud-Verzeichnis nicht gefunden: $NC_DIR"
  [ -f "$NC_DIR/occ" ] || fail "occ nicht gefunden: $NC_DIR/occ"
  [ -d "$NC_DIR/apps" ] || fail "apps-Verzeichnis nicht gefunden: $NC_DIR/apps"
}

occ(){ sudo -u "$WEBUSER" php "$NC_DIR/occ" "$@"; }
app_dir(){ echo "$NC_DIR/apps/$APP_ID"; }

disable_app(){ log "Deaktiviere App falls aktiv..."; occ app:disable "$APP_ID" >/dev/null 2>&1 || true; }

set_permissions(){
  local dir="$(app_dir)"
  log "Setze Rechte auf $WEBUSER:$WEBUSER, Ordner 750, Dateien 640..."
  chown -R "$WEBUSER:$WEBUSER" "$dir"
  find "$dir" -type d -exec chmod 750 {} \;
  find "$dir" -type f -exec chmod 640 {} \;
  find "$dir/tools" -type f -name "*.sh" -exec chmod 750 {} \; 2>/dev/null || true
}

check_web_write_access(){
  local dir="$(app_dir)"
  log "Pruefe Schreibrechte fuer $WEBUSER auf App-Verzeichnis..."
  sudo -u "$WEBUSER" test -w "$dir" || fail "$WEBUSER hat keine Schreibrechte auf $dir. In-App-Updates funktionieren so nicht."
  sudo -u "$WEBUSER" test -w "$dir/appinfo" || fail "$WEBUSER hat keine Schreibrechte auf $dir/appinfo."
  sudo -u "$WEBUSER" test -w "$dir/js" || warn "$WEBUSER hat keine Schreibrechte auf $dir/js."
  sudo -u "$WEBUSER" test -w "$dir/css" || warn "$WEBUSER hat keine Schreibrechte auf $dir/css."
}

validate_info(){
  local dir="$(app_dir)" info="$dir/appinfo/info.xml"
  [ -f "$info" ] || fail "appinfo/info.xml fehlt."
  php -r 'simplexml_load_file($argv[1]) or exit(1);' "$info" || fail "info.xml ist kein gueltiges XML."
  local id
  id="$(php -r '$x=simplexml_load_file($argv[1]); echo (string)$x->id;' "$info")"
  [ "$id" = "$APP_ID" ] || fail "info.xml App-ID ist $id, erwartet $APP_ID."
}

php_lint(){
  local dir="$(app_dir)" failed=0
  log "Pruefe PHP-Dateien..."
  while IFS= read -r -d '' f; do
    if ! php -l "$f"; then failed=1; fi
  done < <(find "$dir" -name '*.php' -print0)
  [ "$failed" -eq 0 ] || fail "PHP-Syntaxcheck fehlgeschlagen. App wird nicht aktiviert."
}


prune_backups(){
  local keep="$1"
  local parent="$NC_DIR/apps"
  if ! [[ "$keep" =~ ^[0-9]+$ ]]; then
    warn "Backup-Aufbewahrung ist keine Zahl: $keep. Verwende 3."
    keep="3"
  fi
  if [ "$keep" -lt 0 ]; then keep="0"; fi
  log "Backup-Aufbewahrung: maximal $keep Backup(s) behalten."
  mapfile -t backups < <(find "$parent" -maxdepth 1 -type d -name "${APP_ID}.backup_*" -printf '%f\n' | sort -r)
  local index=0
  for b in "${backups[@]}"; do
    index=$((index+1))
    if [ "$index" -gt "$keep" ]; then
      log "Entferne altes Backup: $parent/$b"
      rm -rf "$parent/$b"
    fi
  done
}

install_or_upgrade(){
  local zip="$1" dir="$(app_dir)" ts backup
  [ -f "$zip" ] || fail "ZIP nicht gefunden: $zip"
  log "Teste ZIP: $zip"
  unzip -t "$zip" >/dev/null
  disable_app
  ts="$(date +%Y%m%d_%H%M%S)"
  backup="$NC_DIR/apps/${APP_ID}.backup_$ts"
  if [ -d "$dir" ]; then
    if [ "$KEEP_BACKUP" = "yes" ]; then
      log "Sichere bestehende App nach: $backup"
      mv "$dir" "$backup"
    else
      log "Entferne bestehende App ohne Backup."
      rm -rf "$dir"
    fi
  fi
  log "Entpacke App nach $NC_DIR/apps"
  (cd "$NC_DIR/apps" && unzip -q "$zip")
  [ -d "$dir" ] || fail "Nach dem Entpacken fehlt $dir. ZIP muss den Root-Ordner joplix enthalten."
  validate_info
  set_permissions
  check_web_write_access
  php_lint
  log "Pruefe Nextcloud-App-Pfad..."
  occ app:getpath "$APP_ID"
  if [ "$RUN_REPAIR" = "yes" ]; then
    log "Fuehre maintenance:theme:update aus..."
    occ maintenance:theme:update || warn "maintenance:theme:update konnte nicht erfolgreich abgeschlossen werden."
    log "Fuehre maintenance:repair aus..."
    occ maintenance:repair || warn "maintenance:repair konnte nicht erfolgreich abgeschlossen werden."
  fi
  if [ "$ENABLE_APP" = "yes" ]; then
    log "Aktiviere Joplix..."
    occ app:enable "$APP_ID"
  else
    log "App wurde installiert, aber nicht aktiviert."
  fi
  log "Fertig. Backup: ${backup:-keines}"
  prune_backups "$BACKUP_RETENTION"
  occ app:list | grep -i "$APP_ID" -A3 -B3 || true
}

uninstall_app(){
  local dir="$(app_dir)" ts backup
  disable_app
  if [ -d "$dir" ]; then
    ts="$(date +%Y%m%d_%H%M%S)"
    backup="$NC_DIR/apps/${APP_ID}.backup_$ts"
    if [ "$KEEP_BACKUP" = "yes" ]; then
      log "Verschiebe App nach Backup: $backup"
      mv "$dir" "$backup"
    else
      log "Loesche App-Verzeichnis."
      rm -rf "$dir"
    fi
  fi
  prune_backups "$BACKUP_RETENTION"
  log "Uninstall abgeschlossen."
}

rollback_app(){
  [ -n "$BACKUP_NAME" ] || fail "Rollback braucht --backup NAME, z.B. --backup joplix.backup_20260730_123000"
  local backup="$NC_DIR/apps/$BACKUP_NAME" dir="$(app_dir)"
  [ -d "$backup" ] || fail "Backup nicht gefunden: $backup"
  disable_app
  if [ -d "$dir" ]; then
    mv "$dir" "$NC_DIR/apps/${APP_ID}.failed_$(date +%Y%m%d_%H%M%S)"
  fi
  mv "$backup" "$dir"
  set_permissions
  validate_info
  php_lint
  occ app:enable "$APP_ID"
  log "Rollback abgeschlossen."
}

interactive_config(){
  ZIP_PATH="$(find_default_zip)"
  ACTION="${ACTION:-$(ask 'Aktion: install, upgrade, disable, uninstall, rollback' "$DEFAULT_ACTION") }"
  ACTION="$(echo "$ACTION" | xargs)"
  NC_DIR="$(ask 'Nextcloud-Verzeichnis' "$NC_DIR")"
  WEBUSER="$(ask 'Webserver-User' "$WEBUSER")"
  if [ "$ACTION" = "install" ] || [ "$ACTION" = "upgrade" ]; then
    ZIP_PATH="$(ask 'Pfad zur Joplix-ZIP' "$ZIP_PATH")"
    KEEP_BACKUP="$(ask_yes_no 'Bestehende App vorher sichern?' "$DEFAULT_KEEP_BACKUP")"
    BACKUP_RETENTION="$(ask 'Wie viele Backups sollen behalten werden?' "$DEFAULT_BACKUP_RETENTION")"
    ENABLE_APP="$(ask_yes_no 'App nach Installation aktivieren?' "$DEFAULT_ENABLE")"
    RUN_REPAIR="$(ask_yes_no 'maintenance:repair ausfuehren?' "$DEFAULT_REPAIR")"
    STRICT_MODE="$(ask_yes_no 'Bei PHP/XML-Fehler abbrechen?' "$DEFAULT_STRICT")"
  elif [ "$ACTION" = "uninstall" ]; then
    KEEP_BACKUP="$(ask_yes_no 'App-Verzeichnis als Backup behalten?' "$DEFAULT_KEEP_BACKUP")"
    BACKUP_RETENTION="$(ask 'Wie viele Backups sollen behalten werden?' "$DEFAULT_BACKUP_RETENTION")"
  elif [ "$ACTION" = "rollback" ]; then
    BACKUP_NAME="$(ask 'Backup-Ordnername in apps/' "$BACKUP_NAME")"
  fi
}

if [ "$NON_INTERACTIVE" = "yes" ]; then
  ACTION="${ACTION:-$DEFAULT_ACTION}"
  ZIP_PATH="$(find_default_zip)"
  KEEP_BACKUP="${KEEP_BACKUP:-$DEFAULT_KEEP_BACKUP}"
  ENABLE_APP="${ENABLE_APP:-$DEFAULT_ENABLE}"
  RUN_REPAIR="${RUN_REPAIR:-$DEFAULT_REPAIR}"
  STRICT_MODE="${STRICT_MODE:-$DEFAULT_STRICT}"
  BACKUP_RETENTION="${BACKUP_RETENTION:-$DEFAULT_BACKUP_RETENTION}"
else
  interactive_config
fi

BACKUP_RETENTION="${BACKUP_RETENTION:-$DEFAULT_BACKUP_RETENTION}"
preflight_base
log "Konfiguration:"
log "  Aktion: $ACTION"
log "  Nextcloud: $NC_DIR"
log "  Webuser: $WEBUSER"
log "  ZIP: ${ZIP_PATH:-nicht erforderlich}"
log "  Backups behalten: $BACKUP_RETENTION"

case "$ACTION" in
  install|upgrade) install_or_upgrade "$ZIP_PATH" ;;
  disable) disable_app; log "App deaktiviert, falls aktiv." ;;
  uninstall) uninstall_app ;;
  rollback) rollback_app ;;
  *) fail "Ungueltige Aktion: $ACTION" ;;
esac
