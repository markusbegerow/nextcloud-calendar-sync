<?php

namespace OCA\CalDavSync\Service;

use OCP\IDBConnection;
use OCA\DAV\CalDAV\CalDavBackend;
use OCP\Calendar\IManager as CalendarManager;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Client;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;
use OCA\CalDavSync\Service\ConfigService;

class SyncService {
    private IDBConnection $db;
    private CalDavBackend $calDavBackend;
    private CalendarManager $calendarManager;
    private ConfigService $configService;
    private LoggerInterface $logger;

    public function __construct(
        IDBConnection $db,
        CalDavBackend $calDavBackend,
        CalendarManager $calendarManager,
        ConfigService $configService,
        LoggerInterface $logger
    ) {
        $this->db = $db;
        $this->calDavBackend = $calDavBackend;
        $this->calendarManager = $calendarManager;
        $this->configService = $configService;
        $this->logger = $logger;
    }

    public function syncCalendar(array $config): array {
        $direction = $config['sync_direction'] ?? 'bidirectional';
        $duplicateHandling = $config['duplicate_handling'] ?? 'update_newer';
        $logId = $this->createSyncLog($config['id'], $direction, 'running');
        $stats = ['created' => 0, 'updated' => 0, 'deleted' => 0];

        try {
            $this->logger->info('Starting sync for config: ' . $config['name'], [
                'config_id' => $config['id'],
                'app' => 'calendar-sync'
            ]);

            // Connect to external CalDAV server
            $authType = $config['auth_type'] ?? 'digest';
            $client = new Client([
                'baseUri' => $config['url'],
                'userName' => $config['username'],
                'password' => $config['password'],
                'authType' => $authType === 'basic' ? Client::AUTH_BASIC : Client::AUTH_DIGEST
            ]);

            $userId = $config['user_id'];
            $this->logger->debug('Getting calendars for user: ' . $userId);
            $rawCalendars = $this->calDavBackend->getCalendarsForUser('principals/users/' . $userId);

            $calendarSelection = !empty($config['calendar_selection']) ? $config['calendar_selection'] : 'personal';
            $calendarData = null;
            foreach ($rawCalendars as $cal) {
                if ($cal['uri'] === $calendarSelection) {
                    $calendarData = $cal;
                    break;
                }
            }
            if (!$calendarData && !empty($rawCalendars)) {
                $calendarData = $rawCalendars[0];
            }
            if (!$calendarData) {
                throw new \Exception('No Nextcloud calendar found for user: ' . $userId);
            }

            $nextcloudCalendar = new class($calendarData, $this->calDavBackend) {
                private array $info;
                private $backend;
                public function __construct(array $info, $backend) {
                    $this->info = $info;
                    $this->backend = $backend;
                }
                public function getKey() { return $this->info['id']; }
                public function getUri(): string { return $this->info['uri']; }
                public function createFromString(string $name, string $data): void {
                    $this->backend->createCalendarObject($this->info['id'], $name, $data);
                }
            };

            $this->logger->info('Using calendar "' . $calendarData['uri'] . '" for sync with user: ' . $userId);

            // === Step 1: Fetch events from external server (always full fetch like Python All-Inkl) ===
            $this->logger->debug('About to fetch external events...');
            $externalEvents = $this->fetchExternalEvents($client);
            $this->logger->debug('Fetched ' . count($externalEvents) . ' events from external server');

            // === Step 2: Fetch Nextcloud events with incremental sync attempt ===
            $this->logger->debug('About to fetch Nextcloud events...');
            $lastToken = $config['sync_token'] ?? null;
            $deletedEvents = [];
            $nextcloudEventsFull = [];
            
            try {
                // Attempt incremental sync first (like Python fetch_changes_via_sync)
                if ($lastToken) {
                    $syncResult = $this->fetchChangesViaSync($nextcloudCalendar, $lastToken);
                    $deletedEvents = $syncResult['deleted'];
                    $newToken = $syncResult['newToken'];
                    
                    // Save the new token
                    if ($newToken && $newToken !== $lastToken) {
                        $this->configService->updateSyncToken($config['id'], $newToken);
                        $this->logger->debug('Updated sync token: ' . $newToken);
                    }
                }
                
                // Always get full Nextcloud events for comparison (like Python)
                $nextcloudEventsFull = $this->fetchNextcloudEvents($nextcloudCalendar, $userId);
                $this->logger->debug('Fetched ' . count($nextcloudEventsFull) . ' events from Nextcloud');
                
            } catch (\Exception $e) {
                $this->logger->warning('Incremental sync failed, falling back to full sync: ' . $e->getMessage());
                $deletedEvents = [];
                $nextcloudEventsFull = $this->fetchNextcloudEvents($nextcloudCalendar, $userId);
            }

            // === Step 3: State-based deletion detection ===
            $this->logger->info('🔍 DELETION DETECTION STARTING');
            $lastKnownUIDs = $this->getStoredUIDs($config['id']);
            $currentExternalUIDs = array_keys($externalEvents);
            $currentNextcloudUIDs = array_keys($nextcloudEventsFull);

            // Detect deletions by comparing with last known state
            $deletedFromExternal = array_diff($lastKnownUIDs['external'] ?? [], $currentExternalUIDs);
            $deletedFromNextcloud = array_diff($lastKnownUIDs['nextcloud'] ?? [], $currentNextcloudUIDs);

            $this->logger->info('🗑️ Detected deletions - External: ' . count($deletedFromExternal) . ', Nextcloud: ' . count($deletedFromNextcloud));

            // Handle deletions from external (missing in external, remove from Nextcloud)
            foreach ($deletedFromExternal as $uid) {
                if (isset($nextcloudEventsFull[$uid])) {
                    $summary = $this->extractEventSummary($nextcloudEventsFull[$uid]['data']);
                    $this->logger->info("🗑️ [EXT-DELETE] $uid ($summary): Deleted from external → deleting from NC");
                    $this->deleteEventInNextcloud($nextcloudCalendar, $uid);
                    $stats['deleted']++;
                    // Remove from current arrays so it doesn't interfere with other sync logic
                    unset($nextcloudEventsFull[$uid]);
                }
            }

            // Handle deletions from Nextcloud (missing in NC, remove from external) 
            foreach ($deletedFromNextcloud as $uid) {
                if (isset($externalEvents[$uid])) {
                    $summary = $this->extractEventSummary($externalEvents[$uid]['data']);
                    $this->logger->info("🗑️ [NC-DELETE] $uid ($summary): Deleted from NC → deleting from external");
                    $this->deleteEventInExternal($client, $uid);
                    $stats['deleted']++;
                    // Remove from current arrays so it doesn't interfere with other sync logic
                    unset($externalEvents[$uid]);
                }
            }

            // === Step 4: Intelligent sync logic with content-based duplicate detection ===
            $this->logger->info('🔄 INTELLIGENT SYNC ANALYSIS STARTING');
            $this->logger->info('📅 External Events: ' . count($externalEvents));
            $this->logger->info('📅 Nextcloud Events: ' . count($nextcloudEventsFull));
            
            // Log all events for debugging
            foreach ($externalEvents as $uid => $eventData) {
                $summary = $this->extractEventSummary($eventData['data']);
                $this->logger->info("📅 [EXTERNAL] UID: $uid | Summary: $summary | Last Modified: " . $eventData['lastModified']->format('Y-m-d H:i:s'));
            }
            
            foreach ($nextcloudEventsFull as $uid => $eventData) {
                $summary = $this->extractEventSummary($eventData['data']);
                $this->logger->info("📅 [NEXTCLOUD] UID: $uid | Summary: $summary | Last Modified: " . $eventData['lastModified']->format('Y-m-d H:i:s'));
            }
            
            // === Python-equivalent sync logic with exact same order ===
            
            // a) NEW: NC → External (skipped when direction is 'external_to_nc')
            $newInExternal = array_diff_key($nextcloudEventsFull, $externalEvents);
            $this->logger->info('➡️ Events to sync NC → External: ' . count($newInExternal) . ' (direction: ' . $direction . ')');
            if ($direction !== 'external_to_nc') {
                foreach ($newInExternal as $uid => $eventData) {
                    $summary = $this->extractEventSummary($eventData['data']);

                    $contentMatch = ($duplicateHandling !== 'keep_both')
                        ? $this->findEventByContent($summary, $externalEvents)
                        : null;

                    if ($contentMatch) {
                        if ($duplicateHandling === 'skip') {
                            $this->logger->info("⏭️ [NC→EXT SKIP] NC $uid ($summary): duplicate found, skipping per policy");
                            continue;
                        }
                        $matchUid = array_key_first($contentMatch);
                        $matchData = $contentMatch[$matchUid];
                        $ncTime  = $eventData['lastModified']->format('Y-m-d H:i:s');
                        $extTime = $matchData['lastModified']->format('Y-m-d H:i:s');
                        $contentChanged = $this->hasContentChanged($eventData['data'], $matchData['data']);
                        if ($contentChanged) {
                            if ($eventData['lastModified'] > $matchData['lastModified']) {
                                $this->logger->info("🔄 [NC→EXT CONTENT-MATCH] NC $uid → Ext $matchUid ($summary): NC newer ($ncTime) → updating External");
                                $this->updateEventInExternal($client, $matchUid, $eventData['data']);
                                $stats['updated']++;
                            } else {
                                $this->logger->info("🔄 [NC→EXT CONTENT-MATCH] NC $uid → Ext $matchUid ($summary): External newer ($extTime), no action");
                            }
                        } else {
                            $this->logger->info("✅ [NC→EXT CONTENT-MATCH] NC $uid → Ext $matchUid ($summary): Content identical, no action needed");
                        }
                    } else {
                        $this->logger->info("➡️ [NC→EXT NEW] NC $uid ($summary): Creating in External");
                        $this->createEventInExternal($client, $eventData['data']);
                        $stats['created']++;
                    }
                }
            }

            // b) NEW: External → NC (skipped when direction is 'nc_to_external')
            $newInNextcloud = array_diff_key($externalEvents, $nextcloudEventsFull);
            $this->logger->info('⬅️ Events to sync External → NC: ' . count($newInNextcloud) . ' (direction: ' . $direction . ')');
            if ($direction !== 'nc_to_external') {
                foreach ($newInNextcloud as $uid => $eventData) {
                    $summary = $this->extractEventSummary($eventData['data']);

                    $contentMatch = ($duplicateHandling !== 'keep_both')
                        ? $this->findEventByContent($summary, $nextcloudEventsFull)
                        : null;

                    if ($contentMatch) {
                        if ($duplicateHandling === 'skip') {
                            $this->logger->info("⏭️ [EXT→NC SKIP] Ext $uid ($summary): duplicate found, skipping per policy");
                            continue;
                        }
                        $matchUid = array_key_first($contentMatch);
                        $matchData = $contentMatch[$matchUid];
                        $extTime = $eventData['lastModified']->format('Y-m-d H:i:s');
                        $ncTime  = $matchData['lastModified']->format('Y-m-d H:i:s');
                        $contentChanged = $this->hasContentChanged($eventData['data'], $matchData['data']);
                        if ($contentChanged) {
                            if ($eventData['lastModified'] > $matchData['lastModified']) {
                                $this->logger->info("🔄 [EXT→NC CONTENT-MATCH] Ext $uid → NC $matchUid ($summary): External newer ($extTime) → updating NC");
                                $this->updateEventInNextcloud($nextcloudCalendar, $matchUid, $eventData['data']);
                                $stats['updated']++;
                            } else {
                                $this->logger->info("🔄 [EXT→NC CONTENT-MATCH] Ext $uid → NC $matchUid ($summary): NC newer ($ncTime), no action");
                            }
                        } else {
                            $this->logger->info("✅ [EXT→NC CONTENT-MATCH] Ext $uid → NC $matchUid ($summary): Content identical, no action needed");
                        }
                    } else {
                        $this->logger->info("⬅️ [EXT→NC NEW] Ext $uid ($summary): Creating in NC");
                        $this->createEventInNextcloud($nextcloudCalendar, $eventData['data']);
                        $stats['created']++;
                    }
                }
            }

            // c) UPDATES: Common events by UID — always run, but direction limits which side can be written
            $commonByUID = array_intersect_key($externalEvents, $nextcloudEventsFull);
            $this->logger->info('🔄 Common events by UID requiring conflict resolution: ' . count($commonByUID));
            foreach ($commonByUID as $uid => $extEventData) {
                $ncEventData = $nextcloudEventsFull[$uid];
                $summary = $this->extractEventSummary($extEventData['data']);
                $extTime = $extEventData['lastModified']->format('Y-m-d H:i:s');
                $ncTime = $ncEventData['lastModified']->format('Y-m-d H:i:s');
                
                // Check if content has actually changed (beyond timestamps)
                $contentChanged = $this->hasContentChanged($extEventData['data'], $ncEventData['data']);
                
                if ($contentChanged) {
                    if ($extEventData['lastModified'] > $ncEventData['lastModified']) {
                        if ($direction !== 'nc_to_external') {
                            $this->logger->info("🔄 [UID-CONFLICT] $uid ($summary): External newer ($extTime) → updating NC ($ncTime)");
                            $this->updateEventInNextcloud($nextcloudCalendar, $uid, $extEventData['data']);
                            $stats['updated']++;
                        }
                    } elseif ($ncEventData['lastModified'] > $extEventData['lastModified']) {
                        if ($direction !== 'external_to_nc') {
                            $this->logger->info("🔄 [UID-CONFLICT] $uid ($summary): NC newer ($ncTime) → updating External ($extTime)");
                            $this->updateEventInExternal($client, $uid, $ncEventData['data']);
                            $stats['updated']++;
                        }
                    } else {
                        if ($direction !== 'nc_to_external') {
                            $this->logger->info("🔄 [UID-CONFLICT] $uid ($summary): Same timestamp, content differs → updating NC with External");
                            $this->updateEventInNextcloud($nextcloudCalendar, $uid, $extEventData['data']);
                            $stats['updated']++;
                        }
                    }
                } else {
                    $this->logger->info("✅ [UID-SYNC] $uid ($summary): Content identical ($extTime)");
                }
            }

            // Handle deletions: NC tombstones (like Python deletion logic)
            $this->logger->info('🗑️ Deleted events to process: ' . count($deletedEvents));
            foreach ($deletedEvents as $uid) {
                if (isset($externalEvents[$uid])) {
                    $summary = $this->extractEventSummary($externalEvents[$uid]['data']);
                    $this->logger->info("🗑️ [DELETE] $uid ($summary): Deleted in NC → deleting in external");
                    $this->deleteEventInExternal($client, $uid);
                    $stats['deleted']++;
                }
            }

            // Store current state for next sync (after all operations are complete)
            $finalExternalUIDs = array_keys($externalEvents);
            $finalNextcloudUIDs = array_keys($nextcloudEventsFull);
            $this->storeUIDs($config['id'], $finalExternalUIDs, $finalNextcloudUIDs);

            $this->completeSyncLog($logId, 'success', null, $stats);
            $this->logger->info('Sync completed successfully', [
                'config_id' => $config['id'],
                'stats' => $stats,
                'app' => 'calendar-sync'
            ]);

            return [
                'success' => true,
                'stats' => $stats,
                'config' => $config['name']
            ];

        } catch (\Exception $e) {
            $this->completeSyncLog($logId, 'error', $e->getMessage(), $stats);
            $this->logger->error('Sync failed: ' . $e->getMessage(), [
                'config_id' => $config['id'],
                'exception' => $e,
                'app' => 'calendar-sync'
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'config' => $config['name']
            ];
        }
    }

    private function fetchExternalEvents(Client $client): array {
        $events = [];
        
        try {
            // Discover calendar collections if base URL is a calendar home
            $calendarUrls = $this->discoverCalendars($client);
            foreach ($calendarUrls as $calUrl) {
                $calEvents = $this->fetchEventsFromCalendar($client, $calUrl);
                $events = array_merge($events, $calEvents);
            }
            return $events;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to fetch external events: ' . $e->getMessage());
            return [];
        }
    }

    private function discoverCalendars(Client $client): array {
        try {
            $requestBody = '<?xml version="1.0" encoding="utf-8" ?>'
                . '<D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
                . '<D:prop><D:resourcetype /></D:prop></D:propfind>';
            $response = $client->request('PROPFIND', '', $requestBody, [
                'Depth' => '1', 'Content-Type' => 'application/xml'
            ]);
            if (!isset($response['body']) || empty($response['body'])) {
                return [''];
            }
            $xml = @simplexml_load_string(trim($response['body']));
            if ($xml === false) {
                return [''];
            }
            $xml->registerXPathNamespace('D', 'DAV:');
            $responses = $xml->xpath('//D:response');
            if ($responses === false) {
                return [''];
            }
            $calendars = [];
            foreach ($responses as $resp) {
                $resp->registerXPathNamespace('d', 'DAV:');
                $resp->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
                $hrefNodes = $resp->xpath('d:href');
                if ($hrefNodes === false || empty($hrefNodes)) {
                    continue;
                }
                $href = (string)$hrefNodes[0];
                $isCal = $resp->xpath('d:propstat[d:status="HTTP/1.1 200 OK"]/d:prop/d:resourcetype/c:calendar');
                if ($href && $isCal !== false && count($isCal) > 0) {
                    $calendars[] = $href;
                    $this->logger->debug("Discovered calendar: $href");
                }
            }
            return !empty($calendars) ? $calendars : [''];
        } catch (\Throwable $e) {
            $this->logger->debug('Calendar discovery failed, using base URL: ' . $e->getMessage());
            return [''];
        }
    }

    private function fetchEventsFromCalendar(Client $client, string $calUrl): array {
        $events = [];
        try {
            // Use CalDAV calendar-query REPORT (standard way to list events)
            $requestBody = '<?xml version="1.0" encoding="utf-8" ?>
                <C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
                    <D:prop>
                        <D:getetag />
                        <C:calendar-data />
                    </D:prop>
                    <C:filter>
                        <C:comp-filter name="VCALENDAR">
                            <C:comp-filter name="VEVENT" />
                        </C:comp-filter>
                    </C:filter>
                </C:calendar-query>';
            
            $headers = [
                'Depth' => '1',
                'Content-Type' => 'application/xml'
            ];
            
            $response = $client->request('REPORT', $calUrl, $requestBody, $headers);

            if (isset($response['body'])) {
                $xmlBody = $response['body'];
                $this->logger->debug('PROPFIND Response length: ' . strlen($xmlBody) . ' bytes');
                $this->logger->debug('PROPFIND Response: ' . $xmlBody);
                
                // Clean up the XML body
                $xmlBody = trim($xmlBody);
                
                // Enable libxml error reporting
                $useInternalErrors = libxml_use_internal_errors(true);
                libxml_clear_errors();
                
                // Try to parse with DOMDocument first, then convert to SimpleXML
                $dom = new \DOMDocument();
                $dom->loadXML($xmlBody);
                $xml = simplexml_import_dom($dom);
                
                if ($xml === false) {
                    $errors = libxml_get_errors();
                    $errorMessages = [];
                    foreach ($errors as $error) {
                        $errorMessages[] = trim($error->message);
                    }
                    $this->logger->error('Failed to parse XML response: ' . implode(', ', $errorMessages));
                    $this->logger->debug('XML Body hex dump: ' . bin2hex(substr($xmlBody, 0, 100)));
                    libxml_use_internal_errors($useInternalErrors);
                    return [];
                }
                libxml_use_internal_errors($useInternalErrors);
                
                $xml->registerXPathNamespace('D', 'DAV:');

                // Look for .ics files in the PROPFIND response
                $responseNodes = $xml->xpath('//D:response');
                $this->logger->debug('Found ' . count($responseNodes) . ' response nodes');
                
                foreach ($responseNodes as $responseNode) {
                    // Register namespaces on each response node
                    $responseNode->registerXPathNamespace('d', 'DAV:');
                    $responseNode->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
                    
                    // Get href from response node
                    $hrefNodes = $responseNode->xpath('d:href');
                    $href = $hrefNodes && count($hrefNodes) > 0 ? (string)$hrefNodes[0] : '';
                    
                    // Get calendar-data directly from REPORT response (or fall back to GET for PROPFIND)
                    $calDataNodes = $responseNode->xpath('d:propstat[d:status="HTTP/1.1 200 OK"]/d:prop/c:calendar-data');
                    if ($calDataNodes && count($calDataNodes) > 0 && (string)$calDataNodes[0] !== '') {
                        $calendarData = (string)$calDataNodes[0];
                        $this->logger->debug("Got calendar-data inline for: $href");
                    } else {
                        // Fallback: fetch via GET (for PROPFIND-style responses)
                        $propstatNodes = $responseNode->xpath('d:propstat[d:status="HTTP/1.1 200 OK"]/d:prop/d:getcontenttype');
                        $contentType = $propstatNodes && count($propstatNodes) > 0 ? (string)$propstatNodes[0] : '';
                        if (!$href || (strpos($href, '.ics') === false && strpos($contentType, 'text/calendar') === false)) {
                            continue;
                        }
                        $this->logger->debug("Fetching calendar file via GET: $href");
                        $eventResponse = $client->request('GET', $href);
                        if (!isset($eventResponse['body'])) {
                            continue;
                        }
                        $calendarData = $eventResponse['body'];
                    }
                            
                            try {
                                $vcalendar = Reader::read($calendarData);
                                
                                // Handle both single VEVENT and VCALENDAR containers
                                $vevents = [];
                                if ($vcalendar->name === 'VEVENT') {
                                    $vevents = [$vcalendar];
                                } else {
                                    foreach ($vcalendar->VEVENT as $vevent) {
                                        $vevents[] = $vevent;
                                    }
                                }
                                
                                foreach ($vevents as $vevent) {
                                    $uid = (string)$vevent->UID;
                                    $lastModified = isset($vevent->{'LAST-MODIFIED'}) 
                                        ? $vevent->{'LAST-MODIFIED'}->getDateTime()
                                        : (isset($vevent->DTSTAMP) ? $vevent->DTSTAMP->getDateTime() : new \DateTime());
                                        
                                    $events[$uid] = [
                                        'data' => $calendarData,
                                        'lastModified' => $lastModified
                                    ];
                                }
                            } catch (\Exception $parseError) {
                                $this->logger->warning('Failed to parse calendar data from ' . $href . ': ' . $parseError->getMessage());
                                continue;
                            }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch external events: ' . $e->getMessage());
            throw $e;
        }

        return $events;
    }

    private function fetchNextcloudEvents($calendar, string $userId): array {
        $events = [];
        
        try {
            $this->logger->debug('Starting to fetch Nextcloud events (Python equivalent of cal.events())...');
            $this->logger->debug('Calendar object class: ' . get_class($calendar));
            
            // Method: Use CalDAV backend directly to get calendar objects with data
            // This is equivalent to Python's cal.events() method
            try {
                // Get the CalDAV backend from the calendar
                
                // Get calendar info
                $calendarInfo = [
                    'id' => $calendar->getKey(),
                    'uri' => $calendar->getUri(),
                    'principaluri' => 'principals/users/' . $userId
                ];
                
                $this->logger->debug('Using CalDAV backend to fetch calendar objects for calendar: ' . $calendar->getUri());
                
                // Get all calendar objects with their data (equivalent to Python's cal.events())
                $calendarObjects = $this->calDavBackend->getCalendarObjects($calendarInfo['id']);
                
                $this->logger->debug('Found ' . count($calendarObjects) . ' calendar objects via CalDAV backend');
                
                foreach ($calendarObjects as $object) {
                    $this->logger->debug('Calendar object keys: ' . implode(', ', array_keys($object)));
                    
                    // The calendardata might not be included, fetch it separately
                    $calendarData = $object['calendardata'] ?? null;
                    
                    if (!$calendarData) {
                        // Fetch the calendar data separately using the backend
                        $this->logger->debug('Calendar data not included, fetching separately for: ' . $object['uri']);
                        try {
                            $objectData = $this->calDavBackend->getCalendarObject($calendarInfo['id'], $object['uri']);
                            $calendarData = $objectData['calendardata'] ?? null;
                            $this->logger->debug('Fetched calendar data separately: ' . (!empty($calendarData) ? 'SUCCESS' : 'FAILED'));
                        } catch (\Exception $fetchError) {
                            $this->logger->warning('Failed to fetch calendar data for ' . $object['uri'] . ': ' . $fetchError->getMessage());
                            continue;
                        }
                    }
                    
                    if ($calendarData) {
                        $this->logger->debug('Found calendar data, processing...');
                        $this->processEventData($calendarData, $events, $object);
                    } else {
                        $this->logger->warning('No calendar data available for: ' . $object['uri']);
                    }
                }
                
            } catch (\Exception $e) {
                $this->logger->warning('CalDAV backend method failed: ' . $e->getMessage());
                
                // Fallback: try the old approach
                $this->logger->debug('Falling back to calendar search approach...');
                $calendarObjects = $calendar->search('', [], []);
                $this->logger->debug('Fallback search found ' . count($calendarObjects) . ' objects');
                
                foreach ($calendarObjects as $object) {
                    $this->logger->debug('Fallback object keys: ' . implode(', ', array_keys($object)));
                    // Try to process whatever we get
                    if (isset($object['objects']) && is_array($object['objects'])) {
                        $this->logger->debug('Processing ' . count($object['objects']) . ' nested objects');
                    }
                }
            }
            
            $this->logger->debug('Successfully fetched ' . count($events) . ' Nextcloud events');
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch Nextcloud events: ' . $e->getMessage());
            throw $e;
        }

        return $events;
    }
    
    private function processEventData($calendarData, &$events, $object): void {
        try {
            // Parse the iCal data
            $vcalendar = Reader::read($calendarData);
            
            // Handle both single events and VCALENDAR containers
            $vevents = [];
            if ($vcalendar->name === 'VEVENT') {
                $vevents = [$vcalendar];
            } else {
                foreach ($vcalendar->VEVENT as $vevent) {
                    $vevents[] = $vevent;
                }
            }
            
            foreach ($vevents as $vevent) {
                $uid = (string)$vevent->UID;
                $lastModified = isset($vevent->{'LAST-MODIFIED'}) 
                    ? $vevent->{'LAST-MODIFIED'}->getDateTime()
                    : (isset($vevent->DTSTAMP) ? $vevent->DTSTAMP->getDateTime() : new \DateTime());
                    
                $events[$uid] = [
                    'data' => $calendarData,
                    'lastModified' => $lastModified,
                    'uri' => $object['uri'] ?? null
                ];
                $this->logger->debug("Added Nextcloud event with UID: $uid");
            }
        } catch (\Exception $parseError) {
            $this->logger->warning('Failed to parse event data: ' . $parseError->getMessage());
        }
    }

    /**
     * Extract event summary/title from iCal data for debugging
     */
    private function extractEventSummary(string $calendarData): string {
        try {
            $vcalendar = Reader::read($calendarData);
            
            // Handle both single events and VCALENDAR containers
            $vevent = null;
            if ($vcalendar->name === 'VEVENT') {
                $vevent = $vcalendar;
            } else {
                $vevent = $vcalendar->VEVENT;
            }
            
            if ($vevent && isset($vevent->SUMMARY)) {
                return (string)$vevent->SUMMARY;
            }
            
            return '[No Summary]';
        } catch (\Exception $e) {
            return '[Parse Error]';
        }
    }

    /**
     * Extract key event properties for comparison (summary, start time, end time, location)
     */
    private function extractEventProperties(string $calendarData): array {
        try {
            $vcalendar = Reader::read($calendarData);
            
            // Handle both single events and VCALENDAR containers
            $vevent = null;
            if ($vcalendar->name === 'VEVENT') {
                $vevent = $vcalendar;
            } else {
                $vevent = $vcalendar->VEVENT;
            }
            
            if (!$vevent) {
                return [];
            }
            
            $properties = [];
            
            // Summary (title)
            $properties['summary'] = isset($vevent->SUMMARY) ? (string)$vevent->SUMMARY : '';
            
            // Start time
            if (isset($vevent->DTSTART)) {
                $properties['dtstart'] = $vevent->DTSTART->getDateTime()->format('Y-m-d H:i:s');
            } else {
                $properties['dtstart'] = '';
            }
            
            // End time
            if (isset($vevent->DTEND)) {
                $properties['dtend'] = $vevent->DTEND->getDateTime()->format('Y-m-d H:i:s');
            } else {
                $properties['dtend'] = '';
            }
            
            // Location
            $properties['location'] = isset($vevent->LOCATION) ? (string)$vevent->LOCATION : '';
            
            // Description
            $properties['description'] = isset($vevent->DESCRIPTION) ? (string)$vevent->DESCRIPTION : '';
            
            return $properties;
            
        } catch (\Exception $e) {
            $this->logger->warning('Failed to extract event properties: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Compare two events to detect content changes (beyond just LAST-MODIFIED)
     */
    private function hasContentChanged(string $eventData1, string $eventData2): bool {
        $props1 = $this->extractEventProperties($eventData1);
        $props2 = $this->extractEventProperties($eventData2);
        
        // Compare key properties
        $keyFields = ['summary', 'dtstart', 'dtend', 'location', 'description'];
        
        foreach ($keyFields as $field) {
            $val1 = $props1[$field] ?? '';
            $val2 = $props2[$field] ?? '';
            
            if (trim($val1) !== trim($val2)) {
                $this->logger->debug("Content change detected in '$field': '$val1' !== '$val2'");
                return true;
            }
        }
        
        return false;
    }

    /**
     * Find event by content match (same title/summary) instead of just UID
     * Returns [uid => eventData] if found, null otherwise
     */
    private function findEventByContent(string $targetSummary, array $events): ?array {
        foreach ($events as $uid => $eventData) {
            $summary = $this->extractEventSummary($eventData['data']);
            if (trim(strtolower($summary)) === trim(strtolower($targetSummary))) {
                return [$uid => $eventData];
            }
        }
        return null;
    }

    /**
     * Enhanced sync logic that handles content-based duplicates
     */
    private function performIntelligentSync(array $externalEvents, array $nextcloudEvents, $client, $nextcloudCalendar, &$stats): void {
        // Track which events we've already processed to avoid double-processing
        $processedExternal = [];
        $processedNextcloud = [];

        // Step 1: Handle events that exist in both calendars (by UID)
        $commonByUID = array_intersect_key($externalEvents, $nextcloudEvents);
        foreach ($commonByUID as $uid => $extEventData) {
            $ncEventData = $nextcloudEvents[$uid];
            $summary = $this->extractEventSummary($extEventData['data']);
            $extTime = $extEventData['lastModified']->format('Y-m-d H:i:s');
            $ncTime = $ncEventData['lastModified']->format('Y-m-d H:i:s');
            
            if ($extEventData['lastModified'] > $ncEventData['lastModified']) {
                $this->logger->info("🔄 [UID-MATCH] $uid ($summary): External newer ($extTime) → updating NC");
                $this->updateEventInNextcloud($nextcloudCalendar, $uid, $extEventData['data']);
                $stats['updated']++;
            } elseif ($ncEventData['lastModified'] > $extEventData['lastModified']) {
                $this->logger->info("🔄 [UID-MATCH] $uid ($summary): NC newer ($ncTime) → updating External");
                $this->updateEventInExternal($client, $uid, $ncEventData['data']);
                $stats['updated']++;
            } else {
                $this->logger->info("✅ [UID-MATCH] $uid ($summary): Both in sync");
            }
            
            $processedExternal[$uid] = true;
            $processedNextcloud[$uid] = true;
        }

        // Step 2: Handle remaining external events
        foreach ($externalEvents as $uid => $eventData) {
            if (isset($processedExternal[$uid])) continue;
            
            $summary = $this->extractEventSummary($eventData['data']);
            
            // Check if an event with same content exists in Nextcloud
            $contentMatch = $this->findEventByContent($summary, $nextcloudEvents);
            
            if ($contentMatch) {
                $matchUid = array_key_first($contentMatch);
                $matchData = $contentMatch[$matchUid];
                
                if (isset($processedNextcloud[$matchUid])) {
                    // Already processed this NC event, create new one
                    $this->logger->info("➡️ [CONTENT-NEW] External $uid ($summary): Creating in NC (content match already processed)");
                    $this->createEventInNextcloud($nextcloudCalendar, $eventData['data']);
                    $stats['created']++;
                } else {
                    // Update the content-matched event
                    $extTime = $eventData['lastModified']->format('Y-m-d H:i:s');
                    $ncTime = $matchData['lastModified']->format('Y-m-d H:i:s');
                    
                    if ($eventData['lastModified'] > $matchData['lastModified']) {
                        $this->logger->info("🔄 [CONTENT-MATCH] External $uid → NC $matchUid ($summary): External newer ($extTime) → updating NC");
                        $this->updateEventInNextcloud($nextcloudCalendar, $matchUid, $eventData['data']);
                        $stats['updated']++;
                    } else {
                        $this->logger->info("🔄 [CONTENT-MATCH] External $uid → NC $matchUid ($summary): NC newer ($ncTime) → updating External");
                        $this->updateEventInExternal($client, $uid, $matchData['data']);
                        $stats['updated']++;
                    }
                    
                    $processedNextcloud[$matchUid] = true;
                }
            } else {
                // Truly new event
                $this->logger->info("➡️ [NEW] External $uid ($summary): Creating in NC");
                $this->createEventInNextcloud($nextcloudCalendar, $eventData['data']);
                $stats['created']++;
            }
            
            $processedExternal[$uid] = true;
        }

        // Step 3: Handle remaining Nextcloud events
        foreach ($nextcloudEvents as $uid => $eventData) {
            if (isset($processedNextcloud[$uid])) continue;
            
            $summary = $this->extractEventSummary($eventData['data']);
            
            // Check if an event with same content exists in External
            $contentMatch = $this->findEventByContent($summary, $externalEvents);
            
            if ($contentMatch) {
                $matchUid = array_key_first($contentMatch);
                $matchData = $contentMatch[$matchUid];
                
                if (isset($processedExternal[$matchUid])) {
                    // Already processed this External event, create new one
                    $this->logger->info("⬅️ [CONTENT-NEW] NC $uid ($summary): Creating in External (content match already processed)");
                    $this->createEventInExternal($client, $eventData['data']);
                    $stats['created']++;
                } else {
                    // This should have been handled in Step 2, but just in case
                    $this->logger->info("⚠️ [MISSED] NC $uid ($summary): Should have been processed in external loop");
                }
            } else {
                // Truly new event
                $this->logger->info("⬅️ [NEW] NC $uid ($summary): Creating in External");
                $this->createEventInExternal($client, $eventData['data']);
                $stats['created']++;
            }
        }
    }

    /**
     * Implements the Python fetch_changes_via_sync() function
     * Since Nextcloud's high-level calendar API doesn't expose sync-collection directly,
     * we'll simulate incremental sync by comparing with stored state
     */
    private function fetchChangesViaSync($calendar, $lastToken): array {
        return ['deleted' => [], 'newToken' => $lastToken];
    }

    private function addSyncSourceProperty(string $icalData, string $source): string {
        $icalData = preg_replace('/^X-CALENDAR-SYNC-SOURCE:[^\r\n]*\r?\n?/m', '', $icalData);
        return str_replace('END:VEVENT', "X-CALENDAR-SYNC-SOURCE:$source\r\nEND:VEVENT", $icalData);
    }

    private function createEventInNextcloud($calendar, string $eventData): void {
        try {
            $eventData = $this->addSyncSourceProperty($eventData, 'external');
            $vcalendar = Reader::read($eventData);

            $uid = '';
            if ($vcalendar->name === 'VEVENT') {
                $uid = (string)$vcalendar->UID;
            } else {
                $uid = (string)$vcalendar->VEVENT->UID;
            }

            $calendar->createFromString($uid . '.ics', $eventData);
            $this->logger->debug("Successfully created event in Nextcloud: $uid");
            
        } catch (\Exception $e) {
            // Check if it's a duplicate error - this might be expected in some cases
            if (strpos($e->getMessage(), 'already exists') !== false) {
                $this->logger->info('Event already exists in Nextcloud, skipping creation: ' . $e->getMessage());
                // Don't throw - this is not necessarily an error in bidirectional sync
            } else {
                $this->logger->error('Failed to create event in Nextcloud: ' . $e->getMessage());
                throw $e;
            }
        }
    }

    private function createEventInExternal(Client $client, string $eventData): void {
        try {
            $eventData = $this->addSyncSourceProperty($eventData, 'nextcloud');
            $vcalendar = Reader::read($eventData);
            
            // Extract UID
            $uid = '';
            if ($vcalendar->name === 'VEVENT') {
                $uid = (string)$vcalendar->UID;
            } else {
                $uid = (string)$vcalendar->VEVENT->UID;
            }
            
            // Create event on external server (UID must be URL-encoded)
            $client->request('PUT', rawurlencode($uid) . '.ics', $eventData, [
                'Content-Type' => 'text/calendar'
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to create event in external calendar: ' . $e->getMessage());
            throw $e;
        }
    }

    private function updateEventInNextcloud($calendar, string $uid, string $eventData): void {
        try {
            $eventData = $this->addSyncSourceProperty($eventData, 'external');
            $this->logger->debug("Starting updateEventInNextcloud for UID: $uid");
            $calendarId = $calendar->getKey();
            $this->logger->debug("Updating event in calendar ID: $calendarId");

            try {
                $this->calDavBackend->updateCalendarObject($calendarId, $uid . '.ics', $eventData);
                $this->logger->debug("Updated event directly via CalDAV backend: $uid");
            } catch (\Exception $updateException) {
                $this->logger->debug("Direct update failed, trying delete-then-create: " . $updateException->getMessage());

                foreach ($this->calDavBackend->getCalendarObjects($calendarId) as $object) {
                    if (strpos($object['uri'], $uid) !== false) {
                        $this->calDavBackend->deleteCalendarObject($calendarId, $object['uri']);
                        $this->logger->debug("Deleted existing event $uid for update");
                        break;
                    }
                }

                $calendar->createFromString($uid . '.ics', $eventData);
                $this->logger->debug("Created updated event in Nextcloud: $uid");
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to update event in Nextcloud: ' . $e->getMessage());
            throw $e;
        }
    }

    private function updateEventInExternal(Client $client, string $uid, string $eventData): void {
        try {
            $eventData = $this->addSyncSourceProperty($eventData, 'nextcloud');
            $client->request('PUT', rawurlencode($uid) . '.ics', $eventData, [
                'Content-Type' => 'text/calendar'
            ]);
            $this->logger->debug("Updated event in external server: $uid");
        } catch (\Exception $e) {
            $this->logger->error('Failed to update event in external server: ' . $e->getMessage());
            throw $e;
        }
    }

    private function deleteEventInExternal(Client $client, string $uid): void {
        try {
            $client->request('DELETE', rawurlencode($uid) . '.ics');
            $this->logger->debug("Deleted event $uid from external server");
        } catch (\Exception $e) {
            $this->logger->error("Failed to delete event $uid from external server: " . $e->getMessage());
            throw $e;
        }
    }

    private function deleteEventInNextcloud($calendar, string $uid): void {
        try {
            $this->logger->debug("Starting deleteEventInNextcloud for UID: $uid");
            $calendarId = $calendar->getKey();

            // Find the calendar object by UID
            $calendarObjects = $this->calDavBackend->getCalendarObjects($calendarId);
            $objectUri = null;
            
            foreach ($calendarObjects as $object) {
                // Check if this object contains our target UID
                if (strpos($object['uri'], $uid) !== false || $object['uri'] === $uid . '.ics') {
                    $objectUri = $object['uri'];
                    break;
                }
                
                // Fallback: parse the calendar data to find the UID
                if (isset($object['calendardata'])) {
                    try {
                        $vcalendar = \Sabre\VObject\Reader::read($object['calendardata']);
                        $vevent = null;
                        if ($vcalendar->name === 'VEVENT') {
                            $vevent = $vcalendar;
                        } else {
                            $vevent = $vcalendar->VEVENT ?? null;
                        }
                        
                        if ($vevent && (string)$vevent->UID === $uid) {
                            $objectUri = $object['uri'];
                            break;
                        }
                    } catch (\Exception $parseError) {
                        $this->logger->debug("Failed to parse calendar object for UID search: " . $parseError->getMessage());
                        continue;
                    }
                }
            }
            
            if ($objectUri) {
                $this->calDavBackend->deleteCalendarObject($calendarId, $objectUri);
                $this->logger->debug("Deleted event $uid (URI: $objectUri) from Nextcloud");
            } else {
                $this->logger->warning("Could not find calendar object with UID $uid for deletion");
            }
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete event from Nextcloud: ' . $e->getMessage());
            throw $e;
        }
    }

    private function createSyncLog(int $configId, string $syncType, string $status): int {
        $qb = $this->db->getQueryBuilder();
        $qb->insert('caldav_sync_logs')
           ->values([
               'config_id' => $qb->createNamedParameter($configId),
               'sync_type' => $qb->createNamedParameter($syncType),
               'status' => $qb->createNamedParameter($status),
               'started_at' => $qb->createNamedParameter(new \DateTime(), 'datetime')
           ]);

        $qb->executeStatement();
        return $qb->getLastInsertId();
    }

    private function completeSyncLog(int $logId, string $status, ?string $message, array $stats): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update('caldav_sync_logs')
           ->set('status', $qb->createNamedParameter($status))
           ->set('message', $qb->createNamedParameter($message))
           ->set('events_created', $qb->createNamedParameter($stats['created']))
           ->set('events_updated', $qb->createNamedParameter($stats['updated']))
           ->set('events_deleted', $qb->createNamedParameter($stats['deleted']))
           ->set('completed_at', $qb->createNamedParameter(new \DateTime(), 'datetime'))
           ->where($qb->expr()->eq('id', $qb->createNamedParameter($logId)));

        $qb->executeStatement();
    }

    /**
     * Store UIDs from both calendars for deletion detection in next sync
     */
    private function storeUIDs(int $configId, array $externalUIDs, array $nextcloudUIDs): void {
        try {
            $qb = $this->db->getQueryBuilder();
            
            // First, delete existing stored UIDs for this config
            $qb->delete('caldav_sync_state')
               ->where($qb->expr()->eq('config_id', $qb->createNamedParameter($configId)));
            $qb->executeStatement();
            
            // Store external UIDs
            foreach ($externalUIDs as $uid) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('caldav_sync_state')
                   ->values([
                       'config_id' => $qb->createNamedParameter($configId),
                       'event_uid' => $qb->createNamedParameter($uid),
                       'calendar_type' => $qb->createNamedParameter('external'),
                       'last_seen' => $qb->createNamedParameter(new \DateTime(), 'datetime')
                   ]);
                $qb->executeStatement();
            }
            
            // Store nextcloud UIDs
            foreach ($nextcloudUIDs as $uid) {
                $qb = $this->db->getQueryBuilder();
                $qb->insert('caldav_sync_state')
                   ->values([
                       'config_id' => $qb->createNamedParameter($configId),
                       'event_uid' => $qb->createNamedParameter($uid),
                       'calendar_type' => $qb->createNamedParameter('nextcloud'),
                       'last_seen' => $qb->createNamedParameter(new \DateTime(), 'datetime')
                   ]);
                $qb->executeStatement();
            }
            
            $this->logger->debug("Stored " . count($externalUIDs) . " external and " . count($nextcloudUIDs) . " nextcloud UIDs for config $configId");
            
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                $this->logger->info("Sync state table doesn't exist when trying to store UIDs, creating it now");
                $this->createSyncStateTable();
                // Retry the storage operation
                $this->storeUIDs($configId, $externalUIDs, $nextcloudUIDs);
            } else {
                $this->logger->error("Failed to store UIDs: " . $e->getMessage());
                // Don't throw - sync can continue without state storage
            }
        }
    }


    /**
     * Get stored UIDs from previous sync for deletion detection
     */
    private function getStoredUIDs(int $configId): array {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select('event_uid', 'calendar_type')
               ->from('caldav_sync_state')
               ->where($qb->expr()->eq('config_id', $qb->createNamedParameter($configId)));

            $result = $qb->executeQuery();
            $stored = ['external' => [], 'nextcloud' => []];

            while ($row = $result->fetch()) {
                $stored[$row['calendar_type']][] = $row['event_uid'];
            }

            $this->logger->debug("Retrieved " . count($stored['external']) . " external and " . count($stored['nextcloud']) . " nextcloud stored UIDs for config $configId");
            return $stored;
            
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                $this->logger->info("Sync state table doesn't exist, creating it now");
                $this->createSyncStateTable();
                // Return empty arrays for first run
                return ['external' => [], 'nextcloud' => []];
            }
            throw $e;
        }
    }

    /**
     * Create the sync state table using raw SQL as fallback
     */
    private function createSyncStateTable(): void {
        try {
            // Use query builder to get proper table name with prefix
            $qb = $this->db->getQueryBuilder();
            $tableName = $qb->getTableName('caldav_sync_state');
            
            $sql = "CREATE TABLE IF NOT EXISTS $tableName (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `config_id` int(11) NOT NULL,
                `event_uid` varchar(255) NOT NULL,
                `calendar_type` varchar(20) NOT NULL,
                `last_seen` datetime NOT NULL,
                PRIMARY KEY (`id`),
                KEY `caldav_sync_state_config_id` (`config_id`),
                KEY `caldav_sync_state_event_uid` (`event_uid`),
                KEY `caldav_sync_state_calendar_type` (`calendar_type`),
                UNIQUE KEY `caldav_sync_state_unique` (`config_id`,`event_uid`,`calendar_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin";
            
            $this->db->executeStatement($sql);
            $this->logger->info('Successfully created caldav_sync_state table as fallback');
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to create sync state table: ' . $e->getMessage());
            throw $e;
        }
    }

    public function clearHistory(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
           ->from('caldav_sync_configs')
           ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['id'];
        }
        if (empty($ids)) {
            return;
        }
        $qb2 = $this->db->getQueryBuilder();
        $qb2->delete('caldav_sync_logs')
            ->where($qb2->expr()->in(
                'config_id',
                $qb2->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
            ));
        $qb2->executeStatement();
    }

    public function getSyncStatus(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('l.*', 'c.name as config_name')
           ->from('caldav_sync_logs', 'l')
           ->join('l', 'caldav_sync_configs', 'c', $qb->expr()->eq('l.config_id', 'c.id'))
           ->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)))
           ->orderBy('l.started_at', 'DESC')
           ->setMaxResults(10);

        $result = $qb->executeQuery();
        $logs = [];

        while ($row = $result->fetch()) {
            $logs[] = [
                'id' => (int)$row['id'],
                'config_name' => $row['config_name'],
                'sync_type' => $row['sync_type'],
                'status' => $row['status'],
                'message' => $row['message'],
                'events_created' => (int)$row['events_created'],
                'events_updated' => (int)$row['events_updated'],
                'events_deleted' => (int)$row['events_deleted'],
                'started_at' => $row['started_at'],
                'completed_at' => $row['completed_at']
            ];
        }

        return $logs;
    }
}