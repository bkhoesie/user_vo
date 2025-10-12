// Admin interface JavaScript for user_vo plugin

// Simple HTML escaping function
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

document.addEventListener('DOMContentLoaded', function() {
    // Configuration form handling
    const configForm = document.getElementById('user-vo-config-form');
    const testConfigButtons = document.querySelectorAll('.test-config-btn');
    const clearConfigButton = document.getElementById('clear-config');
    const configMessageConfigPhp = document.getElementById('config-message-configphp');
    const configMessageAdmin = document.getElementById('config-message-admin');

    if (configForm) {
        configForm.addEventListener('submit', function(e) {
            e.preventDefault();
            saveConfiguration();
        });
    }

    // Attach event listener to all test config buttons (config.php and admin interface)
    testConfigButtons.forEach(button => {
        button.addEventListener('click', function() {
            testConfiguration(this);
        });
    });

    if (clearConfigButton) {
        clearConfigButton.addEventListener('click', function() {
            if (confirm(t('user_vo', 'Are you sure you want to clear the configuration? This will remove all saved settings.'))) {
                clearConfiguration();
            }
        });
    }

    function saveConfiguration() {
        const formData = new FormData(configForm);
        const data = {
            api_url: formData.get('api_url'),
            api_username: formData.get('api_username'),
            api_password: formData.get('api_password')
        };

        const submitButton = configForm.querySelector('button[type="submit"]');
        const originalText = submitButton.textContent;
        submitButton.disabled = true;
        submitButton.textContent = t('user_vo', 'Saving...');

        fetch(OC.generateUrl('/apps/user_vo/admin/save-config'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            submitButton.disabled = false;
            submitButton.textContent = originalText;

            if (data.success) {
                showConfigMessage(data.message, 'success', submitButton);
                // Reload the page to update the configuration status
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                showConfigMessage(data.message, 'error', submitButton);
            }
        })
        .catch(error => {
            submitButton.disabled = false;
            submitButton.textContent = originalText;
            showConfigMessage(t('user_vo', 'Error saving configuration') + ': ' + error, 'error', submitButton);
        });
    }

    function testConfiguration(button) {
        let data;

        // Check if button has data attributes (config.php mode) or use form (admin interface mode)
        const mode = button.getAttribute('data-mode');
        if (mode === 'config-php') {
            // Send empty values - server will load everything from config.php
            data = {
                api_url: '',
                api_username: '',
                api_password: '' // Password will be retrieved server-side from config
            };
        } else if (configForm) {
            // Get configuration from form (admin interface mode)
            const formData = new FormData(configForm);
            data = {
                api_url: formData.get('api_url'),
                api_username: formData.get('api_username'),
                api_password: formData.get('api_password')
            };
        } else {
            showConfigMessage(t('user_vo', 'Unable to test configuration: no form found'), 'error', button);
            return;
        }

        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = t('user_vo', 'Testing...');

        fetch(OC.generateUrl('/apps/user_vo/admin/test-config'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify(data)
        })
        .then(response => response.json())
        .then(data => {
            button.disabled = false;
            button.textContent = originalText;

            if (data.success) {
                showConfigMessage(data.message, 'success', button);
            } else {
                showConfigMessage(data.message, 'error', button);
            }
        })
        .catch(error => {
            button.disabled = false;
            button.textContent = originalText;
            showConfigMessage(t('user_vo', 'Error testing configuration') + ': ' + error, 'error', button);
        });
    }

    function clearConfiguration() {
        const originalText = clearConfigButton.textContent;
        clearConfigButton.disabled = true;
        clearConfigButton.textContent = t('user_vo', 'Clearing...');

        fetch(OC.generateUrl('/apps/user_vo/admin/clear-config'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            }
        })
        .then(response => response.json())
        .then(data => {
            clearConfigButton.disabled = false;
            clearConfigButton.textContent = originalText;

            if (data.success) {
                showConfigMessage(data.message, 'success');
                // Clear the form fields
                document.getElementById('api-url').value = '';
                document.getElementById('api-username').value = '';
                document.getElementById('api-password').value = '';
                // Refresh the page to update the configuration status
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                showConfigMessage(data.message, 'error');
            }
        })
        .catch(error => {
            clearConfigButton.disabled = false;
            clearConfigButton.textContent = originalText;
            showConfigMessage(t('user_vo', 'Error clearing configuration') + ': ' + error, 'error');
        });
    }

    function showConfigMessage(message, type, button) {
        // Determine which message div to use based on button mode
        let messageDiv = null;
        if (button) {
            const mode = button.getAttribute('data-mode');
            if (mode === 'config-php') {
                messageDiv = configMessageConfigPhp;
            } else {
                messageDiv = configMessageAdmin;
            }
        }

        // Fallback to admin message div if not found
        if (!messageDiv) {
            messageDiv = configMessageAdmin;
        }

        if (messageDiv) {
            messageDiv.textContent = message;
            messageDiv.className = 'config-message ' + type;
            messageDiv.style.display = 'block';

            setTimeout(() => {
                messageDiv.style.display = 'none';
            }, 5000);
        } else {
            // Fallback to browser alert if no message div found
            alert(message);
        }
    }

    // Duplicate user management functionality
    const scanButton = document.getElementById('scan-duplicates');
    const scanResults = document.getElementById('scan-results');
    const summaryInfo = document.getElementById('summary-info');
    const duplicateResults = document.getElementById('duplicate-results');
    const duplicateList = document.getElementById('duplicate-list');
    const allUsersResults = document.getElementById('all-users-results');
    const allUsersList = document.getElementById('all-users-list');

    if (!scanButton) {
        console.error('Scan button not found');
        return;
    }

    scanButton.addEventListener('click', function() {
        scanButton.disabled = true;
        scanButton.textContent = t('user_vo', 'Scanning...');

        fetch(OC.generateUrl('/apps/user_vo/admin/scan-duplicates'), {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            }
        })
        .then(response => {
            return response.json();
        })
        .then(data => {
            scanButton.disabled = false;
            scanButton.textContent = t('user_vo', 'Scan for Users');

            if (data.success) {
                displayResults(data);
            } else {
                OC.Notification.showTemporary(t('user_vo', 'Error scanning for users') + ': ' + data.error);
            }
        })
        .catch(error => {
            console.error('Fetch error:', error);
            scanButton.disabled = false;
            scanButton.textContent = t('user_vo', 'Scan for Users');
            OC.Notification.showTemporary(t('user_vo', 'Error scanning for users') + ': ' + error);
        });
    });

    function displayResults(data) {
        // Display summary
        let summaryHtml = '<div class="user-summary">';
        summaryHtml += '<h4>' + t('user_vo', 'Summary') + '</h4>';
        summaryHtml += '<p><strong>' + t('user_vo', 'Duplicate Users:') + '</strong> ' + data.summary.duplicateSets + '</p>';
        summaryHtml += '<p><strong>' + t('user_vo', 'Total Managed Users:') + '</strong> ' + data.summary.totalManagedUsers + '</p>';
        summaryHtml += '</div>';
        summaryInfo.innerHTML = summaryHtml;

        // Display duplicate users
        if (data.duplicateSets && data.duplicateSets.length > 0) {
            displayDuplicateSets(data.duplicateSets);
            duplicateResults.style.display = 'block';
        } else {
            duplicateList.innerHTML = '<p>' + t('user_vo', 'No duplicate users found.') + '</p>';
            duplicateResults.style.display = 'block';
        }

        // Display all plugin users
        if (data.allPluginUsers && data.allPluginUsers.length > 0) {
            displayAllPluginUsers(data.allPluginUsers);
            allUsersResults.style.display = 'block';
        } else {
            allUsersList.innerHTML = '<p>' + t('user_vo', 'No plugin users found.') + '</p>';
            allUsersResults.style.display = 'block';
        }

        scanResults.style.display = 'block';
    }

    function displayDuplicateSets(duplicateSets) {
        let html = '<div class="duplicate-sets">';

        duplicateSets.forEach((set, index) => {
            // Find the canonical user for the title
            let canonicalUser = set.variants.find(variant => variant.is_canonical);
            let title = canonicalUser ? canonicalUser.displayname : set.normalized_uid;

            html += '<div class="duplicate-set">';
            html += '<h5>' + escapeHtml(title) + '</h5>';
            html += '<table class="duplicate-variants">';
            html += '<thead><tr><th>' + t('user_vo', 'Username') + '</th><th>' + t('user_vo', 'Canonical') + '</th><th>' + t('user_vo', 'Exposed') + '</th><th>' + t('user_vo', 'Files') + '</th><th>' + t('user_vo', 'Groups') + '</th><th>' + t('user_vo', 'Created') + '</th><th>' + t('user_vo', 'Display Name') + '</th></tr></thead>';
            html += '<tbody>';

            set.variants.forEach(variant => {
                html += '<tr>';
                html += '<td>' + escapeHtml(variant.display_uid || variant.uid) + '</td>';
                html += '<td>' + (variant.is_canonical ? '✔️' : '') + '</td>';
                html += '<td>' + renderExposeCheckbox(variant) + '</td>';
                html += '<td>' + variant.file_count + '</td>';
                html += '<td>' + renderGroups(variant.groups) + '</td>';
                html += '<td>' + renderCreationDate(variant.creation_date) + '</td>';
                html += '<td>' + escapeHtml(variant.displayname) + '</td>';
                html += '</tr>';
            });

            html += '</tbody></table></div>';
        });

        html += '</div>';
        duplicateList.innerHTML = html;

        // Add event listeners for checkboxes
        duplicateList.querySelectorAll('.expose-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function(e) {
                const uid = this.getAttribute('data-uid');
                if (this.checked) {
                    exposeUser(uid);
                } else {
                    hideUser(uid);
                }
            });
        });
    }

    function displayAllPluginUsers(allPluginUsers) {
        let html = '<div class="all-plugin-users">';
        html += '<table class="all-users-table">';
        html += '<thead><tr><th>' + t('user_vo', 'Username') + '</th><th>' + t('user_vo', 'Canonical') + '</th><th>' + t('user_vo', 'Exposed') + '</th><th>' + t('user_vo', 'Normalized') + '</th><th>' + t('user_vo', 'Files') + '</th><th>' + t('user_vo', 'Groups') + '</th><th>' + t('user_vo', 'Created') + '</th><th>' + t('user_vo', 'Display Name') + '</th></tr></thead>';
        html += '<tbody>';

        allPluginUsers.forEach(user => {
            html += '<tr>';
            html += '<td>' + escapeHtml(user.display_uid || user.uid) + '</td>';
            html += '<td>' + (user.is_canonical ? '✔️' : '') + '</td>';
            html += '<td>' + (user.is_exposed ? '✔️' : '') + '</td>';
            html += '<td>' + (user.is_normalized ? '✔️' : '') + '</td>';
            html += '<td>' + user.file_count + '</td>';
            html += '<td>' + renderGroups(user.groups) + '</td>';
            html += '<td>' + renderCreationDate(user.creation_date) + '</td>';
            html += '<td>' + escapeHtml(user.displayname) + '</td>';
            html += '</tr>';
        });

        html += '</tbody></table></div>';
        allUsersList.innerHTML = html;
    }

    function renderExposeCheckbox(variant) {
        if (variant.is_canonical) {
            return '<input type="checkbox" checked disabled title="' + t('user_vo', 'Canonical user always exposed') + '">';
        }
        return '<input type="checkbox" class="expose-checkbox" data-uid="' + escapeHtml(variant.uid) + '"' + (variant.is_exposed ? ' checked' : '') + '>';
    }

    function renderGroups(groups) {
        if (!groups || groups.length === 0) {
            return '<span class="no-groups">—</span>';
        }
        return '<span class="groups-list">' + escapeHtml(groups.join(', ')) + '</span>';
    }

    function renderCreationDate(creationDate) {
        if (!creationDate) {
            return '<span class="no-date">—</span>';
        }
        return '<span class="creation-date">' + escapeHtml(creationDate) + '</span>';
    }

    function exposeUser(uid) {
        fetch(OC.generateUrl('/apps/user_vo/admin/expose-user'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify({ uid })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                OC.Notification.showTemporary(t('user_vo', 'User exposed successfully'));
                scanButton.click(); // Refresh the list
            } else {
                OC.Notification.showTemporary(t('user_vo', 'Error exposing user') + ': ' + data.error);
            }
        })
        .catch(error => {
            OC.Notification.showTemporary(t('user_vo', 'Error exposing user') + ': ' + error);
        });
    }

    function hideUser(uid) {
        fetch(OC.generateUrl('/apps/user_vo/admin/hide-user'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'requesttoken': OC.requestToken
            },
            body: JSON.stringify({ uid })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                OC.Notification.showTemporary(t('user_vo', 'User hidden successfully'));
                scanButton.click(); // Refresh the list
            } else {
                OC.Notification.showTemporary(t('user_vo', 'Error hiding user') + ': ' + data.error);
            }
        })
        .catch(error => {
            OC.Notification.showTemporary(t('user_vo', 'Error hiding user') + ': ' + error);
        });
    }

    // ========================================
    // User Data Synchronization
    // ========================================

    const saveUserSyncSettingsButton = document.getElementById('save-user-sync-settings');
    const syncEmailCheckbox = document.getElementById('sync-email');
    const syncPhotoCheckbox = document.getElementById('sync-photo');
    const userSyncMessage = document.getElementById('user-sync-message');
    const syncAllUsersButton = document.getElementById('sync-all-users');
    const syncAllUsersStatus = document.getElementById('sync-all-users-status');
    const userSyncResults = document.getElementById('user-sync-results');
    const userSyncSummary = document.getElementById('user-sync-summary');
    const userSyncList = document.getElementById('user-sync-list');

    // Save user sync settings
    if (saveUserSyncSettingsButton) {
        saveUserSyncSettingsButton.addEventListener('click', function() {
            const syncEmail = syncEmailCheckbox ? syncEmailCheckbox.checked : false;
            const syncPhoto = syncPhotoCheckbox ? syncPhotoCheckbox.checked : false;

            fetch(OC.generateUrl('/apps/user_vo/admin/save-user-sync-settings'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    sync_email: syncEmail,
                    sync_photo: syncPhoto
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    userSyncMessage.textContent = data.message;
                    userSyncMessage.className = 'config-message success';
                    userSyncMessage.style.display = 'inline';

                    setTimeout(() => {
                        userSyncMessage.style.display = 'none';
                    }, 3000);
                } else {
                    userSyncMessage.textContent = data.message || 'Error saving settings';
                    userSyncMessage.className = 'config-message error';
                    userSyncMessage.style.display = 'inline';
                }
            })
            .catch(error => {
                userSyncMessage.textContent = 'Error: ' + error;
                userSyncMessage.className = 'config-message error';
                userSyncMessage.style.display = 'inline';
            });
        });
    }

    // Helper function to escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Nightly sync checkbox handler
    const nightlySyncCheckbox = document.getElementById('enable-nightly-sync');
    if (nightlySyncCheckbox) {
        nightlySyncCheckbox.addEventListener('change', function() {
            const enabled = this.checked;

            fetch(OC.generateUrl('/apps/user_vo/admin/save-nightly-sync'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ enabled: enabled })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    OC.Notification.showTemporary(t('user_vo', 'Nightly sync setting saved'));
                    // Refresh status display
                    loadNightlySyncStatus();
                } else {
                    OC.Notification.showTemporary(t('user_vo', 'Error saving nightly sync setting'), { type: 'error' });
                }
            })
            .catch(error => {
                OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + error, { type: 'error' });
            });
        });

        // Load status on page load
        loadNightlySyncStatus();
    }

    // Function to load and display nightly sync status
    function loadNightlySyncStatus() {
        fetch(OC.generateUrl('/apps/user_vo/admin/nightly-sync-status'), {
            method: 'GET',
            headers: {
                'requesttoken': OC.requestToken
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateNightlySyncStatusDisplay(data);
            }
        })
        .catch(error => {
            console.error('Error loading nightly sync status:', error);
        });
    }

    // Function to update the status display
    function updateNightlySyncStatusDisplay(data) {
        const statusBadge = document.getElementById('nightly-sync-status-badge');
        const lastRunElement = document.getElementById('nightly-sync-last-run');
        const summaryElement = document.getElementById('nightly-sync-summary');
        const errorContainer = document.getElementById('nightly-sync-error-container');
        const errorElement = document.getElementById('nightly-sync-error');

        // Update status badge
        statusBadge.className = 'status-badge ' + data.last_status;
        if (data.last_status === 'success') {
            statusBadge.textContent = t('user_vo', 'Success');
        } else if (data.last_status === 'failed') {
            statusBadge.textContent = t('user_vo', 'Failed');
        } else {
            statusBadge.textContent = t('user_vo', 'Never run');
        }

        // Update last run time
        if (data.last_run) {
            const date = new Date(data.last_run * 1000);
            lastRunElement.textContent = date.toLocaleString();
        } else {
            lastRunElement.textContent = t('user_vo', 'Never');
        }

        // Update summary
        if (data.last_summary && data.last_summary.total !== undefined) {
            const summary = data.last_summary;
            const parts = [];
            if (summary.synced > 0) parts.push(t('user_vo', '{synced} synced', {synced: summary.synced}));
            if (summary.failed > 0) parts.push(t('user_vo', '{failed} failed', {failed: summary.failed}));
            if (summary.skipped > 0) parts.push(t('user_vo', '{skipped} skipped', {skipped: summary.skipped}));
            summaryElement.textContent = parts.length > 0 ? parts.join(', ') : t('user_vo', 'No users to sync');
        } else {
            summaryElement.textContent = t('user_vo', 'No sync data available');
        }

        // Update error display
        if (data.last_error && data.last_error.trim() !== '') {
            errorElement.textContent = data.last_error;
            errorContainer.style.display = 'flex';
        } else {
            errorContainer.style.display = 'none';
        }
    }

    // View local data (fast, no API calls)
    const viewLocalDataButton = document.getElementById('view-local-data');
    if (viewLocalDataButton) {
        viewLocalDataButton.addEventListener('click', function() {
            viewLocalDataButton.disabled = true;
            syncAllUsersStatus.textContent = t('user_vo', 'Loading users...');
            syncAllUsersStatus.className = 'sync-status syncing';
            userSyncResults.style.display = 'none';

            fetch(OC.generateUrl('/apps/user_vo/admin/preview-local-users'), {
                method: 'GET',
                headers: {
                    'requesttoken': OC.requestToken
                }
            })
            .then(response => response.json())
            .then(data => {
                viewLocalDataButton.disabled = false;

                if (data.success) {
                    syncAllUsersStatus.textContent = '';

                    // Show summary
                    userSyncSummary.innerHTML = `
                        <p><strong>${t('user_vo', 'Local user data (database only):')}</strong></p>
                        <ul>
                            <li>${t('user_vo', 'Total users:')} ${data.total}</li>
                        </ul>
                    `;

                    // Show results table
                    userSyncList.innerHTML = '';
                    data.results.forEach(result => {
                        const row = document.createElement('tr');
                        row.className = result.status;

                        const statusIcon = result.status === 'info' ? 'ℹ' :
                                         result.status === 'skipped' ? '○' : '○';

                        row.innerHTML = `
                            <td><input type="checkbox" class="sync-user-checkbox" data-uid="${escapeHtml(result.uid)}" /></td>
                            <td>${escapeHtml(result.uid)}</td>
                            <td>${escapeHtml(result.vo_username || '-')}</td>
                            <td>${escapeHtml(result.vo_user_id || '-')}</td>
                            <td>${escapeHtml(result.display_name || '-')}</td>
                            <td>${escapeHtml(result.email || '-')}</td>
                            <td>${escapeHtml(result.photo_status || '-')}</td>
                            <td>${escapeHtml(result.last_synced || '-')}</td>
                            <td><span class="status-${result.status}">${statusIcon} ${escapeHtml(result.message)}</span></td>
                        `;
                        userSyncList.appendChild(row);
                    });

                    userSyncResults.style.display = 'block';
                } else {
                    syncAllUsersStatus.textContent = t('user_vo', 'Failed to load data:') + ' ' + (data.error || 'Unknown error');
                    syncAllUsersStatus.className = 'sync-status error';
                    OC.Notification.showTemporary(t('user_vo', 'Error loading data') + ': ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                viewLocalDataButton.disabled = false;
                syncAllUsersStatus.textContent = t('user_vo', 'Error:') + ' ' + error;
                syncAllUsersStatus.className = 'sync-status error';
                OC.Notification.showTemporary(t('user_vo', 'Error loading data') + ': ' + error);
            });
        });
    }

    // View user metadata (with VO API calls)
    const viewUserMetadataButton = document.getElementById('view-user-metadata');
    if (viewUserMetadataButton) {
        viewUserMetadataButton.addEventListener('click', function() {
            viewUserMetadataButton.disabled = true;
            syncAllUsersStatus.textContent = t('user_vo', 'Previewing from VO... (this may take a moment)');
            syncAllUsersStatus.className = 'sync-status syncing';
            userSyncResults.style.display = 'none';

            fetch(OC.generateUrl('/apps/user_vo/admin/preview-vo-users'), {
                method: 'GET',
                headers: {
                    'requesttoken': OC.requestToken
                }
            })
            .then(response => response.json())
            .then(data => {
                viewUserMetadataButton.disabled = false;

                if (data.success) {
                    syncAllUsersStatus.textContent = '';

                    // Show summary
                    userSyncSummary.innerHTML = `
                        <p><strong>${t('user_vo', 'User metadata (not synced):')}</strong></p>
                        <ul>
                            <li>${t('user_vo', 'Total users:')} ${data.total}</li>
                        </ul>
                    `;

                    // Show results table
                    userSyncList.innerHTML = '';
                    data.results.forEach(result => {
                        const row = document.createElement('tr');
                        row.className = result.status;

                        const statusIcon = result.status === 'info' ? 'ℹ' :
                                         result.status === 'deleted' ? '⚠' :
                                         result.status === 'failed' ? '✗' :
                                         result.status === 'skipped' ? '○' : '○';

                        row.innerHTML = `
                            <td><input type="checkbox" class="sync-user-checkbox" data-uid="${escapeHtml(result.uid)}" /></td>
                            <td>${escapeHtml(result.uid)}</td>
                            <td>${escapeHtml(result.vo_username || '-')}</td>
                            <td>${escapeHtml(result.vo_user_id || '-')}</td>
                            <td>${escapeHtml(result.display_name || '-')}</td>
                            <td>${escapeHtml(result.email || '-')}</td>
                            <td>${escapeHtml(result.photo_status || '-')}</td>
                            <td>${escapeHtml(result.last_synced || '-')}</td>
                            <td><span class="status-${result.status}">${statusIcon} ${escapeHtml(result.message)}</span></td>
                        `;
                        userSyncList.appendChild(row);
                    });

                    userSyncResults.style.display = 'block';
                } else {
                    syncAllUsersStatus.textContent = t('user_vo', 'Failed to load metadata:') + ' ' + (data.error || 'Unknown error');
                    syncAllUsersStatus.className = 'sync-status error';
                }
            })
            .catch(error => {
                viewUserMetadataButton.disabled = false;
                syncAllUsersStatus.textContent = t('user_vo', 'Error:') + ' ' + error;
                syncAllUsersStatus.className = 'sync-status error';
            });
        });
    }

    // Sync all users
    if (syncAllUsersButton) {
        syncAllUsersButton.addEventListener('click', function() {
            syncAllUsersButton.disabled = true;
            syncAllUsersStatus.textContent = t('user_vo', 'Syncing from VO... (this may take a moment)');
            syncAllUsersStatus.className = 'sync-status syncing';
            userSyncResults.style.display = 'none';

            fetch(OC.generateUrl('/apps/user_vo/admin/sync-from-vo'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                }
            })
            .then(response => response.json())
            .then(data => {
                syncAllUsersButton.disabled = false;

                if (data.success) {
                    const summary = data.summary;
                    syncAllUsersStatus.textContent = '';

                    // Show summary
                    userSyncSummary.innerHTML = `
                        <p><strong>${t('user_vo', 'Sync completed:')}</strong></p>
                        <ul>
                            <li>${t('user_vo', 'Total users:')} ${summary.total}</li>
                            <li class="success">${t('user_vo', 'Successfully synced:')} ${summary.success}</li>
                            <li class="error">${t('user_vo', 'Failed:')} ${summary.failed}</li>
                            <li>${t('user_vo', 'Skipped:')} ${summary.skipped}</li>
                        </ul>
                    `;

                    // Show results table
                    userSyncList.innerHTML = '';
                    data.results.forEach(result => {
                        const row = document.createElement('tr');
                        row.className = result.status;

                        const statusIcon = result.status === 'success' ? '✓' :
                                         result.status === 'deleted' ? '⚠' :
                                         result.status === 'failed' ? '✗' :
                                         result.status === 'skipped' ? '○' : '○';

                        row.innerHTML = `
                            <td><input type="checkbox" class="sync-user-checkbox" data-uid="${escapeHtml(result.uid)}" /></td>
                            <td>${escapeHtml(result.uid)}</td>
                            <td>${escapeHtml(result.vo_username || '-')}</td>
                            <td>${escapeHtml(result.vo_user_id || '-')}</td>
                            <td>${escapeHtml(result.display_name || '-')}</td>
                            <td>${escapeHtml(result.email || '-')}</td>
                            <td>${escapeHtml(result.photo_status || '-')}</td>
                            <td>${escapeHtml(result.last_synced || '-')}</td>
                            <td><span class="status-${result.status}">${statusIcon} ${escapeHtml(result.message)}</span></td>
                        `;
                        userSyncList.appendChild(row);
                    });

                    userSyncResults.style.display = 'block';

                    OC.Notification.showTemporary(
                        t('user_vo', 'User sync completed: {success} succeeded, {failed} failed', {
                            success: summary.success,
                            failed: summary.failed
                        })
                    );
                } else {
                    syncAllUsersStatus.textContent = t('user_vo', 'Sync failed:') + ' ' + (data.error || 'Unknown error');
                    syncAllUsersStatus.className = 'sync-status error';
                }
            })
            .catch(error => {
                syncAllUsersButton.disabled = false;
                syncAllUsersStatus.textContent = t('user_vo', 'Error:') + ' ' + error;
                syncAllUsersStatus.className = 'sync-status error';
            });
        });
    }

    // Pre-provision user accounts
    const searchVOUsersBtn = document.getElementById('search-vo-users-btn');
    if (searchVOUsersBtn) {
        searchVOUsersBtn.addEventListener('click', function() {
            const searchTerm = document.getElementById('vo-user-search').value.trim();
            const searchStatus = document.getElementById('search-vo-users-status');
            const searchWarning = document.getElementById('vo-user-search-warning');
            const searchResults = document.getElementById('vo-user-search-results');

            // Show warning if searching all users
            if (searchTerm === '') {
                searchWarning.style.display = 'block';
            } else {
                searchWarning.style.display = 'none';
            }

            searchVOUsersBtn.disabled = true;
            searchStatus.textContent = t('user_vo', 'Searching...');
            searchStatus.className = 'sync-status';

            const url = OC.generateUrl('/apps/user_vo/admin/search-vo-users') +
                        '?search_term=' + encodeURIComponent(searchTerm);

            fetch(url, {
                method: 'GET',
                headers: {
                    'requesttoken': OC.requestToken
                }
            })
            .then(response => response.json())
            .then(data => {
                searchVOUsersBtn.disabled = false;
                if (data.success) {
                    displayVOUserSearchResults(data);
                    searchStatus.textContent = t('user_vo', 'Found {count} users', { count: data.count });
                    searchStatus.className = 'sync-status success';
                    searchResults.style.display = 'block';
                } else {
                    searchStatus.textContent = t('user_vo', 'Error:') + ' ' + (data.error || 'Unknown error');
                    searchStatus.className = 'sync-status error';
                }
            })
            .catch(error => {
                searchVOUsersBtn.disabled = false;
                searchStatus.textContent = t('user_vo', 'Error:') + ' ' + error;
                searchStatus.className = 'sync-status error';
            });
        });
    }

    function displayVOUserSearchResults(response) {
        const summary = document.getElementById('vo-user-search-summary');
        const list = document.getElementById('vo-user-search-list');

        list.innerHTML = '';

        // Summary
        let summaryText = t('user_vo', 'Found {count} users', { count: response.count });
        if (response.is_all_users) {
            summaryText += ' ' + t('user_vo', '(showing all VO users with login credentials)');
        } else {
            summaryText += ' ' + t('user_vo', 'matching "{term}"', { term: response.search_term });
        }
        summary.innerHTML = `<p>${summaryText}</p>`;

        // Results table
        response.users.forEach(user => {
            const row = document.createElement('tr');

            const statusBadge = user.nc_account_exists
                ? `<span class="vo-badge vo-badge-success">✓ ${escapeHtml(user.nc_username)}</span>`
                : '<span class="vo-badge vo-badge-warning">' + t('user_vo', 'Not created') + '</span>';

            const actionButton = user.nc_account_exists
                ? '<span class="vo-text-muted">—</span>'
                : `<button class="button create-account-btn" data-vo-user-id="${escapeHtml(user.vo_user_id)}">` +
                  t('user_vo', 'Create Account') + '</button>';

            const checkbox = user.nc_account_exists
                ? '<span class="vo-text-muted">—</span>'
                : `<input type="checkbox" class="vo-user-checkbox" data-vo-user-id="${escapeHtml(user.vo_user_id)}" />`;

            row.innerHTML = `
                <td>${checkbox}</td>
                <td>${escapeHtml(user.vo_name || '—')}</td>
                <td>${escapeHtml(user.vo_username)}</td>
                <td>${escapeHtml(user.display_name)}</td>
                <td>${escapeHtml(user.email || '—')}</td>
                <td>${escapeHtml(user.vo_user_id)}</td>
                <td>${statusBadge}</td>
                <td>${actionButton}</td>
            `;
            list.appendChild(row);
        });
    }

    // Create single account
    document.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('create-account-btn')) {
            const voUserId = e.target.getAttribute('data-vo-user-id');
            const button = e.target;

            button.disabled = true;
            button.textContent = t('user_vo', 'Creating...');

            fetch(OC.generateUrl('/apps/user_vo/admin/create-account-from-vo'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ vo_user_id: voUserId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    OC.Notification.showTemporary(
                        t('user_vo', "Account '{username}' created successfully", { username: data.nc_username })
                    );
                    // Refresh search results
                    document.getElementById('search-vo-users-btn').click();
                } else {
                    OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + data.error, { type: 'error' });
                    button.disabled = false;
                    button.textContent = t('user_vo', 'Create Account');
                }
            })
            .catch(error => {
                OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + error, { type: 'error' });
                button.disabled = false;
                button.textContent = t('user_vo', 'Create Account');
            });
        }
    });

    // Bulk create accounts
    const bulkCreateBtn = document.getElementById('bulk-create-accounts-btn');
    if (bulkCreateBtn) {
        bulkCreateBtn.addEventListener('click', function() {
            const selectedUserIds = [];
            document.querySelectorAll('.vo-user-checkbox:checked').forEach(checkbox => {
                selectedUserIds.push(checkbox.getAttribute('data-vo-user-id'));
            });

            if (selectedUserIds.length === 0) {
                OC.Notification.showTemporary(t('user_vo', 'Please select at least one user'), { type: 'error' });
                return;
            }

            if (!confirm(t('user_vo', 'Create {count} account(s)?', { count: selectedUserIds.length }))) {
                return;
            }

            const bulkCreateStatus = document.getElementById('bulk-create-status');
            bulkCreateBtn.disabled = true;
            bulkCreateStatus.textContent = t('user_vo', 'Creating accounts...');
            bulkCreateStatus.className = 'sync-status';

            fetch(OC.generateUrl('/apps/user_vo/admin/bulk-create-accounts-from-vo'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ vo_user_ids: selectedUserIds })
            })
            .then(response => response.json())
            .then(data => {
                bulkCreateBtn.disabled = false;
                if (data.success) {
                    const summary = data.summary;
                    const message = t('user_vo', 'Created: {created}, Skipped: {skipped}, Errors: {errors}', {
                        created: summary.created,
                        skipped: summary.skipped,
                        errors: summary.errors
                    });
                    bulkCreateStatus.textContent = message;
                    bulkCreateStatus.className = 'sync-status success';
                    OC.Notification.showTemporary(t('user_vo', 'Bulk account creation completed'));

                    // Refresh search results
                    setTimeout(() => document.getElementById('search-vo-users-btn').click(), 1000);
                } else {
                    bulkCreateStatus.textContent = t('user_vo', 'Error:') + ' ' + (data.error || 'Unknown error');
                    bulkCreateStatus.className = 'sync-status error';
                }
            })
            .catch(error => {
                bulkCreateBtn.disabled = false;
                bulkCreateStatus.textContent = t('user_vo', 'Error:') + ' ' + error;
                bulkCreateStatus.className = 'sync-status error';
            });
        });
    }

    // Select all checkbox (pre-provision)
    const selectAllCheckbox = document.getElementById('select-all-vo-users');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            document.querySelectorAll('.vo-user-checkbox').forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
        });
    }

    // Select all checkbox (sync users)
    const selectAllSyncCheckbox = document.getElementById('select-all-sync-users');
    if (selectAllSyncCheckbox) {
        selectAllSyncCheckbox.addEventListener('change', function() {
            document.querySelectorAll('.sync-user-checkbox').forEach(checkbox => {
                checkbox.checked = selectAllSyncCheckbox.checked;
            });
        });
    }

    // Sync selected users button
    const syncSelectedBtn = document.getElementById('sync-selected-users-btn');
    if (syncSelectedBtn) {
        syncSelectedBtn.addEventListener('click', function() {
            const selectedCheckboxes = document.querySelectorAll('.sync-user-checkbox:checked');
            const selectedUids = Array.from(selectedCheckboxes).map(cb => cb.dataset.uid);

            if (selectedUids.length === 0) {
                OC.Notification.showTemporary(t('user_vo', 'Please select at least one user to sync'));
                return;
            }

            const statusSpan = document.getElementById('sync-selected-status');
            const userSyncSummary = document.getElementById('user-sync-summary');
            const userSyncList = document.getElementById('user-sync-list');

            statusSpan.textContent = t('user_vo', 'Syncing {count} user(s)...', {count: selectedUids.length});
            statusSpan.style.color = '';
            syncSelectedBtn.disabled = true;

            // Clear previous summary
            userSyncSummary.innerHTML = '';

            fetch(OC.generateUrl('/apps/user_vo/admin/sync-selected-users'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    user_ids: selectedUids
                })
            })
            .then(response => response.json())
            .then(data => {
                syncSelectedBtn.disabled = false;

                if (data.success) {
                    const summary = data.summary;
                    const message = t('user_vo', 'Sync complete: {synced} synced, {failed} failed', {
                        synced: summary.synced,
                        failed: summary.failed
                    });
                    statusSpan.textContent = message;
                    statusSpan.style.color = summary.failed > 0 ? 'orange' : 'green';
                    OC.Notification.showTemporary(message);

                    // Update only the synced rows in the table
                    if (data.results && data.results.length > 0) {
                        data.results.forEach(result => {
                            // Find the existing row for this user
                            const existingCheckbox = userSyncList.querySelector(`.sync-user-checkbox[data-uid="${escapeHtml(result.uid)}"]`);
                            if (existingCheckbox) {
                                const existingRow = existingCheckbox.closest('tr');

                                const statusIcon = result.status === 'success' ? '✓' :
                                                 result.status === 'deleted' ? '⚠' :
                                                 result.status === 'failed' ? '✗' :
                                                 result.status === 'skipped' ? '○' : '○';

                                // Update the row with new data
                                existingRow.className = result.status;
                                existingRow.innerHTML = `
                                    <td><input type="checkbox" class="sync-user-checkbox" data-uid="${escapeHtml(result.uid)}" checked /></td>
                                    <td>${escapeHtml(result.uid)}</td>
                                    <td>${escapeHtml(result.vo_username || '-')}</td>
                                    <td>${escapeHtml(result.vo_user_id || '-')}</td>
                                    <td>${escapeHtml(result.display_name || '-')}</td>
                                    <td>${escapeHtml(result.email || '-')}</td>
                                    <td>${escapeHtml(result.photo_status || '-')}</td>
                                    <td>${escapeHtml(result.last_synced || '-')}</td>
                                    <td><span class="status-${result.status}">${statusIcon} ${escapeHtml(result.message)}</span></td>
                                `;
                            }
                        });
                    }
                } else {
                    statusSpan.textContent = t('user_vo', 'Sync failed: {error}', {error: data.message || 'Unknown error'});
                    statusSpan.style.color = 'red';
                    OC.Notification.showTemporary(t('user_vo', 'Failed to sync selected users: {error}', {error: data.message || 'Unknown error'}));
                }
            })
            .catch(error => {
                syncSelectedBtn.disabled = false;
                statusSpan.textContent = t('user_vo', 'Error: {error}', {error: error.message});
                statusSpan.style.color = 'red';
                OC.Notification.showTemporary(t('user_vo', 'Failed to sync selected users: {error}', {error: error.message}));
            });
        });
    }
});
