<?php

declare(strict_types=1);

use Migrations\AbstractMigration;

class CreateSystemUpdates extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('system_updates');
        $table
            ->addColumn('from_tag', 'string', [
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('to_tag', 'string', [
                'limit' => 100,
                'null' => false,
            ])
            ->addColumn('channel', 'string', [
                'limit' => 50,
                'null' => true,
            ])
            ->addColumn('provider', 'string', [
                'limit' => 50,
                'null' => false,
            ])
            ->addColumn('status', 'string', [
                'limit' => 20,
                'default' => 'pending',
                'null' => false,
            ])
            ->addColumn('backup_id', 'integer', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('error_message', 'text', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('initiated_by', 'integer', [
                'null' => false,
            ])
            ->addColumn('started_at', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('completed_at', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('created', 'datetime', [
                'null' => false,
            ])
            ->addColumn('modified', 'datetime', [
                'null' => false,
            ])
            ->addIndex(['status'])
            ->addIndex(['created'])
            ->addForeignKey('backup_id', 'backups', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
            ])
            ->addForeignKey('initiated_by', 'members', 'id', [
                'delete' => 'NO_ACTION',
                'update' => 'NO_ACTION',
            ])
            ->create();
    }
}
