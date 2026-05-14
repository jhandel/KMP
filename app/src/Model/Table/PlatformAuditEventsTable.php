<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

class PlatformAuditEventsTable extends Table
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
        $this->setTable('platform_audit_events');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);
        $this->belongsTo('PlatformAdmins', ['foreignKey' => 'platform_admin_id']);
        $this->belongsTo('Tenants', ['foreignKey' => 'tenant_id']);
    }
}
