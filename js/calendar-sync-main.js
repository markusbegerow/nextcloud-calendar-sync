/**
 * Calendar Sync App Main JavaScript
 */

(function() {
    'use strict';

    // NC34+: send CSRF token on all AJAX requests (fixes HTTP 412)
    $.ajaxSetup({
        headers: { 'requesttoken': OC.requestToken }
    });

    // NC34 removed OC.Notification; minimal toast shim
    if (typeof OC.Notification === 'undefined') {
        OC.Notification = {
            showTemporary: function(text) {
                const toast = document.createElement('div');
                toast.textContent = text;
                toast.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);'
                    + 'background:#222;color:#fff;padding:12px 20px;border-radius:6px;z-index:10000;'
                    + 'box-shadow:0 2px 8px rgba(0,0,0,0.3);font-size:14px;';
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), 3500);
            }
        };
    }

    let configs = [];
    let selectedConfigId = null;
    let userSettings = { autoRefreshInterval: 0, defaultSyncDirection: '', showSyncLogs: true };
    let autoRefreshTimer = null;

    const CalDavSync = {
        baseUrl: OC.generateUrl('/apps/calendar-sync'),

        init: function() {
            this.bindEvents();
            this.loadUserSettings().always(() => {
                this.loadConfigs();
            });
        },

        bindEvents: function() {
            // Sidebar / empty-state add buttons
            $('#add-config-btn, #add-first-config-btn').on('click', () => this.showConfigModal(null));

            // Config modal
            $('#close-modal, #cancel-btn').on('click', () => this.hideConfigModal());
            $('#config-form').on('submit', (e) => this.saveConfig(e));
            $('#test-connection-btn').on('click', () => this.testConnection());
            $('#config-modal').on('click', function(e) {
                if (e.target === this) CalDavSync.hideConfigModal();
            });

            // Detail view actions
            $('#detail-sync-btn').on('click', () => this.syncNow());
            $('#detail-edit-btn').on('click', () => {
                if (selectedConfigId !== null) {
                    const cfg = configs.find(c => c.id == selectedConfigId);
                    if (cfg) this.showConfigModal(cfg);
                }
            });
            $('#detail-test-btn').on('click', () => {
                if (selectedConfigId !== null) this.testConfigConnection(selectedConfigId);
            });
            $('#detail-delete-btn').on('click', () => {
                if (selectedConfigId !== null) this.deleteConfig(selectedConfigId);
            });
            $('#detail-refresh-btn').on('click', () => this.loadSyncStatus());
            $('#detail-auto-refresh').on('change', function() {
                if (this.checked) {
                    CalDavSync.startAutoRefresh();
                } else {
                    CalDavSync.stopAutoRefresh();
                }
            });
            $('#detail-clear-history-btn').on('click', () => this.clearHistory());

            // Info modal
            $('#info-btn').on('click', () => this.showInfoModal());
            $('#info-close').on('click', () => this.hideInfoModal());
            $('#info-modal').on('click', function(e) {
                if (e.target === this) CalDavSync.hideInfoModal();
            });

            // Settings modal
            $('#settings-btn').on('click', () => this.showSettingsModal());
            $('#settings-close, #settings-cancel-btn').on('click', () => this.hideSettingsModal());
            $('#settings-save-btn').on('click', () => this.saveUserSettings());
            $('#settings-modal').on('click', function(e) {
                if (e.target === this) CalDavSync.hideSettingsModal();
            });
        },

        loadUserSettings: function() {
            return $.get(this.baseUrl + '/api/user-settings')
                .done(function(data) {
                    userSettings.autoRefreshInterval = parseInt(data.autoRefreshInterval) || 0;
                    userSettings.defaultSyncDirection = data.defaultSyncDirection || '';
                    userSettings.showSyncLogs = data.showSyncLogs !== false;
                });
        },

        loadConfigs: function() {
            $.get(this.baseUrl + '/api/configs')
                .done(function(data) {
                    configs = data;
                    CalDavSync.buildConfigNavList(data);
                    if (selectedConfigId !== null) {
                        const still = data.find(c => c.id == selectedConfigId);
                        if (still) {
                            CalDavSync.renderConfigDetail(still);
                        } else {
                            selectedConfigId = null;
                            CalDavSync.showEmptyState();
                        }
                    } else if (data.length === 0) {
                        CalDavSync.showEmptyState();
                    }
                    // If configs exist but none selected, stay on empty state
                })
                .fail(function() {
                    OC.Notification.showTemporary(t('calendar-sync', 'Failed to load configurations'));
                });
        },

        buildConfigNavList: function(cfgs) {
            const $list = $('#config-nav-list');
            $list.empty();

            cfgs.forEach(function(cfg) {
                const isActive = cfg.id == selectedConfigId;
                const statusLabel = cfg.enabled
                    ? t('calendar-sync', 'Active')
                    : t('calendar-sync', 'Paused');
                const $li = $(`
                    <li class="cs-nav-item${isActive ? ' active' : ''}" data-id="${cfg.id}">
                        <a href="#" class="cs-nav-item-link">
                            <span class="cs-nav-item-name">${escapeHtml(cfg.name)}</span>
                            <span class="config-status ${cfg.enabled ? 'enabled' : 'disabled'}">${statusLabel}</span>
                        </a>
                    </li>
                `);
                $li.on('click', function(e) {
                    e.preventDefault();
                    CalDavSync.selectConfig(cfg.id);
                });
                $list.append($li);
            });
        },

        selectConfig: function(id) {
            selectedConfigId = id;
            $('#config-nav-list .cs-nav-item').removeClass('active');
            $('#config-nav-list .cs-nav-item[data-id="' + id + '"]').addClass('active');
            const cfg = configs.find(c => c.id == id);
            if (cfg) {
                this.renderConfigDetail(cfg);
                this.loadSyncStatus();
            }
        },

        renderConfigDetail: function(cfg) {
            $('#no-config-state').hide();
            $('#config-detail').css('display', 'flex');

            const directionLabels = {
                'bidirectional':  '↔ ' + t('calendar-sync', 'Bidirectional'),
                'external_to_nc': '↓ ' + t('calendar-sync', 'External → Nextcloud'),
                'nc_to_external': '↑ ' + t('calendar-sync', 'Nextcloud → External')
            };
            const dupLabels = {
                'update_newer': t('calendar-sync', 'Update with newer version'),
                'skip':         t('calendar-sync', 'Skip duplicates'),
                'keep_both':    t('calendar-sync', 'Keep both copies')
            };

            $('#detail-config-name').text(cfg.name);
            const $badge = $('#detail-status-badge');
            $badge.text(cfg.enabled ? t('calendar-sync', 'Active') : t('calendar-sync', 'Paused'))
                  .removeClass('enabled disabled')
                  .addClass(cfg.enabled ? 'enabled' : 'disabled');

            $('#detail-url').text(cfg.url);
            $('#detail-direction').text(
                directionLabels[cfg.sync_direction || 'bidirectional'] || cfg.sync_direction
            );
            $('#detail-duplicates').text(
                dupLabels[cfg.duplicate_handling || 'update_newer'] || cfg.duplicate_handling
            );
            $('#detail-last-sync').text(
                cfg.last_sync ? formatDateTime(cfg.last_sync) : t('calendar-sync', 'Never')
            );

            if (userSettings.showSyncLogs) {
                $('#detail-sync-section').show();
            } else {
                $('#detail-sync-section').hide();
            }
        },

        showEmptyState: function() {
            $('#config-detail').hide();
            $('#no-config-state').css('display', 'flex');
        },

        showInfoModal: function() {
            $('#info-modal').show();
        },

        hideInfoModal: function() {
            $('#info-modal').hide();
        },

        showSettingsModal: function() {
            $('#setting-auto-refresh').val(String(userSettings.autoRefreshInterval));
            $('#setting-default-direction').val(userSettings.defaultSyncDirection);
            $('#setting-show-logs').val(userSettings.showSyncLogs ? '1' : '0');
            $('#settings-modal').show();
        },

        hideSettingsModal: function() {
            $('#settings-modal').hide();
        },

        saveUserSettings: function() {
            const data = {
                autoRefreshInterval:  parseInt($('#setting-auto-refresh').val()) || 0,
                defaultSyncDirection: $('#setting-default-direction').val(),
                showSyncLogs:         $('#setting-show-logs').val() === '1'
            };
            $.ajax({ url: this.baseUrl + '/api/user-settings', method: 'PUT', data })
                .done(function() {
                    userSettings.autoRefreshInterval  = data.autoRefreshInterval;
                    userSettings.defaultSyncDirection = data.defaultSyncDirection;
                    userSettings.showSyncLogs         = data.showSyncLogs;
                    CalDavSync.hideSettingsModal();
                    if ($('#detail-auto-refresh').is(':checked')) {
                        CalDavSync.startAutoRefresh();
                    }
                    if (selectedConfigId !== null) {
                        const cfg = configs.find(c => c.id == selectedConfigId);
                        if (cfg) CalDavSync.renderConfigDetail(cfg);
                    }
                    OC.Notification.showTemporary(t('calendar-sync', 'Settings saved'));
                })
                .fail(function() {
                    OC.Notification.showTemporary(t('calendar-sync', 'Failed to save settings'));
                });
        },

        showConfigModal: function(config) {
            if (config) {
                $('#modal-title').text(t('calendar-sync', 'Edit Configuration'));
                $('#config-id').val(config.id);
                $('#config-name').val(config.name);
                $('#config-url').val(config.url);
                $('#config-username').val(config.username);
                $('#config-password').val('');
                $('#config-enabled').prop('checked', config.enabled);
                $('#config-calendar').val(config.calendar_selection || 'personal');
                $('#config-sync-direction').val(config.sync_direction || 'bidirectional');
                $('#config-duplicate-handling').val(config.duplicate_handling || 'update_newer');
            } else {
                $('#modal-title').text(t('calendar-sync', 'Add Configuration'));
                $('#config-form')[0].reset();
                $('#config-id').val('');
                $('#config-enabled').prop('checked', true);
                if (userSettings.defaultSyncDirection) {
                    $('#config-sync-direction').val(userSettings.defaultSyncDirection);
                }
            }
            $('#config-modal').show();
        },

        hideConfigModal: function() {
            $('#config-modal').hide();
            $('#config-form')[0].reset();
        },

        saveConfig: function(e) {
            e.preventDefault();
            const configId = $('#config-id').val();
            const data = {
                name:               $('#config-name').val(),
                url:                $('#config-url').val(),
                username:           $('#config-username').val(),
                password:           $('#config-password').val(),
                enabled:            $('#config-enabled').is(':checked'),
                calendar_selection: $('#config-calendar').val(),
                sync_direction:     $('#config-sync-direction').val(),
                duplicate_handling: $('#config-duplicate-handling').val()
            };
            const url    = configId
                ? this.baseUrl + '/api/configs/' + configId
                : this.baseUrl + '/api/configs';
            const method = configId ? 'PUT' : 'POST';

            $.ajax({ url, method, data })
                .done(function(saved) {
                    const savedId = (saved && saved.id) ? saved.id : parseInt(configId);
                    if (savedId) selectedConfigId = savedId;
                    CalDavSync.hideConfigModal();
                    CalDavSync.loadConfigs();
                    OC.Notification.showTemporary(t('calendar-sync', 'Configuration saved successfully'));
                })
                .fail(function() {
                    OC.Notification.showTemporary(t('calendar-sync', 'Failed to save configuration'));
                });
        },

        deleteConfig: function(configId) {
            if (!confirm(t('calendar-sync', 'Are you sure you want to delete this configuration?'))) return;

            $.ajax({
                url:     this.baseUrl + '/api/configs/' + configId,
                method:  'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            })
            .done(function() {
                if (selectedConfigId == configId) selectedConfigId = null;
                CalDavSync.loadConfigs();
                OC.Notification.showTemporary(t('calendar-sync', 'Configuration deleted successfully'));
            })
            .fail(function() {
                OC.Notification.showTemporary(t('calendar-sync', 'Failed to delete configuration'));
            });
        },

        testConnection: function() {
            const data = {
                url:      $('#config-url').val(),
                username: $('#config-username').val(),
                password: $('#config-password').val()
            };
            $.post(this.baseUrl + '/api/configs/test', data)
                .done(function(response) {
                    if (response.success) {
                        OC.Notification.showTemporary(t('calendar-sync', 'Connection successful!'));
                    } else {
                        OC.Notification.showTemporary(
                            t('calendar-sync', 'Connection failed: {error}', {error: response.message})
                        );
                    }
                })
                .fail(function() {
                    OC.Notification.showTemporary(t('calendar-sync', 'Connection test failed'));
                });
        },

        testConfigConnection: function(configId) {
            $.post(this.baseUrl + '/api/configs/' + configId + '/test')
                .done(function(response) {
                    if (response.success) {
                        OC.Notification.showTemporary(t('calendar-sync', 'Connection successful!'));
                    } else {
                        OC.Notification.showTemporary(
                            t('calendar-sync', 'Connection failed: {error}', {error: response.message})
                        );
                    }
                })
                .fail(function() {
                    OC.Notification.showTemporary(t('calendar-sync', 'Connection test failed'));
                });
        },

        setSyncStatus: function(state, text) {
            $('#detail-sync-status-badge')
                .removeClass('status-idle status-running status-success status-error')
                .addClass('status-' + state);
            $('#detail-sync-status-text').text(text);
        },

        syncNow: function() {
            if (selectedConfigId === null) return;
            const $btn = $('#detail-sync-btn');
            $btn.prop('disabled', true);
            this.setSyncStatus('running', t('calendar-sync', 'Syncing…'));

            $.post(this.baseUrl + '/sync')
                .done(function(response) {
                    if (response.success) {
                        CalDavSync.setSyncStatus('success', t('calendar-sync', 'Completed'));
                        OC.Notification.showTemporary(t('calendar-sync', 'Sync completed successfully'));
                        CalDavSync.loadSyncStatus();
                    } else {
                        CalDavSync.setSyncStatus('error', t('calendar-sync', 'Sync failed'));
                        OC.Notification.showTemporary(
                            t('calendar-sync', 'Sync failed: {error}', {error: response.message})
                        );
                    }
                })
                .fail(function() {
                    CalDavSync.setSyncStatus('error', t('calendar-sync', 'Sync failed'));
                    OC.Notification.showTemporary(t('calendar-sync', 'Sync failed'));
                })
                .always(function() {
                    $btn.prop('disabled', false);
                });
        },

        loadSyncStatus: function() {
            $.get(this.baseUrl + '/sync/status')
                .done(function(logs) {
                    CalDavSync.renderSyncHistory(logs);
                });
        },

        renderSyncHistory: function(logs) {
            const $container = $('#detail-sync-history');
            $container.empty();

            if (!userSettings.showSyncLogs) return;

            if (logs.length === 0) {
                $container.html(
                    '<p class="cs-empty-history">'
                    + t('calendar-sync', 'No sync history available.')
                    + '</p>'
                );
                return;
            }

            logs.forEach(function(log) {
                const statusClass = log.status === 'success' ? 'success' :
                                    log.status === 'error'   ? 'error'   : 'running';
                const $log = $(`
                    <div class="sync-log-item status-${statusClass}">
                        <div class="log-header">
                            <span class="config-name">${escapeHtml(log.config_name)}</span>
                            <span class="sync-status">${log.status.toUpperCase()}</span>
                            <span class="sync-time">${formatDateTime(log.started_at)}</span>
                        </div>
                        <div class="log-details">
                            <div class="stats">
                                <span>${t('calendar-sync', 'Created: {n}', {n: log.events_created})}</span>
                                <span>${t('calendar-sync', 'Updated: {n}', {n: log.events_updated})}</span>
                                <span>${t('calendar-sync', 'Deleted: {n}', {n: log.events_deleted})}</span>
                            </div>
                            ${log.message ? '<div class="log-message">' + escapeHtml(log.message) + '</div>' : ''}
                        </div>
                    </div>
                `);
                $container.append($log);
            });
        },

        startAutoRefresh: function() {
            this.stopAutoRefresh();
            const interval = userSettings.autoRefreshInterval;
            if (interval > 0) {
                autoRefreshTimer = setInterval(() => this.loadSyncStatus(), interval * 1000);
            }
        },

        stopAutoRefresh: function() {
            if (autoRefreshTimer) {
                clearInterval(autoRefreshTimer);
                autoRefreshTimer = null;
            }
        },

        clearHistory: function() {
            if (!confirm(t('calendar-sync', 'Clear all sync history? This cannot be undone.'))) return;
            $.ajax({
                url:     this.baseUrl + '/sync/history',
                method:  'DELETE',
                headers: { 'requesttoken': OC.requestToken }
            })
            .done(function() {
                CalDavSync.loadSyncStatus();
                OC.Notification.showTemporary(t('calendar-sync', 'Sync history cleared'));
            })
            .fail(function(xhr) {
                OC.Notification.showTemporary(
                    t('calendar-sync', 'Failed to clear history ({status})', {status: xhr.status})
                );
            });
        }
    };

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatDateTime(dateString) {
        if (!dateString) return t('calendar-sync', 'Unknown');
        return new Date(dateString).toLocaleString();
    }

    $(document).ready(function() {
        CalDavSync.init();
    });

})();
