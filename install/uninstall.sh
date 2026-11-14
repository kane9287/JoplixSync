#!/usr/bin/env bash

set -Eeuo pipefail

APP_ID="joplix"

PURGE=false

for arg in "$@"; do
    case "$arg" in
        --purge)
            PURGE=true
            ;;
    esac
done

echo
echo "=========================================="
echo "         Joplix Uninstaller"
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
# Webserver User ermitteln
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

APP_DIR="$NC_DIR/apps/$APP_ID"

#
# Existiert Joplix?
#

if [ ! -d "$APP_DIR" ]; then

    echo "Joplix ist nicht installiert."
    exit 0

fi

#
# Version anzeigen
#

CURRENT_VERSION=$(
    grep -oPm1 '(?<=<version>)[^<]+' \
    "$APP_DIR/appinfo/info.xml" \
    2>/dev/null || true
)

echo "Gefundene Version: ${CURRENT_VERSION:-unbekannt}"
echo

#
# Backup
#

BACKUP_ROOT="$NC_DIR/apps/.joplix-uninstall"

mkdir -p "$BACKUP_ROOT"

TIMESTAMP=$(date +%Y%m%d%H%M%S)

BACKUP_DIR="$BACKUP_ROOT/joplix-$TIMESTAMP"

cp -a "$APP_DIR" "$BACKUP_DIR"

echo "Backup erstellt:"
echo "  $BACKUP_DIR"
echo

#
# App deaktivieren
#

echo "Deaktiviere Joplix ..."

sudo -u "$WEBUSER" \
    "$PHP_BIN" "$OCC" app:disable "$APP_ID" \
    >/dev/null 2>&1 || true

echo "OK"
echo

#
# Purge Modus
#

if [ "$PURGE" = true ]; then

    echo "=========================================="
    echo "          PURGE MODUS AKTIV"
    echo "=========================================="
    echo

    #
    # App komplett entfernen
    #

    echo "Entferne App-Konfiguration ..."

    sudo -u "$WEBUSER" \
        "$PHP_BIN" "$OCC" app:remove "$APP_ID" \
        >/dev/null 2>&1 || true

    echo "OK"
    echo

    #
    # Dataverzeichnis finden
    #

    DATA_DIR=$(
        sudo -u "$WEBUSER" \
        "$PHP_BIN" "$OCC" config:system:get datadirectory \
        2>/dev/null || true
    )

    if [ -n "$DATA_DIR" ] && [ -d "$DATA_DIR" ]; then

        echo "Suche verbleibende Joplix-Daten ..."

        find "$DATA_DIR" \
            -type d \
            -iname "*joplix*" \
            2>/dev/null | while read -r DIR
        do

            echo "Entferne: $DIR"

            rm -rf "$DIR"

        done

        echo

    fi

fi

#
# App-Dateien löschen
#

echo "Entferne App-Dateien ..."

rm -rf "$APP_DIR"

echo "OK"
echo

#
# Purge: Backups entfernen
#

if [ "$PURGE" = true ]; then

    if [ -d "$NC_DIR/apps/.joplix-backups" ]; then

        echo "Entferne Upgrade-Backups ..."

        rm -rf "$NC_DIR/apps/.joplix-backups"

        echo "OK"
        echo

    fi

    if [ -d "$NC_DIR/apps/.joplix-uninstall" ]; then

        echo "Entferne Uninstall-Backups ..."

        rm -rf "$NC_DIR/apps/.joplix-uninstall"

        echo "OK"
        echo

    fi

fi

#
# Abschlussprüfung
#

if [ -d "$APP_DIR" ]; then

    echo "FEHLER: App-Verzeichnis konnte nicht entfernt werden."
    exit 1

fi

echo "=========================================="

if [ "$PURGE" = true ]; then

    echo " Joplix vollständig entfernt (PURGE)"

else

    echo " Joplix erfolgreich deinstalliert"

fi

echo "=========================================="
echo