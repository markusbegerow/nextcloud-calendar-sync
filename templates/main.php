<?php
script('calendar-sync', 'vendor/jquery.min');
script('calendar-sync', 'calendar-sync-main');
style('calendar-sync', 'style');
?>

<div id="app">
    <div id="app-navigation">
        <div class="app-navigation-new">
            <button id="add-config-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?php p($l->t('New Configuration')); ?>
            </button>
        </div>
        <div class="app-navigation-personal">
            <ul id="config-nav-list"></ul>
        </div>
        <div id="app-settings">
            <div class="cw-settings-actions">
                <button class="cw-settings-btn" id="info-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <?php p($l->t('Info')); ?>
                </button>
                <button class="cw-settings-btn" id="settings-btn">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <?php p($l->t('Settings')); ?>
                </button>
            </div>
        </div>
    </div>

    <div id="app-content">
        <!-- Empty state -->
        <div id="no-config-state">
            <div class="empty-content">
                <svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.3"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <h2><?php p($l->t('Calendar Sync')); ?></h2>
                <p><?php p($l->t('Select a configuration from the sidebar or create a new one to get started.')); ?></p>
                <button id="add-first-config-btn" class="primary"><?php p($l->t('+ New Configuration')); ?></button>
            </div>
        </div>

        <!-- Config detail view -->
        <div id="config-detail" style="display:none">
            <div class="cs-detail-wrapper">

                <div class="cs-detail-header">
                    <div class="cs-detail-title">
                        <h2 id="detail-config-name"></h2>
                        <span id="detail-status-badge" class="config-status"></span>
                    </div>
                    <div class="cs-detail-actions">
                        <button id="detail-sync-btn" class="primary">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="currentColor" style="vertical-align:middle;margin-right:4px"><path d="M12 4V1L8 5l4 4V6c3.31 0 6 2.69 6 6 0 1.01-.25 1.97-.7 2.8l1.46 1.46C19.54 15.03 20 13.57 20 12c0-4.42-3.58-8-8-8zm0 14c-3.31 0-6-2.69-6-6 0-1.01.25-1.97.7-2.8L5.24 7.74C4.46 8.97 4 10.43 4 12c0 4.42 3.58 8 8 8v3l4-4-4-4v3z"/></svg><?php p($l->t('Sync Now')); ?>
                        </button>
                        <button id="detail-edit-btn"><?php p($l->t('Edit')); ?></button>
                        <button id="detail-test-btn"><?php p($l->t('Test')); ?></button>
                        <button id="detail-delete-btn" class="cs-danger-btn"><?php p($l->t('Delete')); ?></button>
                    </div>
                </div>

                <dl class="cs-detail-info">
                    <div class="cs-info-item">
                        <dt><?php p($l->t('External server')); ?></dt>
                        <dd id="detail-url"></dd>
                    </div>
                    <div class="cs-info-item">
                        <dt><?php p($l->t('Sync direction')); ?></dt>
                        <dd id="detail-direction"></dd>
                    </div>
                    <div class="cs-info-item">
                        <dt><?php p($l->t('Duplicate handling')); ?></dt>
                        <dd id="detail-duplicates"></dd>
                    </div>
                    <div class="cs-info-item">
                        <dt><?php p($l->t('Last sync')); ?></dt>
                        <dd id="detail-last-sync"></dd>
                    </div>
                </dl>

                <div id="detail-sync-section">
                    <div class="cs-section-bar">
                        <div class="cs-section-bar-left">
                            <h3><?php p($l->t('Sync Status')); ?></h3>
                            <span id="detail-sync-status-badge" class="sync-status-badge status-idle">
                                <span class="status-dot"></span>
                                <span id="detail-sync-status-text"><?php p($l->t('Idle')); ?></span>
                            </span>
                        </div>
                        <div class="cs-section-bar-right">
                            <button id="detail-refresh-btn" class="cs-icon-btn" title="<?php p($l->t('Refresh Status')); ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg>
                            </button>
                            <label class="cs-autosync-label">
                                <input type="checkbox" id="detail-auto-refresh" />
                                <?php p($l->t('Auto-refresh')); ?>
                            </label>
                            <button id="detail-clear-history-btn" class="cs-text-btn"><?php p($l->t('Clear History')); ?></button>
                        </div>
                    </div>
                    <div id="detail-sync-history"></div>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- Info Modal -->
