<?php
script('user_vo', 'admin');
style('user_vo', 'admin');
?>

<div id="user_vo_admin" class="section">
    <h2><?php p($l->t('VereinOnline User Authentication')); ?></h2>

    <!-- Configuration Section -->
    <div class="configuration-section">
        <h3><?php p($l->t('Configuration')); ?></h3>

        <?php
            $sources = $_['config_status']['current_config']['sources'] ?? [];
            $hasConfigPhp = in_array('config.php', $sources);
            $hasAdminInterface = in_array('admin_interface', $sources);
            $adminConfig = $_['config_status']['admin_config'];
            $hasAdminConfigSet = !empty($adminConfig['api_url']) || !empty($adminConfig['api_username']) || !empty($adminConfig['api_password']);

            // Check if config.php configuration is complete
            $configPhpComplete = ($sources['api_url'] === 'config.php') &&
                                 ($sources['api_username'] === 'config.php') &&
                                 ($sources['api_password'] === 'config.php');
            $isPartialConfig = $hasConfigPhp && !$configPhpComplete;

            // Check if overall configuration is complete (from any source)
            // A value is set if it has a source (not null)
            $isConfigComplete = ($sources['api_url'] !== null) &&
                               ($sources['api_username'] !== null) &&
                               ($sources['api_password'] !== null);
        ?>

        <?php if ($hasConfigPhp): ?>
            <!-- Config.php is present - show current active config -->
            <div class="status-box <?php echo $isPartialConfig ? 'status-box-red' : 'status-box-green'; ?>">
                <div style="margin-bottom: 15px;">
                    <span class="icon icon-info"></span>
                    <strong>
                        <?php if ($isPartialConfig): ?>
                            <?php p($l->t('Partial Configuration (from config.php)')); ?>
                        <?php else: ?>
                            <?php p($l->t('Active Configuration (from config.php)')); ?>
                        <?php endif; ?>
                    </strong>
                    <p style="margin: 5px 0;">
                        <?php if ($isPartialConfig): ?>
                            <?php p($l->t('This plugin is partially configured via config.php. Some required values are missing. Values in config.php take precedence over admin interface settings. To configure the plugin through this admin interface instead, remove the user_backends entry for UserVO from your config.php file.')); ?>
                        <?php else: ?>
                            <?php p($l->t('This plugin is configured via config.php. These values take precedence and cannot be changed through this interface. To configure the plugin through this admin interface instead, remove the user_backends entry for UserVO from your config.php file.')); ?>
                        <?php endif; ?>
                    </p>
                </div>

                <div style="max-width: 600px;">
                    <div style="margin-bottom: 12px;">
                        <div style="font-weight: bold; margin-bottom: 5px;"><?php p($l->t('API URL')); ?></div>
                        <div class="config-value-display">
                            <?php if ($sources['api_url'] === 'config.php'): ?>
                                <?php p($_['config_status']['current_config']['api_url']); ?>
                            <?php else: ?>
                                <span class="not-set"><?php p($l->t('(not set in config.php)')); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="margin-bottom: 12px;">
                        <div style="font-weight: bold; margin-bottom: 5px;"><?php p($l->t('API Username')); ?></div>
                        <div class="config-value-display">
                            <?php if ($sources['api_username'] === 'config.php'): ?>
                                <?php p($_['config_status']['current_config']['api_username']); ?>
                            <?php else: ?>
                                <span class="not-set"><?php p($l->t('(not set in config.php)')); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="margin-bottom: 12px;">
                        <div style="font-weight: bold; margin-bottom: 5px;"><?php p($l->t('API Password')); ?></div>
                        <div class="config-value-display">
                            <?php if ($sources['api_password'] === 'config.php'): ?>
                                <?php p($_['config_status']['current_config']['api_password']); ?>
                            <?php else: ?>
                                <span class="not-set"><?php p($l->t('(not set in config.php)')); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div style="margin-top: 15px;">
                    <button type="button" id="test-config" class="btn btn-secondary test-config-btn"
                            data-mode="config-php"
                            data-api-url="<?php p($_['config_status']['current_config']['api_url'] ?? ''); ?>"
                            data-api-username="<?php p($_['config_status']['current_config']['api_username'] ?? ''); ?>"
                            <?php if (!$isConfigComplete): ?>disabled="disabled" style="opacity: 0.5; cursor: not-allowed;"<?php endif; ?>>
                        <?php p($l->t('Test Configuration')); ?>
                    </button>
                    <?php if (!$isConfigComplete): ?>
                        <p class="incomplete-warning">
                            <?php p($l->t('Configuration is incomplete. All three values (URL, Username, Password) are required to test the configuration.')); ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div id="config-message-configphp" class="config-message" style="display: none; margin-top: 15px;"></div>
            </div>

        <?php endif; ?>

        <!-- Show configuration form always -->
        <?php if ($hasConfigPhp): ?>
            <!-- When config.php is active, show the form in a yellow warning box -->
            <div class="status-box status-box-yellow">
                <div style="margin-bottom: 15px;">
                    <span class="icon icon-info"></span>
                    <strong><?php p($l->t('Database Configuration (from admin interface - currently unused)')); ?></strong>
                    <p style="margin: 5px 0;"><?php p($l->t('This configuration is stored in the database but not currently used because config.php takes precedence. You can edit these values in preparation for removing the config.php settings.')); ?></p>
                </div>
        <?php else: ?>
            <!-- No config.php - show form in green box if configured, red if not -->
            <?php if ($_['config_status']['is_configured']): ?>
                <div class="status-box status-box-green">
                    <div style="margin-bottom: 15px;">
                        <span class="icon icon-checkmark"></span>
                        <strong><?php p($l->t('Active Configuration (from admin interface)')); ?></strong>
                        <p style="margin: 5px 0;"><?php p($l->t('This plugin is configured via the admin interface. You can modify the configuration below.')); ?></p>
                    </div>
            <?php else: ?>
                <div class="status-box status-box-red">
                    <div style="margin-bottom: 15px;">
                        <span class="icon icon-error"></span>
                        <strong><?php p($l->t('Configuration Required')); ?></strong>
                        <p style="margin: 5px 0;"><?php p($l->t('Please configure the plugin using the form below.')); ?></p>
                    </div>
            <?php endif; ?>
        <?php endif; ?>

        <form id="user-vo-config-form">
                <div class="form-group">
                    <label for="api-url"><?php p($l->t('API URL')); ?></label>
                    <input type="url" id="api-url" name="api_url"
                           value="<?php p($_['config_status']['admin_config']['api_url']); ?>"
                           placeholder="https://vereinonline.org/YOUR_ORGANIZATION_NAME"
                           required>
                    <em><?php p($l->t('The base URL of your VereinOnline organization')); ?></em>
                </div>

                <div class="form-group">
                    <label for="api-username"><?php p($l->t('API Username')); ?></label>
                    <input type="text" id="api-username" name="api_username"
                           value="<?php p($_['config_status']['admin_config']['api_username']); ?>"
                           placeholder="API_USER"
                           required>
                    <em><?php p($l->t('Your VereinOnline API username')); ?></em>
                </div>

                <div class="form-group">
                    <label for="api-password"><?php p($l->t('API Password')); ?></label>
                    <input type="password" id="api-password" name="api_password"
                           placeholder="<?php p($_['config_status']['admin_config']['api_password'] ? $l->t('Password set - leave empty to keep current') : $l->t('Enter API password')); ?>"
                           <?php if (!$_['config_status']['admin_config']['api_password']): ?>required<?php endif; ?>>
                    <em><?php p($l->t('Your VereinOnline API password')); ?></em>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <?php p($l->t('Save Configuration')); ?>
                    </button>
                    <button type="button" class="btn btn-secondary test-config-btn">
                        <?php p($l->t('Test Configuration')); ?>
                    </button>
                    <button type="button" id="clear-config" class="btn btn-secondary">
                        <?php p($l->t('Clear Configuration')); ?>
                    </button>
                </div>
            </form>

            <div id="config-message-admin" class="config-message" style="display: none;"></div>

        </div> <!-- Close colored configuration box (yellow/green/red) -->
    </div>

    <!-- User Data Synchronization Section -->
    <div class="user-sync-section">
        <h3><?php p($l->t('User Data Synchronization')); ?></h3>

        <div class="vo-notice">
            <span class="icon icon-info"></span>
            <?php p($l->t('User data (display name, email) is automatically synchronized from VereinOnline on every login. VO is the source of truth - manual changes in Nextcloud will be overwritten.')); ?>
        </div>

        <h4><?php p($l->t('Sync Options')); ?></h4>
        <p>
            <input type="checkbox" id="sync-email" name="sync_email" class="checkbox"
                   <?php if (($_['sync_settings']['sync_email'] ?? 'true') === 'true'): ?>checked<?php endif; ?> />
            <label for="sync-email"><?php p($l->t('Sync email addresses from VO (enabled by default)')); ?></label>
        </p>
        <p>
            <input type="checkbox" id="sync-photo" name="sync_photo" class="checkbox"
                   <?php if (($_['sync_settings']['sync_photo'] ?? 'true') === 'true'): ?>checked<?php endif; ?> />
            <label for="sync-photo"><?php p($l->t('Sync profile pictures from VO (enabled by default)')); ?></label>
        </p>

        <h4><?php p($l->t('Nightly Sync')); ?></h4>
        <p>
            <input type="checkbox" id="enable-nightly-user-sync" name="enable_nightly_user_sync" class="checkbox"
                   <?php if (($_['nightly_sync']['enabled'] ?? false)): ?>checked<?php endif; ?> />
            <label for="enable-nightly-user-sync"><?php p($l->t('Enable automatic nightly user sync (runs once per day)')); ?></label>
        </p>
        <p>
            <input type="checkbox" id="enable-nightly-group-sync" name="enable_nightly_group_sync" class="checkbox"
                   <?php if (($_['nightly_sync']['group_enabled'] ?? false)): ?>checked<?php endif; ?>
                   <?php if (!($_['nightly_sync']['enabled'] ?? false)): ?>disabled<?php endif; ?> />
            <label for="enable-nightly-group-sync">
                <?php
                $managedCount = $_['nightly_sync']['managed_groups_count'] ?? 0;
                p($l->t('Enable automatic nightly group membership sync for %d managed VO group(s) (runs after user sync)', [$managedCount]));
                ?>
            </label>
        </p>
        <?php if (($_['nightly_sync']['managed_groups_count'] ?? 0) > 0): ?>
        <p class="vo-notice" style="margin-left: 25px; margin-top: -8px;">
            <span class="icon icon-info"></span>
            <?php p($l->t('Syncs membership for VO groups that have been manually enabled in the Group Management section below.')); ?>
        </p>
        <?php else: ?>
        <p class="vo-notice" style="margin-left: 25px; margin-top: -8px;">
            <span class="icon icon-info"></span>
            <?php p($l->t('No VO groups are currently managed. Enable groups in the Group Management section below to use this feature.')); ?>
        </p>
        <?php endif; ?>
        <div id="nightly-sync-status" class="nightly-sync-status">
            <div class="status-row">
                <span class="status-label"><?php p($l->t('Last run:')); ?></span>
                <span id="nightly-sync-last-run"></span>
            </div>
            <div class="status-row">
                <span class="status-label"><?php p($l->t('Status:')); ?></span>
                <span id="nightly-sync-status-badge" class="status-badge"></span>
            </div>
            <div class="status-row">
                <span class="status-label"><?php p($l->t('Summary:')); ?></span>
                <span id="nightly-sync-summary"></span>
            </div>
            <div id="nightly-sync-error-container" class="status-row" style="display: none;">
                <span class="status-label"><?php p($l->t('Error:')); ?></span>
                <span id="nightly-sync-error" class="error-message"></span>
            </div>
        </div>

        <h4><?php p($l->t('Manual User Sync')); ?></h4>
        <p><?php p($l->t('Trigger immediate synchronization for all users. This will fetch the latest data from VereinOnline for all user_vo users.')); ?></p>
        <p>
            <button id="view-local-data" class="button"><?php p($l->t('View Users')); ?></button>
            <button id="view-user-metadata" class="button"><?php p($l->t('Preview VO')); ?></button>
            <button id="sync-all-users" class="button"><?php p($l->t('Sync from VO')); ?></button>
            <button id="sync-selected-users-btn" class="button"><?php p($l->t('Sync Selected Users')); ?></button>
            <span id="sync-all-users-status"></span>
        </p>

        <div id="user-sync-results" style="display:none; margin-top: 20px;">
            <h4><?php p($l->t('Sync Results')); ?></h4>
            <div id="user-sync-summary"></div>
            <table class="vo-users-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all-sync-users" /></th>
                        <th><?php p($l->t('NC Username')); ?></th>
                        <th><?php p($l->t('VO Username')); ?></th>
                        <th><?php p($l->t('VO User ID')); ?></th>
                        <th><?php p($l->t('Display Name')); ?></th>
                        <th><?php p($l->t('Email')); ?></th>
                        <th><?php p($l->t('Photo')); ?></th>
                        <th><?php p($l->t('Group IDs')); ?></th>
                        <th><?php p($l->t('Groups')); ?></th>
                        <th><?php p($l->t('Last Synced')); ?></th>
                        <th><?php p($l->t('Status')); ?></th>
                    </tr>
                </thead>
                <tbody id="user-sync-list"></tbody>
            </table>
        </div>
    </div>

    <!-- Pre-provision User Accounts Section -->
    <div class="user-sync-section">
        <h3><?php p($l->t('Pre-provision User Accounts')); ?></h3>

        <div class="vo-notice">
            <span class="icon icon-info"></span>
            <?php p($l->t('Search for VereinOnline users and create their Nextcloud accounts before their first login. Only users with VO login credentials will be shown.')); ?>
        </div>

        <h4><?php p($l->t('Search Users')); ?></h4>
        <p>
            <input type="text" id="vo-user-search" placeholder="<?php p($l->t('Enter name to search...')); ?>"
                   style="width: 300px;" />
            <button id="search-vo-users-btn" class="button"><?php p($l->t('Search')); ?></button>
            <span id="search-vo-users-status"></span>
        </p>

        <div id="vo-user-search-warning" class="vo-warning" style="display:none;">
            ⚠️ <?php p($l->t('Searching all users may take a long time depending on the number of members in VereinOnline. Consider entering a name to narrow down the results.')); ?>
        </div>

        <div id="vo-user-search-results" style="display:none; margin-top: 20px;">
            <h4><?php p($l->t('Search Results')); ?></h4>
            <div id="vo-user-search-summary"></div>

            <table class="vo-users-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all-vo-users" /></th>
                        <th><?php p($l->t('Name (VO)')); ?></th>
                        <th><?php p($l->t('VO Username')); ?></th>
                        <th><?php p($l->t('Display Name')); ?></th>
                        <th><?php p($l->t('Email')); ?></th>
                        <th><?php p($l->t('VO User ID')); ?></th>
                        <th><?php p($l->t('NC Account Status')); ?></th>
                        <th><?php p($l->t('Actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="vo-user-search-list"></tbody>
            </table>

            <div class="vo-bulk-actions" style="margin-top: 15px;">
                <button id="bulk-create-accounts-btn" class="button-primary">
                    <?php p($l->t('Create Selected Accounts')); ?>
                </button>
                <span id="bulk-create-status"></span>
            </div>
        </div>
    </div>

    <!-- Group Management Section -->
    <div class="user-sync-section">
        <h3><?php p($l->t('Group Management')); ?></h3>

        <div class="vo-notice">
            <span class="icon icon-info"></span>
            <?php p($l->t('Manage VereinOnline groups in Nextcloud. Create and synchronize groups from VO, preserving non-VO members.')); ?>
        </div>

        <h4><?php p($l->t('View Groups')); ?></h4>
        <p><?php p($l->t('Load groups from VereinOnline or view groups that are already managed in Nextcloud.')); ?></p>
        <p>
            <button id="load-all-vo-groups" class="button"><?php p($l->t('Load All VO Groups')); ?></button>
            <button id="load-managed-groups" class="button"><?php p($l->t('Load Managed Groups')); ?></button>
            <span id="groups-status"></span>
        </p>

        <div id="groups-results" style="display:none; margin-top: 20px;">
            <h4><?php p($l->t('Group List')); ?></h4>
            <div id="groups-summary"></div>
            <div style="margin-bottom: 10px;">
                <button id="expand-all-groups" class="button"><?php p($l->t('Expand All')); ?></button>
                <button id="collapse-all-groups" class="button"><?php p($l->t('Collapse All')); ?></button>
                <button id="bulk-create-groups" class="button" style="margin-left: 15px;"><?php p($l->t('Create Selected')); ?></button>
                <button id="bulk-sync-groups" class="button"><?php p($l->t('Sync Selected')); ?></button>
                <button id="sync-all-groups" class="button"><?php p($l->t('Sync All Managed Groups')); ?></button>
                <button id="bulk-delete-groups" class="button button-danger"><?php p($l->t('Delete Selected')); ?></button>
                <span id="bulk-groups-status"></span>
            </div>
            <table class="vo-groups-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all-groups" /></th>
                        <th><?php p($l->t('Pos')); ?></th>
                        <th><?php p($l->t('VO Group Name')); ?></th>
                        <th><?php p($l->t('VO Group ID')); ?></th>
                        <th><?php p($l->t('NC Display Name')); ?></th>
                        <th><?php p($l->t('NC Group ID')); ?></th>
                        <th><?php p($l->t('Status')); ?></th>
                        <th><?php p($l->t('VO Members')); ?></th>
                        <th><?php p($l->t('Non-VO Members')); ?></th>
                        <th><?php p($l->t('Last Synced')); ?></th>
                        <th><?php p($l->t('Actions')); ?></th>
                    </tr>
                </thead>
                <tbody id="groups-list"></tbody>
            </table>
        </div>
    </div>

    <!-- User Account Management Section - collapsed by default: this tool only matters
         for orgs affected by the case-sensitivity bug in v0.1.2 and earlier, not general
         day-to-day use. -->
    <details class="user-sync-section advanced-section">
        <summary><?php p($l->t('Advanced: User Account Management')); ?></summary>

        <div class="vo-notice">
            <span class="icon icon-info"></span>
            <?php p($l->t('This tool helps you identify and manage duplicate user accounts that were created due to a case sensitivity bug in version 0.1.2 and earlier of the user_vo plugin (see ')); ?><a href="https://github.com/NikolausDemmel/user_vo/issues/2" target="_blank" rel="noopener">GitHub issue #2</a><?php p($l->t('). When users logged in with different capitalizations of their username, multiple accounts were created for the same person.')); ?>
        </div>

        <h4><?php p($l->t('Scan Users')); ?></h4>
        <p><?php p($l->t('Use this interface to scan for duplicates and decide which accounts to keep visible. After exposing duplicate accounts, users can log into them to retrieve files or data, and you can then delete unwanted accounts through the user management interface or using the occ user:delete command.')); ?></p>
        <p>
            <button id="scan-duplicates" class="button">
                <?php p($l->t('Scan for Users')); ?>
            </button>
        </p>

        <div id="scan-results" style="display: none;">
            <div id="summary-info"></div>

            <div id="duplicate-results" style="display: none;">
                <h4><?php p($l->t('Duplicate Users')); ?></h4>
                <p><?php p($l->t('Users with existing duplicates that can be managed:')); ?></p>
                <div id="duplicate-list"></div>
            </div>

            <div id="all-users-results" style="display: none;">
                <h4><?php p($l->t('All Plugin Users')); ?></h4>
                <p><?php p($l->t('Complete overview of all users managed by the user_vo plugin:')); ?></p>
                <div id="all-users-list"></div>
            </div>
        </div>
    </details>

    <!-- Audit Log Section - collapsed by default: only useful when actively
         debugging something, not general day-to-day use. -->
    <details class="audit-log-section advanced-section">
        <summary><?php p($l->t('Advanced: Audit Log')); ?></summary>

        <div class="vo-notice">
            <span class="icon icon-info"></span>
            <?php p($l->t('Records logins, provisioning, group membership changes, sync failures, and config changes - not routine successful logins or no-op syncs. Entries older than the configured retention period (default 7 days) are cleaned up automatically every night.')); ?>
        </div>

        <p>
            <button id="load-audit-log" class="button"><?php p($l->t('Load Recent Entries')); ?></button>
            <a id="download-audit-log" class="button" href="#"><?php p($l->t('Download Full Log (.txt)')); ?></a>
            <button id="clear-audit-log" class="button button-danger"><?php p($l->t('Clear Log')); ?></button>
        </p>

        <div id="audit-log-results" style="display: none;">
            <table class="vo-groups-table">
                <thead>
                    <tr>
                        <th><?php p($l->t('Time')); ?></th>
                        <th><?php p($l->t('Event')); ?></th>
                        <th><?php p($l->t('User')); ?></th>
                        <th><?php p($l->t('Group')); ?></th>
                        <th><?php p($l->t('Message')); ?></th>
                    </tr>
                </thead>
                <tbody id="audit-log-list"></tbody>
            </table>
        </div>
    </details>
</div>
