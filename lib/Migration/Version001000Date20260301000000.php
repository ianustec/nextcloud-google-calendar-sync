<?php

declare(strict_types=1);

namespace OCA\NeuraGoogleCalendarSync\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000Date20260301000000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('neura_gcal_calendar_mapping')) {
            $table = $schema->createTable('neura_gcal_calendar_mapping');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('nc_calendar_id', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('google_calendar_id', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('google_sync_token', 'text', [
                'notnull' => false,
            ]);
            $table->addColumn('nc_ctag', 'string', [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('last_synced_at', 'integer', [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'nc_calendar_id'], 'neura_gcal_cal_user_nc');
            $table->addUniqueIndex(['user_id', 'google_calendar_id'], 'neura_gcal_cal_user_g');
        }

        if (!$schema->hasTable('neura_gcal_event_mapping')) {
            $table = $schema->createTable('neura_gcal_event_mapping');
            $table->addColumn('id', 'bigint', [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', 'string', [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('nc_calendar_id', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('nc_event_uid', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('google_calendar_id', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('google_event_id', 'string', [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('nc_etag', 'string', [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->addColumn('google_etag', 'string', [
                'notnull' => false,
                'length' => 64,
            ]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['nc_calendar_id', 'nc_event_uid'], 'neura_gcal_evt_nc');
            $table->addUniqueIndex(['google_calendar_id', 'google_event_id'], 'neura_gcal_evt_g');
        }

        return $schema;
    }
}
