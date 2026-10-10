<?php

namespace OCA\CalDavSync\Controller;

use OCP\IRequest;
use OCP\IConfig;
use OCP\IUserSession;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCA\CalDavSync\Service\ConfigService;
use Psr\Log\LoggerInterface;

class ConfigController extends Controller {
    private ConfigService $configService;
    private LoggerInterface $logger;

    public function __construct(
        string $appName,
        IRequest $request,
        ConfigService $configService,
        LoggerInterface $logger,
        private IUserSession $userSession,
        private IConfig $config
    ) {
        parent::__construct($appName, $request);
        $this->configService = $configService;
        $this->logger = $logger;
    }

    private function getUserId(): string {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \Exception('Not authenticated');
        }
        return $user->getUID();
    }

    /**
     * @NoAdminRequired
     */
    public function getConfigs(): DataResponse {
        try {
            $configs = $this->configService->getUserConfigs();
            return new DataResponse($configs);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get configs: ' . $e->getMessage(), [
                'exception' => $e,
                'app' => 'calendar-sync'
            ]);
            return new DataResponse(['error' => 'Failed to load configurations'], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function createConfig(
        string $name,
        string $url,
        string $username,
        string $password,
        bool $enabled = true,
        string $calendar_selection = 'personal',
        string $sync_direction = 'bidirectional',
        string $duplicate_handling = 'update_newer',
        string $auth_type = 'digest'
    ): DataResponse {
        try {
            $config = $this->configService->createConfig($name, $url, $username, $password, $enabled, $calendar_selection, $sync_direction, $duplicate_handling, $auth_type);
            return new DataResponse($config);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create config: ' . $e->getMessage(), [
                'exception' => $e,
                'app' => 'calendar-sync'
            ]);
            return new DataResponse(['error' => 'Failed to create configuration'], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function updateConfig(int $id): DataResponse {
        try {
            $data = $this->request->getParams();
            $config = $this->configService->updateConfig($id, $data);
            return new DataResponse($config);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update config: ' . $e->getMessage(), [
                'exception' => $e,
                'app' => 'calendar-sync'
            ]);
            return new DataResponse(['error' => 'Failed to update configuration'], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function deleteConfig(int $id): DataResponse {
        try {
            $this->configService->deleteConfig($id);
            return new DataResponse(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete config: ' . $e->getMessage(), [
                'exception' => $e,
                'app' => 'calendar-sync'
            ]);
            return new DataResponse(['error' => 'Failed to delete configuration'], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function testConnection(int $id): DataResponse {
        try {
            $result = $this->configService->testConnection($id);
            return new DataResponse($result);
        } catch (\Exception $e) {
            $this->logger->error('Connection test failed: ' . $e->getMessage(), [
                'exception' => $e,
                'app' => 'calendar-sync'
            ]);
            return new DataResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function getUserSettings(): DataResponse {
        try {
            $uid = $this->getUserId();
            return new DataResponse([
                'autoRefreshInterval'  => (int) $this->config->getUserValue($uid, 'calendar-sync', 'auto_refresh_interval', '0'),
                'defaultSyncDirection' => $this->config->getUserValue($uid, 'calendar-sync', 'default_sync_direction', ''),
                'showSyncLogs'         => $this->config->getUserValue($uid, 'calendar-sync', 'show_sync_logs', '1') === '1',
            ]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function saveUserSettings(int $autoRefreshInterval = 0, string $defaultSyncDirection = '', bool $showSyncLogs = true): DataResponse {
        try {
            $uid     = $this->getUserId();
            $allowed = [0, 15, 30, 60, 300];
            $interval = in_array($autoRefreshInterval, $allowed, true) ? $autoRefreshInterval : 0;
            $this->config->setUserValue($uid, 'calendar-sync', 'auto_refresh_interval',  (string) $interval);
            $this->config->setUserValue($uid, 'calendar-sync', 'default_sync_direction', $defaultSyncDirection);
            $this->config->setUserValue($uid, 'calendar-sync', 'show_sync_logs',         $showSyncLogs ? '1' : '0');
            return new DataResponse(['status' => 'ok']);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function testNewConnection(): DataResponse {
        try {
            $url = $this->request->getParam('url');
            $username = $this->request->getParam('username');
            $password = $this->request->getParam('password');
            $authType = $this->request->getParam('auth_type', 'digest');

            if (!$url || !$username || !$password) {
                return new DataResponse([
                    'success' => false,
                    'message' => 'Missing required parameters'
                ]);
            }

            $result = $this->configService->testNewConnection($url, $username, $password, $authType);
            return new DataResponse($result);
        } catch (\Exception $e) {
            $this->logger->error('New connection test failed: ' . $e->getMessage(), [
                'exception' => $e,
                'app' => 'calendar-sync'
            ]);
            return new DataResponse([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}