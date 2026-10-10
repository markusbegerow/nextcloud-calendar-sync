<?php

namespace OCA\CalDavSync\Controller;

use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;
use OCP\IUserSession;
use OCA\CalDavSync\Service\SyncService;
use OCA\CalDavSync\Service\ConfigService;
use Psr\Log\LoggerInterface;

class SyncController extends Controller {
    private SyncService $syncService;
    private ConfigService $configService;
    private LoggerInterface $logger;
    private IUserSession $userSession;

    public function __construct(
        string $appName,
        IRequest $request,
        SyncService $syncService,
        ConfigService $configService,
        LoggerInterface $logger,
        IUserSession $userSession
    ) {
        parent::__construct($appName, $request);
        $this->syncService = $syncService;
        $this->configService = $configService;
        $this->logger = $logger;
        $this->userSession = $userSession;
    }

    /**
     * @NoAdminRequired
     */
    public function sync(): DataResponse {
        try {
            $configs = $this->configService->getUserConfigs();
            $results = [];
            
            foreach ($configs as $config) {
                if ($config['enabled']) {
                    $result = $this->syncService->syncCalendar($config);
                    $results[] = $result;
                }
            }
            
            return new DataResponse([
                'success' => true,
                'message' => 'Sync completed',
                'results' => $results
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Sync failed: ' . $e->getMessage(), [
                'exception' => $e,
                'app' => 'calendar-sync'
            ]);
            
            return new DataResponse([
                'success' => false,
                'message' => 'Sync failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function clearHistory(): DataResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['error' => 'Not logged in'], 401);
            }
            $this->syncService->clearHistory($user->getUID());
            return new DataResponse(['success' => true]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to clear history: ' . $e->getMessage(), [
                'exception' => $e,
                'app' => 'calendar-sync'
            ]);
            return new DataResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function status(): DataResponse {
        try {
            $user = $this->userSession->getUser();
            if (!$user) {
                return new DataResponse(['error' => 'Not logged in'], 401);
            }
            $status = $this->syncService->getSyncStatus($user->getUID());
            return new DataResponse($status);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get sync status: ' . $e->getMessage(), [
                'exception' => $e,
                'app' => 'calendar-sync'
            ]);
            
            return new DataResponse([
                'success' => false,
                'message' => 'Failed to get status'
            ], 500);
        }
    }
}