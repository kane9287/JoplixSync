# Joplix Installer & Uninstaller

## Overview

Joplix can be installed, upgraded, removed, or completely purged directly from the official Codeberg releases.

The installer automatically selects the correct package for the requested release channel.

---

# Stable Installation

## One-Step Installation

To install the latest stable version of Joplix, run:

```bash
curl -fsSL https://codeberg.org/Stoneubi/joplix/raw/branch/main/install/install.sh | sudo bash
```

The installer will:

1. Detect Nextcloud automatically
2. Find the newest stable Joplix release
3. Find the matching `JoplixNC_v<version>.zip` package
4. Download the package
5. Install or upgrade Joplix
6. Complete the Nextcloud app upgrade lifecycle when required
7. Activate Joplix
8. Verify the installed version

Example result:

```text
==========================================
 Joplix successfully installed
 Version: 2026.09.60
==========================================
```

---

# Beta Installation

To install the latest Joplix Beta release, explicitly select the Beta channel:

```bash
curl -fsSL https://codeberg.org/Stoneubi/joplix/raw/branch/main/install/install.sh | sudo bash -s -- beta
```

The installer will only consider releases that:

- Are marked as pre-releases on Codeberg
- Use a tag matching the Joplix Beta naming scheme
- Contain a matching Joplix Beta ZIP package

For example:

```text
JoplixNC_v2026.09.60-beta
JoplixNC_v2026.09.60-beta.zip
```

The Beta channel is intended for testing upcoming releases and fixes.

---

# Upgrade

The One-Step Installer can also be used to upgrade an existing Joplix installation.

## Upgrade to the Latest Stable Version

Run:

```bash
curl -fsSL https://codeberg.org/Stoneubi/joplix/raw/branch/main/install/install.sh | sudo bash
```

## Upgrade to the Latest Beta Version

Run:

```bash
curl -fsSL https://codeberg.org/Stoneubi/joplix/raw/branch/main/install/install.sh | sudo bash -s -- beta
```

When an existing Joplix installation is detected, the installer will:

- Detect the currently installed version
- Download the latest release for the selected channel
- Create a backup of the existing Joplix application
- Install the new application files
- Run the Nextcloud app-specific upgrade lifecycle
- Verify the version stored by Nextcloud
- Ensure that Joplix is enabled
- Verify the final installation

This avoids leaving Nextcloud with different application-file and `installed_version` states after an upgrade.

---

# Upgrade Backups

Before replacing an existing Joplix installation, the installer creates a backup.

Upgrade backups are stored in:

```text
/var/www/nextcloud/apps/.joplix-backups
```

Example:

```text
/var/www/nextcloud/apps/.joplix-backups/joplix-20260916195335
```

These backups contain the previous Joplix application files.

---

# Switching Release Channels

The selected release channel only applies to the current installer run.

Running:

```bash
curl -fsSL https://codeberg.org/Stoneubi/joplix/raw/branch/main/install/install.sh | sudo bash
```

selects the latest **Stable** release.

Running:

```bash
curl -fsSL https://codeberg.org/Stoneubi/joplix/raw/branch/main/install/install.sh | sudo bash -s -- beta
```

selects the latest **Beta** release.

The installer does not permanently change the release-channel setting of the integrated Joplix updater.

---

# Standard Uninstallation

The standard uninstaller removes the application while keeping backups.

## Run

```bash
curl -fsSL https://codeberg.org/Stoneubi/joplix/raw/branch/main/install/uninstall.sh | sudo bash
```

The uninstaller will:

- Detect Nextcloud
- Detect the installed Joplix version
- Create a backup
- Disable Joplix
- Remove the application files

Uninstall backups are stored in:

```text
/var/www/nextcloud/apps/.joplix-uninstall
```

---

# Purge Mode

## Overview

Purge mode completely removes Joplix from the server.

In addition to normal uninstallation, it may also remove:

