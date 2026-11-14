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

for cmd in curl unzip; do
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

#
# Nextcloud finden
#

OCC=$(find /var/www -type f -name occ 2>/dev/null | head -n1)

if [ -z "$OCC" ]; then
    echo "FEHLER: Keine Nextcloud Installation gefunden."
    exit 1
fi

NC_DIR=$(dirname "$OCC")

echo "Nextcloud gefunden:"
echo "  $NC_DIR"

#
# PHP finden
#

PHP_BIN=$(command -v php)

if [ -z "$PHP_BIN" ]; then
    echo "FEHLER: PHP nicht gefunden."
    exit 1
fi

#
# Webserver User
#

WEBUSER="www-data"

for USER in www-data apache http nginx; do
    if id "$USER" >/dev/null 2>&1; then
        WEBUSER="$USER"
        break
    fi
done

echo "Webserver User: $WEBUSER"
echo

#
# Release-Kanal und neueste passende Version
#

CHANNEL="${1:-main}"

case "$CHANNEL" in
    main|stable)
        CHANNEL="main"
        echo "Suche neueste stabile Joplix-Version ..."
        ;;
    beta)
        echo "Suche neueste Joplix-Beta-Version ..."
        ;;
    *)
        echo "FEHLER: Unbekannter Release-Kanal: $CHANNEL"
        echo "Verwendung: $0 [main|stable|beta]"
        exit 1
        ;;
esac

JSON=$(curl -fsSL "$REPO_API")

