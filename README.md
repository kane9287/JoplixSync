# Joplix Xsync
## Forked From: https://codeberg.org/Stoneubi/joplix

### Updated to include sync with a self hosted Joplin sync server.

Joplix is a modern, open-source, notes and productivity application built for seamless Nextcloud integration. It lets you create, organize, edit, and synchronize your notes while keeping your data under your own control.  Joplix is **the** tool that allows you to access and work with your Joplin notes directly through a web browser. Joplix is designed for users who want to keep their notes, documents, and data under their own control.

## Built for Nextcloud
Joplix provides a central workspace for creating, editing, organizing, and synchronizing your notes. Notes can be structured with notebooks and tags. You can add attachments and files directly from your Nextcloud server. 

EuroOffice **ONLY**: Office documents can also be opened and edited with external Office applications and then seamlessly returned to Joplix.

Joplix is especially best for self-hosted environments where privacy, data ownership, and independence are important. Your data remains in your own Nextcloud environment, synchronization with a Joplin server keeps your Joplix workspace available across your devices.

## Synchronisation with Joplin Server

Joplix can synchronise its Nextcloud folder with a self-hosted [Joplin Server](https://github.com/laurent22/joplin/tree/dev/packages/server) account. Open **Settings › Synchronisation**, enter the server address, e-mail and password, test the connection and enable synchronisation.

- Joplin apps keep synchronising with the Joplin Server. Joplix exchanges changes with the same account in both directions.
- The Joplix folder in Nextcloud is at the same time a backup of the server content. With the Nextcloud *Versions* and *Deleted files* apps enabled, earlier versions of every note and remotely deleted notes stay recoverable.
- A Nextcloud background job keeps the folder current even when Joplix is not open. Background jobs run via cron.
- End-to-end encryption **must** be disabled on the Joplin account.
- If the Joplin Server has a local or private address, a Nextcloud administrator has to switch on *Allow local server addresses* in the settings tab.
- Do not let a Joplin app synchronise with the Joplix folder over WebDAV while this is enabled.

## Installation

Nextcloud administrators can update Joplix through its integrated updater and remove it from the Nextcloud Apps administration. Manual ZIP installations require access to the Nextcloud host. To remove the app while keeping user files, use Nextcloud Apps administration and avoid the separate legacy purge script.

### One-Step Installation

Joplix can be installed on your own server using the **Easy One-Step Installer**. Run the following command as root:

```bash
curl -fsSL https://raw.githubusercontent.com/kane9287/joplix-xsync/main/install/install.sh | sudo bash
```

The installer handles the required installation steps and provides a quick way to get your own Joplix instance up and running.

The installation files and further information are available in the [Joplix Xsync repository on GitHub](https://github.com/kane9287/joplix-xsync). See [install/README.md](install/README.md) for the beta channel, upgrades and removal.

Whether you use Joplix for personal notes, documentation, projects, research, checklists, or everyday organization, it combines the convenience of a modern notes application with the freedom of a self-hosted Nextcloud environment.

**Your notes. Your files. Your cloud. Your control.**

---

Forked from Joplix which was created with ❤️ by Stoneubi from Austria / Tirol.

## Links

- [Website](https://github.com/kane9287/joplix-xsync)
- [Support](https://github.com/kane9287/joplix-xsync/issues)
- [Issue tracker](https://github.com/kane9287/joplix-xsync/issues)
- [Source code](https://github.com/kane9287/joplix-xsync)
- [Releases](https://github.com/kane9287/joplix-xsync/releases)
- [Original project (Stoneubi/joplix on Codeberg)](https://codeberg.org/Stoneubi/joplix)
