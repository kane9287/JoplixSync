#!/usr/bin/env bash
#
# Joplix One-Step Installer (install or upgrade)
#
#   curl -fsSL https://raw.githubusercontent.com/kane9287/joplix-xsync/main/install/install.sh | sudo bash
#   curl -fsSL https://raw.githubusercontent.com/kane9287/joplix-xsync/main/install/install.sh | sudo bash -s -- beta
#
# Installs the newest GitHub release of the chosen channel (stable = regular releases, beta = pre-releases).
# Optional environment: NC_DIR (Nextcloud root), WEBUSER (PHP service account).
# The block from "Root prüfen" up to the lock is shared verbatim with uninstall.sh; keep both in step.

set -Eeuo pipefail

APP_ID="joplix"
REPO="kane9287/joplix-xsync"
REPO_API="https://api.github.com/repos/$REPO/releases"
MAX_ZIP_BYTES=$((35 * 1024 * 1024))

case ${1:-stable} in
    stable|main) CHANNEL=stable; BETA=false ;;
    beta)        CHANNEL=beta;   BETA=true ;;
    *) echo "FEHLER: Unbekannter Kanal '${1}'. Erlaubt: stable, beta." >&2; exit 1 ;;
esac

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

#
# Neuestes Release des gewählten Kanals auf GitHub ermitteln
#

WORK=$(mktemp -d)
STAGE=
cleanup() {
    rm -rf -- "$WORK"
    [[ -z $STAGE ]] || rm -rf -- "$STAGE"
}
trap cleanup EXIT

fetch() {
    # fetch <url> <zieldatei> [max-bytes]
    curl -fsSL --proto '=https' --proto-redir '=https' --retry 2 \
        ${3:+--max-filesize "$3"} -H 'User-Agent: Joplix-Installer' -o "$2" "$1"
}

echo "Suche neuestes Joplix-Release ($CHANNEL) in $REPO ..."
fetch "$REPO_API?per_page=50" "$WORK/releases.json" ||
    fail "Release-Liste konnte nicht von GitHub geladen werden."

# Kanal: stable = normale Releases, beta = als Pre-release markierte Releases.
# Die Version steht im Tag (z. B. joplix-2026.11.67, v2026.11.67, JoplixNC_v2026.11.67-beta).
# Paket: angehängtes JoplixNC_v<version>[-beta].zip, sonst das automatische Quellarchiv des Tags.
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
' "$WORK/releases.json") || fail "Release-Liste von GitHub ist ungültig."
[[ -n $RELEASE ]] || fail "Kein passendes Joplix-Release für den Kanal '$CHANNEL' gefunden."

VERSION=$(jq -r '.version' <<< "$RELEASE")
TAG=$(jq -r '.tag' <<< "$RELEASE")
ASSET_NAME=$(jq -r '.asset_name' <<< "$RELEASE")
ASSET_URL=$(jq -r '.asset_url' <<< "$RELEASE")
ASSET_DIGEST=$(jq -r '.asset_digest' <<< "$RELEASE")
SUMS_URL=$(jq -r '.sums_url' <<< "$RELEASE")
ZIPBALL_URL=$(jq -r '.zipball_url' <<< "$RELEASE")
echo "Gefunden: Joplix $VERSION (Tag $TAG)"

INSTALLED=
if [[ -e $APP_DIR ]]; then
    [[ -f $APP_DIR/appinfo/info.xml ]] || fail "Keine gültige Joplix-Installation unter $APP_DIR."
    INSTALLED=$(grep -oPm1 '(?<=<version>)[^<]+' "$APP_DIR/appinfo/info.xml" || true)
    echo "Installiert: Joplix ${INSTALLED:-unbekannt}"
    if [[ -n $INSTALLED && $INSTALLED != "$VERSION" &&
          $(printf '%s\n%s\n' "$INSTALLED" "$VERSION" | sort -V | tail -n1) == "$INSTALLED" ]]; then
        fail "Die installierte Version $INSTALLED ist neuer als das Release $VERSION."
    fi
fi

#
# Paket herunterladen und prüfen
#

# Neben der aktiven App bereitstellen, damit der Austausch ein einfaches Umbenennen ist.
STAGE=$(mktemp -d "$APP_PARENT/.joplix-install-XXXXXXXX")
PACKAGE="$STAGE/package.zip"

