<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Platform-managed encrypted secrets.
 */
class PlatformSecretsTable extends Table
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
        $this->setTable('platform_secrets');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->belongsTo('PlatformAdmins', [
            'foreignKey' => 'created_by_platform_admin_id',
        ]);
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
            ->scalar('name')
            ->maxLength('name', 255)
            ->requirePresence('name', 'create')
            ->notEmptyString('name')
            ->regex(
                'name',
                '/^[A-Za-z0-9_.\/:-]+$/',
                'Use letters, numbers, slash, colon, dot, underscore, or hyphen.',
            );

        $validator
            ->scalar('encrypted_value')
            ->requirePresence('encrypted_value', 'create')
            ->notEmptyString('encrypted_value');

        $validator
            ->scalar('key_version')
            ->maxLength('key_version', 64)
            ->requirePresence('key_version', 'create')
            ->notEmptyString('key_version');

        $validator
            ->scalar('description')
            ->maxLength('description', 512)
            ->allowEmptyString('description');

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
        $rules->add($rules->isUnique(['name']), ['errorField' => 'name']);

        return $rules;
    }
}
