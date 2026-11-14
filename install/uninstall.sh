
    
  
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

