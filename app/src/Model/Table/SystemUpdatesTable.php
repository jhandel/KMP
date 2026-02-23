<?php

declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Persistence for container update history records.
 *
 * @method \App\Model\Entity\SystemUpdate newEmptyEntity()
 * @method \App\Model\Entity\SystemUpdate newEntity(array $data, array $options = [])
 * @method \App\Model\Entity\SystemUpdate get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 */
class SystemUpdatesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('system_updates');
        $this->setDisplayField('to_tag');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Backups', [
            'foreignKey' => 'backup_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('Members', [
            'foreignKey' => 'initiated_by',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('from_tag')
            ->maxLength('from_tag', 100)
            ->requirePresence('from_tag', 'create')
            ->notEmptyString('from_tag');

        $validator
            ->scalar('to_tag')
            ->maxLength('to_tag', 100)
            ->requirePresence('to_tag', 'create')
            ->notEmptyString('to_tag');

        $validator
            ->scalar('status')
            ->inList('status', ['pending', 'running', 'completed', 'failed', 'rolled_back'])
            ->notEmptyString('status');

        $validator
            ->scalar('provider')
            ->maxLength('provider', 50)
            ->requirePresence('provider', 'create')
            ->notEmptyString('provider');

        $validator
            ->integer('initiated_by')
            ->requirePresence('initiated_by', 'create');

        return $validator;
    }
}
