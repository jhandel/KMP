<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Platform-scoped runtime service configuration.
 */
class PlatformServiceConfigsTable extends Table
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
        $this->setTable('platform_service_configs');
        $this->setDisplayField('service_name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
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
            ->scalar('service_name')
            ->maxLength('service_name', 64)
            ->requirePresence('service_name', 'create')
            ->notEmptyString('service_name')
            ->inList('service_name', ['email']);
        $validator
            ->scalar('config_key')
            ->maxLength('config_key', 64)
            ->requirePresence('config_key', 'create')
            ->notEmptyString('config_key');
        $validator
            ->scalar('adapter')
            ->maxLength('adapter', 64)
            ->allowEmptyString('adapter');
        $validator
            ->scalar('secret_reference')
            ->maxLength('secret_reference', 512)
            ->allowEmptyString('secret_reference');
        $validator->boolean('is_active');

        return $validator;
    }

    /**
     * Application integrity rules.
     *
     * @param \Cake\ORM\RulesChecker $rules Rules
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->isUnique(['service_name', 'config_key']), ['errorField' => 'config_key']);

        return $rules;
    }
}
