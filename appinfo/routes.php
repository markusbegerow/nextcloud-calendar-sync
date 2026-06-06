<?php
return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'sync#sync', 'url' => '/sync', 'verb' => 'POST'],
        ['name' => 'sync#status', 'url' => '/sync/status', 'verb' => 'GET'],
        ['name' => 'sync#clearHistory', 'url' => '/sync/history', 'verb' => 'DELETE'],
        ['name' => 'config#getConfigs', 'url' => '/api/configs', 'verb' => 'GET'],
        ['name' => 'config#createConfig', 'url' => '/api/configs', 'verb' => 'POST'],
        ['name' => 'config#updateConfig', 'url' => '/api/configs/{id}', 'verb' => 'PUT'],
        ['name' => 'config#deleteConfig', 'url' => '/api/configs/{id}', 'verb' => 'DELETE'],
        ['name' => 'config#testConnection', 'url' => '/api/configs/{id}/test', 'verb' => 'POST'],
        ['name' => 'config#testNewConnection',  'url' => '/api/configs/test',      'verb' => 'POST'],
        ['name' => 'config#getUserSettings',   'url' => '/api/user-settings',      'verb' => 'GET'],
        ['name' => 'config#saveUserSettings',  'url' => '/api/user-settings',      'verb' => 'PUT'],
    ]
];