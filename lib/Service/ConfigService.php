<?php

namespace OCA\CalDavSync\Service;

use OCP\IDBConnection;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use OCP\AppFramework\Db\DoesNotExistException;
use Sabre\DAV\Client;

class ConfigService {
    private IDBConnection $db;
    private IUserSession $userSession;
    private ICrypto $crypto;

    public function __construct(
        IDBConnection $db,
        IUserSession $userSession,
        ICrypto $crypto
    ) {
        $this->db = $db;
        $this->userSession = $userSession;
        $this->crypto = $crypto;
    }

    public function getUserConfigs(?string $userId = null): array {
        if ($userId === null) {
            $user = $this->userSession->getUser();
            if (!$user) {
                throw new \Exception('No user logged in');
            }
            $userId = $user->getUID();
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('caldav_sync_configs')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $configs = [];

        while ($row = $result->fetch()) {
            $configs[] = $this->rowToConfig($row);
        }

        return $configs;
    }

    public function createConfig(
        string $name,
        string $url,
        string $username,
        string $password,
        bool $enabled = true,
        string $calendarSelection = 'personal',
        string $syncDirection = 'bidirectional',
        string $duplicateHandling = 'update_newer'
    ): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('No user logged in');
        }

        $encryptedPassword = $this->crypto->encrypt($password);
        $now = new \DateTime();

        $qb = $this->db->getQueryBuilder();
        $qb->insert('caldav_sync_configs')
           ->values([
               'user_id' => $qb->createNamedParameter($user->getUID()),
               'name' => $qb->createNamedParameter($name),
               'url' => $qb->createNamedParameter($url),
               'username' => $qb->createNamedParameter($username),
               'password' => $qb->createNamedParameter($encryptedPassword),
               'enabled' => $qb->createNamedParameter($enabled ? 1 : 0, \PDO::PARAM_INT),
               'calendar_selection' => $qb->createNamedParameter($calendarSelection),
               'sync_direction' => $qb->createNamedParameter($syncDirection),
               'duplicate_handling' => $qb->createNamedParameter($duplicateHandling),
               'created_at' => $qb->createNamedParameter($now, 'datetime'),
               'updated_at' => $qb->createNamedParameter($now, 'datetime')
           ]);

        $qb->executeStatement();
        $id = $qb->getLastInsertId();

        return $this->getConfig($id);
    }

    public function updateConfig(int $id, array $data): array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('No user logged in');
        }

        // Verify ownership
        $config = $this->getConfig($id);
        if (!$config) {
            throw new DoesNotExistException('Configuration not found');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update('caldav_sync_configs')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($user->getUID())));

        if (isset($data['name'])) {
            $qb->set('name', $qb->createNamedParameter($data['name']));
        }
        if (isset($data['url'])) {
            $qb->set('url', $qb->createNamedParameter($data['url']));
        }
        if (isset($data['username'])) {
            $qb->set('username', $qb->createNamedParameter($data['username']));
        }
        if (!empty($data['password'])) {
            $encryptedPassword = $this->crypto->encrypt($data['password']);
            $qb->set('password', $qb->createNamedParameter($encryptedPassword));
        }
        if (isset($data['enabled'])) {
            $qb->set('enabled', $qb->createNamedParameter($data['enabled'] ? 1 : 0, \PDO::PARAM_INT));
        }
        if (isset($data['calendar_selection'])) {
            $qb->set('calendar_selection', $qb->createNamedParameter($data['calendar_selection']));
        }
        if (isset($data['sync_direction'])) {
            $qb->set('sync_direction', $qb->createNamedParameter($data['sync_direction']));
        }
        if (isset($data['duplicate_handling'])) {
            $qb->set('duplicate_handling', $qb->createNamedParameter($data['duplicate_handling']));
        }

        $qb->set('updated_at', $qb->createNamedParameter(new \DateTime(), 'datetime'));
        $qb->executeStatement();

        return $this->getConfig($id);
    }

    public function deleteConfig(int $id): void {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('No user logged in');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete('caldav_sync_configs')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($user->getUID())));

        $qb->executeStatement();
    }

    public function getConfig(int $id): ?array {
        $user = $this->userSession->getUser();
        if (!$user) {
            throw new \Exception('No user logged in');
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
           ->from('caldav_sync_configs')
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
           ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($user->getUID())));

        $result = $qb->executeQuery();
        $row = $result->fetch();

        if (!$row) {
            return null;
        }

        return $this->rowToConfig($row);
    }

    private function rowToConfig(array $row): array {
        return [
            'id' => (int)$row['id'],
            'user_id' => $row['user_id'],
            'name' => $row['name'],
            'url' => $row['url'],
            'username' => $row['username'],
            'password' => $this->crypto->decrypt($row['password']),
            'enabled' => (bool)$row['enabled'],
            'calendar_selection' => $row['calendar_selection'] ?? 'personal',
            'sync_direction' => $row['sync_direction'] ?? 'bidirectional',
            'duplicate_handling' => $row['duplicate_handling'] ?? 'update_newer',
            'sync_token' => $row['sync_token'],
            'last_sync' => $row['last_sync'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at']
        ];
    }

    public function testConnection(int $id): array {
        $config = $this->getConfig($id);
        if (!$config) {
            throw new \Exception('Configuration not found');
        }

        return $this->testNewConnection($config['url'], $config['username'], $config['password']);
    }

    public function testNewConnection(string $url, string $username, string $password): array {
        try {
            $client = new Client([
                'baseUri' => $url,
                'userName' => $username,
                'password' => $password,
                'authType' => Client::AUTH_DIGEST  // Use Digest auth for All-Inkl compatibility
            ]);

            // Simple connection test - just try PROPFIND like in working cURL test
            try {
                $response = $client->propfind('', ['{DAV:}resourcetype'], 0);
                
                // If we get here without an exception, the connection with Digest auth worked
                return [
                    'success' => true,
                    'message' => "Connection successful! CalDAV server accessible with credentials.",
                    'details' => [
                        'method' => 'PROPFIND',
                        'url' => $url,
                        'auth_type' => 'Digest'
                    ]
                ];
                
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'message' => "Connection failed: " . $e->getMessage(),
                    'details' => [
                        'error' => $e->getMessage(),
                        'url' => $url,
                        'auth_type' => 'Digest'
                    ]
                ];
            }
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
                'details' => [
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode(),
                    'url' => $url,
                    'username' => $username
                ]
            ];
        }
    }

    public function updateSyncToken(int $id, ?string $syncToken): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('caldav_sync_configs')
           ->set('sync_token', $qb->createNamedParameter($syncToken))
           ->set('last_sync', $qb->createNamedParameter(new \DateTime(), 'datetime'))
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

        $qb->executeStatement();
    }
}