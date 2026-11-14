<?php
script('joplix','app');
style('joplix','style');
$iconUrl = image_path('joplix','app.svg');
$iconDarkUrl = image_path('joplix','app-dark.svg');
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
?>
<link rel="icon" type="image/svg+xml" href="<?php p($faviconUrl); ?>">
<div id="joplix-app" data-joplix-version="<?php p($joplixVersion); ?>" data-joplix-is-admin="<?php p($isAdmin ? '1' : '0'); ?>">
  <aside class="jp-sidebar" data-jp-col="left">
    <div class="jp-brand">
      <img class="jp-logo-img jp-logo-img-light" src="<?php p($iconUrl); ?>" alt="">
      <img class="jp-logo-img jp-logo-img-dark" src="<?php p($iconDarkUrl); ?>" alt="">
      <div class="jp-brand-text"><strong>Joplix <?php p($joplixVersion); ?></strong><?php if ($isAdmin): ?><a id="jp-brand-update" class="jp-brand-update" href="#" target="_blank" rel="noopener" hidden></a><?php endif; ?></div>
    </div>
    <button class="jp-action" id="jp-new-root">+ Notizbuch</button>
    <div class="jp-nav-section">
    </div>
    <div class="jp-nav-title">Notizbücher</div>
    <div id="jp-tree" class="jp-tree"></div>
    <button class="jp-settings-button" id="jp-open-settings" title="Einstellungen">⚙ Einstellungen</button>
  </aside>

  <div class="jp-resizer" id="jp-resize-left" data-resize="left" title="Spaltenbreite ändern"></div>

  <main class="jp-content" data-jp-col="middle">
    <section id="jp-editor-view" class="jp-editor-view">
      <div class="jp-toolbar">
        <div id="jp-breadcrumb" class="jp-breadcrumb">Bitte Notizbuch auswählen</div>
        <div class="jp-toolbar-actions"><button id="jp-rebuild-index">Index neu</button><button id="jp-new-note-main">+ Notiz</button></div>
      </div>
      <div class="jp-note-modebar"><button id="jp-edit-note" type="button" class="jp-mode-edit">Bearbeiten</button><button id="jp-save-note-top" type="button" class="primary jp-save-note-top" disabled>Speichern</button><button id="jp-cancel-edit" type="button" class="jp-mode-cancel">Anzeige</button></div>
      <input id="jp-title" class="jp-title" placeholder="Titel">
      <div id="jp-note-path" class="jp-note-path">Keine Notiz geöffnet</div>
      <div id="jp-editor-formatbar" class="jp-editor-formatbar" aria-label="Editor-Werkzeuge">
        <button type="button" data-jp-editor="undo" title="Rückgängig">‹</button>
        <span class="jp-editor-separator"></span>
        <button type="button" data-jp-editor="bold" title="Fett"><strong>B</strong></button>
        <button type="button" data-jp-editor="italic" title="Kursiv"><em>I</em></button>
        <button type="button" data-jp-editor="link" title="Link einfügen">🔗</button>
        <button type="button" data-jp-editor="code" title="Code einfügen">{}</button>
        <button type="button" data-jp-editor="resource" title="Ressource anhängen">📎</button>
        <button type="button" data-jp-editor="ul" title="Aufzählung">≡</button>
        <button type="button" data-jp-editor="ol" title="Nummerierte Liste">☰</button>
        <button type="button" data-jp-editor="todo" title="Checkliste">☑</button>
        <button type="button" data-jp-editor="h2" title="Überschrift">H</button>
        <button type="button" data-jp-editor="hr" title="Trennlinie">•••</button>
        <button type="button" data-jp-editor="datetime" title="Aktuelles Datum und Uhrzeit einfügen">📅</button>
        <button type="button" data-jp-editor="marker" title="Markierung einfügen">🖌</button>
      </div>
      <div id="jp-meta" class="jp-meta"></div>
      <div id="jp-tags" class="jp-tags"></div>
      <div id="jp-note-display" class="jp-note-display" aria-live="polite"></div>
      <textarea id="jp-body" class="jp-body" placeholder="Notizinhalt"></textarea>
      <div class="jp-editor-actions"><button id="jp-save-note" class="primary" disabled>Speichern</button><button id="jp-delete-note">Löschen</button><label class="jp-upload">📎 Ressource<input id="jp-resource-file" type="file"></label></div>
      <div id="jp-resources" class="jp-resources"></div>
    </section>
  </main>

  <div class="jp-resizer" id="jp-resize-right" data-resize="right" title="Spaltenbreite ändern"></div>

  <section class="jp-list" data-jp-col="right">
    <div class="jp-list-head"><input id="jp-search" placeholder="Volltextsuche..."><button id="jp-sort-button">⇅</button></div>
    <div id="jp-index-status" class="jp-index-status">Index wird geladen...</div>
    <div id="jp-notes" class="jp-notes"><p>Bitte links ein Notizbuch auswählen.</p></div>
  </section>
</div>

