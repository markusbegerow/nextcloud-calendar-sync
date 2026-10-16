<?php

declare(strict_types=1);

namespace OCA\CalDavSync\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1002Date20261010000000 extends SimpleMigrationStep {

    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('caldav_sync_configs');
        if (!$table->hasColumn('auth_type')) {
            $table->addColumn('auth_type', 'string', [
                'notnull' => true,
                'length' => 10,
                'default' => 'digest',
            ]);
        }

        return $schema;
    }
}
