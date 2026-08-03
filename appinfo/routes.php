<?php
return [
    'routes' => [
        ['name' => 'admin#index', 'url' => '/admin', 'verb' => 'GET'],
        // User Account Management (UserAccountController)
        ['name' => 'user_account#scanDuplicates', 'url' => '/admin/scan-duplicates', 'verb' => 'GET'],
        ['name' => 'user_account#exposeUser', 'url' => '/admin/expose-user', 'verb' => 'POST'],
        ['name' => 'user_account#hideUser', 'url' => '/admin/hide-user', 'verb' => 'POST'],

        // Configuration (ConfigController)
        ['name' => 'config#getConfigurationStatus', 'url' => '/admin/config-status', 'verb' => 'GET'],
        ['name' => 'config#saveConfiguration', 'url' => '/admin/save-config', 'verb' => 'POST'],
        ['name' => 'config#testConfiguration', 'url' => '/admin/test-config', 'verb' => 'POST'],
        ['name' => 'config#clearConfiguration', 'url' => '/admin/clear-config', 'verb' => 'POST'],
        ['name' => 'config#saveUserSyncSettings', 'url' => '/admin/save-user-sync-settings', 'verb' => 'POST'],
        ['name' => 'config#saveNightlySyncSetting', 'url' => '/admin/save-nightly-sync', 'verb' => 'POST'],
        ['name' => 'config#getNightlySyncStatus', 'url' => '/admin/nightly-sync-status', 'verb' => 'GET'],

        // User Sync (UserSyncController)
        ['name' => 'user_sync#previewLocalUsers', 'url' => '/admin/preview-local-users', 'verb' => 'GET'],
        ['name' => 'user_sync#previewVOUsers', 'url' => '/admin/preview-vo-users', 'verb' => 'GET'],
        ['name' => 'user_sync#syncFromVO', 'url' => '/admin/sync-from-vo', 'verb' => 'POST'],
        ['name' => 'user_sync#syncSelectedUsers', 'url' => '/admin/sync-selected-users', 'verb' => 'POST'],

        // User Provisioning (UserProvisioningController)
        ['name' => 'user_provisioning#searchVOUsers', 'url' => '/admin/search-vo-users', 'verb' => 'GET'],
        ['name' => 'user_provisioning#createAccountFromVO', 'url' => '/admin/create-account-from-vo', 'verb' => 'POST'],
        ['name' => 'user_provisioning#bulkCreateAccountsFromVO', 'url' => '/admin/bulk-create-accounts-from-vo', 'verb' => 'POST'],

        // Group Management (GroupController)
        ['name' => 'group#fetchAllVOGroups', 'url' => '/admin/fetch-all-vo-groups', 'verb' => 'GET'],
        ['name' => 'group#fetchManagedGroups', 'url' => '/admin/fetch-managed-groups', 'verb' => 'GET'],
        ['name' => 'group#createGroup', 'url' => '/admin/create-group', 'verb' => 'POST'],
        ['name' => 'group#recreateGroup', 'url' => '/admin/recreate-group', 'verb' => 'POST'],
        ['name' => 'group#deleteGroup', 'url' => '/admin/delete-group', 'verb' => 'POST'],
        ['name' => 'group#bulkCreateGroups', 'url' => '/admin/bulk-create-groups', 'verb' => 'POST'],
        ['name' => 'group#bulkDeleteGroups', 'url' => '/admin/bulk-delete-groups', 'verb' => 'POST'],

        // Group Sync (GroupSyncController)
        ['name' => 'group_sync#syncGroup', 'url' => '/admin/sync-group', 'verb' => 'POST'],
        ['name' => 'group_sync#syncAllGroups', 'url' => '/admin/sync-all-groups', 'verb' => 'POST'],
        ['name' => 'group_sync#syncSelectedGroups', 'url' => '/admin/sync-selected-groups', 'verb' => 'POST'],
    ]
]; 