- Nextcloud app configuration
- Joplix app data
- Installer backups
- Uninstaller backups
- Remaining Joplix-related directories in the Nextcloud data directory

Use purge mode only when Joplix and its associated application data should be removed completely.

## Run Purge Mode

```bash
curl -fsSL https://codeberg.org/Stoneubi/joplix/raw/branch/main/install/uninstall.sh | sudo bash -s -- --purge
```

Example:

```text
PURGE MODE ACTIVE

Removing app configuration...
Removing Joplix app data...
Removing installer backups...
Removing uninstall backups...
Running maintenance repair...

Joplix completely removed.
```

---

# Backup Locations

## Upgrade Backups

```text
/var/www/nextcloud/apps/.joplix-backups
```

## Uninstall Backups

```text
/var/www/nextcloud/apps/.joplix-uninstall
```

Example upgrade backup:

```text
/var/www/nextcloud/apps/.joplix-backups/joplix-20260916195335
```

---

# Supported Package Formats

The installer automatically supports both Joplix package layouts.

## Flat ZIP

```text
appinfo/
lib/
templates/
js/
css/
img/
```

## Traditional Nextcloud ZIP

```text
joplix/
├── appinfo/
├── lib/
├── templates/
├── js/
├── css/
└── img/
```

No manual package-format selection is required.

---

# Release Package Naming

Current Joplix releases use versioned package names.

## Stable

```text
JoplixNC_v<version>.zip
```

Example:

```text
JoplixNC_v2026.09.60.zip
```

## Beta

```text
JoplixNC_v<version>-beta.zip
```

Example:

```text
JoplixNC_v2026.09.60-beta.zip
```

The corresponding Codeberg tag uses the same name without `.zip`.

Example:

```text
Tag:
JoplixNC_v2026.09.60-beta

Package:
JoplixNC_v2026.09.60-beta.zip
```

Legacy packages named only `joplix.zip` are no longer used by the current installer.

---

# Troubleshooting

## Verify Installation

```bash
sudo -u www-data php /var/www/nextcloud/occ app:list | grep joplix
```

## Verify the Installed Joplix Version

```bash
grep -oPm1 '(?<=<version>)[^<]+' \
/var/www/nextcloud/apps/joplix/appinfo/info.xml
```

## Verify Nextcloud's Stored Joplix Version

```bash
sudo -u www-data php /var/www/nextcloud/occ \
config:app:get joplix installed_version
```

The version reported by `appinfo/info.xml` and Nextcloud's stored `installed_version` should match after a successful upgrade.

## Check Nextcloud Status

```bash
sudo -u www-data php /var/www/nextcloud/occ status
```

## Run Nextcloud Repair

If required for general Nextcloud maintenance:

```bash
sudo -u www-data php /var/www/nextcloud/occ maintenance:repair
```

---

# Updating Older Joplix Beta Installations

Older Joplix Beta versions may use the previous release-package naming scheme.

If an older integrated Joplix updater cannot install a current `JoplixNC` release, perform a one-time upgrade using the One-Step Installer:

```bash
curl -fsSL https://codeberg.org/Stoneubi/joplix/raw/branch/main/install/install.sh | sudo bash -s -- beta
```

The installer will upgrade the existing installation to the latest Beta release using the current package naming scheme.

After upgrading to a current Joplix version, future updates can again be installed using the integrated Joplix updater.

---

# Safety Notice

Standard upgrades create a backup of the existing Joplix application before replacing it.

Purge mode may permanently remove:

- App configuration
- Joplix app data
- Backup directories
- Cache files

Always ensure that you have a valid backup before executing purge mode.

---

# Official Resources

## Repository

https://codeberg.org/Stoneubi/joplix

## Releases

https://codeberg.org/Stoneubi/joplix/releases

---

# License

Joplix is free and open-source software licensed under the GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later).

See the repository `LICENSE` file for the complete license text.