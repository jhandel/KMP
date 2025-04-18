<?php

declare(strict_types=1);

use Migrations\BaseSeed;
use Cake\I18n\DateTime;

/**
 * Branches seed.
 */
class InitBranchesSeed extends BaseSeed
{
    /**
     * Run Method.
     *
     * Write your database seeder using this method.
     *
     * More information on writing seeds is available here:
     * https://book.cakephp.org/phinx/0/en/seeding.html
     *
     * @return void
     */
    public function run(): void
    {
        $data = [
            [
                'id' => 1,
                'name' => 'Kingdom',
                'location' => 'Kingdom',
                'parent_id' => NULL,
                'lft' => 1,
                'rght' => 18,
                'created' => DateTime::now(),
                'created_by' => '1',
            ]
        ];

        $table = $this->table('branches');
        $options = $table->getAdapter()->getOptions();
        $options['identity_insert'] = true;
        $table->getAdapter()->setOptions($options);
        $table->insert($data)->saveData();
    }
}