<div id="info-modal" class="modal" style="display:none;">
    <div class="modal-content cw-about-card">
        <button id="info-close" class="cw-modal-close-btn" aria-label="<?php p($l->t('Close')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <div class="cw-about-header">
            <div class="cw-about-icon-box">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            </div>
            <div class="cw-about-title">
                <h2><?php p($l->t('Calendar Sync')); ?></h2>
                <div class="cw-about-subtitle-row">
                    <span><?php p($l->t('Bidirectional CalDAV sync for Nextcloud')); ?></span>
                    <span class="cw-about-version-badge">v1.0</span>
                </div>
            </div>
        </div>
        <div class="cw-about-section">
            <p class="cw-section-heading"><?php p($l->t('Application Information')); ?></p>
            <dl class="cw-about-meta">
                <div><dt><?php p($l->t('Author')); ?></dt><dd>Markus Begerow</dd></div>
                <div><dt><?php p($l->t('Version')); ?></dt><dd>1.0</dd></div>
                <div><dt><?php p($l->t('License')); ?></dt><dd>GPL v3</dd></div>
            </dl>
        </div>
        <div class="cw-about-section">
            <p class="cw-section-heading"><?php p($l->t('Features')); ?></p>
            <ul class="cw-about-features">
                <li><?php p($l->t('Two-way sync with conflict resolution')); ?></li>
                <li><?php p($l->t('Multiple external CalDAV sources per user')); ?></li>
                <li><?php p($l->t('Configurable sync direction and duplicate handling')); ?></li>
                <li><?php p($l->t('Encrypted credential storage (Nextcloud ICrypto)')); ?></li>
                <li><?php p($l->t('Automatic background sync every 30 minutes')); ?></li>
            </ul>
        </div>
        <div class="cw-about-section">
            <p class="cw-section-heading"><?php p($l->t('Links')); ?></p>
            <div class="cw-about-links">
                <a href="https://markus-begerow.de/linktree" target="_blank" rel="noopener" class="cw-about-link">
                    <span class="cw-about-link-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></span>
                    <div class="cw-about-link-body"><p><?php p($l->t('Website')); ?></p><p>markus-begerow.de/linktree</p></div>
                    <span class="cw-about-link-arrow">&#8599;</span>
                </a>
                <a href="https://github.com/markusbegerow/nextcloud-calendar-sync" target="_blank" rel="noopener" class="cw-about-link">
                    <span class="cw-about-link-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg></span>
                    <div class="cw-about-link-body"><p><?php p($l->t('GitHub')); ?></p><p>markusbegerow/nextcloud-calendar-sync</p></div>
                    <span class="cw-about-link-arrow">&#8599;</span>
                </a>
                <a href="https://www.paypal.com/paypalme/MarkusBegerow" target="_blank" rel="noopener" class="cw-about-link">
                    <span class="cw-about-link-icon"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg></span>
                    <div class="cw-about-link-body"><p><?php p($l->t('Donate a coffee')); ?></p><p>paypal.me/MarkusBegerow</p></div>
                    <span class="cw-about-link-arrow">&#8599;</span>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Settings Modal -->
<div id="settings-modal" class="modal" style="display:none;">
    <div class="modal-content cw-settings-card">
        <button id="settings-close" class="cw-modal-close-btn" aria-label="<?php p($l->t('Close')); ?>">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <h2 class="cw-settings-title"><?php p($l->t('Settings')); ?></h2>

        <div class="cw-form-group">
            <label for="setting-auto-refresh"><?php p($l->t('Auto-refresh interval')); ?></label>
            <select id="setting-auto-refresh">
                <option value="0"><?php p($l->t('Never (manual only)')); ?></option>
                <option value="15"><?php p($l->t('Every 15 seconds')); ?></option>
                <option value="30"><?php p($l->t('Every 30 seconds')); ?></option>
                <option value="60"><?php p($l->t('Every minute')); ?></option>
                <option value="300"><?php p($l->t('Every 5 minutes')); ?></option>
            </select>
        </div>

        <div class="cw-form-group">
            <label for="setting-default-direction"><?php p($l->t('Default sync direction for new configurations')); ?></label>
            <select id="setting-default-direction">
                <option value=""><?php p($l->t('— None —')); ?></option>
                <option value="bidirectional"><?php p($l->t('↔ Bidirectional')); ?></option>
                <option value="external_to_nc"><?php p($l->t('⬇ External → Nextcloud')); ?></option>
                <option value="nc_to_external"><?php p($l->t('⬆ Nextcloud → External')); ?></option>
            </select>
        </div>

        <div class="cw-form-group">
            <label for="setting-show-logs"><?php p($l->t('Show sync history')); ?></label>
            <select id="setting-show-logs">
                <option value="1"><?php p($l->t('Yes')); ?></option>
                <option value="0"><?php p($l->t('No')); ?></option>
            </select>
        </div>

        <div class="cw-form-actions">
            <button id="settings-save-btn" class="primary"><?php p($l->t('Save')); ?></button>
            <button id="settings-cancel-btn"><?php p($l->t('Cancel')); ?></button>
        </div>
    </div>
