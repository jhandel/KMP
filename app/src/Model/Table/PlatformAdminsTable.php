<?php
declare(strict_types=1);

namespace App\Model\Table;

use App\Model\Entity\PlatformAdmin;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class PlatformAdminsTable extends Table
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
     * Initialize table metadata and associations.
     *
     * @param array<string, mixed> $config Config
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('platform_admins');
        $this->setDisplayField('email');
        $this->setPrimaryKey('id');
        $this->addBehavior('Timestamp');
        $this->hasMany('PlatformAdminEmailCodes', ['foreignKey' => 'platform_admin_id']);
        $this->hasMany('PlatformAdminRecoveryCodes', ['foreignKey' => 'platform_admin_id']);
        $this->hasMany('PlatformAdminSessions', ['foreignKey' => 'platform_admin_id']);
        $this->hasMany('PlatformAdminWebauthnCredentials', ['foreignKey' => 'platform_admin_id']);
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
            ->email('email')
            ->requirePresence('email', 'create')
            ->notEmptyString('email');
        $validator
            ->scalar('display_name')
            ->maxLength('display_name', 255)
            ->requirePresence('display_name', 'create')
            ->notEmptyString('display_name');
        $validator
            ->scalar('password_hash')
            ->maxLength('password_hash', 255)
            ->requirePresence('password_hash', 'create')
            ->notEmptyString('password_hash');
        $validator
            ->scalar('status')
            ->inList('status', [
                PlatformAdmin::STATUS_ACTIVE,
                PlatformAdmin::STATUS_DISABLED,
                PlatformAdmin::STATUS_LOCKED,
            ]);
        $validator
            ->scalar('role')
            ->requirePresence('role', 'create')
            ->notEmptyString('role')
            ->inList('role', [
                PlatformAdmin::ROLE_VIEWER,
                PlatformAdmin::ROLE_OPERATOR,
                PlatformAdmin::ROLE_PROVISIONER,
                PlatformAdmin::ROLE_SECURITY_ADMIN,
                PlatformAdmin::ROLE_BREAK_GLASS,
            ]);

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
        $rules->add($rules->isUnique(['email']), ['errorField' => 'email']);

        return $rules;
    }
}
