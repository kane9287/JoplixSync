# Joplix Xsync
## Forked From: https://codeberg.org/Stoneubi/joplix

### Updated to include sync with a self hosted Joplin sync server.

Joplix is a modern, open-source, notes and productivity application built for seamless Nextcloud integration. It lets you create, organize, edit, and synchronize your notes while keeping your data under your own control.  Joplix is **the** tool that allows you to access and work with your Joplin notes directly through a web browser. Joplix is designed for users who want to keep their notes, documents, and data under their own control.

## Built for Nextcloud
Joplix provides a central workspace for creating, editing, organizing, and synchronizing your notes. Notes can be structured with notebooks and tags. You can add attachments and files directly from your Nextcloud server. 

Joplix is best for self-hosted environments where privacy, data ownership, and independence are important. Your data remains in your own Nextcloud environment, synchronization with a Joplin server keeps your Joplix workspace available across your devices.

## Edit With Office

Office attachments (`docx`, `xlsx`, `pptx`, `odt`, `ods`, `odp`, and the legacy `doc`, `xls`, `ppt`) can be edited in an Office editor installed on the same Nextcloud. Joplix opens a working copy in the editor and writes each save back into the attachment, so the change syncs to Joplin like any other edit.

Supported editors (any one is enough):

| Editor | Nextcloud app |
|---|---|
| Nextcloud Office / Collabora Online (built-in CODE server or a separate Collabora) | `richdocuments` |
| ONLYOFFICE | `onlyoffice` |
| Euro Office | `eurooffice` |

**Settings › Office editor** chooses one when several are installed; *Automatic* takes the first available in the order above. Without an editor, Office attachments offer Download only. ONLYOFFICE converts the legacy `doc`, `xls` and `ppt` formats into a new file, so Joplix does not open those three with it.

Each save in the editor is written into the attachment by the server right away, also when the Joplix tab is closed meanwhile. If the attachment was changed elsewhere in the meantime, the working copy is kept for 30 days in `.joplix/editing` in your files.

## Do Not Edit the Joplix Folder Directly

The Joplix folder (default `/JoplinV2`) holds Joplin's sync files: one `<id>.md` per note, notebook, tag and attachment, plus the attachments in `.resource`. They look like Markdown but are not ordinary Markdown documents: the lines at the end (`id: …`, `parent_id: …`, `type_: 5`) are metadata that Joplin and Joplix read exactly.

**Do not open these files with Nextcloud Text or another Markdown editor in the Files app.** Saving them there rewrites the file as Markdown, for example `type_: 5` becomes `type\_: 5`, and the item is then broken. Joplix shows such an item under *Settings › Synchronisation* as *Not accepted by the Joplin Server: … Not a valid Joplin item (id or type_ missing)*, and the Joplin apps cannot read it either. Nextcloud cannot restrict which app opens files in a folder, so this is up to you. Open notes in Joplix instead.

If a file was damaged this way: download it, correct the metadata lines in a plain text editor (Notepad, VS Code, …), for example `type\_:` back to `type_:`, and upload it again, replacing the file. Nextcloud's *Versions* also keeps the previous, intact version of the file. Do not rename, move or delete files in the folder by hand either: Joplix treats a file that disappears without being deleted in Joplix as *missing* and asks whether to restore it from the Joplin Server.

## Synchronisation with Joplin Server

Joplix can synchronise its Nextcloud folder with a self-hosted [Joplin Server](https://github.com/laurent22/joplin/tree/dev/packages/server) account. Open **Settings › Synchronisation**, enter the server address, e-mail and password, test the connection and enable synchronisation.

- Joplin apps keep synchronising with the Joplin Server. Joplix exchanges changes with the same account in both directions.
- The Joplix folder in Nextcloud is at the same time a backup of the server content. With the Nextcloud *Versions* and *Deleted files* apps enabled, earlier versions of every note and remotely deleted notes stay recoverable.
- A Nextcloud background job keeps the folder current even when Joplix is not open. Background jobs run via cron.
- End-to-end encryption **must** be disabled on the Joplin account.
- The server address must use `https://`. If the Joplin Server has a local or private address, a Nextcloud administrator has to add it under *Local Joplin servers* in the settings tab (for example `joplin.lan` or `192.168.1.20:22300`); only those servers may also use `http://`.
- Joplix deletes notes on the Joplin Server only when they were deleted in Joplix. Files that disappear from the folder in another way are reported as missing; *Synchronise now* asks whether to restore them from the server or delete them there.
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
