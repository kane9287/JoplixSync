# JoplixSync Installer & Uninstaller

## Overview

JoplixSync can be installed, upgraded, or removed directly from the GitHub releases of [kane9287/JoplixSync](https://github.com/kane9287/JoplixSync/releases).

The installer selects the newest release of the chosen channel by version. If the release has a `JoplixNC_v<version>.zip` (beta: `JoplixNC_v<version>-beta.zip`) asset, that package is used and its SHA-256 checksum must match the digest GitHub publishes for the asset or a `SHA256SUMS` asset. If no package is attached, the installer uses GitHub’s automatic source archive of the release tag; there is no checksum for it, so the download relies on HTTPS alone.

In both cases the version in the tag (for example `joplixsync-2026.11.67` or `v2026.11.67`) must equal `<version>` in `appinfo/info.xml` of the tagged commit, otherwise the package is rejected.

Both scripts use Nextcloud’s configured `apps_paths`, including `custom_apps` when configured. Set `NC_DIR` to the Nextcloud root if it lives outside `/var/www` or more than one instance is found. Set `WEBUSER` if the PHP service account differs from the detected account.

Both variables go after `sudo`, so they reach the script:

```bash
curl -fsSL https://raw.githubusercontent.com/kane9287/JoplixSync/main/install/install.sh | sudo NC_DIR=/var/www/nextcloud bash
```

If several Nextcloud instances are found, the installer lists them and stops; pick one with `NC_DIR`.

---

# Stable Installation

## One-Step Installation

To install the latest stable version of JoplixSync, run:

```bash
curl -fsSL https://raw.githubusercontent.com/kane9287/JoplixSync/main/install/install.sh | sudo bash
```

The installer will:

1. Detect Nextcloud automatically
2. Find the newest stable JoplixSync release
3. Find the matching `JoplixNC_v<version>.zip` package
4. Download the package and verify its SHA-256 checksum before extraction
5. Install or upgrade JoplixSync
6. Complete the Nextcloud app upgrade lifecycle when required
7. Activate JoplixSync
8. Verify the installed version

Example result:

```text
==========================================
 JoplixSync successfully installed
 Version: 2026.09.60
==========================================
```

---

# Beta Installation

To install the latest JoplixSync Beta release, explicitly select the Beta channel:

```bash
curl -fsSL https://raw.githubusercontent.com/kane9287/JoplixSync/main/install/install.sh | sudo bash -s -- beta
```

The installer will only consider releases that:

- Are marked as pre-releases on GitHub
- Have a tag that contains the version
- Contain a matching JoplixSync Beta ZIP package, or can be installed from the source archive of the tag

For example:

```text
JoplixNC_v2026.09.60-beta
JoplixNC_v2026.09.60-beta.zip
```

The Beta channel is intended for testing upcoming releases and fixes.

---

# Upgrade

The One-Step Installer can also be used to upgrade an existing JoplixSync installation.

## Upgrade to the Latest Stable Version

Run:

```bash
curl -fsSL https://raw.githubusercontent.com/kane9287/JoplixSync/main/install/install.sh | sudo bash
```

## Upgrade to the Latest Beta Version

Run:

```bash
curl -fsSL https://raw.githubusercontent.com/kane9287/JoplixSync/main/install/install.sh | sudo bash -s -- beta
```

When an existing JoplixSync installation is detected, the installer will:

- Detect the currently installed version
- Download the latest release for the selected channel
- Create a backup of the existing JoplixSync application
- Install the new application files
- Run the Nextcloud app-specific upgrade lifecycle
- Verify the version stored by Nextcloud
- Ensure that JoplixSync is enabled
- Verify the final installation

This avoids leaving Nextcloud with different application-file and `installed_version` states after an upgrade.

---

# Upgrade Backups

Before replacing an existing JoplixSync installation, the installer creates a backup.

Upgrade backups are stored under the configured app directory. For example:

```text
/var/www/nextcloud/apps/.joplixsync-backups
```

Example:

```text
/var/www/nextcloud/apps/.joplixsync-backups/joplixsync-Ab12Cd34/previous
```

These backups contain the previous JoplixSync application files.

---

# Switching Release Channels

The selected release channel only applies to the current installer run.

Running:

```bash
curl -fsSL https://raw.githubusercontent.com/kane9287/JoplixSync/main/install/install.sh | sudo bash
```

selects the latest **Stable** release.

Running:

```bash
curl -fsSL https://raw.githubusercontent.com/kane9287/JoplixSync/main/install/install.sh | sudo bash -s -- beta
```

selects the latest **Beta** release.

The installer does not permanently change the release-channel setting of the integrated JoplixSync updater.

---

# Standard Uninstallation

The standard uninstaller removes the application while keeping backups.

## Run

```bash
curl -fsSL https://raw.githubusercontent.com/kane9287/JoplixSync/main/install/uninstall.sh | sudo bash
```

The uninstaller will:

- Detect Nextcloud
- Detect the installed JoplixSync version
- Create a backup
- Disable JoplixSync
- Remove the application files

Uninstall backups are stored under the configured app directory. For example:

```text
/var/www/nextcloud/apps/.joplixsync-uninstall
```

---

# Purge Mode

The `--purge` option delegates app removal to Nextcloud’s `occ app:remove joplixsync`. The script keeps app backups and leaves the Nextcloud data directory alone, including users’ `.joplixsync` workspaces and Joplin sync files.

```bash
curl -fsSL https://raw.githubusercontent.com/kane9287/JoplixSync/main/install/uninstall.sh | sudo bash -s -- --purge
```

---

# Backup Locations

## Upgrade Backups

```text
/var/www/nextcloud/apps/.joplixsync-backups
```

## Uninstall Backups

```text
/var/www/nextcloud/apps/.joplixsync-uninstall
```

Example upgrade backup:

```text
/var/www/nextcloud/apps/.joplixsync-backups/joplixsync-Ab12Cd34/previous
```

---

# Supported Package Formats

The installer automatically supports both JoplixSync package layouts.

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
joplixsync/
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

Current JoplixSync releases use versioned package names.

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

The GitHub tag only has to contain the same version, for example `joplixsync-2026.09.60`. Attaching a package is optional: without one, the source archive of the tag is installed.

Example:

```text
Tag:
JoplixNC_v2026.09.60-beta

Package:
JoplixNC_v2026.09.60-beta.zip
```

Legacy packages named only `joplixsync.zip` are no longer used by the current installer.

---

# Troubleshooting

## Verify Installation

```bash
sudo -u www-data php /var/www/nextcloud/occ app:list | grep joplixsync
```

## Verify the Installed JoplixSync Version

```bash
grep -oPm1 '(?<=<version>)[^<]+' \
/var/www/nextcloud/apps/joplixsync/appinfo/info.xml
```

## Verify Nextcloud's Stored JoplixSync Version

```bash
sudo -u www-data php /var/www/nextcloud/occ \
config:app:get joplixsync installed_version
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

# Updating Older JoplixSync Beta Installations

Older JoplixSync Beta versions may use the previous release-package naming scheme.

If an older integrated JoplixSync updater cannot install a current `JoplixNC` release, perform a one-time upgrade using the One-Step Installer:

```bash
curl -fsSL https://raw.githubusercontent.com/kane9287/JoplixSync/main/install/install.sh | sudo bash -s -- beta
```

The installer will upgrade the existing installation to the latest Beta release using the current package naming scheme.

After upgrading to a current JoplixSync version, future updates can again be installed using the integrated JoplixSync updater.

---

# Recovery

The installer stages the verified package next to the live app. If the upgrade command fails, it restores the previous app files. Review Nextcloud’s database state if an upgrade started applying migrations before failing. If the process was forcibly stopped during the swap, the previous app files may still be under `.joplixsync-backups`.

---

# Official Resources

## Repository

https://github.com/kane9287/JoplixSync

JoplixSync Xsync is a fork of https://codeberg.org/Stoneubi/joplixsync

## Releases

https://github.com/kane9287/JoplixSync/releases

---

# License

JoplixSync is free and open-source software licensed under the GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later).

See the repository `LICENSE` file for the complete license text.