<?php

namespace OCA\CalDavSync\BackgroundJob;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUserManager;
use OCA\CalDavSync\Service\SyncService;
use OCA\CalDavSync\Service\ConfigService;
use Psr\Log\LoggerInterface;

class SyncJob extends TimedJob {
    private SyncService $syncService;
    private ConfigService $configService;
    private LoggerInterface $logger;
    private IConfig $config;
    private IUserManager $userManager;

    public function __construct(
        ITimeFactory $time,
        SyncService $syncService,
        ConfigService $configService,
        LoggerInterface $logger,
        IConfig $config,
        IUserManager $userManager
    ) {
        parent::__construct($time);
        $this->setInterval(30 * 60);

        $this->syncService = $syncService;
        $this->configService = $configService;
        $this->logger = $logger;
        $this->config = $config;
        $this->userManager = $userManager;
    }

    protected function run($argument): void {
        $this->logger->info('Starting scheduled CalDAV sync job', [
            'app' => 'calendar-sync'
        ]);

        try {
            $autoSyncEnabled = $this->config->getAppValue('calendar-sync', 'auto_sync_enabled', 'yes');

            if ($autoSyncEnabled !== 'yes') {
                $this->logger->info('Automatic sync is disabled, skipping scheduled sync', [
                    'app' => 'calendar-sync'
                ]);
                return;
            }

            $allConfigs = $this->getAllEnabledConfigs();

            $successCount = 0;
            $errorCount = 0;

            foreach ($allConfigs as $config) {
                try {
                    $result = $this->syncService->syncCalendar($config);

                    if ($result['success']) {
                        $successCount++;
                        $this->logger->debug('Scheduled sync successful for config: ' . $config['name'], [
                            'config_id' => $config['id'],
                            'stats' => $result['stats'],
                            'app' => 'calendar-sync'
                        ]);
                    } else {
                        $errorCount++;
                        $this->logger->warning('Scheduled sync failed for config: ' . $config['name'], [
                            'config_id' => $config['id'],
                            'error' => $result['error'],
                            'app' => 'calendar-sync'
                        ]);
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->logger->error('Exception during scheduled sync for config: ' . $config['name'], [
                        'config_id' => $config['id'],
                        'exception' => $e,
                        'app' => 'calendar-sync'
                    ]);
                }
            }

            $this->logger->info('Scheduled CalDAV sync job completed', [
                'configs_processed' => count($allConfigs),
                'successful' => $successCount,
                'errors' => $errorCount,
                'app' => 'calendar-sync'
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Scheduled sync job failed with exception', [
                'exception' => $e,
                'app' => 'calendar-sync'
            ]);
        }
    }

    private function getAllEnabledConfigs(): array {
        $allConfigs = [];
        $this->userManager->callForAllUsers(function ($user) use (&$allConfigs) {
            foreach ($this->configService->getUserConfigs($user->getUID()) as $config) {
                if (!empty($config['enabled'])) {
                    $allConfigs[] = $config;
                }
            }
        });
        return $allConfigs;
    }
}
