<?php

declare(strict_types=1);

use Migrations\BaseMigration;

class CreateOfficeProgress extends BaseMigration
{
    /**
     * Change Method.
     *
     * More information on this method is available here:
     * https://book.cakephp.org/migrations/4/en/migrations.html#the-change-method
     * @return void
     */
    public function change(): void
    {
        $table = $this->table('office_progress', ['id' => false]);

        // Primary key
        $table->addColumn('id', 'integer', [
            'autoIncrement' => true,
            'default' => null,
            'limit' => 11,
            'null' => false,
        ]);

        // Foreign key to gatherings
        $table->addColumn('gathering_id', 'integer', [
            'default' => null,
            'limit' => 11,
            'null' => false,
            'comment' => 'The gathering this progress is associated with'
        ]);

        // Foreign key to gatherings attendance
        $table->addColumn('attendance_id', 'integer', [
            'default' => null,
            'limit' => 11,
            'null' => false,
            'comment' => 'The attendance this progress is associated with'
        ]);

        // Foreign key to office
        $table->addColumn('office_id', 'integer', [
            'default' => null,
            'limit' => 11,
            'null' => false,
            'comment' => 'The office this progress is associated with'
        ]);

        // Foreign key to members 
        $table->addColumn('member_id', 'integer', [
            'default' => null,
            'limit' => 11,
            'null' => true,
            'comment' => 'AMP member account'
        ]);

        // Sort order for display
        $table->addColumn('sort_order', 'integer', [
            'default' => 0,
            'limit' => 11,
            'null' => false,
            'comment' => 'Display order (Crown first, then others)'
        ]);

        // Standard audit fields
        $table->addColumn('created', 'datetime', [
            'default' => null,
            'null' => false,
        ]);

        $table->addColumn('modified', 'datetime', [
            'default' => null,
            'null' => true,
        ]);

        $table->addColumn('created_by', 'integer', [
            'default' => null,
            'limit' => 11,
            'null' => true,
        ]);

        $table->addColumn('modified_by', 'integer', [
            'default' => null,
            'limit' => 11,
            'null' => true,
        ]);

        $table->addColumn('deleted', 'datetime', [
            'default' => null,
            'null' => true,
        ]);

        // Primary key
        $table->addPrimaryKey(['id']);

        // Indexes
        $table->addIndex(['gathering_id']);
        $table->addIndex(['attendance_id']);
        $table->addIndex(['office_id']);
        $table->addIndex(['member_id']);
        $table->addIndex(['sort_order']);
        $table->addIndex(['deleted']);

        // Foreign key constraints
        $table->addForeignKey(
            'gathering_id',
            'gatherings',
            'id',
            [
                'update' => 'NO_ACTION',
                'delete' => 'CASCADE'
            ]
        );
        $table->addForeignKey(
            'attendance_id',
            'gathering_attendances',
            'id',
            [
                'update' => 'NO_ACTION',
                'delete' => 'CASCADE'
            ]
        );

        $table->addForeignKey(
            'office_id',
            'officers_offices',
            'id',
            [
                'update' => 'NO_ACTION',
                'delete' => 'NO_ACTION'
            ]
        );

        $table->addForeignKey(
            'member_id',
            'members',
            'id',
            [
                'update' => 'NO_ACTION',
                'delete' => 'NO_ACTION'
            ]
        );



        $table->create();
    }
}