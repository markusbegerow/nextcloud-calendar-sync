<?php

namespace OCA\CalDavSync\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\IConfig;

class AdminSettings implements ISettings {
    private IConfig $config;

    public function __construct(IConfig $config) {
        $this->config = $config;
    }

    public function getForm() {
        $autoSyncEnabled = $this->config->getAppValue('calendar-sync', 'auto_sync_enabled', 'yes');
        $syncInterval = $this->config->getAppValue('calendar-sync', 'sync_interval', '30');
        
        $parameters = [
            'auto_sync_enabled' => $autoSyncEnabled === 'yes',
            'sync_interval' => (int)$syncInterval
        ];

        return new TemplateResponse('calendar-sync', 'admin-settings', $parameters);
    }

    public function getSection() {
        return 'additional';
    }

    public function getPriority() {
        return 10;
    }
}