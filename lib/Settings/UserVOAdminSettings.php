<?php
namespace OCA\UserVO\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\Settings\ISettings;
use OCP\IConfig;
use OCP\IDBConnection;
use OCA\UserVO\Service\ConfigService;

class UserVOAdminSettings implements ISettings {
    private IL10N $l;
    private ConfigService $configService;
    private IDBConnection $connection;

    public function __construct(IL10N $l, ConfigService $configService, IDBConnection $connection) {
        $this->l = $l;
        $this->configService = $configService;
        $this->connection = $connection;
    }

    public function getForm() {
        // Get configuration status from the service
        $configStatus = $this->configService->getConfigurationStatus();

        // Get sync settings
        $syncSettings = [
            'sync_email' => $this->configService->get('sync_email', 'true'),
            'sync_photo' => $this->configService->get('sync_photo', 'true')
        ];

        // Count managed VO groups for display
        $managedGroupsCount = 0;
        try {
            $qb = $this->connection->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*)'))
                ->from('user_vo_groups')
                ->where($qb->expr()->isNotNull('nc_group_id'));
            $result = $qb->executeQuery();
            $managedGroupsCount = (int)$result->fetchOne();
            $result->closeCursor();
        } catch (\Exception $e) {
            // Silently fail - table might not exist yet
        }

        $nightlySync = [
            'enabled' => $this->configService->get('enable_nightly_user_sync', 'true') === 'true',
            'group_enabled' => $this->configService->get('enable_nightly_group_sync', 'true') === 'true',
            'managed_groups_count' => $managedGroupsCount
        ];

        return new TemplateResponse('user_vo', 'admin', [
            'config_status' => $configStatus,
            'sync_settings' => $syncSettings,
            'nightly_sync' => $nightlySync
        ], '');
    }

    public function getSection() {
        return 'user_vo'; // Must match getID() from your section class
    }

    public function getPriority() {
        return 10;
    }
}
