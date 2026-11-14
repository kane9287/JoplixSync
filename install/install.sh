#!/usr/bin/env bash
#
# Joplix One-Step Installer (install or upgrade)
#
#   curl -fsSL https://raw.githubusercontent.com/kane9287/joplix-xsync/main/install/install.sh | sudo bash
#   curl -fsSL https://raw.githubusercontent.com/kane9287/joplix-xsync/main/install/install.sh | sudo bash -s -- beta
#
# Installs the newest GitHub release of the chosen channel (stable = regular releases, beta = pre-releases).
# Optional environment: NC_DIR (Nextcloud root), WEBUSER (PHP service account).
# The block from "Require root" up to the lock is shared verbatim with uninstall.sh; keep both in step.

set -Eeuo pipefail

APP_ID="joplix"
REPO="kane9287/joplix-xsync"
REPO_API="https://api.github.com/repos/$REPO/releases"
MAX_ZIP_BYTES=$((35 * 1024 * 1024))

case ${1:-stable} in
    stable|main) CHANNEL=stable; BETA=false ;;
    beta)        CHANNEL=beta;   BETA=true ;;
    *) echo "ERROR: Unknown channel '${1}'. Allowed: stable, beta." >&2; exit 1 ;;
esac

echo
echo "=========================================="
echo "       Joplix One-Step Installer"
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

for cmd in curl python3 sha256sum flock runuser; do
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

#
# Find the newest GitHub release of the chosen channel
#

WORK=$(mktemp -d)
STAGE=
cleanup() {
    rm -rf -- "$WORK"
    [[ -z $STAGE ]] || rm -rf -- "$STAGE"
}
trap cleanup EXIT

fetch() {
    # fetch <url> <target file> [max-bytes]
    curl -fsSL --proto '=https' --proto-redir '=https' --retry 2 \
        ${3:+--max-filesize "$3"} -H 'User-Agent: Joplix-Installer' -o "$2" "$1"
}

echo "Looking for the newest Joplix release ($CHANNEL) in $REPO ..."
fetch "$REPO_API?per_page=50" "$WORK/releases.json" ||
    fail "Could not load the release list from GitHub."

