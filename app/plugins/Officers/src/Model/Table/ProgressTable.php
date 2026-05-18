<?php

declare(strict_types=1);

namespace Officers\Model\Table;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * Progress Model
 *
 * 
 *
 * @property \App\Model\Table\GatheringsTable&\Cake\ORM\Association\BelongsTo $Gatherings
 * @property \App\Model\Table\GatheringAttendancesTable&\Cake\ORM\Association\BelongsTo $Attendances
 * @property \App\Model\Table\MembersTable&\Cake\ORM\Association\BelongsTo $Members
 * @property \Officers\Model\Table\OfficesTable&\Cake\ORM\Association\BelongsTo $Offices
 * 
 * 
 */
class ProgressTable extends Table
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

        $this->setTable('office_progress');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
        $this->addBehavior('Muffin/Footprint.Footprint');
        $this->addBehavior('Muffin/Trash.Trash', [
            'field' => 'deleted',
        ]);

        $this->belongsTo('Gatherings', [
            'foreignKey' => 'gathering_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Attendances', [
            'className' => 'GatheringAttendances',
            'foreignKey' => 'attendance_id',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('Offices', [
            'className' => ('Officers.Offices'),
            'foreignKey' => 'office_id',
            'joinType' => 'INNER',
        ]);

        $this->belongsTo('Members', [
            'foreignKey' => 'member_id',
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
            ->integer('gathering_id')
            ->requirePresence('gathering_id', 'create')
            ->notEmptyString('gathering_id');
        $validator
            ->integer('attendance_id')
            ->allowEmptyString('attendance_id');
        $validator
            ->integer('office_id')
            ->allowEmptyString('office_id');
        $validator
            ->integer('member_id')
            ->allowEmptyString('member_id');


        $validator
            ->integer('sort_order')
            ->notEmptyString('sort_order');

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
        $rules->add($rules->existsIn(['gathering_id'], 'Gatherings'), ['errorField' => 'gathering_id']);
        $rules->add($rules->existsIn(['attendance_id'], 'Attendances'), ['errorField' => 'attendance_id']);
        $rules->add($rules->existsIn(['office_id'], 'Offices'), ['errorField' => 'office_id']);
        $rules->add($rules->existsIn(['member_id'], 'Members'), ['errorField' => 'member_id']);


        return $rules;
    }
}