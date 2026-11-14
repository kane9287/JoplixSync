#!/usr/bin/env bash
#
# Joplix Uninstaller
#
#   curl -fsSL https://raw.githubusercontent.com/kane9287/joplix-xsync/main/install/uninstall.sh | sudo bash
#   curl -fsSL https://raw.githubusercontent.com/kane9287/joplix-xsync/main/install/uninstall.sh | sudo bash -s -- --purge
#
# Optional environment: NC_DIR (Nextcloud root), WEBUSER (PHP service account).
# The block from "Require root" up to the lock is shared verbatim with install.sh; keep both in step.

set -Eeuo pipefail

APP_ID="joplix"

echo
echo "=========================================="
echo "          Joplix Uninstaller"
echo "=========================================="
echo

#
# Require root
#

if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: Please run as root." >&2
    exit 1
fi

#
# Required tools
#

for cmd in sha256sum flock runuser; do
    if ! command -v "$cmd" >/dev/null 2>&1; then
        echo "ERROR: $cmd is not installed." >&2
        exit 1
    fi
done

#
# Install jq if needed
#

if ! command -v jq >/dev/null 2>&1; then
    echo "Installing jq ..."

    if command -v apt-get >/dev/null 2>&1; then
        apt-get update < /dev/null
        apt-get install -y jq < /dev/null
    else
        echo "ERROR: jq is missing. Please install jq and run again." >&2
        exit 1
    fi
fi

# Find Nextcloud and the PHP user. With several instances the path has to be given explicitly.
fail() {
    echo "ERROR: $*" >&2
    exit 1
}

if [[ -z ${NC_DIR:-} ]]; then
    found=()
    mapfile -d '' -t candidates < <(find /var/www -type f -name occ -print0 2>/dev/null)
    for candidate in "${candidates[@]}"; do
        [[ -f ${candidate%/*}/lib/base.php && -f ${candidate%/*}/config/config.php ]] || continue
        found+=("${candidate%/*}")
    done
    if (( ${#found[@]} > 1 )); then
        echo "ERROR: Several Nextcloud instances found:" >&2
        printf '  %s
' "${found[@]}" >&2
        echo "Choose one by setting NC_DIR, for example:" >&2
        echo "  curl -fsSL <script-url> | sudo NC_DIR=${found[0]} bash" >&2
        exit 1
    fi
    NC_DIR=${found[0]:-}
fi
[[ -n ${NC_DIR:-} && -f $NC_DIR/occ && -f $NC_DIR/lib/base.php && -f $NC_DIR/config/config.php ]] ||
    fail "Nextcloud not found. Set NC_DIR to the Nextcloud root directory (the folder that contains occ), for example: sudo NC_DIR=/var/www/nextcloud bash"
NC_DIR=$(realpath -e -- "$NC_DIR")
PHP_BIN=$(command -v php) || fail "PHP is missing."
WEBUSER=${WEBUSER:-$(stat -c %U -- "$NC_DIR/config/config.php")}
if [[ $WEBUSER == root ]]; then
    WEBUSER=www-data
fi
id "$WEBUSER" >/dev/null 2>&1 && [[ $(id -u "$WEBUSER") != 0 ]] ||
    fail "Invalid PHP user. Set WEBUSER to the account that runs Nextcloud (for example www-data)."
# The official Nextcloud Docker image takes PHP settings (memory_limit and others) from container
# environment variables. sudo and su drop them; PHP and occ then print warnings in front of their real
# output. Restore missing PHP_* variables from the container's main process (harmless elsewhere).
if [[ -r /proc/1/environ ]]; then
    while IFS= read -r -d '' kv; do
        name=${kv%%=*}
        if [[ $kv == PHP_*=* && $name =~ ^PHP_[A-Z0-9_]+$ && -z ${!name+x} ]]; then
            export "$kv"
        fi
    done < /proc/1/environ
fi
# stdin is detached: under "curl ... | bash" stdin is the script itself and must not be read by PHP.
run_php() {
    runuser -u "$WEBUSER" -- "$PHP_BIN" -d apc.enable_cli=1 "$@" < /dev/null
}
occ() {
    run_php "$NC_DIR/occ" "$@"
}

# Honour the configured app paths and keep an existing installation where it is.
APP_PATHS=$(occ config:list system --output=json | sed -n '/^{/,$p' | jq -ec --arg path "$NC_DIR/apps" '
    .system | if type != "object" then error("Nextcloud system configuration is missing")
    elif has("apps_paths") then .apps_paths | if type == "array" then . else error("Invalid apps_paths") end
    else [{path: $path, writable: true}] end
')
APP_PARENT=
EXISTING_PARENT=
while IFS= read -r row; do
    configured=$(jq -er '.path | select(type == "string" and startswith("/"))' <<< "$row")
    [[ -d $configured ]] || continue
    parent=$(realpath -e -- "$configured")
    [[ $parent != / ]] || fail "Invalid Nextcloud app path."
    if [[ -e $parent/joplix || -L $parent/joplix ]]; then
        [[ -z $EXISTING_PARENT || $EXISTING_PARENT == "$parent" ]] ||
            fail "Joplix directories found in more than one app path."
        EXISTING_PARENT=$parent
    fi
    # New installations go into the first writable app path (for example custom_apps).
    if [[ -z $APP_PARENT ]] && jq -e '.writable == true' <<< "$row" >/dev/null; then
        APP_PARENT=$parent
    fi
done < <(jq -c '.[]' <<< "$APP_PATHS")
APP_PARENT=${EXISTING_PARENT:-$APP_PARENT}
[[ -n $APP_PARENT ]] || fail "No Nextcloud app directory found."
APP_DIR="$APP_PARENT/joplix"
[[ ! -L $APP_DIR ]] || fail "The Joplix directory is a symlink."

# Root-owned lock, shared by both shell scripts.
LOCK_ID=$(printf '%s' "$NC_DIR" | sha256sum)
exec 9>"/run/lock/joplix-${LOCK_ID%% *}.lock"
flock -n 9 || fail "A Joplix installation or removal is already running."

if [[ ! -e $APP_DIR ]]; then
    echo "Joplix is not installed."
    exit 0
fi
[[ -f $APP_DIR/appinfo/info.xml ]] || fail "No valid Joplix installation at $APP_DIR."

BACKUP_ROOT="$APP_PARENT/.joplix-uninstall"
[[ ! -L $BACKUP_ROOT ]] || fail "The backup directory is a symlink."
mkdir -p -- "$BACKUP_ROOT"
BACKUP_DIR=$(mktemp -d "$BACKUP_ROOT/joplix-XXXXXXXX")
cp -a -- "$APP_DIR" "$BACKUP_DIR/joplix"
echo "App backup: $BACKUP_DIR/joplix"

if [[ ${1:-} == --purge ]]; then
    # Nextcloud manages its own app configuration.
    occ app:remove "$APP_ID"
else
    occ app:disable "$APP_ID"
    rm -rf -- "$APP_DIR"
fi
[[ ! -e $APP_DIR ]] || fail "Nextcloud did not remove the app directory."
echo "Joplix removed. User files and app backups were kept."

