<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\TenantAlias;
use App\Services\Tenant\TenantRegistry;
use ArrayObject;
use Cake\Event\EventInterface;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Platform registry table for tenant host/path aliases.
 */
class TenantAliasesTable extends Table
{
    /**
     * Use the platform registry datasource.
     *
     * @return string
     */
    public static function defaultConnectionName(): string
    {
        return 'platform';
    }

    /**
     * Initialize table metadata and associations.
     *
     * @param array<string, mixed> $config Configuration
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('tenant_aliases');
        $this->setDisplayField('value');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');

        $this->belongsTo('Tenants', [
            'foreignKey' => 'tenant_id',
            'joinType' => 'INNER',
        ]);
    }

    /**
     * Normalize host aliases before validation.
     *
     * @param \Cake\Event\EventInterface $event Event
     * @param \ArrayObject $data Request data
     * @param \ArrayObject $options Marshal options
     * @return void
     */
    public function beforeMarshal(EventInterface $event, ArrayObject $data, ArrayObject $options): void
    {
        if (($data['alias_type'] ?? TenantAlias::TYPE_HOST) === TenantAlias::TYPE_HOST && !empty($data['value'])) {
            $data['normalized_value'] = TenantRegistry::normalizeHost((string)$data['value']);
        }
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('tenant_id')
            ->requirePresence('tenant_id', 'create')
            ->notEmptyString('tenant_id');

        $validator
            ->scalar('alias_type')
            ->maxLength('alias_type', 16)
            ->requirePresence('alias_type', 'create')
            ->notEmptyString('alias_type')
            ->inList('alias_type', [TenantAlias::TYPE_HOST, TenantAlias::TYPE_PATH]);

        $validator
            ->scalar('value')
            ->maxLength('value', 255)
            ->requirePresence('value', 'create')
            ->notEmptyString('value');

        $validator
            ->scalar('normalized_value')
            ->maxLength('normalized_value', 255)
            ->requirePresence('normalized_value', 'create')
            ->notEmptyString('normalized_value');

        $validator
            ->integer('priority')
            ->notEmptyString('priority');

        $validator
            ->boolean('is_active')
            ->notEmptyString('is_active');

        return $validator;
    }

    /**
     * Application integrity rules.
     *
     * @param \Cake\ORM\RulesChecker $rules Rules checker
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['tenant_id'], 'Tenants'), ['errorField' => 'tenant_id']);
        $rules->add($rules->isUnique(['alias_type', 'normalized_value']), ['errorField' => 'value']);

        return $rules;
    }
}