</div>

<!-- Add / Edit Configuration Modal -->
<div id="config-modal" class="modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="modal-title"><?php p($l->t('Add Configuration')); ?></h3>
            <button class="close-btn" id="close-modal">&times;</button>
        </div>
        <form id="config-form">
            <input type="hidden" id="config-id" value="">

            <div class="form-group">
                <label for="config-name"><?php p($l->t('Configuration Name')); ?></label>
                <input type="text" id="config-name" required>
            </div>

            <div class="form-group">
                <label for="config-url"><?php p($l->t('CalDAV Server URL')); ?></label>
                <input type="url" id="config-url" required
                       placeholder="https://example.com/calendars/username/calendar/">
            </div>

            <div class="form-group">
                <label for="config-username"><?php p($l->t('Username')); ?></label>
                <input type="text" id="config-username" required>
            </div>

            <div class="form-group">
                <label for="config-password"><?php p($l->t('Password')); ?></label>
                <input type="password" id="config-password" required>
            </div>

            <div class="form-group">
                <label for="config-auth-type"><?php p($l->t('Authentication Type')); ?></label>
                <select id="config-auth-type" required>
                    <option value="digest"><?php p($l->t('Digest')); ?></option>
                    <option value="basic"><?php p($l->t('Basic')); ?></option>
                </select>
                <small><?php p($l->t('Use Basic for most servers (e.g. SmarterMail); Digest for All-Inkl')); ?></small>
            </div>

            <div class="form-group">
                <label for="config-calendar"><?php p($l->t('Nextcloud Calendar')); ?></label>
                <select id="config-calendar" required>
                    <option value="personal"><?php p($l->t('Personal (Default)')); ?></option>
                    <option value="auto"><?php p($l->t('First Available')); ?></option>
                </select>
                <small><?php p($l->t('Which Nextcloud calendar to sync with')); ?></small>
            </div>

            <div class="form-group">
                <label for="config-sync-direction"><?php p($l->t('Sync Direction')); ?></label>
                <select id="config-sync-direction">
                    <option value="bidirectional"><?php p($l->t('↔ Synchronize (both ways)')); ?></option>
                    <option value="external_to_nc"><?php p($l->t('⬇ Import (External → Nextcloud)')); ?></option>
                    <option value="nc_to_external"><?php p($l->t('⬆ Export (Nextcloud → External)')); ?></option>
                </select>
                <small><?php p($l->t('Control which direction events are copied')); ?></small>
            </div>

            <div class="form-group">
                <label for="config-duplicate-handling"><?php p($l->t('Duplicate Handling')); ?></label>
                <select id="config-duplicate-handling">
                    <option value="update_newer"><?php p($l->t('Update with newer version (default)')); ?></option>
                    <option value="skip"><?php p($l->t('Skip — leave existing events untouched')); ?></option>
                    <option value="keep_both"><?php p($l->t('Keep both — always create new copies')); ?></option>
                </select>
                <small><?php p($l->t('What to do when an event with the same title already exists in the target')); ?></small>
            </div>

            <div class="form-group">
                <label>
                    <input type="checkbox" id="config-enabled" checked>
                    <?php p($l->t('Enabled')); ?>
                </label>
            </div>

            <div class="form-actions">
                <button type="button" id="test-connection-btn"><?php p($l->t('Test Connection')); ?></button>
                <button type="submit" class="primary"><?php p($l->t('Save')); ?></button>
                <button type="button" id="cancel-btn"><?php p($l->t('Cancel')); ?></button>
            </div>
        </form>
    </div>
</div>
