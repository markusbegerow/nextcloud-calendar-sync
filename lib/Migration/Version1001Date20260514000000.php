<?php

namespace OCA\CalDavSync\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1001Date20260514000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        $schema = $schemaClosure();
        $table = $schema->getTable('caldav_sync_configs');

        if (!$table->hasColumn('sync_direction')) {
            $table->addColumn('sync_direction', 'string', [
                'length'  => 20,
                'default' => 'bidirectional',
                'notnull' => false,
            ]);
        }

        if (!$table->hasColumn('duplicate_handling')) {
            $table->addColumn('duplicate_handling', 'string', [
                'length'  => 20,
                'default' => 'update_newer',
                'notnull' => false,
            ]);
        }

        return $schema;
    }
}
