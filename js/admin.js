// Admin interface JavaScript for user_vo plugin

// Simple HTML escaping function
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Format a datetime value for display using moment.js for locale-aware formatting
 * Nextcloud provides moment.js globally and sets the locale based on user preferences
 *
 * @param {string|number|null} value - Date value (ISO string, Unix timestamp, or null)
 * @param {string} format - Moment.js format string (default: 'L LTS' for date + time with seconds)
 * @return {string} Formatted date string or dash for null values
 */
function formatDateTime(value, format = 'L LTS') {
    if (!value || value === '-') {
        return '-';
    }

    // Handle Unix timestamps (numbers)
    if (typeof value === 'number') {
        return moment(value * 1000).format(format);
    }

    // Handle ISO strings from database (YYYY-MM-DD HH:MM:SS)
    return moment(value).format(format);
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

    // Extract display names for tooltip
    const displayNames = groups.map(g => g.display_name).join(', ');
    const count = groups.length;

    return '<span class="groups-list" title="All groups: ' + escapeHtml(displayNames) + '">' + count + '</span>';
}

function renderCreationDate(creationDate) {
    if (!creationDate) {
        return '<span class="no-date">—</span>';
    }
    return '<span class="creation-date">' + escapeHtml(formatDateTime(creationDate)) + '</span>';
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

// Helper function to render group status badge
function renderGroupStatusBadge(group) {
    if (group.deleted_in_vo) {
        const tooltipText = 'Group ID ' + group.vo_group_id + ' (' + group.vo_group_name + ') was not found in VereinOnline';
        return '<span class="vo-badge vo-badge-error" title="' + escapeHtml(tooltipText) + '">⚠ ' + escapeHtml(t('user_vo', 'Deleted in VO')) + '</span>';
    }

    if (group.is_managed && group.display_name_mismatch) {
        const currentName = String(group.nc_display_name || '(empty)');
        const expectedName = String(group.expected_display_name || '(empty)');
        return '<span class="vo-badge vo-badge-info" title="Current: &quot;' + escapeHtml(currentName) + '&quot;, Expected: &quot;' + escapeHtml(expectedName) + '&quot; (will be fixed on next sync)">ℹ ' + escapeHtml(t('user_vo', 'Display Name Mismatch')) + '</span>';
    }

    if (group.is_managed) {
        return '<span class="vo-badge vo-badge-success">✓ ' + escapeHtml(t('user_vo', 'Created')) + '</span>';
    }

    return '<span class="vo-badge vo-badge-warning">' + escapeHtml(t('user_vo', 'Not created')) + '</span>';
}

// Helper function to render group actions
function renderGroupActions(group) {
    if (group.deleted_in_vo) {
        // Deleted groups - show Sync (to update member counts) and Delete buttons
        return `
            <button class="button sync-group-btn" data-vo-group-id="${escapeHtml(group.vo_group_id)}">
                ${escapeHtml(t('user_vo', 'Sync'))}
            </button>
            <button class="button button-danger delete-group-btn" data-vo-group-id="${escapeHtml(group.vo_group_id)}" data-nc-group-id="${escapeHtml(group.nc_group_id)}" data-vo-group-name="${escapeHtml(group.vo_group_name)}">
                ${escapeHtml(t('user_vo', 'Delete'))}
            </button>
        `;
    }

    if (group.is_managed) {
        // Managed groups - show Sync and Delete buttons
        return `
            <button class="button sync-group-btn" data-vo-group-id="${escapeHtml(group.vo_group_id)}">
                ${escapeHtml(t('user_vo', 'Sync'))}
            </button>
            <button class="button button-danger delete-group-btn" data-vo-group-id="${escapeHtml(group.vo_group_id)}" data-nc-group-id="${escapeHtml(group.nc_group_id)}" data-vo-group-name="${escapeHtml(group.vo_group_name)}">
                ${escapeHtml(t('user_vo', 'Delete'))}
            </button>
        `;
    } else {
        // Not created groups - show Create button
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

/**
 * Sort groups hierarchically by VO position and calculate depth.
 *
 * @param {Array} groups - Flat list of groups from the API
 * @param {string|null} viewType - 'all' or 'managed'; in 'managed' view, placeholder rows are
 *   synthesized for missing parent groups so the hierarchy stays navigable
 */
function sortGroupsHierarchically(groups, viewType) {
    // In "managed" view, add placeholders for missing parents
    let workingGroups = [...groups];
    if (viewType === 'managed') {
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

// Exposed for Jest (Node's `module` global is undefined in the browser, so this is a no-op there)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        escapeHtml,
        formatDateTime,
        renderExposeCheckbox,
        renderGroups,
        renderCreationDate,
        generateSyncSummaryHTML,
        generatePhotoErrorsHTML,
        renderGroupStatusBadge,
        renderGroupActions,
        addPlaceholdersForMissingParents,
        sortGroupsHierarchically,
    };
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

    const syncEmailCheckbox = document.getElementById('sync-email');
    const syncPhotoCheckbox = document.getElementById('sync-photo');
    const syncAllUsersButton = document.getElementById('sync-all-users');
    const syncAllUsersStatus = document.getElementById('sync-all-users-status');
    const userSyncResults = document.getElementById('user-sync-results');
    const userSyncSummary = document.getElementById('user-sync-summary');
    const userSyncList = document.getElementById('user-sync-list');

    // Save user sync settings on change (instant save)
    function saveSyncSettings(syncEmail, syncPhoto) {
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
                OC.Notification.showTemporary(t('user_vo', 'Sync settings saved'));
            } else {
                OC.Notification.showTemporary(t('user_vo', 'Error saving sync settings'), { type: 'error' });
            }
        })
        .catch(error => {
            OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + error, { type: 'error' });
        });
    }

    // Sync email checkbox - instant save
    if (syncEmailCheckbox) {
        syncEmailCheckbox.addEventListener('change', function() {
            const syncEmail = this.checked;
            const syncPhoto = syncPhotoCheckbox ? syncPhotoCheckbox.checked : false;
            saveSyncSettings(syncEmail, syncPhoto);
        });
    }

    // Sync photo checkbox - instant save
    if (syncPhotoCheckbox) {
        syncPhotoCheckbox.addEventListener('change', function() {
            const syncEmail = syncEmailCheckbox ? syncEmailCheckbox.checked : true;
            const syncPhoto = this.checked;
            saveSyncSettings(syncEmail, syncPhoto);
        });
    }

    // Nightly sync checkbox handler
    // Nightly user sync toggle
    const nightlyUserSyncCheckbox = document.getElementById('enable-nightly-user-sync');
    const nightlyGroupSyncCheckbox = document.getElementById('enable-nightly-group-sync');

    if (nightlyUserSyncCheckbox) {
        nightlyUserSyncCheckbox.addEventListener('change', function() {
            const enabled = this.checked;

            // Enable/disable group sync checkbox based on user sync state
            if (nightlyGroupSyncCheckbox) {
                if (enabled) {
                    nightlyGroupSyncCheckbox.disabled = false;
                } else {
                    nightlyGroupSyncCheckbox.disabled = true;
                    // If user sync is disabled, also disable group sync
                    if (nightlyGroupSyncCheckbox.checked) {
                        nightlyGroupSyncCheckbox.checked = false;
                        // Save the group sync disabled state
                        fetch(OC.generateUrl('/apps/user_vo/admin/save-nightly-sync'), {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'requesttoken': OC.requestToken
                            },
                            body: JSON.stringify({
                                enabled: false,
                                sync_type: 'group'
                            })
                        });
                    }
                }
            }

            fetch(OC.generateUrl('/apps/user_vo/admin/save-nightly-sync'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    enabled: enabled,
                    sync_type: 'user'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    OC.Notification.showTemporary(t('user_vo', 'Nightly user sync setting saved'));
                    loadNightlySyncStatus();
                } else {
                    OC.Notification.showTemporary(t('user_vo', 'Error saving nightly sync setting'), { type: 'error' });
                }
            })
            .catch(error => {
                OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + error, { type: 'error' });
            });
        });
    }

    // Nightly group sync toggle
    if (nightlyGroupSyncCheckbox) {
        nightlyGroupSyncCheckbox.addEventListener('change', function() {
            const enabled = this.checked;

            fetch(OC.generateUrl('/apps/user_vo/admin/save-nightly-sync'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({
                    enabled: enabled,
                    sync_type: 'group'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    OC.Notification.showTemporary(t('user_vo', 'Nightly group membership sync setting saved'));
                    loadNightlySyncStatus();
                } else {
                    OC.Notification.showTemporary(t('user_vo', 'Error saving nightly sync setting'), { type: 'error' });
                }
            })
            .catch(error => {
                OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + error, { type: 'error' });
            });
        });
    }

    // Load status on page load
    if (nightlyUserSyncCheckbox || nightlyGroupSyncCheckbox) {
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
            lastRunElement.textContent = formatDateTime(data.last_run);
        } else {
            lastRunElement.textContent = t('user_vo', 'Never');
        }

        // Update summary - handle both legacy format and new combined format
        if (data.last_summary) {
            const summary = data.last_summary;
            const parts = [];

            // Check if we have the new combined format (users + groups)
            if (summary.users || summary.groups) {
                // New format: separate users and groups

                // User sync status (based on LAST RUN, not current settings)
                if (data.last_run) {
                    // Job has run at least once
                    if (summary.users && summary.users.total > 0) {
                        // User sync ran and processed users
                        const userParts = [];
                        if (summary.users.synced > 0) userParts.push(t('user_vo', '{synced} synced', {synced: summary.users.synced}));
                        if (summary.users.failed > 0) userParts.push(t('user_vo', '{failed} failed', {failed: summary.users.failed}));
                        if (summary.users.skipped > 0) userParts.push(t('user_vo', '{skipped} skipped', {skipped: summary.users.skipped}));
                        parts.push(t('user_vo', 'Users:') + ' ' + userParts.join(', '));
                    } else {
                        // User sync didn't run (was disabled) or found no users
                        parts.push(t('user_vo', 'Users: not run'));
                    }

                    // Group sync status (based on LAST RUN, not current settings)
                    if (summary.groups && summary.groups.total > 0) {
                        // Group sync ran and processed groups
                        const groupParts = [];
                        if (summary.groups.succeeded > 0) groupParts.push(t('user_vo', '{succeeded} synced', {succeeded: summary.groups.succeeded}));
                        if (summary.groups.failed > 0) groupParts.push(t('user_vo', '{failed} failed', {failed: summary.groups.failed}));
                        parts.push(t('user_vo', 'Groups:') + ' ' + groupParts.join(', '));
                    } else {
                        // Group sync didn't run (was disabled) or found no groups
                        parts.push(t('user_vo', 'Groups: not run'));
                    }
                } else {
                    // Job has never run
                    parts.push(t('user_vo', 'Never run'));
                }

                summaryElement.textContent = parts.length > 0 ? parts.join(' | ') : t('user_vo', 'No sync performed');
            } else if (summary.total !== undefined) {
                // Legacy format: just user sync data
                if (summary.synced > 0) parts.push(t('user_vo', '{synced} synced', {synced: summary.synced}));
                if (summary.failed > 0) parts.push(t('user_vo', '{failed} failed', {failed: summary.failed}));
                if (summary.skipped > 0) parts.push(t('user_vo', '{skipped} skipped', {skipped: summary.skipped}));
                summaryElement.textContent = parts.length > 0 ? parts.join(', ') : t('user_vo', 'No users to sync');
            } else {
                summaryElement.textContent = t('user_vo', 'No sync data available');
            }
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

                        // Format VO Group IDs column
                        let voGroupIdsDisplay = '-';
                        if (result.vo_group_ids) {
                            const groupIds = result.vo_group_ids.split(',').filter(id => id.trim());
                            const groupCount = groupIds.length;
                            if (groupCount > 0) {
                                // Show count with full list in tooltip
                                voGroupIdsDisplay = `<span title="All VO group IDs: ${escapeHtml(groupIds.join(', '))}">${groupCount}</span>`;
                            }
                        }

                        // Format Managed Groups column
                        let managedGroupsDisplay = '-';
                        if (result.managed_groups_count > 0) {
                            const groupNames = result.managed_groups_names || '';
                            managedGroupsDisplay = `<span title="Managed groups: ${escapeHtml(groupNames)}">${result.managed_groups_count}</span>`;
                        } else {
                            managedGroupsDisplay = `<span title="No managed groups">0</span>`;
                        }

                        row.innerHTML = `
                            <td><input type="checkbox" class="sync-user-checkbox" data-uid="${escapeHtml(result.uid)}" /></td>
                            <td>${escapeHtml(result.uid)}</td>
                            <td>${escapeHtml(result.vo_username || '-')}</td>
                            <td>${escapeHtml(result.vo_user_id || '-')}</td>
                            <td>${escapeHtml(result.display_name || '-')}</td>
                            <td>${escapeHtml(result.email || '-')}</td>
                            <td>${escapeHtml(result.photo_status || '-')}</td>
                            <td>${voGroupIdsDisplay}</td>
                            <td>${managedGroupsDisplay}</td>
                            <td>${escapeHtml(formatDateTime(result.last_synced))}</td>
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

                        // Format VO Group IDs column
                        let voGroupIdsDisplay = '-';
                        if (result.vo_group_ids) {
                            const groupIds = result.vo_group_ids.split(',').filter(id => id.trim());
                            const groupCount = groupIds.length;
                            if (groupCount > 0) {
                                // Show count with full list in tooltip
                                voGroupIdsDisplay = `<span title="All VO group IDs: ${escapeHtml(groupIds.join(', '))}">${groupCount}</span>`;
                            }
                        }

                        // Format Managed Groups column
                        let managedGroupsDisplay = '-';
                        if (result.managed_groups_count > 0) {
                            const groupNames = result.managed_groups_names || '';
                            managedGroupsDisplay = `<span title="Managed groups: ${escapeHtml(groupNames)}">${result.managed_groups_count}</span>`;
                        } else {
                            managedGroupsDisplay = `<span title="No managed groups">0</span>`;
                        }

                        row.innerHTML = `
                            <td><input type="checkbox" class="sync-user-checkbox" data-uid="${escapeHtml(result.uid)}" /></td>
                            <td>${escapeHtml(result.uid)}</td>
                            <td>${escapeHtml(result.vo_username || '-')}</td>
                            <td>${escapeHtml(result.vo_user_id || '-')}</td>
                            <td>${escapeHtml(result.display_name || '-')}</td>
                            <td>${escapeHtml(result.email || '-')}</td>
                            <td>${escapeHtml(result.photo_status || '-')}</td>
                            <td>${voGroupIdsDisplay}</td>
                            <td>${managedGroupsDisplay}</td>
                            <td>${escapeHtml(formatDateTime(result.last_synced))}</td>
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

                        // Format VO Group IDs column
                        let voGroupIdsDisplay = '-';
                        if (result.vo_group_ids) {
                            const groupIds = result.vo_group_ids.split(',').filter(id => id.trim());
                            const groupCount = groupIds.length;
                            if (groupCount > 0) {
                                // Show count with full list in tooltip
                                voGroupIdsDisplay = `<span title="All VO group IDs: ${escapeHtml(groupIds.join(', '))}">${groupCount}</span>`;
                            }
                        }

                        // Format Managed Groups column
                        let managedGroupsDisplay = '-';
                        if (result.managed_groups_count > 0) {
                            const groupNames = result.managed_groups_names || '';
                            managedGroupsDisplay = `<span title="Managed groups: ${escapeHtml(groupNames)}">${result.managed_groups_count}</span>`;
                        } else {
                            managedGroupsDisplay = `<span title="No managed groups">0</span>`;
                        }

                        row.innerHTML = `
                            <td><input type="checkbox" class="sync-user-checkbox" data-uid="${escapeHtml(result.uid)}" /></td>
                            <td>${escapeHtml(result.uid)}</td>
                            <td>${escapeHtml(result.vo_username || '-')}</td>
                            <td>${escapeHtml(result.vo_user_id || '-')}</td>
                            <td>${escapeHtml(result.display_name || '-')}</td>
                            <td>${escapeHtml(result.email || '-')}</td>
                            <td>${photoDisplay}</td>
                            <td>${voGroupIdsDisplay}</td>
                            <td>${managedGroupsDisplay}</td>
                            <td>${escapeHtml(formatDateTime(result.last_synced))}</td>
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

                                // Format VO Group IDs column
                                let voGroupIdsDisplay = '-';
                                if (result.vo_group_ids) {
                                    const groupIds = result.vo_group_ids.split(',').filter(id => id.trim());
                                    const groupCount = groupIds.length;
                                    if (groupCount > 0) {
                                        // Show count with full list in tooltip
                                        voGroupIdsDisplay = `<span title="All VO group IDs: ${escapeHtml(groupIds.join(', '))}">${groupCount}</span>`;
                                    }
                                }

                                // Format Managed Groups column
                                let managedGroupsDisplay = '-';
                                if (result.managed_groups_count > 0) {
                                    const groupNames = result.managed_groups_names || '';
                                    managedGroupsDisplay = `<span title="Managed groups: ${escapeHtml(groupNames)}">${result.managed_groups_count}</span>`;
                                } else {
                                    managedGroupsDisplay = `<span title="No managed groups">0</span>`;
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
                                    <td>${voGroupIdsDisplay}</td>
                                    <td>${managedGroupsDisplay}</td>
                                    <td>${escapeHtml(formatDateTime(result.last_synced))}</td>
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

    // Display groups in the table
    function displayGroups(groups, viewType) {
        const managedCount = groups.filter(g => g.is_managed).length;
        const deletedCount = groups.filter(g => g.deleted_in_vo).length;
        const totalCount = groups.length;

        // Track current view type for state handling
        currentViewType = viewType;

        // Enable/disable bulk buttons based on view
        const bulkCreateButton = document.getElementById('bulk-create-groups');
        const bulkDeleteButton = document.getElementById('bulk-delete-groups');

        if (viewType === 'all') {
            // In "All VO Groups" view: enable create, enable delete
            if (bulkCreateButton) bulkCreateButton.disabled = false;
            if (bulkDeleteButton) bulkDeleteButton.disabled = false;
        } else if (viewType === 'managed') {
            // In "Managed Groups" view: disable create (no unmanaged groups), enable delete
            if (bulkCreateButton) bulkCreateButton.disabled = true;
            if (bulkDeleteButton) bulkDeleteButton.disabled = false;
        }

        // Sort groups hierarchically using VO's defined sort order
        const sortedGroups = sortGroupsHierarchically(groups, viewType);

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
            row.innerHTML = `<td colspan="10" style="text-align: center; padding: 20px;">${escapeHtml(t('user_vo', 'No groups found.'))}</td>`;
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
            } else if (group.is_managed && group.display_name_mismatch) {
                row.className = 'vo-group-renamed';
            } else if (group.is_managed) {
                row.className = 'vo-group-managed';
            }

            // Show checkbox for all real groups (both managed and unmanaged), but not for placeholders or deleted groups
            const checkbox = (isPlaceholder || group.deleted_in_vo)
                ? '<span class="vo-text-muted">—</span>'
                : `<input type="checkbox" class="vo-group-checkbox" value="${escapeHtml(group.vo_group_id)}" data-vo-group-id="${escapeHtml(group.vo_group_id)}" data-is-managed="${group.is_managed ? 'true' : 'false'}" />`;

            // Format member count displays
            let voMemberCountDisplay = '-';
            let nonVoMemberCountDisplay = '-';
            if (!isPlaceholder && group.member_count !== null && group.member_count !== undefined) {
                voMemberCountDisplay = (group.vo_member_count || 0).toString();
                nonVoMemberCountDisplay = (group.non_vo_member_count || 0).toString();
            }

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
            row.setAttribute('data-is-managed', group.is_managed ? 'true' : 'false');

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
                    <td><span class="vo-text-muted">—</span></td>
                    <td><span class="vo-text-muted">—</span></td>
                    <td><span class="vo-text-muted">—</span></td>
                `;
            } else {
                row.innerHTML = `
                    <td>${checkbox}</td>
                    <td>${escapeHtml(positionIndex)}</td>
                    <td style="white-space: pre-wrap;">${groupNameWithIndent}</td>
                    <td>${escapeHtml(group.vo_group_id)}</td>
                    <td>${escapeHtml(group.nc_display_name || '-')}</td>
                    <td>${escapeHtml(group.nc_group_id || '-')}</td>
                    <td>${renderGroupStatusBadge(group)}</td>
                    <td>${escapeHtml(voMemberCountDisplay)}</td>
                    <td>${escapeHtml(nonVoMemberCountDisplay)}</td>
                    <td>${escapeHtml(formatDateTime(group.last_synced))}</td>
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
                    groupsStatus.textContent = '';
                    OC.Notification.showTemporary(t('user_vo', 'Failed to load groups:') + ' ' + (data.error || 'Unknown error'), { type: 'error' });
                }
            })
            .catch(error => {
                loadAllVOGroupsButton.disabled = false;
                groupsStatus.textContent = '';
                OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + error, { type: 'error' });
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
                    groupsStatus.textContent = '';
                    OC.Notification.showTemporary(t('user_vo', 'Failed to load groups:') + ' ' + (data.error || 'Unknown error'), { type: 'error' });
                }
            })
            .catch(error => {
                loadManagedGroupsButton.disabled = false;
                groupsStatus.textContent = '';
                OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + error, { type: 'error' });
            });
        });
    }

    // Sync Selected Groups button
    const bulkSyncGroupsButton = document.getElementById('bulk-sync-groups');
    if (bulkSyncGroupsButton) {
        bulkSyncGroupsButton.addEventListener('click', function() {
            // Collect selected checkboxes
            const selectedCheckboxes = document.querySelectorAll('.vo-group-checkbox:checked');

            if (selectedCheckboxes.length === 0) {
                OC.Notification.showTemporary(t('user_vo', 'Please select at least one group'), { type: 'error' });
                return;
            }

            // Filter only managed groups (those that have is_managed = true)
            const voGroupIds = [];
            selectedCheckboxes.forEach(checkbox => {
                const row = checkbox.closest('tr');
                const isManaged = row.getAttribute('data-is-managed') === 'true';
                if (isManaged) {
                    voGroupIds.push(checkbox.value);
                }
            });

            if (voGroupIds.length === 0) {
                OC.Notification.showTemporary(t('user_vo', 'Selected groups are not created yet (only created groups can be synced)'), { type: 'error' });
                return;
            }

            bulkSyncGroupsButton.disabled = true;

            fetch(OC.generateUrl('/apps/user_vo/admin/sync-selected-groups'), {
                method: 'POST',
                headers: {
                    'requesttoken': OC.requestToken,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    vo_group_ids: voGroupIds
                })
            })
            .then(response => response.json())
            .then(data => {
                bulkSyncGroupsButton.disabled = false;

                if (data.success) {
                    const summary = data.summary;
                    const message = t('user_vo', 'Synced {total} groups ({succeeded} succeeded, {failed} failed)', {
                        total: summary.total,
                        succeeded: summary.succeeded,
                        failed: summary.failed
                    });

                    OC.Notification.showTemporary(message);

                    // Show detailed results in console
                    if (data.results && data.results.length > 0) {
                        console.log('Bulk sync results:', data.results);
                        data.results.forEach(result => {
                            if (result.status === 'error') {
                                console.error('Failed to sync group:', result.vo_group_name, result.error);
                            } else {
                                console.log('Synced group:', result.vo_group_name,
                                    'Added:', result.added.length,
                                    'Removed:', result.removed.length);
                            }
                        });
                    }

                    // Reload groups view to show updated data
                    if (currentViewType === 'managed' && loadManagedGroupsButton) {
                        loadManagedGroupsButton.click();
                    } else if (currentViewType === 'all' && loadAllVOGroupsButton) {
                        loadAllVOGroupsButton.click();
                    }
                } else {
                    OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + (data.error || 'Unknown error'), { type: 'error' });
                }
            })
            .catch(error => {
                bulkSyncGroupsButton.disabled = false;
                OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + error, { type: 'error' });
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
                        t('user_vo', "Group '{name}' created successfully", { name: data.vo_group_name || data.nc_group_id })
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
            const voGroupName = e.target.getAttribute('data-vo-group-name');
            const button = e.target;

            // Confirm deletion
            if (!confirm(t('user_vo', "Are you sure you want to delete group '{name}'?", { name: voGroupName || ncGroupId }))) {
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
                        t('user_vo', "Group '{name}' deleted successfully", { name: data.vo_group_name || data.nc_group_id })
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

    // Sync single group (event delegation for dynamically created buttons)
    document.addEventListener('click', function(e) {
        if (e.target && e.target.classList.contains('sync-group-btn')) {
            const voGroupId = e.target.getAttribute('data-vo-group-id');
            const button = e.target;
            const originalText = button.textContent;

            button.disabled = true;
            button.textContent = t('user_vo', 'Syncing...');

            fetch(OC.generateUrl('/apps/user_vo/admin/sync-group'), {
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
                    const summary = data.summary;
                    const message = t('user_vo', "Group '{name}' synced: {added} added, {removed} removed, {skipped} skipped", {
                        name: data.vo_group_name || data.nc_group_id,
                        added: summary.added,
                        removed: summary.removed,
                        skipped: summary.skipped
                    });
                    OC.Notification.showTemporary(message);

                    // Refresh the current view to update the "Last Synced" timestamp
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

    // Bulk create groups
    const bulkCreateGroupsButton = document.getElementById('bulk-create-groups');
    if (bulkCreateGroupsButton) {
        bulkCreateGroupsButton.addEventListener('click', function() {
            // Filter for unmanaged groups only (those that can be created)
            const unmanagedGroupIds = [];
            let skippedCount = 0;

            document.querySelectorAll('.vo-group-checkbox:checked').forEach(checkbox => {
                const voGroupId = checkbox.getAttribute('data-vo-group-id');
                const isManaged = checkbox.getAttribute('data-is-managed') === 'true';

                if (!isManaged) {
                    unmanagedGroupIds.push(voGroupId);
                } else {
                    skippedCount++;
                }
            });

            if (unmanagedGroupIds.length === 0) {
                if (skippedCount > 0) {
                    OC.Notification.showTemporary(t('user_vo', 'All selected groups are already created'), { type: 'error' });
                } else {
                    OC.Notification.showTemporary(t('user_vo', 'Please select at least one group'), { type: 'error' });
                }
                return;
            }

            // Show detailed confirmation
            let confirmMsg = t('user_vo', 'Create {count} group(s)?', { count: unmanagedGroupIds.length });
            if (skippedCount > 0) {
                confirmMsg += '\n' + t('user_vo', '({skipped} already created will be skipped)', { skipped: skippedCount });
            }

            if (!confirm(confirmMsg)) {
                return;
            }

            bulkCreateGroupsButton.disabled = true;

            fetch(OC.generateUrl('/apps/user_vo/admin/bulk-create-groups'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ vo_group_ids: unmanagedGroupIds })
            })
            .then(response => response.json())
            .then(data => {
                bulkCreateGroupsButton.disabled = false;

                if (data.success) {
                    const summary = data.summary;
                    const message = t('user_vo', 'Created: {created}, Skipped: {skipped}, Errors: {errors}', {
                        created: summary.created,
                        skipped: summary.skipped,
                        errors: summary.errors
                    });
                    OC.Notification.showTemporary(message);

                    // Refresh the current view
                    setTimeout(() => {
                        if (currentViewType === 'all') {
                            loadAllVOGroupsButton.click();
                        } else if (currentViewType === 'managed') {
                            loadManagedGroupsButton.click();
                        }
                    }, 1000);
                } else {
                    OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + (data.error || 'Unknown error'), { type: 'error' });
                }
            })
            .catch(error => {
                bulkCreateGroupsButton.disabled = false;
                OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + error, { type: 'error' });
            });
        });
    }

    // Bulk delete groups
    const bulkDeleteGroupsButton = document.getElementById('bulk-delete-groups');
    if (bulkDeleteGroupsButton) {
        bulkDeleteGroupsButton.addEventListener('click', function() {
            // Filter for managed groups only (those that can be deleted)
            const managedGroupIds = [];
            let skippedCount = 0;

            document.querySelectorAll('.vo-group-checkbox:checked').forEach(checkbox => {
                const voGroupId = checkbox.getAttribute('data-vo-group-id');
                const isManaged = checkbox.getAttribute('data-is-managed') === 'true';

                if (isManaged) {
                    managedGroupIds.push(voGroupId);
                } else {
                    skippedCount++;
                }
            });

            if (managedGroupIds.length === 0) {
                if (skippedCount > 0) {
                    OC.Notification.showTemporary(t('user_vo', 'Selected groups are not created yet'), { type: 'error' });
                } else {
                    OC.Notification.showTemporary(t('user_vo', 'Please select at least one group'), { type: 'error' });
                }
                return;
            }

            // Show detailed confirmation
            let confirmMsg = t('user_vo', 'Delete {count} group(s)? This will remove them from Nextcloud.', { count: managedGroupIds.length });
            if (skippedCount > 0) {
                confirmMsg += '\n' + t('user_vo', '({skipped} not created will be skipped)', { skipped: skippedCount });
            }

            if (!confirm(confirmMsg)) {
                return;
            }

            bulkDeleteGroupsButton.disabled = true;

            fetch(OC.generateUrl('/apps/user_vo/admin/bulk-delete-groups'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'requesttoken': OC.requestToken
                },
                body: JSON.stringify({ vo_group_ids: managedGroupIds })
            })
            .then(response => response.json())
            .then(data => {
                bulkDeleteGroupsButton.disabled = false;

                if (data.success) {
                    const summary = data.summary;
                    const message = t('user_vo', 'Deleted: {deleted}, Not found: {not_found}, Errors: {errors}', {
                        deleted: summary.deleted,
                        not_found: summary.not_found,
                        errors: summary.errors
                    });
                    OC.Notification.showTemporary(message);

                    // Refresh the current view
                    setTimeout(() => {
                        if (currentViewType === 'all') {
                            loadAllVOGroupsButton.click();
                        } else if (currentViewType === 'managed') {
                            loadManagedGroupsButton.click();
                        }
                    }, 1000);
                } else {
                    OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + (data.error || 'Unknown error'), { type: 'error' });
                }
            })
            .catch(error => {
                bulkDeleteGroupsButton.disabled = false;
                OC.Notification.showTemporary(t('user_vo', 'Error:') + ' ' + error, { type: 'error' });
            });
        });
    }
});