if [ "$CHANNEL" = "beta" ]; then
    RELEASE=$(echo "$JSON" | jq -c '
        map(select(
            .draft == false and
            .prerelease == true and
            (.tag_name | test("^JoplixNC_v[0-9]+\\.[0-9]+\\.[0-9]+-beta$"))
        ))
        | sort_by(.created_at)
        | reverse
        | .[0] // empty
    ')
    ASSET_REGEX='^JoplixNC_v[0-9]+\.[0-9]+\.[0-9]+-beta\.zip$'
else
    RELEASE=$(echo "$JSON" | jq -c '
        map(select(
            .draft == false and
            .prerelease == false and
            (.tag_name | test("^JoplixNC_v[0-9]+\\.[0-9]+\\.[0-9]+$"))
        ))
        | sort_by(.created_at)
        | reverse
        | .[0] // empty
    ')
    ASSET_REGEX='^JoplixNC_v[0-9]+\.[0-9]+\.[0-9]+\.zip$'
fi

if [ -z "$RELEASE" ] || [ "$RELEASE" = "null" ]; then
    echo "FEHLER: Keine passende Joplix-Version für Kanal $CHANNEL gefunden."
    exit 1
fi

TAG=$(echo "$RELEASE" | jq -r '.tag_name')

DOWNLOAD_URL=$(echo "$RELEASE" | jq -r --arg re "$ASSET_REGEX" '
    .assets[]
    | select(.name | test($re))
    | .browser_download_url
' | head -n1)

ASSET_NAME=$(echo "$RELEASE" | jq -r --arg re "$ASSET_REGEX" '
    .assets[]
    | select(.name | test($re))
    | .name
' | head -n1)

if [ -z "$DOWNLOAD_URL" ] || [ "$DOWNLOAD_URL" = "null" ]; then
    echo "FEHLER: Kein passendes JoplixNC-ZIP im Release $TAG gefunden."
    exit 1
fi

RELEASE_VERSION="${TAG#JoplixNC_v}"
RELEASE_VERSION="${RELEASE_VERSION%-beta}"

echo "Gefundener Release: $TAG"
echo "Paket: $ASSET_NAME"
echo

#
# Bereits aktuell?
#

APP_DIR="$NC_DIR/apps/$APP_ID"
HAD_EXISTING_APP=0

if [ -f "$APP_DIR/appinfo/info.xml" ]; then

    HAD_EXISTING_APP=1

    INSTALLED_VERSION=$(
        grep -oPm1 '(?<=<version>)[^<]+' \
        "$APP_DIR/appinfo/info.xml" \
        || true
    )

    if [ "$INSTALLED_VERSION" = "$RELEASE_VERSION" ]; then

        NC_INSTALLED_VERSION=$(
            sudo -u "$WEBUSER" \
                "$PHP_BIN" "$OCC" config:app:get "$APP_ID" installed_version \
                2>/dev/null \
                || true
        )

        if [ "$NC_INSTALLED_VERSION" = "$INSTALLED_VERSION" ]; then
            echo "Joplix ist bereits aktuell."
            echo "Installierte Version: $INSTALLED_VERSION"
            exit 0
        fi

        echo "Joplix-Dateien sind bereits Version $INSTALLED_VERSION,"
        echo "aber Nextcloud hat noch installed_version=${NC_INSTALLED_VERSION:-unbekannt} gespeichert."
        echo "Die Installation wird fortgesetzt, um den App-Upgrade-Lifecycle sauber abzuschließen."
        echo

    fi

fi

#
# Download
#

TMPDIR=$(mktemp -d)
ZIPFILE="$TMPDIR/$ASSET_NAME"

echo "Lade Release herunter ..."

curl -fL \
     -o "$ZIPFILE" \
     "$DOWNLOAD_URL"

#
# Entpacken
#

WORKDIR="$TMPDIR/unpack"

mkdir -p "$WORKDIR"

unzip -q "$ZIPFILE" -d "$WORKDIR"

#
# ZIP-Typ erkennen
#

if [ -f "$WORKDIR/appinfo/info.xml" ]; then

    SOURCE_DIR="$WORKDIR"

    echo "Paketformat: Flat ZIP"

elif [ -f "$WORKDIR/joplix/appinfo/info.xml" ]; then

    SOURCE_DIR="$WORKDIR/joplix"

    echo "Paketformat: Nextcloud ZIP"

else

    echo "FEHLER: appinfo/info.xml nicht gefunden."

    rm -rf "$TMPDIR"
    exit 1

fi

echo

#
# Backup
#

if [ -d "$APP_DIR" ]; then

    CURRENT_VERSION=$(
        grep -oPm1 '(?<=<version>)[^<]+' \
        "$APP_DIR/appinfo/info.xml" \
        || true
    )

    echo "Vorhandene Joplix-Version: ${CURRENT_VERSION:-unbekannt}"

    BACKUP_DIR="$NC_DIR/apps/.joplix-backups"

    mkdir -p "$BACKUP_DIR"

    mv \
        "$APP_DIR" \
        "$BACKUP_DIR/joplix-$(date +%Y%m%d%H%M%S)"

    echo "Backup erstellt."

fi

#
# Installation
#

mkdir -p "$APP_DIR"

cp -a "$SOURCE_DIR"/. "$APP_DIR"/

chown -R "$WEBUSER:$WEBUSER" "$APP_DIR"

echo "Dateien installiert."
echo

#
# Nextcloud App-Upgrade sauber abschließen
#
# Beim Ersetzen einer bereits installierten App sieht Nextcloud sofort die neue
# Version aus appinfo/info.xml, während in der App-Konfiguration noch die alte
# installed_version steht. Bleibt dieser Zustand bestehen, zeigt Nextcloud beim
# nächsten Seitenaufruf die globale Seite "App-Aktualisierung notwendig".
#
# Der integrierte Joplix-Updater verhindert das mit IAppManager::upgradeApp().
# Der One-Step-Installer führt deshalb bei einem bestehenden Joplix exakt
# denselben app-spezifischen Upgrade-Lifecycle aus.
#

if [ "$HAD_EXISTING_APP" -eq 1 ]; then

    EXPECTED_VERSION=$(
        grep -oPm1 '(?<=<version>)[^<]+' \
        "$APP_DIR/appinfo/info.xml" \
        || true
    )

    if [ -z "$EXPECTED_VERSION" ]; then
        echo "FEHLER: Neue Joplix-Version konnte nicht aus appinfo/info.xml gelesen werden."
        exit 1
    fi

    echo "Schließe Joplix-App-Aktualisierung in Nextcloud ab ..."

    if ! (cd "$NC_DIR" && sudo -u "$WEBUSER" "$PHP_BIN" -r '
        require "lib/base.php";
        $appId = "joplix";
        $expected = (string)($argv[1] ?? "");
        $manager = \OC::$server->get(\OCP\App\IAppManager::class);
        if (!$manager->upgradeApp($appId)) {
            fwrite(STDERR, "Nextcloud konnte die Joplix-App-Aktualisierung nicht abschließen.\n");
            exit(2);
        }
        $config = \OCP\Server::get(\OCP\IConfig::class);
        $installed = (string)$config->getAppValue($appId, "installed_version", "");
        if ($installed !== $expected) {
            fwrite(STDERR, "Nextcloud meldet nach dem Upgrade installed_version=" . $installed . " statt " . $expected . "\n");
            exit(3);
        }
    ' "$EXPECTED_VERSION"); then
        echo "FEHLER: Der Nextcloud-App-Upgrade-Lifecycle für Joplix ist fehlgeschlagen."
        exit 1
    fi

    echo "Nextcloud-App-Aktualisierung abgeschlossen: $EXPECTED_VERSION"
    echo

fi

#
# Aktivieren
#

echo "Aktiviere Joplix ..."

sudo -u "$WEBUSER" \
    "$PHP_BIN" "$OCC" app:enable joplix

echo

#
# Abschlussprüfung
#

if [ ! -f "$APP_DIR/appinfo/info.xml" ]; then

    echo "FEHLER: Installation unvollständig."
    exit 1

fi

INSTALLED_VERSION=$(
    grep -oPm1 '(?<=<version>)[^<]+' \
    "$APP_DIR/appinfo/info.xml"
)

rm -rf "$TMPDIR"

echo
echo "=========================================="
echo " Joplix erfolgreich installiert"
echo " Version: $INSTALLED_VERSION"
echo "=========================================="
echo