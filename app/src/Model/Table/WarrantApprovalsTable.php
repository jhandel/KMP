<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * WarrantApprovals Model
 *
 * @property \App\Model\Table\WarrantApprovalSetsTable&\Cake\ORM\Association\BelongsTo $WarrantApprovalSets
 * @property \App\Model\Table\MembersTable&\Cake\ORM\Association\BelongsTo $Members
 *
 * @method \App\Model\Entity\WarrantApproval newEmptyEntity()
 * @method \App\Model\Entity\WarrantApproval newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\WarrantApproval> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\WarrantApproval get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\WarrantApproval findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\WarrantApproval patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\WarrantApproval> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\WarrantApproval|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\WarrantApproval saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\WarrantApproval>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\WarrantApproval>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\WarrantApproval>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\WarrantApproval> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\WarrantApproval>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\WarrantApproval>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\WarrantApproval>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\WarrantApproval> deleteManyOrFail(iterable $entities, array $options = [])
 */
class WarrantApprovalsTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('warrant_approvals');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->belongsTo('WarrantApprovalSets', [
            'foreignKey' => 'warrant_approval_set_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Members', [
            'foreignKey' => 'approver_id',
            'joinType' => 'INNER',
        ]);
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('warrant_approval_set_id')
            ->notEmptyString('warrant_approval_set_id');

        $validator
            ->integer('approver_id')
            ->notEmptyString('approver_id');

        $validator
            ->scalar('authorization_token')
            ->maxLength('authorization_token', 255)
            ->requirePresence('authorization_token', 'create')
            ->notEmptyString('authorization_token');

        $validator
            ->dateTime('requested_on')
            ->requirePresence('requested_on', 'create')
            ->notEmptyDateTime('requested_on');

        $validator
            ->dateTime('responded_on')
            ->allowEmptyDateTime('responded_on');

        $validator
            ->boolean('approved')
            ->notEmptyString('approved');

        $validator
            ->scalar('approver_notes')
            ->maxLength('approver_notes', 255)
            ->allowEmptyString('approver_notes');

        return $validator;
    }

    /**
     * Returns a rules checker object that will be used for validating
     * application integrity.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add($rules->existsIn(['warrant_approval_set_id'], 'WarrantApprovalSets'), ['errorField' => 'warrant_approval_set_id']);
        $rules->add($rules->existsIn(['approver_id'], 'Members'), ['errorField' => 'approver_id']);

        return $rules;
    }
}
