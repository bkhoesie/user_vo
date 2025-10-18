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

    // Helper function to generate sync summary HTML
    function generateSyncSummaryHTML(summary, isSelectiveSync = false) {
        let totalText = summary.total.toString();
        if (isSelectiveSync && summary.total_in_table) {
            totalText = `${summary.total} (out of ${summary.total_in_table})`;
        }

        return `
            <p><strong>${t('user_vo', 'Sync completed:')}</strong></p>
            <ul>
                <li>${t('user_vo', 'Total users:')} ${totalText}</li>
                <li class="success">${t('user_vo', 'Successfully synced:')} ${summary.success || summary.synced || 0}</li>
                <li class="error">${t('user_vo', 'Failed:')} ${summary.failed || 0}</li>
                <li>${t('user_vo', 'Skipped:')} ${summary.skipped || 0}</li>
            </ul>
        `;
    }

    // Helper function to generate photo errors HTML
    function generatePhotoErrorsHTML(results) {
        const photoErrors = results.filter(r => r.photo_error);
        if (photoErrors.length === 0) {
            return '';
        }

        const errorListHTML = photoErrors.map(r =>
            `<li>${escapeHtml(r.uid)}: ${escapeHtml(r.photo_error)}</li>`
        ).join('');

        return `
            <p><strong>⚠ ${t('user_vo', 'Photo Sync Issues')} (${photoErrors.length} ${photoErrors.length === 1 ? 'user' : 'users'}):</strong></p>
            <ul class="photo-errors">
                ${errorListHTML}
            </ul>
        `;
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

                    // Show summary with photo errors
                    userSyncSummary.innerHTML = generateSyncSummaryHTML(summary, false) + generatePhotoErrorsHTML(data.results);

                    // Show results table
                    userSyncList.innerHTML = '';
                    data.results.forEach(result => {
                        const row = document.createElement('tr');
                        row.className = result.status;

                        const statusIcon = result.status === 'success' ? '✓' :
                                         result.status === 'deleted' ? '⚠' :
                                         result.status === 'failed' ? '✗' :
                                         result.status === 'skipped' ? '○' : '○';

                        // Add warning icon for photo errors
                        let photoDisplay = escapeHtml(result.photo_status || '-');
                        if (result.photo_error) {
                            photoDisplay = '⚠ ' + photoDisplay;
                        }

                        row.innerHTML = `
                            <td><input type="checkbox" class="sync-user-checkbox" data-uid="${escapeHtml(result.uid)}" /></td>
                            <td>${escapeHtml(result.uid)}</td>
                            <td>${escapeHtml(result.vo_username || '-')}</td>
                            <td>${escapeHtml(result.vo_user_id || '-')}</td>
                            <td>${escapeHtml(result.display_name || '-')}</td>
                            <td>${escapeHtml(result.email || '-')}</td>
                            <td>${photoDisplay}</td>
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

            // Use unified status area
            syncSelectedBtn.disabled = true;
            syncAllUsersStatus.textContent = t('user_vo', 'Syncing {count} user(s)...', {count: selectedUids.length});
            syncAllUsersStatus.className = 'sync-status syncing';

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
                    syncAllUsersStatus.textContent = '';

                    // Show summary with photo errors (indicating selective sync)
                    userSyncSummary.innerHTML = generateSyncSummaryHTML(summary, true) + generatePhotoErrorsHTML(data.results);

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

                                // Add warning icon for photo errors
                                let photoDisplay = escapeHtml(result.photo_status || '-');
                                if (result.photo_error) {
                                    photoDisplay = '⚠ ' + photoDisplay;
                                }

                                // Update the row with new data
                                existingRow.className = result.status;
                                existingRow.innerHTML = `
                                    <td><input type="checkbox" class="sync-user-checkbox" data-uid="${escapeHtml(result.uid)}" checked /></td>
                                    <td>${escapeHtml(result.uid)}</td>
                                    <td>${escapeHtml(result.vo_username || '-')}</td>
                                    <td>${escapeHtml(result.vo_user_id || '-')}</td>
                                    <td>${escapeHtml(result.display_name || '-')}</td>
                                    <td>${escapeHtml(result.email || '-')}</td>
                                    <td>${photoDisplay}</td>
                                    <td>${escapeHtml(result.last_synced || '-')}</td>
                                    <td><span class="status-${result.status}">${statusIcon} ${escapeHtml(result.message)}</span></td>
                                `;
                            }
                        });
                    }

                    OC.Notification.showTemporary(
                        t('user_vo', 'Sync complete: {synced} synced, {failed} failed', {
                            synced: summary.synced,
                            failed: summary.failed
                        })
                    );
                } else {
                    syncAllUsersStatus.textContent = t('user_vo', 'Sync failed: {error}', {error: data.message || 'Unknown error'});
                    syncAllUsersStatus.className = 'sync-status error';
                    OC.Notification.showTemporary(t('user_vo', 'Failed to sync selected users: {error}', {error: data.message || 'Unknown error'}));
                }
            })
            .catch(error => {
                syncSelectedBtn.disabled = false;
                syncAllUsersStatus.textContent = t('user_vo', 'Error: {error}', {error: error.message});
                syncAllUsersStatus.className = 'sync-status error';
                OC.Notification.showTemporary(t('user_vo', 'Failed to sync selected users: {error}', {error: error.message}));
            });
        });
    }

    // ========================================
    // Group Management
    // ========================================

    const loadAllVOGroupsButton = document.getElementById('load-all-vo-groups');
    const loadManagedGroupsButton = document.getElementById('load-managed-groups');
    const groupsStatus = document.getElementById('groups-status');
    const groupsResults = document.getElementById('groups-results');
    const groupsSummary = document.getElementById('groups-summary');
    const groupsList = document.getElementById('groups-list');

    // Store expanded group position indices and current view type for state preservation
    // Using position index instead of group ID allows expansion state to work for placeholder parents
    let expandedGroupIndices = new Set();
    let currentViewType = null; // 'all' or 'managed'

    // Helper function to render group status badge
    function renderGroupStatusBadge(group) {
        if (group.deleted_in_vo) {
            return '<span class="vo-badge vo-badge-error">⚠ ' + escapeHtml(t('user_vo', 'Deleted in VO')) + '</span>';
        }

        if (group.is_managed) {
            return '<span class="vo-badge vo-badge-success">✓ ' + escapeHtml(t('user_vo', 'Created')) + '</span>';
        }

        return '<span class="vo-badge vo-badge-warning">' + escapeHtml(t('user_vo', 'Not created')) + '</span>';
    }

    // Helper function to render group actions
    function renderGroupActions(group) {
        if (group.deleted_in_vo) {
            // Deleted groups - only show info
            return '<span class="vo-text-muted">—</span>';
        }

        if (group.is_managed) {
            // Managed groups - show Sync and Delete buttons (Sync disabled for now, Delete enabled for Step 6)
            return `
                <button class="button sync-group-btn" data-vo-group-id="${escapeHtml(group.vo_group_id)}" disabled>
                    ${escapeHtml(t('user_vo', 'Sync'))}
                </button>
                <button class="button delete-group-btn" data-vo-group-id="${escapeHtml(group.vo_group_id)}" data-nc-group-id="${escapeHtml(group.nc_group_id)}">
                    ${escapeHtml(t('user_vo', 'Delete'))}
                </button>
            `;
        } else {
            // Not created groups - show Create button (enabled for Step 5)
            return `
                <button class="button create-group-btn" data-vo-group-id="${escapeHtml(group.vo_group_id)}">
                    ${escapeHtml(t('user_vo', 'Create'))}
                </button>
            `;
        }
    }

    // Helper function to create placeholder rows for missing parents in "managed" view
    function addPlaceholdersForMissingParents(groups) {
        const placeholders = [];
        // Map of position index -> group (to check if a parent at that index exists)
        const groupsByIndex = new Map();
        groups.forEach(g => {
            if (g.vo_position_index) {
                groupsByIndex.set(g.vo_position_index, g);
            }
        });

        // Track which placeholder indices we've already created
        const placeholderIndices = new Set();

        groups.forEach(group => {
            const posIndex = group.vo_position_index || '';
            const parts = posIndex.split('.');

            // For groups with hierarchical indices (e.g., "13.2.3"), check if parents exist
            if (parts.length > 1) {
                // Build each parent level
                for (let i = 1; i < parts.length; i++) {
                    const parentIndexParts = parts.slice(0, i);
                    const parentIndex = parentIndexParts.join('.');

                    // Skip if a real group exists at this index
                    if (groupsByIndex.has(parentIndex)) {
                        continue;
                    }

                    // Skip if we already created a placeholder for this index
                    if (placeholderIndices.has(parentIndex)) {
                        continue;
                    }

                    // Determine this placeholder's parent
                    const grandparentIndexParts = parentIndexParts.slice(0, -1);
                    const grandparentIndex = grandparentIndexParts.join('.');

                    // Check if grandparent exists (real or placeholder)
                    let grandparentId;
                    if (grandparentIndexParts.length === 0) {
                        grandparentId = '0'; // Root
                    } else if (groupsByIndex.has(grandparentIndex)) {
                        grandparentId = groupsByIndex.get(grandparentIndex).vo_group_id;
                    } else {
                        grandparentId = 'placeholder_' + grandparentIndex.replace(/\./g, '_');
                    }

                    const parentId = 'placeholder_' + parentIndex.replace(/\./g, '_');

                    placeholders.push({
                        vo_group_id: parentId,
                        vo_group_name: '',
                        vo_parent_id: grandparentId,
                        vo_position: parseInt(parentIndexParts[parentIndexParts.length - 1]) || 0,
                        vo_position_index: parentIndex,
                        nc_group_id: '',
                        is_managed: false,
                        _is_placeholder: true
                    });

                    placeholderIndices.add(parentIndex);
                }
            }
        });

        return placeholders;
    }

    // Helper function to sort groups hierarchically by VO position and calculate depth
    function sortGroupsHierarchically(groups) {
        // In "managed" view, add placeholders for missing parents
        let workingGroups = [...groups];
        if (currentViewType === 'managed') {
            const placeholders = addPlaceholdersForMissingParents(groups);

            // Update real groups to point to their immediate parent (placeholder or real)
            // Build index map first (including placeholders)
            const indexToId = new Map();
            groups.forEach(g => {
                if (g.vo_position_index) {
                    indexToId.set(g.vo_position_index, g.vo_group_id);
                }
            });
            placeholders.forEach(p => {
                indexToId.set(p.vo_position_index, p.vo_group_id);
            });

            // Update parent_id for groups whose parent is a placeholder
            workingGroups.forEach(group => {
                const posIndex = group.vo_position_index || '';
                const parts = posIndex.split('.');

                if (parts.length > 1) {
                    // Calculate parent index
                    const parentIndexParts = parts.slice(0, -1);
                    const parentIndex = parentIndexParts.join('.');

                    // If parent exists (real or placeholder), update parent_id
                    if (indexToId.has(parentIndex)) {
                        group.vo_parent_id = indexToId.get(parentIndex);
                    }
                }
            });

            workingGroups = [...workingGroups, ...placeholders];
        }

        // Build a map of groups by parent_id for efficient lookup
        const groupsByParent = {};
        const groupMap = {};

        workingGroups.forEach(group => {
            groupMap[group.vo_group_id] = group;
            const parentId = group.vo_parent_id || '0';
            if (!groupsByParent[parentId]) {
                groupsByParent[parentId] = [];
            }
            groupsByParent[parentId].push(group);
        });

        // Mark groups that have children
        const hasChildren = {};
        Object.keys(groupsByParent).forEach(parentId => {
            if (parentId !== '0' && groupsByParent[parentId].length > 0) {
                hasChildren[parentId] = true;
            }
        });

        // Sort siblings within each parent by position, then by name
        Object.keys(groupsByParent).forEach(parentId => {
            groupsByParent[parentId].sort((a, b) => {
                const posA = a.vo_position || 0;
                const posB = b.vo_position || 0;
                if (posA !== posB) {
                    return posA - posB;
                }
                // Secondary sort by name when positions are equal
                return a.vo_group_name.localeCompare(b.vo_group_name);
            });
        });

        // Recursively build the sorted flat list with depth and position index
        const result = [];
        function addGroupsRecursively(parentId, depth = 0, parentPositionIndex = '') {
            const children = groupsByParent[parentId] || [];
            children.forEach((group, index) => {
                // Build position index (e.g., "1", "2", "5.1", "5.2")
                const position = group.vo_position || 0;
                const positionIndex = parentPositionIndex
                    ? `${parentPositionIndex}.${position}`
                    : position.toString();

                // Add depth, isLast, hasChildren, and position index for display
                const isLast = index === children.length - 1;
                result.push({
                    ...group,
                    _depth: depth,
                    _isLast: isLast,
                    _positionIndex: positionIndex,
                    _hasChildren: hasChildren[group.vo_group_id] || false,
                    _expanded: false // Start collapsed by default
                });
                // Add this group's children recursively
                addGroupsRecursively(group.vo_group_id, depth + 1, positionIndex);
            });
        }

        // Start with root groups (parent_id = '0' or null)
        addGroupsRecursively('0');

        return result;
    }

    // Display groups in the table
    function displayGroups(groups, viewType) {
        const managedCount = groups.filter(g => g.is_managed).length;
        const deletedCount = groups.filter(g => g.deleted_in_vo).length;
        const totalCount = groups.length;

        // Track current view type for state handling
        currentViewType = viewType;

        // Sort groups hierarchically using VO's defined sort order
        const sortedGroups = sortGroupsHierarchically(groups);

        // In "managed" view, initialize all groups as expanded on first load only
        // (when expandedGroupIndices is empty, indicating first time showing this view)
        if (viewType === 'managed' && expandedGroupIndices.size === 0) {
            sortedGroups.forEach(group => {
                if (group._hasChildren) {
                    expandedGroupIndices.add(group._positionIndex);
                }
            });
        }

        // Show summary
        let summaryHTML = '';
        if (viewType === 'all') {
            summaryHTML = `
                <p><strong>${t('user_vo', 'All VereinOnline Groups:')}</strong></p>
                <ul>
                    <li>${t('user_vo', 'Showing:')} ${totalCount} ${t('user_vo', 'groups')}</li>
                    <li>${t('user_vo', 'Managed in NC:')} ${managedCount}</li>
                    <li>${t('user_vo', 'Not created:')} ${totalCount - managedCount}</li>
                </ul>
            `;
        } else {
            summaryHTML = `
                <p><strong>${t('user_vo', 'Managed Groups:')}</strong></p>
                <ul>
                    <li>${t('user_vo', 'Showing:')} ${totalCount} ${t('user_vo', 'managed groups')}</li>
                    ${deletedCount > 0 ? `<li class="error">${t('user_vo', 'Deleted in VO:')} ${deletedCount}</li>` : ''}
                </ul>
            `;
        }
        groupsSummary.innerHTML = summaryHTML;

        // Show groups table
        groupsList.innerHTML = '';

        if (groups.length === 0) {
            const row = document.createElement('tr');
            row.innerHTML = `<td colspan="8" style="text-align: center; padding: 20px;">${escapeHtml(t('user_vo', 'No groups found.'))}</td>`;
            groupsList.appendChild(row);
            return;
        }

        sortedGroups.forEach(group => {
            const row = document.createElement('tr');

            // Check if this is a placeholder for a missing parent
            const isPlaceholder = group._is_placeholder === true;

            // Apply row class based on status
            if (isPlaceholder) {
                row.className = 'vo-group-placeholder';
                row.style.opacity = '0.5'; // Dim placeholder rows
            } else if (group.deleted_in_vo) {
                row.className = 'vo-group-deleted';
            } else if (group.is_managed) {
                row.className = 'vo-group-managed';
            }

            const checkbox = (isPlaceholder || !group.is_managed)
                ? '<span class="vo-text-muted">—</span>'
                : `<input type="checkbox" class="vo-group-checkbox" data-vo-group-id="${escapeHtml(group.vo_group_id)}" />`;

            const voMemberCount = (isPlaceholder || group.vo_member_count === null) ? '-' : group.vo_member_count.toString();

            // Build indented group name with visual indicator
            const depth = group._depth || 0;

            // Build indent using invisible spacers for exact alignment
            let indent = '';
            if (depth > 0) {
                // For each parent level, add invisible spacer containing "└─ " to align with arrow tip
                for (let i = 1; i < depth; i++) {
                    indent += '<span style="visibility: hidden;">└─ </span>';
                }
            }

            const treeIndicator = depth > 0 ? '└─ ' : '';

            // Add expand/collapse icon for groups with children (after tree indicator)
            // Check if this group should be expanded based on stored state (using position index)
            const isExpanded = expandedGroupIndices.has(group._positionIndex);
            let expandIcon = '';
            if (group._hasChildren) {
                // Arrow with one space after for separation - use stored state for initial icon
                const arrowIcon = isExpanded ? '▼' : '▶';
                expandIcon = `<span class="vo-group-toggle" data-position-index="${escapeHtml(group._positionIndex)}" style="cursor: pointer;">${arrowIcon}</span>&nbsp;`;
            } else if (depth > 0) {
                // For groups without children, add spacing to align with siblings that have arrows
                expandIcon = '&nbsp;&nbsp;';
            }

            // For placeholders, show tree structure but no name
            const groupNameDisplay = isPlaceholder
                ? '<span class="vo-text-muted" style="font-style: italic;">(parent not managed)</span>'
                : escapeHtml(group.vo_group_name);
            const groupNameWithIndent = indent + escapeHtml(treeIndicator) + expandIcon + groupNameDisplay;

            // Position index (e.g., "1", "2", "5.1", "5.2")
            const positionIndex = group._positionIndex || '-';

            // Add data attributes for parent-child relationship (using position index for expansion tracking)
            row.setAttribute('data-group-id', group.vo_group_id);
            row.setAttribute('data-parent-id', group.vo_parent_id || '0');
            row.setAttribute('data-position-index', group._positionIndex);
            row.setAttribute('data-depth', depth.toString());

            // Hide child rows if ANY ancestor is not expanded (check entire ancestor chain)
            if (depth > 0) {
                const parts = group._positionIndex.split('.');
                // Check all ancestor levels from root down to immediate parent
                let shouldHide = false;
                for (let i = 1; i < parts.length; i++) {
                    const ancestorIndex = parts.slice(0, i).join('.');
                    if (!expandedGroupIndices.has(ancestorIndex)) {
                        shouldHide = true;
                        break;
                    }
                }
                if (shouldHide) {
                    row.style.display = 'none';
                }
            }

            // Render placeholder rows with minimal data
            if (isPlaceholder) {
                row.innerHTML = `
                    <td>${checkbox}</td>
                    <td>${escapeHtml(positionIndex)}</td>
                    <td style="white-space: pre-wrap;">${groupNameWithIndent}</td>
                    <td><span class="vo-text-muted">—</span></td>
                    <td><span class="vo-text-muted">—</span></td>
                    <td><span class="vo-text-muted">—</span></td>
                    <td><span class="vo-text-muted">—</span></td>
                    <td><span class="vo-text-muted">—</span></td>
                `;
            } else {
                row.innerHTML = `
                    <td>${checkbox}</td>
                    <td>${escapeHtml(positionIndex)}</td>
                    <td style="white-space: pre-wrap;">${groupNameWithIndent}</td>
                    <td>${escapeHtml(group.nc_group_id)}</td>
                    <td>${renderGroupStatusBadge(group)}</td>
                    <td>${escapeHtml(voMemberCount)}</td>
                    <td>${escapeHtml(group.last_synced || '-')}</td>
                    <td>${renderGroupActions(group)}</td>
                `;
            }
            groupsList.appendChild(row);
        });

        // Add click handlers for expand/collapse toggles
        groupsList.querySelectorAll('.vo-group-toggle').forEach(toggle => {
            toggle.addEventListener('click', function(e) {
                e.stopPropagation();
                const positionIndex = this.getAttribute('data-position-index');
                const parentRow = this.closest('tr');
                const groupId = parentRow.getAttribute('data-group-id');
                const isExpanded = this.textContent === '▼';

                // Toggle icon and update state (using position index)
                if (isExpanded) {
                    this.textContent = '▶';
                    expandedGroupIndices.delete(positionIndex);
                } else {
                    this.textContent = '▼';
                    expandedGroupIndices.add(positionIndex);
                }

                // Find all descendant rows recursively (still using group ID for DOM traversal)
                function getDescendants(parentId) {
                    const descendants = [];
                    const directChildren = Array.from(groupsList.querySelectorAll(`tr[data-parent-id="${parentId}"]`));

                    directChildren.forEach(childRow => {
                        descendants.push(childRow);
                        const childGroupId = childRow.getAttribute('data-group-id');
                        // Recursively get children's descendants
                        descendants.push(...getDescendants(childGroupId));
                    });

                    return descendants;
                }

                const descendantRows = getDescendants(groupId);

                // Toggle visibility of all descendants
                descendantRows.forEach(row => {
                    if (isExpanded) {
                        row.style.display = 'none';
                    } else {
                        // Only show direct children; nested collapsed groups stay collapsed
                        const parentId = row.getAttribute('data-parent-id');
                        const parentRow = groupsList.querySelector(`tr[data-group-id="${parentId}"]`);
                        const parentToggle = parentRow ? parentRow.querySelector('.vo-group-toggle') : null;
                        const parentIsExpanded = parentToggle ? parentToggle.textContent === '▼' : true;

                        if (parentIsExpanded || parentId === groupId) {
                            row.style.display = '';
                        }
                    }
                });
            });
        });
    }

    // Load all VO groups
    if (loadAllVOGroupsButton) {
        loadAllVOGroupsButton.addEventListener('click', function() {
            loadAllVOGroupsButton.disabled = true;
            groupsStatus.textContent = t('user_vo', 'Loading groups from VO... (this may take a moment)');
            groupsStatus.className = 'sync-status syncing';
            groupsResults.style.display = 'none';

            fetch(OC.generateUrl('/apps/user_vo/admin/fetch-all-vo-groups'), {
                method: 'GET',
                headers: {
                    'requesttoken': OC.requestToken
                }
            })
            .then(response => response.json())
            .then(data => {
                loadAllVOGroupsButton.disabled = false;

                if (data.success) {
                    groupsStatus.textContent = '';
                    displayGroups(data.groups, 'all');
                    groupsResults.style.display = 'block';
                } else {
                    groupsStatus.textContent = t('user_vo', 'Failed to load groups:') + ' ' + (data.error || 'Unknown error');
                    groupsStatus.className = 'sync-status error';
                }
            })
            .catch(error => {
                loadAllVOGroupsButton.disabled = false;
                groupsStatus.textContent = t('user_vo', 'Error:') + ' ' + error;
                groupsStatus.className = 'sync-status error';
            });
        });
    }

    // Load managed groups
    if (loadManagedGroupsButton) {
        loadManagedGroupsButton.addEventListener('click', function() {
            loadManagedGroupsButton.disabled = true;
            groupsStatus.textContent = t('user_vo', 'Loading managed groups...');
            groupsStatus.className = 'sync-status syncing';
            groupsResults.style.display = 'none';

            fetch(OC.generateUrl('/apps/user_vo/admin/fetch-managed-groups'), {
                method: 'GET',
                headers: {
                    'requesttoken': OC.requestToken
                }
            })
            .then(response => response.json())
            .then(data => {
                loadManagedGroupsButton.disabled = false;

                if (data.success) {
                    groupsStatus.textContent = '';
                    displayGroups(data.groups, 'managed');
                    groupsResults.style.display = 'block';
                } else {
                    groupsStatus.textContent = t('user_vo', 'Failed to load groups:') + ' ' + (data.error || 'Unknown error');
                    groupsStatus.className = 'sync-status error';
                }
            })
            .catch(error => {
                loadManagedGroupsButton.disabled = false;
                groupsStatus.textContent = t('user_vo', 'Error:') + ' ' + error;
                groupsStatus.className = 'sync-status error';
            });
        });
    }

    // Select all checkbox
    const selectAllGroupsCheckbox = document.getElementById('select-all-groups');
    if (selectAllGroupsCheckbox) {
        selectAllGroupsCheckbox.addEventListener('change', function() {
            document.querySelectorAll('.vo-group-checkbox').forEach(checkbox => {
                checkbox.checked = selectAllGroupsCheckbox.checked;
            });
        });
    }

    // Expand all groups button
    const expandAllGroupsButton = document.getElementById('expand-all-groups');
    if (expandAllGroupsButton) {
        expandAllGroupsButton.addEventListener('click', function() {
            // Show all rows
            groupsList.querySelectorAll('tr').forEach(row => {
                row.style.display = '';
            });
            // Change all toggle icons to expanded (▼) and update state
            groupsList.querySelectorAll('.vo-group-toggle').forEach(toggle => {
                toggle.textContent = '▼';
                const positionIndex = toggle.getAttribute('data-position-index');
                expandedGroupIndices.add(positionIndex);
            });
        });
    }

    // Collapse all groups button
    const collapseAllGroupsButton = document.getElementById('collapse-all-groups');
    if (collapseAllGroupsButton) {
        collapseAllGroupsButton.addEventListener('click', function() {
            // Hide all child rows (depth > 0)
            groupsList.querySelectorAll('tr').forEach(row => {
                const depth = parseInt(row.getAttribute('data-depth') || '0');
                if (depth > 0) {
                    row.style.display = 'none';
                }
            });
            // Change all toggle icons to collapsed (▶) and clear state
            groupsList.querySelectorAll('.vo-group-toggle').forEach(toggle => {
                toggle.textContent = '▶';
            });
            expandedGroupIndices.clear();
        });
    }

    // Create single group (event delegation for dynamically created buttons)
    document.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('create-group-btn')) {
            const voGroupId = e.target.getAttribute('data-vo-group-id');
            const button = e.target;
            const originalText = button.textContent;

            // Find the group data to get parent chain (using position index)
            const row = button.closest('tr');
            const positionIndex = row ? row.getAttribute('data-position-index') : null;

            button.disabled = true;
            button.textContent = t('user_vo', 'Creating...');

            fetch(OC.generateUrl('/apps/user_vo/admin/create-group'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ vo_group_id: voGroupId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    OC.Notification.showTemporary(
                        t('user_vo', "Group '{name}' created successfully", { name: data.nc_group_id })
                    );

                    // Expand parent chain so newly created group is visible (using position indices)
                    if (positionIndex) {
                        const parts = positionIndex.split('.');
                        // Add all parent position indices to expanded set
                        for (let i = 1; i < parts.length; i++) {
                            const parentPositionIndex = parts.slice(0, i).join('.');
                            expandedGroupIndices.add(parentPositionIndex);
                        }
                    }

                    // Refresh the current view while preserving state
                    if (currentViewType === 'all') {
                        loadAllVOGroupsButton.click();
                    } else if (currentViewType === 'managed') {
                        loadManagedGroupsButton.click();
                    }
                } else {
                    OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + data.error, { type: 'error' });
                    button.disabled = false;
                    button.textContent = originalText;
                }
            })
            .catch(error => {
                OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + error, { type: 'error' });
                button.disabled = false;
                button.textContent = originalText;
            });
        }
    });

    // Delete single group (event delegation for dynamically created buttons)
    document.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('delete-group-btn')) {
            const voGroupId = e.target.getAttribute('data-vo-group-id');
            const ncGroupId = e.target.getAttribute('data-nc-group-id');
            const button = e.target;

            // Confirm deletion
            if (!confirm(t('user_vo', "Are you sure you want to delete group '{name}'?", { name: ncGroupId }))) {
                return;
            }

            const originalText = button.textContent;
            button.disabled = true;
            button.textContent = t('user_vo', 'Deleting...');

            fetch(OC.generateUrl('/apps/user_vo/admin/delete-group'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ vo_group_id: voGroupId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    OC.Notification.showTemporary(
                        t('user_vo', "Group '{name}' deleted successfully", { name: data.nc_group_id })
                    );

                    // Refresh the current view while preserving state
                    if (currentViewType === 'all') {
                        loadAllVOGroupsButton.click();
                    } else if (currentViewType === 'managed') {
                        loadManagedGroupsButton.click();
                    }
                } else {
                    OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + data.error, { type: 'error' });
                    button.disabled = false;
                    button.textContent = originalText;
                }
            })
            .catch(error => {
                OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + error, { type: 'error' });
                button.disabled = false;
                button.textContent = originalText;
            });
        }
    });
});