# Channel: stable = regular releases, beta = releases marked as pre-release.
# The version is taken from the tag (for example joplix-2026.11.67, v2026.11.67, JoplixNC_v2026.11.67-beta).
# Package: an attached JoplixNC_v<version>[-beta].zip, otherwise the automatic source archive of the tag.
RELEASE=$(jq -c --argjson beta "$BETA" '
    [ .[]
      | select(type == "object" and (.draft | not) and .prerelease == $beta)
      | select((.tag_name // "") | test("[0-9]+\\.[0-9]+\\.[0-9]+"))
      | (.tag_name | capture("(?<a>[0-9]+)\\.(?<b>[0-9]+)\\.(?<c>[0-9]+)")) as $v
      | ("\($v.a).\($v.b).\($v.c)") as $version
      | ([ (.assets // [])[] | select(.name == "JoplixNC_v\($version).zip" or .name == "JoplixNC_v\($version)-beta.zip") ][0]) as $asset
      | { tag: .tag_name,
          version: $version,
          key: [($v.a | tonumber), ($v.b | tonumber), ($v.c | tonumber)],
          asset_name: ($asset.name // ""),
          asset_url: ($asset.browser_download_url // ""),
          asset_digest: ($asset.digest // ""),
          sums_url: ([ (.assets // [])[] | select(.name == "SHA256SUMS") ][0].browser_download_url // ""),
          zipball_url: (.zipball_url // "") }
    ] | sort_by(.key) | last // empty
' "$WORK/releases.json") || fail "The release list from GitHub is invalid."
[[ -n $RELEASE ]] || fail "No Joplix release found for the '$CHANNEL' channel."

VERSION=$(jq -r '.version' <<< "$RELEASE")
TAG=$(jq -r '.tag' <<< "$RELEASE")
ASSET_NAME=$(jq -r '.asset_name' <<< "$RELEASE")
ASSET_URL=$(jq -r '.asset_url' <<< "$RELEASE")
ASSET_DIGEST=$(jq -r '.asset_digest' <<< "$RELEASE")
SUMS_URL=$(jq -r '.sums_url' <<< "$RELEASE")
ZIPBALL_URL=$(jq -r '.zipball_url' <<< "$RELEASE")
echo "Found: Joplix $VERSION (tag $TAG)"

INSTALLED=
if [[ -e $APP_DIR ]]; then
    [[ -f $APP_DIR/appinfo/info.xml ]] || fail "No valid Joplix installation at $APP_DIR."
    INSTALLED=$(grep -oPm1 '(?<=<version>)[^<]+' "$APP_DIR/appinfo/info.xml" || true)
    echo "Installed: Joplix ${INSTALLED:-unknown}"
    if [[ -n $INSTALLED && $INSTALLED != "$VERSION" &&
          $(printf '%s\n%s\n' "$INSTALLED" "$VERSION" | sort -V | tail -n1) == "$INSTALLED" ]]; then
        fail "The installed version $INSTALLED is newer than the release $VERSION."
    fi
fi

#
# Download and verify the package
#

# Stage next to the live app so the swap is a plain rename.
STAGE=$(mktemp -d "$APP_PARENT/.joplix-install-XXXXXXXX")
PACKAGE="$STAGE/package.zip"

if [[ -n $ASSET_URL ]]; then
    [[ $ASSET_URL == "https://github.com/$REPO/releases/download/"* ]] || fail "Unexpected package URL: $ASSET_URL"
    echo "Downloading $ASSET_NAME ..."
    fetch "$ASSET_URL" "$PACKAGE" "$MAX_ZIP_BYTES" || fail "Download failed: $ASSET_URL"
    EXPECTED=
    if [[ $ASSET_DIGEST == sha256:* ]]; then
        EXPECTED=${ASSET_DIGEST#sha256:}
    elif [[ -n $SUMS_URL ]]; then
        fetch "$SUMS_URL" "$WORK/SHA256SUMS" 1048576 || fail "Could not load SHA256SUMS."
        EXPECTED=$(awk -v name="$ASSET_NAME" '$2 == name || $2 == "*" name { print $1; exit }' "$WORK/SHA256SUMS")
    fi
    [[ -n $EXPECTED ]] || fail "No SHA-256 checksum is published for $ASSET_NAME."
    ACTUAL=$(sha256sum -- "$PACKAGE")
    [[ ${ACTUAL%% *} == "${EXPECTED,,}" ]] || fail "SHA-256 checksum of $ASSET_NAME does not match."
    echo "SHA-256 checksum verified."
else
    [[ $ZIPBALL_URL == "https://api.github.com/repos/$REPO/zipball/"* ]] || fail "Unexpected source archive URL: $ZIPBALL_URL"
    echo "No JoplixNC package attached to the release. Downloading the source archive of tag $TAG ..."
    echo "Note: there is no checksum for the source archive; the download is protected by HTTPS only."
    fetch "$ZIPBALL_URL" "$PACKAGE" "$MAX_ZIP_BYTES" || fail "Download failed: $ZIPBALL_URL"
fi
[[ $(stat -c %s -- "$PACKAGE") -le $MAX_ZIP_BYTES ]] || fail "The package is too large."

# Extract safely: no absolute paths, no "..", no links or special files.
python3 - "$PACKAGE" "$STAGE/extracted" <<'PY' || fail "The package could not be extracted safely."
import stat
import sys
import zipfile

source, destination = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(source) as archive:
    entries = archive.infolist()
    if len(entries) > 10000:
        sys.exit("ZIP contains too many entries.")
    total = 0
    for entry in entries:
        name = entry.filename
        parts = name.rstrip("/").split("/")
        if name.startswith("/") or "\\" in name or ":" in name or any(p in ("", ".", "..") for p in parts):
            sys.exit("ZIP contains an unsafe path: " + name)
        kind = (entry.external_attr >> 16) & 0o170000
        if kind not in (0, stat.S_IFREG, stat.S_IFDIR):
            sys.exit("ZIP contains links or special files: " + name)
        total += entry.file_size
        if total > 200 * 1024 * 1024:
            sys.exit("The extracted ZIP is too large.")
    archive.extractall(destination)
PY

# Detect the package layout: joplix/ subfolder, flat ZIP, or a single top folder (GitHub source archive).
SOURCE=
for candidate in "$STAGE/extracted/joplix" "$STAGE/extracted" "$STAGE/extracted"/*; do
    if [[ -d $candidate && ! -L $candidate && -f $candidate/appinfo/info.xml ]]; then
        SOURCE=$candidate
        break
    fi
done
[[ -n $SOURCE ]] || fail "The package does not contain a Joplix app (appinfo/info.xml is missing)."

PKG_ID=$(grep -oPm1 '(?<=<id>)[^<]+' "$SOURCE/appinfo/info.xml" || true)
PKG_VERSION=$(grep -oPm1 '(?<=<version>)[^<]+' "$SOURCE/appinfo/info.xml" || true)
[[ $PKG_ID == "$APP_ID" ]] || fail "The package does not contain a valid Joplix app."
[[ $PKG_VERSION == "$VERSION" ]] ||
    fail "The version in the package ($PKG_VERSION) does not match the release tag ($VERSION). appinfo/info.xml and the tag must agree."
for required in appinfo/routes.php lib/AppInfo/Application.php js/app.js templates/main.php; do
    [[ -f $SOURCE/$required ]] || fail "Incomplete Joplix package: $required"
done

# Files from the source repository that are not part of the installed app.
for extra in tools docs install assets .github .gitignore .gitattributes; do
    rm -rf -- "${SOURCE:?}/$extra"
done
if [[ -f $APP_DIR/.joplix_keep && ! -e $SOURCE/.joplix_keep ]]; then
    cp -a -- "$APP_DIR/.joplix_keep" "$SOURCE/.joplix_keep"
fi
chown -R "$WEBUSER:$(id -gn "$WEBUSER")" -- "$SOURCE"
chmod -R u=rwX,go=rX -- "$SOURCE"

#
# Swap the app and complete the Nextcloud upgrade
#

BACKUP_DIR=
if [[ -e $APP_DIR ]]; then
    BACKUP_ROOT="$APP_PARENT/.joplix-backups"
    [[ ! -L $BACKUP_ROOT ]] || fail "The backup directory is a symlink."
    mkdir -p -- "$BACKUP_ROOT"
    BACKUP_DIR=$(mktemp -d "$BACKUP_ROOT/joplix-XXXXXXXX")
    mv -- "$APP_DIR" "$BACKUP_DIR/previous"
    echo "App backup: $BACKUP_DIR/previous"
fi
mv -- "$SOURCE" "$APP_DIR"

finish_install() {
    local rc=0
    if [[ -n $BACKUP_DIR ]]; then
        # Runs the app migrations. Exit code 3 means: nothing to upgrade.
        occ upgrade || rc=$?
        [[ $rc == 0 || $rc == 3 ]] || return 1
    fi
    occ app:enable "$APP_ID" || return 1
    [[ $(occ config:app:get "$APP_ID" installed_version | tail -n 1) == "$VERSION" ]] || return 1
}

if ! finish_install; then
    echo "ERROR: Nextcloud could not activate Joplix $VERSION." >&2
    rm -rf -- "$APP_DIR"
    if [[ -n $BACKUP_DIR ]]; then
        mv -- "$BACKUP_DIR/previous" "$APP_DIR"
        echo "The previous app files were restored. Please check the Nextcloud state (occ status, occ upgrade)." >&2
    fi
    exit 1
fi

echo
echo "=========================================="
echo " Joplix successfully installed"
echo " Version: $VERSION"
echo "=========================================="
echo
