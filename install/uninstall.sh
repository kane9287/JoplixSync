#!/usr/bin/env bash
#
# Joplix Uninstaller
#
#   curl -fsSL https://raw.githubusercontent.com/kane9287/joplix-xsync/main/install/uninstall.sh | sudo bash
#   curl -fsSL https://raw.githubusercontent.com/kane9287/joplix-xsync/main/install/uninstall.sh | sudo bash -s -- --purge
#
# Optional environment: NC_DIR (Nextcloud root), WEBUSER (PHP service account).
# The block from "Root prüfen" up to the lock is shared verbatim with install.sh; keep both in step.

set -Eeuo pipefail

APP_ID="joplix"

echo
echo "=========================================="
echo "          Joplix Uninstaller"
echo "=========================================="
echo

#
# Root prüfen
#

if [ "$(id -u)" -ne 0 ]; then
    echo "FEHLER: Bitte als root ausführen."
    exit 1
fi

#
# Benötigte Programme
#

for cmd in sha256sum flock runuser; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "FEHLER: $cmd ist nicht installiert."
        exit 1
    fi
done

#
# jq installieren falls nötig
#

if ! command -v jq >/dev/null 2>&1; then
    echo "Installiere jq ..."

    if command -v apt-get >/dev/null 2>&1; then
        apt-get update < /dev/null
        apt-get install -y jq < /dev/null
    else
        echo "FEHLER: jq fehlt."
        exit 1
    fi
fi

# Nextcloud und PHP-Benutzer ermitteln. Bei mehreren Instanzen ist der Pfad explizit nötig.
fail() {
    echo "FEHLER: $*" >&2
    exit 1
}

if [[ -z ${NC_DIR:-} ]]; then
    mapfile -d '' -t candidates < <(find /var/www -type f -name occ -print0 2>/dev/null)
    for candidate in "${candidates[@]}"; do
        [[ -f ${candidate%/*}/lib/base.php && -f ${candidate%/*}/config/config.php ]] || continue
        [[ -z ${NC_DIR:-} ]] || fail "Mehrere Nextcloud-Instanzen gefunden. NC_DIR setzen."
        NC_DIR=${candidate%/*}
    done
fi
[[ -n ${NC_DIR:-} && -f $NC_DIR/occ && -f $NC_DIR/lib/base.php && -f $NC_DIR/config/config.php ]] ||
    fail "Nextcloud nicht gefunden. NC_DIR auf den Nextcloud-Stamm setzen."
NC_DIR=$(realpath -e -- "$NC_DIR")
PHP_BIN=$(command -v php) || fail "PHP fehlt."
WEBUSER=${WEBUSER:-$(stat -c %U -- "$NC_DIR/config/config.php")}
if [[ $WEBUSER == root ]]; then
    WEBUSER=www-data
fi
id "$WEBUSER" >/dev/null 2>&1 && [[ $(id -u "$WEBUSER") != 0 ]] ||
    fail "Ungültiger PHP-Benutzer. WEBUSER setzen."
# stdin wird abgekoppelt: bei "curl ... | bash" ist stdin das Skript selbst und darf nicht von PHP gelesen werden.
run_php() {
    runuser -u "$WEBUSER" -- "$PHP_BIN" -d apc.enable_cli=1 "$@" < /dev/null
}
occ() {
    run_php "$NC_DIR/occ" "$@"
}

# Die konfigurierten App-Pfade berücksichtigen und bestehende Installationen beibehalten.
APP_PATHS=$(occ config:list system --output=json | jq -ec --arg path "$NC_DIR/apps" '
    .system | if type != "object" then error("Nextcloud-Systemkonfiguration fehlt")
    elif has("apps_paths") then .apps_paths | if type == "array" then . else error("Ungültige apps_paths") end
    else [{path: $path, writable: true}] end
')
APP_PARENT=
EXISTING_PARENT=
while IFS= read -r row; do
    configured=$(jq -er '.path | select(type == "string" and startswith("/"))' <<< "$row")
    [[ -d $configured ]] || continue
    parent=$(realpath -e -- "$configured")
    [[ $parent != / ]] || fail "Ungültiger Nextcloud-App-Pfad."
    if [[ -e $parent/joplix || -L $parent/joplix ]]; then
        [[ -z $EXISTING_PARENT || $EXISTING_PARENT == "$parent" ]] ||
            fail "Mehrere Joplix-Verzeichnisse gefunden."
        EXISTING_PARENT=$parent
    fi
    # Neuinstallationen landen im ersten beschreibbaren App-Pfad (z. B. custom_apps).
    if [[ -z $APP_PARENT ]] && jq -e '.writable == true' <<< "$row" >/dev/null; then
        APP_PARENT=$parent
    fi
done < <(jq -c '.[]' <<< "$APP_PATHS")
APP_PARENT=${EXISTING_PARENT:-$APP_PARENT}
[[ -n $APP_PARENT ]] || fail "Kein Nextcloud-App-Verzeichnis gefunden."
APP_DIR="$APP_PARENT/joplix"
[[ ! -L $APP_DIR ]] || fail "Joplix-Verzeichnis ist ein Symlink."

# Root-eigene Sperre, gemeinsam für beide Shell-Skripte.
LOCK_ID=$(printf '%s' "$NC_DIR" | sha256sum)
exec 9>"/run/lock/joplix-${LOCK_ID%% *}.lock"
flock -n 9 || fail "Eine Joplix-Installation oder Deinstallation läuft bereits."

if [[ ! -e $APP_DIR ]]; then
    echo "Joplix ist nicht installiert."
    exit 0
fi
[[ -f $APP_DIR/appinfo/info.xml ]] || fail "Keine gültige Joplix-Installation unter $APP_DIR."

BACKUP_ROOT="$APP_PARENT/.joplix-uninstall"
[[ ! -L $BACKUP_ROOT ]] || fail "Backup-Verzeichnis ist ein Symlink."
mkdir -p -- "$BACKUP_ROOT"
BACKUP_DIR=$(mktemp -d "$BACKUP_ROOT/joplix-XXXXXXXX")
cp -a -- "$APP_DIR" "$BACKUP_DIR/joplix"
echo "App-Backup: $BACKUP_DIR/joplix"

if [[ ${1:-} == --purge ]]; then
    # Nextcloud verwaltet seine App-Konfiguration selbst.
    occ app:remove "$APP_ID"
else
    occ app:disable "$APP_ID"
    rm -rf -- "$APP_DIR"
fi
[[ ! -e $APP_DIR ]] || fail "Nextcloud hat das App-Verzeichnis nicht entfernt."
echo "Joplix deinstalliert. Benutzerdateien und App-Backups wurden erhalten."

