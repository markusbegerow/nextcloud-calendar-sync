<?php

declare(strict_types=1);

namespace OCA\CalDavSync\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20240907000000 extends SimpleMigrationStep {
    
    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create sync configurations table
        if (!$schema->hasTable('caldav_sync_configs')) {
            $table = $schema->createTable('caldav_sync_configs');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('name', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('url', 'text', [
                'notnull' => true,
            ]);
            $table->addColumn('username', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('password', 'text', [
                'notnull' => true,
            ]);
            $table->addColumn('enabled', 'smallint', [
                'notnull' => true,
                'default' => 1,
                'unsigned' => true,
            ]);
            $table->addColumn('sync_token', 'text', [
                'notnull' => false,
            ]);
            $table->addColumn('last_sync', 'datetime', [
                'notnull' => false,
            ]);
            $table->addColumn('calendar_selection', 'string', [
                'notnull' => true,
                'length' => 50,
                'default' => 'personal'
            ]);
            $table->addColumn('created_at', 'datetime', [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'caldav_sync_configs_user_id');
            $table->addIndex(['enabled'], 'caldav_sync_configs_enabled');
        }

        // Create sync state table for deletion detection
        if (!$schema->hasTable('caldav_sync_state')) {
            $table = $schema->createTable('caldav_sync_state');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('config_id', 'integer', [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('event_uid', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('calendar_type', 'string', [
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('last_seen', 'datetime', [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['config_id'], 'caldav_sync_state_config_id');
            $table->addIndex(['event_uid'], 'caldav_sync_state_event_uid');
            $table->addIndex(['calendar_type'], 'caldav_sync_state_calendar_type');
            $table->addUniqueIndex(['config_id', 'event_uid', 'calendar_type'], 'caldav_sync_state_unique');
        }

        // Create sync logs table
        if (!$schema->hasTable('caldav_sync_logs')) {
            $table = $schema->createTable('caldav_sync_logs');
            $table->addColumn('id', 'integer', [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('config_id', 'integer', [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('sync_type', 'string', [
                'notnull' => true,
                'length' => 50,
            ]);
            $table->addColumn('status', 'string', [
                'notnull' => true,
                'length' => 20,
            ]);
            $table->addColumn('message', 'text', [
                'notnull' => false,
            ]);
            $table->addColumn('events_created', 'integer', [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);
            $table->addColumn('events_updated', 'integer', [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);
            $table->addColumn('events_deleted', 'integer', [
                'notnull' => true,
                'default' => 0,
                'unsigned' => true,
            ]);
            $table->addColumn('started_at', 'datetime', [
                'notnull' => true,
            ]);
            $table->addColumn('completed_at', 'datetime', [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['config_id'], 'caldav_sync_logs_config_id');
            $table->addIndex(['status'], 'caldav_sync_logs_status');
            $table->addIndex(['started_at'], 'caldav_sync_logs_started_at');
        }

        return $schema;
    }
}