<?php
script('joplix','app');
style('joplix','style');
$iconUrl = image_path('joplix','app.svg');
$faviconUrl = image_path('joplix','favicon.svg');
$joplixVersion = 'unknown';
$isAdmin = (bool)($_['isAdmin'] ?? false);
$infoXml = __DIR__ . '/../appinfo/info.xml';
if (is_readable($infoXml)) {
    $infoContent = file_get_contents($infoXml);
    if (preg_match('/<version>([^<]+)<\/version>/', $infoContent, $m)) {
        $joplixVersion = trim($m[1]);
    }
}
/*
 * Markup notes:
 * - #joplix wraps the app and every overlay; css/style.css scopes all rules to it, and menus/dialogs
 *   created by js/app.js are appended to it.
 * - <span class="jp-icon" data-icon="name"> is filled with an inline SVG by hydrateIcons() in js/app.js.
 * - translate="no" (plus the notranslate meta tag set in PageController) stops browsers from offering to
 *   translate the page: the markup is German until translateStaticUi() swaps in the user's language.
 * - Element ids are the contract with js/app.js. Text is German; English comes from JP_EN in js/app.js.
 */
?>
<link rel="icon" type="image/svg+xml" href="<?php p($faviconUrl); ?>">
<div id="joplix" class="notranslate" translate="no">
<div id="joplix-app" class="jp-view-mode jp-no-note" data-pane="list" data-joplix-version="<?php p($joplixVersion); ?>" data-joplix-is-admin="<?php p($isAdmin ? '1' : '0'); ?>">
  <aside class="jp-sidebar" id="jp-sidebar" data-jp-col="nav">
    <div class="jp-brand">
      <img class="jp-logo-img" src="<?php p($iconUrl); ?>" alt="">
      <div class="jp-brand-text"><strong>Joplix</strong><?php if ($isAdmin): ?><a id="jp-brand-update" class="jp-brand-update" href="#" target="_blank" rel="noopener" hidden></a><?php endif; ?></div>
    </div>
    <button class="jp-action" id="jp-new-root" type="button"><span class="jp-icon" data-icon="plus"></span><span>Neues Notizbuch</span></button>
    <div class="jp-nav-title">Notizbücher</div>
    <div id="jp-tree" class="jp-tree"></div>
    <button class="jp-settings-button" id="jp-open-settings" type="button"><span class="jp-icon" data-icon="cog"></span><span>Einstellungen</span></button>
  </aside>
  <!-- Below 1025px the sidebar is a drawer over the list (class jp-nav-open on #joplix-app); this dims the rest. -->
  <div class="jp-nav-backdrop" id="jp-nav-backdrop"></div>

  <div class="jp-resizer" id="jp-resize-left" data-resize="nav" title="Spaltenbreite ändern"></div>

  <section class="jp-list" data-jp-col="list">
    <div class="jp-list-head">
      <button id="jp-nav-toggle" class="jp-icon-btn jp-nav-toggle" type="button" title="Notizbücher" aria-label="Notizbücher" aria-controls="jp-sidebar" aria-expanded="false"><span class="jp-icon" data-icon="menu"></span></button>
      <div class="jp-search-wrap"><span class="jp-icon" data-icon="magnify"></span><input id="jp-search" type="search" placeholder="Notizen durchsuchen …" aria-label="Notizen durchsuchen …"></div>
      <button id="jp-sort-button" class="jp-icon-btn" type="button" title="Sortierung umkehren" aria-label="Sortierung umkehren"><span class="jp-icon" data-icon="sort"></span></button>
      <button id="jp-empty-trash" class="jp-icon-btn jp-trash-only" type="button" title="Papierkorb leeren" aria-label="Papierkorb leeren"><span class="jp-icon" data-icon="delete"></span></button>
      <button id="jp-new-note-main" class="jp-icon-btn primary" type="button" title="Neue Notiz" aria-label="Neue Notiz"><span class="jp-icon" data-icon="plus"></span></button>
    </div>
    <div id="jp-breadcrumb" class="jp-breadcrumb">Bitte Notizbuch auswählen</div>
    <div id="jp-notes" class="jp-notes"><p>Bitte links ein Notizbuch auswählen.</p></div>
    <div id="jp-index-status" class="jp-index-status">Index wird geladen...</div>
  </section>

  <div class="jp-resizer" id="jp-resize-right" data-resize="list" title="Spaltenbreite ändern"></div>

  <main class="jp-content" data-jp-col="note">
    <section id="jp-editor-view" class="jp-editor-view">
      <div class="jp-toolbar">
        <button id="jp-back-to-list" class="jp-icon-btn jp-back" type="button" title="Zurück" aria-label="Zurück"><span class="jp-icon" data-icon="arrowLeft"></span></button>
        <div id="jp-note-path" class="jp-note-path">Keine Notiz geöffnet</div>
        <div class="jp-toolbar-actions">
          <button id="jp-sync-now" class="jp-btn-tertiary" type="button" hidden><span class="jp-icon" data-icon="sync"></span><span>Synchronisieren</span></button>
          <div class="jp-segment jp-note-only" role="group">
            <button id="jp-cancel-edit" type="button"><span class="jp-icon" data-icon="eye"></span><span>Vorschau</span></button>
            <button id="jp-edit-note" type="button"><span class="jp-icon" data-icon="pencil"></span><span>Bearbeiten</span></button>
          </div>
          <button id="jp-save-note" class="primary jp-note-only" type="button" disabled><span class="jp-icon" data-icon="save"></span><span>Speichern</span></button>
          <div class="jp-more">
            <button id="jp-more-button" class="jp-icon-btn" type="button" title="Weitere Aktionen" aria-label="Weitere Aktionen" aria-haspopup="menu" aria-expanded="false"><span class="jp-icon" data-icon="dots"></span></button>
            <div id="jp-more-menu" class="jp-menu" role="menu" hidden>
              <label class="jp-menu-item jp-upload jp-note-only"><span class="jp-icon" data-icon="paperclip"></span><span>Vom Gerät anhängen</span><input id="jp-resource-file" type="file"></label>
              <button id="jp-attach-nextcloud" class="jp-menu-item jp-note-only" type="button" role="menuitem"><span class="jp-icon" data-icon="cloud"></span><span>Aus Nextcloud anhängen</span></button>
              <button id="jp-rebuild-index" class="jp-menu-item" type="button" role="menuitem"><span class="jp-icon" data-icon="refresh"></span><span>Index neu aufbauen</span></button>
              <button id="jp-delete-note" class="jp-menu-item danger jp-note-only" type="button" role="menuitem"><span class="jp-icon" data-icon="delete"></span><span>Notiz löschen</span></button>
            </div>
          </div>
        </div>
      </div>

      <div class="jp-empty">
        <span class="jp-icon" data-icon="note"></span>
        <strong>Keine Notiz geöffnet</strong>
        <span>Wähle eine Notiz aus der Liste oder lege eine neue an.</span>
      </div>

      <div class="jp-note-area">
        <div class="jp-note-inner">
          <div id="jp-trash-bar" class="jp-trash-bar"><span class="jp-icon" data-icon="delete"></span><span class="jp-trash-bar-text">Diese Notiz liegt im Papierkorb.</span><button id="jp-trash-restore" type="button"><span class="jp-icon" data-icon="restore"></span><span>Wiederherstellen</span></button><button id="jp-trash-purge" class="danger" type="button">Endgültig löschen</button></div>
          <input id="jp-title" class="jp-title" placeholder="Titel" aria-label="Titel">
          <div id="jp-meta" class="jp-meta"></div>
          <div id="jp-tags" class="jp-tags"></div>
          <div id="jp-editor-formatbar" class="jp-editor-formatbar" role="toolbar" aria-label="Editor-Werkzeuge">
            <button type="button" data-jp-editor="undo" title="Rückgängig" aria-label="Rückgängig"><span class="jp-icon" data-icon="undo"></span></button>
            <span class="jp-editor-separator"></span>
            <button type="button" data-jp-editor="bold" title="Fett" aria-label="Fett"><span class="jp-icon" data-icon="bold"></span></button>
            <button type="button" data-jp-editor="italic" title="Kursiv" aria-label="Kursiv"><span class="jp-icon" data-icon="italic"></span></button>
            <button type="button" data-jp-editor="marker" title="Markierung einfügen" aria-label="Markierung einfügen"><span class="jp-icon" data-icon="marker"></span></button>
            <button type="button" data-jp-editor="code" title="Code einfügen" aria-label="Code einfügen"><span class="jp-icon" data-icon="code"></span></button>
            <span class="jp-editor-separator"></span>
            <button type="button" data-jp-editor="h2" title="Überschrift" aria-label="Überschrift"><span class="jp-icon" data-icon="heading"></span></button>
            <button type="button" data-jp-editor="ul" title="Aufzählung" aria-label="Aufzählung"><span class="jp-icon" data-icon="listBulleted"></span></button>
            <button type="button" data-jp-editor="ol" title="Nummerierte Liste" aria-label="Nummerierte Liste"><span class="jp-icon" data-icon="listNumbered"></span></button>
            <button type="button" data-jp-editor="todo" title="Checkliste" aria-label="Checkliste"><span class="jp-icon" data-icon="checkbox"></span></button>
            <button type="button" data-jp-editor="hr" title="Trennlinie" aria-label="Trennlinie"><span class="jp-icon" data-icon="minus"></span></button>
            <span class="jp-editor-separator"></span>
            <button type="button" data-jp-editor="link" title="Link einfügen" aria-label="Link einfügen"><span class="jp-icon" data-icon="link"></span></button>
            <button type="button" data-jp-editor="notelink" title="Notiz verlinken" aria-label="Notiz verlinken"><span class="jp-icon" data-icon="note"></span></button>
            <button type="button" data-jp-editor="resource" title="Ressource anhängen" aria-label="Ressource anhängen"><span class="jp-icon" data-icon="paperclip"></span></button>
            <button type="button" data-jp-editor="datetime" title="Aktuelles Datum und Uhrzeit einfügen" aria-label="Aktuelles Datum und Uhrzeit einfügen"><span class="jp-icon" data-icon="clock"></span></button>
          </div>
          <div id="jp-note-display" class="jp-note-display" aria-live="polite"></div>
          <textarea id="jp-body" class="jp-body" placeholder="Notizinhalt" aria-label="Notizinhalt" spellcheck="true"></textarea>
          <div id="jp-resources" class="jp-resources"></div>
        </div>
      </div>
    </section>
  </main>
</div>

<div id="jp-settings-overlay" class="jp-settings-overlay jp-overlay" aria-hidden="true">
  <div class="jp-settings-backdrop jp-backdrop" id="jp-settings-backdrop"></div>
  <section class="jp-settings-modal" role="dialog" aria-modal="true" aria-labelledby="jp-settings-title">
    <aside class="jp-settings-menu">
      <h2 id="jp-settings-title">Joplix-Einstellungen</h2>
      <button class="active" type="button" data-settings-tab="general">Allgemein</button>
      <button type="button" data-settings-tab="view">Aussehen</button>
      <button type="button" data-settings-tab="index">Index</button>
      <button type="button" data-settings-tab="sync">Synchronisation</button>
      <?php if ($isAdmin): ?><button type="button" data-settings-tab="update">Update</button><?php endif; ?>
      <button type="button" data-settings-tab="language">Sprache</button>
      <button type="button" data-settings-tab="privacy">Datenschutz</button>
      <button type="button" data-settings-tab="about">Über</button>
    </aside>
    <div class="jp-settings-panel-area">
      <button id="jp-close-settings" class="jp-settings-close jp-icon-btn" type="button" title="Einstellungen schließen" aria-label="Einstellungen schließen"><span class="jp-icon" data-icon="close"></span></button>
      <section class="jp-settings-pane active" data-settings-section="general">
        <h3>Allgemein</h3>
        <div class="jp-setting-card jp-setting-card-path">
          <label for="jp-setting-joplin-path"><strong>Joplix-Ordner</strong><span>Nextcloud-Ordner mit den Joplix-Daten auswählen oder manuell eingeben</span></label>
          <div class="jp-path-control">
            <input id="jp-setting-joplin-path" list="jp-folder-list" value="/JoplinV2" placeholder="/JoplinV2" autocomplete="off">
            <datalist id="jp-folder-list"></datalist>
          </div>
        </div>
        <div class="jp-setting-card">
          <label for="jp-setting-sort"><strong>Sortierung</strong><span>Legt fest, wie Notizen angezeigt werden</span></label>
          <select id="jp-setting-sort">
            <option value="updated_desc">Neueste zuerst</option>
            <option value="updated_asc">Älteste zuerst</option>
            <option value="title_asc">Titel A-Z</option>
          </select>
        </div>
        <div class="jp-setting-card">
          <label for="jp-setting-office-open-mode"><strong>Office-Dateien öffnen</strong><span>Legt fest, ob die Bearbeitung in einem eigenen Fenster oder in einem neuen Browser-Tab startet.</span></label>
          <select id="jp-setting-office-open-mode">
            <option value="window">Eigenes Fenster</option>
            <option value="tab">Neuer Tab</option>
          </select>
        </div>
        <?php if ($isAdmin): ?><div class="jp-setting-card">
          <label for="jp-setting-update-backup"><strong>Automatische App-Backups</strong><span>Standardmäßig werden keine App-Backups erstellt. Vollständige Nextcloud-Backups bleiben Aufgabe des Administrators.</span></label>
          <select id="jp-setting-update-backup">
            <option value="no">Keine Backups (empfohlen)</option>
            <option value="yes">Backup vor Update erstellen</option>
          </select>
        </div><?php endif; ?>
      </section>
      <section class="jp-settings-pane" data-settings-section="view">
        <h3>Aussehen</h3>
        <div class="jp-setting-card">
          <label><strong>Letzte Position merken</strong><span>Beim Öffnen wieder zum zuletzt verwendeten Notizbuch springen</span></label>
          <button id="jp-setting-remember-toggle" class="jp-switch" type="button" role="switch" aria-checked="true"><span></span></button>
        </div>
        <div class="jp-setting-card jp-setting-card-action">
          <div><strong>Spaltenbreiten</strong><span>Setzt die Breite der Spalten auf den Standard zurück.</span></div>
          <button id="jp-layout-reset" type="button">Zurücksetzen</button>
        </div>
      </section>
      <section class="jp-settings-pane" data-settings-section="index">
        <h3>Index</h3>
        <div class="jp-setting-card jp-setting-card-action">
          <div><strong>Index neu aufbauen</strong><span>Wenn Notizbücher oder Notizen fehlen, kann der lokale Index neu aufgebaut werden.</span></div>
          <button id="jp-settings-rebuild" type="button">Starten</button>
        </div>
      </section>

      <section class="jp-settings-pane" data-settings-section="sync">
        <h3>Synchronisation</h3>
        <div class="jp-setting-card jp-setting-card-static">
          <div><strong>Joplin Server</strong><span>Joplix gleicht den Joplix-Ordner mit einem Joplin Server ab. Der Ordner in Nextcloud ist zugleich eine versionierte Sicherung aller Notizen.</span></div>
        </div>
        <div class="jp-setting-card jp-setting-card-path">
          <label for="jp-sync-url"><strong>Server-Adresse</strong><span>Adresse des Joplin Servers, zum Beispiel https://joplin.example.com</span></label>
          <div class="jp-path-control"><input id="jp-sync-url" type="url" placeholder="https://joplin.example.com" autocomplete="off" spellcheck="false"></div>
        </div>
        <div class="jp-setting-card jp-setting-card-path">
          <label for="jp-sync-email"><strong>E-Mail</strong><span>Anmeldung des Joplin-Server-Kontos</span></label>
          <div class="jp-path-control"><input id="jp-sync-email" type="email" autocomplete="off" spellcheck="false"></div>
        </div>
        <div class="jp-setting-card jp-setting-card-path">
          <label for="jp-sync-password"><strong>Passwort</strong><span>Wird verschlüsselt in Nextcloud gespeichert und nie wieder angezeigt. Leer lassen, um das gespeicherte Passwort zu behalten.</span></label>
          <div class="jp-path-control"><input id="jp-sync-password" type="password" autocomplete="new-password"></div>
        </div>
        <div class="jp-setting-card">
          <label><strong>Synchronisation aktivieren</strong><span>Gleicht beim Öffnen, nach dem Speichern und regelmäßig im Hintergrund ab.</span></label>
          <button id="jp-sync-enabled" class="jp-switch" type="button" role="switch" aria-checked="false"><span></span></button>
        </div>
        <?php if ($isAdmin): ?><div class="jp-setting-card">
          <label><strong>Lokale Server-Adressen erlauben</strong><span>Nötig, wenn der Joplin Server im eigenen Netzwerk oder auf demselben Rechner läuft. Gilt für alle Benutzer.</span></label>
          <button id="jp-sync-allow-local" class="jp-switch" type="button" role="switch" aria-checked="false"><span></span></button>
        </div><?php endif; ?>
        <div class="jp-sync-actions">
          <button id="jp-sync-save" class="primary" type="button">Speichern</button>
          <button id="jp-sync-test" type="button">Verbindung testen</button>
          <button id="jp-sync-run" type="button">Jetzt synchronisieren</button>
        </div>
        <div id="jp-sync-status" class="jp-update-status">Noch nicht synchronisiert.</div>
      </section>

<?php if ($isAdmin): ?>
      <section class="jp-settings-pane" data-settings-section="update">
        <h3>Update</h3>
        <div class="jp-setting-card jp-setting-card-static jp-update-current-version">
          <div><strong>Aktuell installierte Version</strong><span id="jp-update-installed-version">Joplix <?php p($joplixVersion); ?></span></div>
        </div>
        <div class="jp-setting-card jp-setting-card-path">
          <label for="jp-update-repo-url"><strong>GitHub-Adresse</strong><span>Standard: https://github.com/kane9287/joplix-xsync/releases</span></label>
          <div class="jp-path-control"><input id="jp-update-repo-url" value="https://github.com/kane9287/joplix-xsync/releases" autocomplete="off" spellcheck="false"></div>
        </div>
        <div class="jp-setting-card">
          <label for="jp-update-channel"><strong>Release Channel</strong><span>Main zeigt reguläre GitHub-Releases, Beta zeigt Vorabversionen (Pre-releases).</span></label>
          <select id="jp-update-channel">
            <option value="main">Main</option>
            <option value="beta">Beta</option>
          </select>
        </div>
        <div class="jp-setting-card jp-setting-card-action">
          <div><strong>Verfügbare Versionen</strong><span>Release-Liste von GitHub abrufen und ausgewählte Version installieren.</span></div>
          <button id="jp-update-check" type="button">Updates suchen</button>
        </div>
        <div id="jp-update-status" class="jp-update-status">Noch nicht geprüft.</div>
        <div id="jp-update-list" class="jp-update-list"></div>
      </section>
      <?php endif; ?>
      <section class="jp-settings-pane" data-settings-section="language">
        <h3>Sprache</h3>
        <div class="jp-setting-card">
          <label for="jp-setting-language"><strong>Sprache</strong><span>Systemsprache verwendet Deutsch bei einer deutschen Systemsprache, andernfalls Englisch.</span></label>
          <select id="jp-setting-language">
            <option value="system">Systemsprache</option>
            <option value="de">Deutsch</option>
            <option value="en">English</option>
          </select>
        </div>
      </section>
      <section class="jp-settings-pane" data-settings-section="privacy">
        <h3>Datenschutz</h3>
        <div class="jp-setting-card jp-setting-card-static">
          <div><strong>Lokale Speicherung</strong><span>Spaltenbreiten, Sortierung und letzte Position werden nur lokal im Browser gespeichert.</span></div>
        </div>
      </section>
      <section class="jp-settings-pane" data-settings-section="about">
        <h3>Über</h3>
        <div class="jp-setting-card jp-setting-card-static jp-about-card">
          <img class="jp-logo-img" src="<?php p($iconUrl); ?>" alt="">
          <div><strong>Joplix <?php p($joplixVersion); ?></strong><span>Nextcloud-App für deine Joplix-Daten.</span>
          <p class="jp-about-created">Created with <span class="jp-heart" aria-label="love">♥</span> by STONEUBI from Tirol / Austria.</p></div>
        </div>
      </section>
    </div>
  </section>
</div>


<div id="jp-welcome-overlay" class="jp-welcome-overlay jp-overlay" aria-hidden="true">
  <div class="jp-welcome-backdrop jp-backdrop"></div>
  <section class="jp-welcome-modal jp-modal" role="dialog" aria-modal="true" aria-labelledby="jp-welcome-title">
    <button id="jp-welcome-close" class="jp-welcome-close jp-icon-btn" type="button" title="Willkommen schließen" aria-label="Willkommen schließen"><span class="jp-icon" data-icon="close"></span></button>
    <img class="jp-welcome-icon" src="<?php p($iconUrl); ?>" alt="">
    <h2 id="jp-welcome-title">Willkommen bei Joplix</h2>
    <p>Joplix zeigt deine synchronisierten Notizen direkt in Nextcloud an. Für die erste Nutzung muss der Joplix-Ordner korrekt gesetzt und der Index aufgebaut sein. Unter Einstellungen › Synchronisation kann Joplix den Ordner selbst mit einem Joplin Server abgleichen.</p>
    <ol class="jp-welcome-steps">
      <li><strong>Joplix-Ordner prüfen</strong><span>Standard ist <code>/JoplinV2</code>. Der Pfad kann in den Einstellungen geändert werden.</span></li>
      <li><strong>Index aufbauen</strong><span>Wenn Notizen fehlen, in den Einstellungen unter Index den Neuaufbau starten.</span></li>
      <li><strong>Notizen bearbeiten</strong><span>Der Speichern-Button wird nur aktiv, wenn es Änderungen gibt.</span></li>
    </ol>
    <div class="jp-modal-actions">
      <button id="jp-welcome-settings" type="button">Einstellungen öffnen</button>
      <button id="jp-welcome-done" class="primary" type="button">Loslegen</button>
    </div>
  </section>
</div>

<div id="jp-note-context-menu" class="jp-note-context-menu jp-menu" role="menu" aria-hidden="true"></div>

<div id="jp-pdf-viewer-overlay" class="jp-pdf-viewer-overlay jp-overlay" aria-hidden="true">
  <div class="jp-pdf-viewer-backdrop jp-backdrop" id="jp-pdf-viewer-backdrop"></div>
  <section class="jp-pdf-viewer-modal" role="dialog" aria-modal="true" aria-labelledby="jp-pdf-viewer-title">
    <header class="jp-pdf-viewer-head">
      <div>
        <strong id="jp-pdf-viewer-title">PDF anzeigen</strong>
        <span id="jp-pdf-viewer-meta"></span>
      </div>
      <div class="jp-pdf-viewer-actions">
        <a id="jp-pdf-viewer-open" href="#" target="_blank" rel="noopener">Extern öffnen</a>
        <button id="jp-pdf-viewer-close" class="jp-icon-btn" type="button" title="PDF schließen" aria-label="PDF schließen"><span class="jp-icon" data-icon="close"></span></button>
      </div>
    </header>
    <iframe id="jp-pdf-viewer-frame" title="PDF-Anzeige"></iframe>
  </section>
</div>
</div>