<div id="jp-settings-overlay" class="jp-settings-overlay" aria-hidden="true">
  <div class="jp-settings-backdrop" id="jp-settings-backdrop"></div>
  <section class="jp-settings-modal" role="dialog" aria-modal="true" aria-labelledby="jp-settings-title">
    <aside class="jp-settings-menu">
      <h2 id="jp-settings-title">Joplix-<br>Einstellungen</h2>
      <button class="active" data-settings-tab="general">Allgemein</button>
      <button data-settings-tab="view">Aussehen</button>
      <button data-settings-tab="index">Index</button>
      <?php if ($isAdmin): ?><button data-settings-tab="update">Update</button><?php endif; ?>
      <button data-settings-tab="language">Sprache</button>
      <button data-settings-tab="privacy">Datenschutz</button>
      <button data-settings-tab="about">Über</button>
    </aside>
    <div class="jp-settings-panel-area">
      <button id="jp-close-settings" class="jp-settings-close" type="button" aria-label="Einstellungen schließen">×</button>
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
        <?php if ($isAdmin): ?><div class="jp-setting-row">
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
        <h4>Layout</h4>
        <div class="jp-segmented">
          <button id="jp-layout-vertical" class="active" type="button">▣ Vertikale Aufteilung</button>
          <button id="jp-layout-reset" type="button">↺ Spalten zurücksetzen</button>
        </div>
      </section>
      <section class="jp-settings-pane" data-settings-section="index">
        <h3>Index</h3>
        <div class="jp-setting-card jp-setting-card-action">
          <div><strong>Index neu aufbauen</strong><span>Wenn Notizbücher oder Notizen fehlen, kann der lokale Index neu aufgebaut werden.</span></div>
          <button id="jp-settings-rebuild" type="button">Starten</button>
        </div>
      </section>

<?php if ($isAdmin): ?>
      <section class="jp-settings-pane" data-settings-section="update">
        <h3>Update</h3>
        <div class="jp-setting-card jp-setting-card-static">
          <div><strong>Update-Quelle</strong><span>Codeberg Repository für Joplix Updates</span></div>
          <div class="jp-segmented jp-update-source">
            <button id="jp-update-source-codeberg" class="active" type="button">Codeberg</button>
          </div>
        </div>
        <div class="jp-setting-card jp-setting-card-static jp-update-current-version">
          <div><strong>Aktuell installierte Version</strong><span id="jp-update-installed-version">Joplix <?php p($joplixVersion); ?></span></div>
        </div>
        <div class="jp-setting-card jp-setting-card-path">
          <label for="jp-update-repo-url"><strong>Codeberg Adresse</strong><span>Standard: https://codeberg.org/Stoneubi/joplix/releases</span></label>
          <input id="jp-update-repo-url" value="https://codeberg.org/Stoneubi/joplix/releases" autocomplete="off">
        </div>
        <div class="jp-setting-card">
          <label for="jp-update-channel"><strong>Release Channel</strong><span>Main entspricht dem Codeberg-Hauptchannel, Beta zeigt Vorabversionen.</span></label>
          <select id="jp-update-channel">
            <option value="main">Main</option>
            <option value="beta">Beta</option>
          </select>
        </div>
        <div class="jp-setting-card jp-setting-card-action">
          <div><strong>Verfügbare Versionen</strong><span>Release-Liste von Codeberg abrufen und ausgewählte Version installieren.</span></div>
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
          <div><strong>Joplix <?php p($joplixVersion); ?></strong><span>Nextcloud-App für deine Joplix-Daten.</span></div>
          <p class="jp-about-created">Created with <span class="jp-heart" aria-label="love">♥</span> by STONEUBI from Tirol / Austria.</p>
        </div>
      </section>
    </div>
  </section>
</div>


<div id="jp-welcome-overlay" class="jp-welcome-overlay" aria-hidden="true">
  <div class="jp-welcome-backdrop"></div>
  <section class="jp-welcome-modal" role="dialog" aria-modal="true" aria-labelledby="jp-welcome-title">
    <button id="jp-welcome-close" class="jp-welcome-close" type="button" aria-label="Willkommen schließen">×</button>
    <div class="jp-welcome-icon">📝</div>
    <h2 id="jp-welcome-title">Willkommen bei Joplix</h2>
    <p>Joplix zeigt deine synchronisierten Notizen direkt in Nextcloud an. Für die erste Nutzung muss der Joplix-Ordner korrekt gesetzt und der Index aufgebaut sein.</p>
    <ol class="jp-welcome-steps">
      <li><strong>Joplix-Ordner prüfen</strong><span>Standard ist <code>/JoplinV2</code>. Der Pfad kann in den Einstellungen geändert werden.</span></li>
      <li><strong>Index aufbauen</strong><span>Wenn Notizen fehlen, in den Einstellungen unter Index den Neuaufbau starten.</span></li>
      <li><strong>Notizen bearbeiten</strong><span>Der Speichern-Button wird nur aktiv, wenn es Änderungen gibt.</span></li>
    </ol>
    <div class="jp-welcome-actions">
      <button id="jp-welcome-settings" type="button">Einstellungen öffnen</button>
      <button id="jp-welcome-done" class="primary" type="button">Loslegen</button>
    </div>
  </section>
</div>

<div id="jp-note-context-menu" class="jp-note-context-menu" aria-hidden="true"></div>

<div id="jp-pdf-viewer-overlay" class="jp-pdf-viewer-overlay" aria-hidden="true">
  <div class="jp-pdf-viewer-backdrop" id="jp-pdf-viewer-backdrop"></div>
  <section class="jp-pdf-viewer-modal" role="dialog" aria-modal="true" aria-labelledby="jp-pdf-viewer-title">
    <header class="jp-pdf-viewer-head">
      <div>
        <strong id="jp-pdf-viewer-title">PDF anzeigen</strong>
        <span id="jp-pdf-viewer-meta"></span>
      </div>
      <div class="jp-pdf-viewer-actions">
        <a id="jp-pdf-viewer-open" href="#" target="_blank" rel="noopener">Extern öffnen</a>
        <button id="jp-pdf-viewer-close" type="button" aria-label="PDF schließen">×</button>
      </div>
    </header>
    <iframe id="jp-pdf-viewer-frame" title="PDF-Anzeige"></iframe>
  </section>
</div>

