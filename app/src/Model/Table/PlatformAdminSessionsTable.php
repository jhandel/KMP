<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

class PlatformAdminSessionsTable extends Table
{
    /**
     * Use platform datasource.
     *
     * @return string
     */
    public static function defaultConnectionName(): string
    {
        return 'platform';
    }

    /**
     * Initialize table metadata.
     *
     * @param array<string, mixed> $config Config
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('platform_admin_sessions');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->belongsTo('PlatformAdmins', ['foreignKey' => 'platform_admin_id']);
    }
}
