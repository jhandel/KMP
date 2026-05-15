<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class CreatePlatformAuditRetentionAnchors extends BaseMigration
{
    /**
     * Create retention anchor table for archived audit prefixes.
     *
     * @return void
     */
    public function change(): void
    {
        if ($this->hasTable('platform_audit_retention_anchors')) {
            return;
        }

        $this->table('platform_audit_retention_anchors', ['id' => false])
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('purged_before_event_id', 'integer', [
                'limit' => 11,
                'null' => false,
            ])
            ->addColumn('purged_before_event_hash', 'string', [
                'limit' => 64,
                'null' => false,
            ])
            ->addColumn('next_event_id', 'integer', [
                'default' => null,
                'limit' => 11,
                'null' => true,
            ])
            ->addColumn('next_event_previous_hash', 'string', [
                'default' => null,
                'limit' => 64,
                'null' => true,
            ])
            ->addColumn('archived_path', 'string', [
                'default' => null,
                'limit' => 512,
                'null' => true,
            ])
            ->addColumn('archive_sha256', 'string', [
                'default' => null,
                'limit' => 64,
                'null' => true,
            ])
            ->addColumn('archive_event_count', 'integer', [
                'default' => null,
                'limit' => 11,
                'null' => true,
            ])
            ->addColumn('metadata', 'json', [
                'default' => null,
                'null' => true,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addIndex(['purged_before_event_id'], [
                'name' => 'idx_platform_audit_retention_boundary',
            ])
            ->addIndex(['created'], [
                'name' => 'idx_platform_audit_retention_created',
            ])
            ->create();
    }
}

