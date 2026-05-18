<?php

declare(strict_types=1);

use Migrations\BaseMigration;

class AddTracksProgressFlagToOffice extends BaseMigration
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
        $table = $this->table('officers_offices')
            ->addColumn('tracks_progress', 'boolean', [
                "default" => false,
                "limit" => null,
                "null" => false,
                'comment' => 'Flag to indicate if the offices tracks progress or not',
            ]);
        $table->update();
    }
}