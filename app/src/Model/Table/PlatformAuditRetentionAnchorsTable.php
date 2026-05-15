<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;

/**
 * Chain anchor records created when old audit rows are archived/purged.
 */
class PlatformAuditRetentionAnchorsTable extends Table
{
    /**
     * @return string
     */
    public static function defaultConnectionName(): string
    {
        return 'platform';
    }

    /**
     * @param array<string, mixed> $config Table config
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('platform_audit_retention_anchors');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp', ['events' => ['Model.beforeSave' => ['created' => 'new']]]);
    }
}