if [[ -n $ASSET_URL ]]; then
    [[ $ASSET_URL == "https://github.com/$REPO/releases/download/"* ]] || fail "Unerwartete Paket-Adresse: $ASSET_URL"
    echo "Lade $ASSET_NAME ..."
    fetch "$ASSET_URL" "$PACKAGE" "$MAX_ZIP_BYTES" || fail "Download fehlgeschlagen: $ASSET_URL"
    EXPECTED=
    if [[ $ASSET_DIGEST == sha256:* ]]; then
        EXPECTED=${ASSET_DIGEST#sha256:}
    elif [[ -n $SUMS_URL ]]; then
        fetch "$SUMS_URL" "$WORK/SHA256SUMS" 1048576 || fail "SHA256SUMS konnte nicht geladen werden."
        EXPECTED=$(awk -v name="$ASSET_NAME" '$2 == name || $2 == "*" name { print $1; exit }' "$WORK/SHA256SUMS")
    fi
    [[ -n $EXPECTED ]] || fail "Für $ASSET_NAME ist keine SHA-256-Prüfsumme veröffentlicht."
    ACTUAL=$(sha256sum -- "$PACKAGE")
    [[ ${ACTUAL%% *} == "${EXPECTED,,}" ]] || fail "SHA-256-Prüfsumme von $ASSET_NAME stimmt nicht."
    echo "SHA-256-Prüfsumme geprüft."
else
    [[ $ZIPBALL_URL == "https://api.github.com/repos/$REPO/zipball/"* ]] || fail "Unerwartete Quellarchiv-Adresse: $ZIPBALL_URL"
    echo "Kein JoplixNC-Paket am Release. Lade Quellarchiv des Tags $TAG ..."
    echo "Hinweis: Für das Quellarchiv gibt es keine Prüfsumme; der Download ist nur durch HTTPS geschützt."
    fetch "$ZIPBALL_URL" "$PACKAGE" "$MAX_ZIP_BYTES" || fail "Download fehlgeschlagen: $ZIPBALL_URL"
fi
[[ $(stat -c %s -- "$PACKAGE") -le $MAX_ZIP_BYTES ]] || fail "Paket ist zu groß."

# Sicher entpacken: keine absoluten Pfade, kein "..", keine Links oder Sonderdateien.
python3 - "$PACKAGE" "$STAGE/extracted" <<'PY' || fail "Paket konnte nicht sicher entpackt werden."
import stat
import sys
import zipfile

source, destination = sys.argv[1], sys.argv[2]
with zipfile.ZipFile(source) as archive:
    entries = archive.infolist()
    if len(entries) > 10000:
        sys.exit("ZIP enthält zu viele Einträge.")
    total = 0
    for entry in entries:
        name = entry.filename
        parts = name.rstrip("/").split("/")
        if name.startswith("/") or "\\" in name or ":" in name or any(p in ("", ".", "..") for p in parts):
            sys.exit("ZIP enthält einen unsicheren Pfad: " + name)
        kind = (entry.external_attr >> 16) & 0o170000
        if kind not in (0, stat.S_IFREG, stat.S_IFDIR):
            sys.exit("ZIP enthält Links oder Sonderdateien: " + name)
        total += entry.file_size
        if total > 200 * 1024 * 1024:
            sys.exit("Entpacktes ZIP ist zu groß.")
    archive.extractall(destination)
PY

# Paketaufbau erkennen: joplix/-Unterordner, flaches ZIP oder ein einzelner Ordner (GitHub-Quellarchiv).
SOURCE=
for candidate in "$STAGE/extracted/joplix" "$STAGE/extracted" "$STAGE/extracted"/*; do
    if [[ -d $candidate && ! -L $candidate && -f $candidate/appinfo/info.xml ]]; then
        SOURCE=$candidate
        break
    fi
done
[[ -n $SOURCE ]] || fail "Paket enthält keine Joplix-App (appinfo/info.xml fehlt)."

PKG_ID=$(grep -oPm1 '(?<=<id>)[^<]+' "$SOURCE/appinfo/info.xml" || true)
PKG_VERSION=$(grep -oPm1 '(?<=<version>)[^<]+' "$SOURCE/appinfo/info.xml" || true)
[[ $PKG_ID == "$APP_ID" ]] || fail "Paket enthält keine gültige Joplix-App."
[[ $PKG_VERSION == "$VERSION" ]] ||
    fail "Version im Paket ($PKG_VERSION) passt nicht zum Release-Tag ($VERSION). appinfo/info.xml und Tag müssen übereinstimmen."
for required in appinfo/routes.php lib/AppInfo/Application.php js/app.js templates/main.php; do
    [[ -f $SOURCE/$required ]] || fail "Unvollständiges Joplix-Paket: $required"
done

# Dateien aus dem Quell-Repository, die nicht zur installierten App gehören.
for extra in tools docs install assets .github .gitignore .gitattributes; do
    rm -rf -- "${SOURCE:?}/$extra"
done
if [[ -f $APP_DIR/.joplix_keep && ! -e $SOURCE/.joplix_keep ]]; then
    cp -a -- "$APP_DIR/.joplix_keep" "$SOURCE/.joplix_keep"
fi
chown -R "$WEBUSER:$(id -gn "$WEBUSER")" -- "$SOURCE"
chmod -R u=rwX,go=rX -- "$SOURCE"

#
# App austauschen und Nextcloud-Upgrade abschließen
#

BACKUP_DIR=
if [[ -e $APP_DIR ]]; then
    BACKUP_ROOT="$APP_PARENT/.joplix-backups"
    [[ ! -L $BACKUP_ROOT ]] || fail "Backup-Verzeichnis ist ein Symlink."
    mkdir -p -- "$BACKUP_ROOT"
    BACKUP_DIR=$(mktemp -d "$BACKUP_ROOT/joplix-XXXXXXXX")
    mv -- "$APP_DIR" "$BACKUP_DIR/previous"
    echo "App-Backup: $BACKUP_DIR/previous"
fi
mv -- "$SOURCE" "$APP_DIR"

finish_install() {
    local rc=0
    if [[ -n $BACKUP_DIR ]]; then
        # Führt die App-Migrationen aus. Rückgabewert 3 bedeutet: nichts zu aktualisieren.
        occ upgrade || rc=$?
        [[ $rc == 0 || $rc == 3 ]] || return 1
    fi
    occ app:enable "$APP_ID" || return 1
    [[ $(occ config:app:get "$APP_ID" installed_version) == "$VERSION" ]] || return 1
}

if ! finish_install; then
    echo "FEHLER: Nextcloud konnte Joplix $VERSION nicht aktivieren." >&2
    rm -rf -- "$APP_DIR"
    if [[ -n $BACKUP_DIR ]]; then
        mv -- "$BACKUP_DIR/previous" "$APP_DIR"
        echo "Vorherige App-Dateien wurden wiederhergestellt. Bitte den Nextcloud-Status prüfen (occ status, occ upgrade)." >&2
    fi
    exit 1
fi

echo
echo "=========================================="
echo " Joplix successfully installed"
echo " Version: $VERSION"
echo "=========================================="
echo
