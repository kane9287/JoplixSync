<?php
script('joplixsync','app');
style('joplixsync','style');
$iconUrl = image_path('joplixsync','app-dark.svg');
$faviconUrl = image_path('joplixsync','favicon.svg');
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
 *   translate the page, which would also rewrite the rendered note.
 * - Element ids are the contract with js/app.js. Text is English, wrapped in $l->t(); translations are in
 *   l10n/<lang>.json. The language follows the Nextcloud account.
 */
?>
<link rel="icon" type="image/svg+xml" href="<?php p($faviconUrl); ?>">
<div id="joplix" class="notranslate" translate="no">
<div id="joplix-app" class="jp-view-mode jp-no-note" data-pane="list" data-joplix-version="<?php p($joplixVersion); ?>" data-joplix-is-admin="<?php p($isAdmin ? '1' : '0'); ?>">
  <aside class="jp-sidebar" id="jp-sidebar" data-jp-col="nav">
    <div class="jp-brand">
      <img class="jp-logo-img" src="<?php p($iconUrl); ?>" alt="">
      <div class="jp-brand-text"><strong>JoplixSync</strong><?php if ($isAdmin): ?><a id="jp-brand-update" class="jp-brand-update" href="#" target="_blank" rel="noopener" hidden></a><?php endif; ?></div>
    </div>
    <button class="jp-action" id="jp-new-root" type="button"><span class="jp-icon" data-icon="plus"></span><span><?php p($l->t('New notebook')); ?></span></button>
    <div class="jp-nav-title"><?php p($l->t('Notebooks')); ?></div>
    <div id="jp-tree" class="jp-tree"></div>
    <button class="jp-settings-button" id="jp-open-settings" type="button"><span class="jp-icon" data-icon="cog"></span><span><?php p($l->t('Settings')); ?></span></button>
  </aside>
  <!-- Below 1025px the sidebar is a drawer over the list (class jp-nav-open on #joplix-app); this dims the rest. -->
  <div class="jp-nav-backdrop" id="jp-nav-backdrop"></div>

  <div class="jp-resizer" data-resize="nav" title="<?php p($l->t('Resize column')); ?>"></div>

  <section class="jp-list" data-jp-col="list">
    <div class="jp-list-head">
      <button id="jp-nav-toggle" class="jp-icon-btn jp-nav-toggle" type="button" title="<?php p($l->t('Notebooks')); ?>" aria-label="<?php p($l->t('Notebooks')); ?>" aria-controls="jp-sidebar" aria-expanded="false"><span class="jp-icon" data-icon="menu"></span></button>
      <div class="jp-search-wrap"><span class="jp-icon" data-icon="magnify"></span><input id="jp-search" type="search" placeholder="<?php p($l->t('Search notes …')); ?>" aria-label="<?php p($l->t('Search notes …')); ?>"></div>
      <button id="jp-sort-button" class="jp-icon-btn" type="button" title="<?php p($l->t('Reverse sort order')); ?>" aria-label="<?php p($l->t('Reverse sort order')); ?>"><span class="jp-icon" data-icon="sort"></span></button>
      <button id="jp-empty-trash" class="jp-icon-btn jp-trash-only" type="button" title="<?php p($l->t('Empty trash')); ?>" aria-label="<?php p($l->t('Empty trash')); ?>"><span class="jp-icon" data-icon="delete"></span></button>
      <button id="jp-new-note-main" class="jp-icon-btn primary" type="button" title="<?php p($l->t('New note')); ?>" aria-label="<?php p($l->t('New note')); ?>"><span class="jp-icon" data-icon="plus"></span></button>
    </div>
    <div id="jp-breadcrumb" class="jp-breadcrumb"><?php p($l->t('Please select a notebook')); ?></div>
    <div id="jp-notes" class="jp-notes"><p><?php p($l->t('Please select a notebook on the left.')); ?></p></div>
    <div id="jp-index-status" class="jp-index-status"><?php p($l->t('Loading index...')); ?></div>
  </section>

  <div class="jp-resizer" data-resize="list" title="<?php p($l->t('Resize column')); ?>"></div>

  <main class="jp-content" data-jp-col="note">
    <section id="jp-editor-view" class="jp-editor-view">
      <div class="jp-toolbar">
        <button id="jp-back-to-list" class="jp-icon-btn jp-back" type="button" title="<?php p($l->t('Back')); ?>" aria-label="<?php p($l->t('Back')); ?>"><span class="jp-icon" data-icon="arrowLeft"></span></button>
        <div id="jp-note-path" class="jp-note-path"><?php p($l->t('No note open')); ?></div>
        <div class="jp-toolbar-actions">
          <button id="jp-sync-now" class="jp-btn-tertiary" type="button" hidden><span class="jp-icon" data-icon="sync"></span><span><?php p($l->t('Sync')); ?></span></button>
          <div class="jp-segment jp-note-only" role="group">
            <button id="jp-cancel-edit" type="button"><span class="jp-icon" data-icon="eye"></span><span><?php p($l->t('Preview')); ?></span></button>
            <button id="jp-edit-note" type="button"><span class="jp-icon" data-icon="pencil"></span><span><?php p($l->t('Edit')); ?></span></button>
          </div>
          <button id="jp-save-note" class="primary jp-note-only" type="button" disabled><span class="jp-icon" data-icon="save"></span><span><?php p($l->t('Save')); ?></span></button>
          <div class="jp-more">
            <button id="jp-more-button" class="jp-icon-btn" type="button" title="<?php p($l->t('More actions')); ?>" aria-label="<?php p($l->t('More actions')); ?>" aria-haspopup="menu" aria-expanded="false"><span class="jp-icon" data-icon="dots"></span></button>
            <div id="jp-more-menu" class="jp-menu" role="menu" hidden>
              <label class="jp-menu-item jp-upload jp-note-only"><span class="jp-icon" data-icon="paperclip"></span><span><?php p($l->t('Attach from device')); ?></span><input id="jp-resource-file" type="file"></label>
              <button id="jp-attach-nextcloud" class="jp-menu-item jp-note-only" type="button" role="menuitem"><span class="jp-icon" data-icon="cloud"></span><span><?php p($l->t('Attach from Nextcloud')); ?></span></button>
              <button id="jp-rebuild-index" class="jp-menu-item" type="button" role="menuitem"><span class="jp-icon" data-icon="refresh"></span><span><?php p($l->t('Rebuild index')); ?></span></button>
              <button id="jp-delete-note" class="jp-menu-item danger jp-note-only" type="button" role="menuitem"><span class="jp-icon" data-icon="delete"></span><span><?php p($l->t('Delete note')); ?></span></button>
            </div>
          </div>
        </div>
      </div>

      <div class="jp-empty">
        <span class="jp-icon" data-icon="note"></span>
        <strong><?php p($l->t('No note open')); ?></strong>
        <span><?php p($l->t('Select a note from the list or create a new one.')); ?></span>
      </div>

      <div class="jp-note-area">
        <div class="jp-note-inner">
          <div id="jp-trash-bar" class="jp-trash-bar"><span class="jp-icon" data-icon="delete"></span><span class="jp-trash-bar-text"><?php p($l->t('This note is in the trash.')); ?></span><button id="jp-trash-restore" type="button"><span class="jp-icon" data-icon="restore"></span><span><?php p($l->t('Restore')); ?></span></button><button id="jp-trash-purge" class="danger" type="button"><?php p($l->t('Delete permanently')); ?></button></div>
          <input id="jp-title" class="jp-title" placeholder="<?php p($l->t('Title')); ?>" aria-label="<?php p($l->t('Title')); ?>">
          <div id="jp-meta" class="jp-meta"></div>
          <div id="jp-tags" class="jp-tags"></div>
          <div id="jp-editor-formatbar" class="jp-editor-formatbar" role="toolbar" aria-label="<?php p($l->t('Editor tools')); ?>">
            <button type="button" data-jp-editor="undo" title="<?php p($l->t('Undo')); ?>" aria-label="<?php p($l->t('Undo')); ?>"><span class="jp-icon" data-icon="undo"></span></button>
            <span class="jp-editor-separator"></span>
            <button type="button" data-jp-editor="bold" title="<?php p($l->t('Bold')); ?>" aria-label="<?php p($l->t('Bold')); ?>"><span class="jp-icon" data-icon="bold"></span></button>
            <button type="button" data-jp-editor="italic" title="<?php p($l->t('Italic')); ?>" aria-label="<?php p($l->t('Italic')); ?>"><span class="jp-icon" data-icon="italic"></span></button>
            <button type="button" data-jp-editor="marker" title="<?php p($l->t('Insert highlight')); ?>" aria-label="<?php p($l->t('Insert highlight')); ?>"><span class="jp-icon" data-icon="marker"></span></button>
            <button type="button" data-jp-editor="code" title="<?php p($l->t('Insert code')); ?>" aria-label="<?php p($l->t('Insert code')); ?>"><span class="jp-icon" data-icon="code"></span></button>
            <span class="jp-editor-separator"></span>
            <button type="button" data-jp-editor="h2" title="<?php p($l->t('Heading')); ?>" aria-label="<?php p($l->t('Heading')); ?>"><span class="jp-icon" data-icon="heading"></span></button>
            <button type="button" data-jp-editor="ul" title="<?php p($l->t('Bulleted list')); ?>" aria-label="<?php p($l->t('Bulleted list')); ?>"><span class="jp-icon" data-icon="listBulleted"></span></button>
            <button type="button" data-jp-editor="ol" title="<?php p($l->t('Numbered list')); ?>" aria-label="<?php p($l->t('Numbered list')); ?>"><span class="jp-icon" data-icon="listNumbered"></span></button>
            <button type="button" data-jp-editor="todo" title="<?php p($l->t('Checklist')); ?>" aria-label="<?php p($l->t('Checklist')); ?>"><span class="jp-icon" data-icon="checkbox"></span></button>
            <button type="button" data-jp-editor="hr" title="<?php p($l->t('Horizontal rule')); ?>" aria-label="<?php p($l->t('Horizontal rule')); ?>"><span class="jp-icon" data-icon="minus"></span></button>
            <span class="jp-editor-separator"></span>
            <button type="button" data-jp-editor="link" title="<?php p($l->t('Insert link')); ?>" aria-label="<?php p($l->t('Insert link')); ?>"><span class="jp-icon" data-icon="link"></span></button>
            <button type="button" data-jp-editor="notelink" title="<?php p($l->t('Link to note')); ?>" aria-label="<?php p($l->t('Link to note')); ?>"><span class="jp-icon" data-icon="note"></span></button>
            <button type="button" data-jp-editor="resource" title="<?php p($l->t('Attach file')); ?>" aria-label="<?php p($l->t('Attach file')); ?>"><span class="jp-icon" data-icon="paperclip"></span></button>
            <button type="button" data-jp-editor="datetime" title="<?php p($l->t('Insert current date and time')); ?>" aria-label="<?php p($l->t('Insert current date and time')); ?>"><span class="jp-icon" data-icon="clock"></span></button>
          </div>
          <div id="jp-note-display" class="jp-note-display" aria-live="polite"></div>
          <textarea id="jp-body" class="jp-body" placeholder="<?php p($l->t('Note content')); ?>" aria-label="<?php p($l->t('Note content')); ?>" spellcheck="true"></textarea>
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
      <h2 id="jp-settings-title"><?php p($l->t('JoplixSync settings')); ?></h2>
      <button class="active" type="button" data-settings-tab="general"><?php p($l->t('General')); ?></button>
      <button type="button" data-settings-tab="view"><?php p($l->t('Appearance')); ?></button>
      <button type="button" data-settings-tab="index">Index</button>
      <button type="button" data-settings-tab="sync"><?php p($l->t('Synchronisation')); ?></button>
      <?php if ($isAdmin): ?><button type="button" data-settings-tab="update">Update</button><?php endif; ?>
      <button type="button" data-settings-tab="privacy"><?php p($l->t('Privacy')); ?></button>
      <button type="button" data-settings-tab="about"><?php p($l->t('About')); ?></button>
    </aside>
    <div class="jp-settings-panel-area">
      <button id="jp-close-settings" class="jp-settings-close jp-icon-btn" type="button" title="<?php p($l->t('Close settings')); ?>" aria-label="<?php p($l->t('Close settings')); ?>"><span class="jp-icon" data-icon="close"></span></button>
      <section class="jp-settings-pane active" data-settings-section="general">
        <h3><?php p($l->t('General')); ?></h3>
        <div class="jp-setting-card jp-setting-card-path">
          <label for="jp-setting-joplin-path"><strong><?php p($l->t('JoplixSync folder')); ?></strong><span><?php p($l->t('Select the Nextcloud folder containing your JoplixSync data or enter it manually')); ?></span></label>
          <div class="jp-path-control">
            <input id="jp-setting-joplin-path" list="jp-folder-list" value="/JoplinV2" placeholder="/JoplinV2" autocomplete="off">
            <datalist id="jp-folder-list"></datalist>
          </div>
        </div>
        <div class="jp-setting-card">
          <label for="jp-setting-sort"><strong><?php p($l->t('Sorting')); ?></strong><span><?php p($l->t('Controls how notes are displayed')); ?></span></label>
          <select id="jp-setting-sort">
            <option value="updated_desc"><?php p($l->t('Newest first')); ?></option>
            <option value="updated_asc"><?php p($l->t('Oldest first')); ?></option>
            <option value="title_asc"><?php p($l->t('Title A-Z')); ?></option>
          </select>
        </div>
        <div class="jp-setting-card jp-office-setting" hidden>
          <label for="jp-setting-office-editor"><strong><?php p($l->t('Office editor')); ?></strong><span><?php p($l->t('Which installed Nextcloud app edits Office attachments. Automatic uses the first available one.')); ?></span></label>
          <select id="jp-setting-office-editor"><option value="auto"><?php p($l->t('Automatic')); ?></option></select>
        </div>
        <div class="jp-setting-card jp-office-setting" hidden>
          <label for="jp-setting-office-open-mode"><strong><?php p($l->t('Open Office files')); ?></strong><span><?php p($l->t('Choose whether editing opens in a separate window or a new browser tab.')); ?></span></label>
          <select id="jp-setting-office-open-mode">
            <option value="window"><?php p($l->t('Separate window')); ?></option>
            <option value="tab"><?php p($l->t('New tab')); ?></option>
          </select>
        </div>
        <?php if ($isAdmin): ?><div class="jp-setting-card">
          <label for="jp-setting-update-backup"><strong><?php p($l->t('Automatic app backups')); ?></strong><span><?php p($l->t('By default no app backups are created. Complete Nextcloud backups remain the administrator’s responsibility.')); ?></span></label>
          <select id="jp-setting-update-backup">
            <option value="no"><?php p($l->t('No backups (recommended)')); ?></option>
            <option value="yes"><?php p($l->t('Create backup before update')); ?></option>
          </select>
        </div><?php endif; ?>
      </section>
      <section class="jp-settings-pane" data-settings-section="view">
        <h3><?php p($l->t('Appearance')); ?></h3>
        <div class="jp-setting-card">
          <label><strong><?php p($l->t('Remember last position')); ?></strong><span><?php p($l->t('Return to the last used notebook when opening JoplixSync')); ?></span></label>
          <button id="jp-setting-remember-toggle" class="jp-switch" type="button" role="switch" aria-checked="true"><span></span></button>
        </div>
        <div class="jp-setting-card jp-setting-card-action">
          <div><strong><?php p($l->t('Column widths')); ?></strong><span><?php p($l->t('Resets the column widths to their defaults.')); ?></span></div>
          <button id="jp-layout-reset" type="button"><?php p($l->t('Reset')); ?></button>
        </div>
      </section>
      <section class="jp-settings-pane" data-settings-section="index">
        <h3>Index</h3>
        <div class="jp-setting-card jp-setting-card-action">
          <div><strong><?php p($l->t('Rebuild index')); ?></strong><span><?php p($l->t('Rebuild the local index if notebooks or notes are missing.')); ?></span></div>
          <button id="jp-settings-rebuild" type="button"><?php p($l->t('Start')); ?></button>
        </div>
      </section>

      <section class="jp-settings-pane" data-settings-section="sync">
        <h3><?php p($l->t('Synchronisation')); ?></h3>
        <div class="jp-setting-card jp-setting-card-static">
          <div><strong>Joplin Server</strong><span><?php p($l->t('JoplixSync synchronises the JoplixSync folder with a Joplin Server. The folder in Nextcloud doubles as a versioned backup of all notes.')); ?></span></div>
        </div>
        <div class="jp-setting-card jp-setting-card-path">
          <label for="jp-sync-url"><strong><?php p($l->t('Server address')); ?></strong><span><?php p($l->t('Address of the Joplin Server, for example https://joplin.example.com')); ?></span></label>
          <div class="jp-path-control"><input id="jp-sync-url" type="url" placeholder="https://joplin.example.com" autocomplete="off" spellcheck="false"></div>
        </div>
        <div class="jp-setting-card jp-setting-card-path">
          <label for="jp-sync-email"><strong><?php p($l->t('E-mail')); ?></strong><span><?php p($l->t('Login of the Joplin Server account')); ?></span></label>
          <div class="jp-path-control"><input id="jp-sync-email" type="email" autocomplete="off" spellcheck="false"></div>
        </div>
        <div class="jp-setting-card jp-setting-card-path">
          <label for="jp-sync-password"><strong><?php p($l->t('Password')); ?></strong><span><?php p($l->t('Stored encrypted in Nextcloud and never shown again. Leave empty to keep the stored password.')); ?></span></label>
          <div class="jp-path-control"><input id="jp-sync-password" type="password" autocomplete="new-password"></div>
        </div>
        <div class="jp-setting-card">
          <label><strong><?php p($l->t('Enable synchronisation')); ?></strong><span><?php p($l->t('Synchronises on opening, after saving and regularly in the background.')); ?></span></label>
          <button id="jp-sync-enabled" class="jp-switch" type="button" role="switch" aria-checked="false"><span></span></button>
        </div>
        <?php if ($isAdmin): ?><div class="jp-setting-card jp-setting-card-path">
          <label for="jp-sync-local-hosts"><strong><?php p($l->t('Local Joplin servers')); ?></strong><span><?php p($l->t('Administrators only. Joplin Servers in your own network that JoplixSync may connect to, for example joplin.lan or 192.168.1.20:22300, separated by commas. Only these may use http://. Applies to all users.')); ?></span></label>
          <div class="jp-path-control"><input id="jp-sync-local-hosts" autocomplete="off" spellcheck="false"></div>
        </div><?php endif; ?>
        <div class="jp-sync-actions">
          <button id="jp-sync-save" class="primary" type="button"><?php p($l->t('Save')); ?></button>
          <button id="jp-sync-test" type="button"><?php p($l->t('Test connection')); ?></button>
          <button id="jp-sync-run" type="button"><?php p($l->t('Synchronise now')); ?></button>
        </div>
        <div id="jp-sync-status" class="jp-update-status"><?php p($l->t('Not synchronised yet.')); ?></div>
        <div id="jp-sync-rejected" class="jp-sync-rejected" hidden></div>
      </section>

<?php if ($isAdmin): ?>
      <section class="jp-settings-pane" data-settings-section="update">
        <h3>Update</h3>
        <div class="jp-setting-card jp-setting-card-static jp-update-current-version">
          <div><strong><?php p($l->t('Currently installed version')); ?></strong><span id="jp-update-installed-version">JoplixSync <?php p($joplixVersion); ?></span></div>
        </div>
        <div class="jp-setting-card jp-setting-card-path">
          <label for="jp-update-repo-url"><strong><?php p($l->t('GitHub address')); ?></strong><span><?php p($l->t('Releases are installed from this repository. It can only be changed on the server: occ config:app:set joplixsync update_repo_url --value=…')); ?></span></label>
          <div class="jp-path-control"><input id="jp-update-repo-url" value="https://github.com/kane9287/JoplixSync/releases" readonly autocomplete="off" spellcheck="false"></div>
        </div>
        <div class="jp-setting-card">
          <label for="jp-update-channel"><strong><?php p($l->t('Release channel')); ?></strong><span><?php p($l->t('Main shows regular GitHub releases; Beta shows pre-releases.')); ?></span></label>
          <select id="jp-update-channel">
            <option value="main">Main</option>
            <option value="beta">Beta</option>
          </select>
        </div>
        <div class="jp-setting-card jp-setting-card-action">
          <div><strong><?php p($l->t('Available versions')); ?></strong><span><?php p($l->t('Fetch the release list from GitHub and install the selected version.')); ?></span></div>
          <button id="jp-update-check" type="button"><?php p($l->t('Check for updates')); ?></button>
        </div>
        <div id="jp-update-status" class="jp-update-status"><?php p($l->t('Not checked yet.')); ?></div>
        <div id="jp-update-list" class="jp-update-list"></div>
      </section>
      <?php endif; ?>
      <section class="jp-settings-pane" data-settings-section="privacy">
        <h3><?php p($l->t('Privacy')); ?></h3>
        <div class="jp-setting-card jp-setting-card-static">
          <div><strong><?php p($l->t('Local storage')); ?></strong><span><?php p($l->t('Column widths, sorting and the last position are stored locally in the browser.')); ?></span></div>
        </div>
      </section>
      <section class="jp-settings-pane" data-settings-section="about">
        <h3><?php p($l->t('About')); ?></h3>
        <div class="jp-setting-card jp-setting-card-static jp-about-card">
          <img class="jp-logo-img" src="<?php p($iconUrl); ?>" alt="">
          <div><strong>JoplixSync <?php p($joplixVersion); ?></strong><span><?php p($l->t('Nextcloud app for your JoplixSync data.')); ?></span>
          <p class="jp-about-created">Created with <span class="jp-heart" aria-label="love">♥</span> by STONEUBI from Tirol / Austria.</p></div>
        </div>
      </section>
    </div>
  </section>
</div>


<div id="jp-welcome-overlay" class="jp-welcome-overlay jp-overlay" aria-hidden="true">
  <div class="jp-welcome-backdrop jp-backdrop"></div>
  <section class="jp-welcome-modal jp-modal" role="dialog" aria-modal="true" aria-labelledby="jp-welcome-title">
    <button id="jp-welcome-close" class="jp-welcome-close jp-icon-btn" type="button" title="<?php p($l->t('Close welcome')); ?>" aria-label="<?php p($l->t('Close welcome')); ?>"><span class="jp-icon" data-icon="close"></span></button>
    <img class="jp-welcome-icon" src="<?php p($iconUrl); ?>" alt="">
    <h2 id="jp-welcome-title"><?php p($l->t('Welcome to JoplixSync')); ?></h2>
    <p><?php p($l->t('JoplixSync shows your synchronised notes directly in Nextcloud. For first use, the JoplixSync folder must be set correctly and the index built. Under Settings › Synchronisation, JoplixSync can synchronise the folder with a Joplin Server itself.')); ?></p>
    <ol class="jp-welcome-steps">
      <li><strong><?php p($l->t('Check the JoplixSync folder')); ?></strong><span><?php p($l->t('Default is')); ?> <code>/JoplinV2</code>. <?php p($l->t('The path can be changed in the settings.')); ?></span></li>
      <li><strong><?php p($l->t('Build the index')); ?></strong><span><?php p($l->t('If notes are missing, start the rebuild under Index in the settings.')); ?></span></li>
      <li><strong><?php p($l->t('Edit notes')); ?></strong><span><?php p($l->t('The Save button only becomes active when there are changes.')); ?></span></li>
    </ol>
    <div class="jp-modal-actions">
      <button id="jp-welcome-settings" type="button"><?php p($l->t('Open settings')); ?></button>
      <button id="jp-welcome-done" class="primary" type="button"><?php p($l->t('Get started')); ?></button>
    </div>
  </section>
</div>

<div id="jp-note-context-menu" class="jp-note-context-menu jp-menu" role="menu" aria-hidden="true"></div>

</div>
