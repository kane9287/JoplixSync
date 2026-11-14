
    
  
#!/usr/bin/env bash

set -Eeuo pipefail

APP_ID="joplix"
REPO_API="https://codeberg.org/api/v1/repos/Stoneubi/joplix/releases"

echo
echo "=========================================="
echo "       Joplix One-Step Installer"
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

for cmd in curl python3 sha256sum flock runuser; do
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
        apt-get update
        apt-get install -y jq
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
run_php() {
    runuser -u "$WEBUSER" -- "$PHP_BIN" -d apc.enable_cli=1 "$@"
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

