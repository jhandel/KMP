<?php

declare(strict_types=1);

namespace OfficerEventReporting\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use App\Model\Table\BaseTable;

/**
 * Forms Model
 *
 * @property \OfficerEventReporting\Model\Table\FormFieldsTable&\Cake\ORM\Association\HasMany $FormFields
 * @property \OfficerEventReporting\Model\Table\SubmissionsTable&\Cake\ORM\Association\HasMany $Submissions
 * @property \App\Model\Table\MembersTable&\Cake\ORM\Association\BelongsTo $CreatedBy
 * @property \App\Model\Table\MembersTable&\Cake\ORM\Association\BelongsTo $ModifiedBy
 *
 * @method \OfficerEventReporting\Model\Entity\Form newEmptyEntity()
 * @method \OfficerEventReporting\Model\Entity\Form newEntity(array $data, array $options = [])
 * @method array<\OfficerEventReporting\Model\Entity\Form> newEntities(array $data, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\Form get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \OfficerEventReporting\Model\Entity\Form findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\Form patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\OfficerEventReporting\Model\Entity\Form> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\Form|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\Form saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 */
class FormsTable extends BaseTable
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

        $this->setTable("officer_event_reporting_forms");
        $this->setDisplayField("title");
        $this->setPrimaryKey("id");

        $this->hasMany("FormFields", [
            "className" => "OfficerEventReporting.FormFields",
            "foreignKey" => "form_id",
            "dependent" => true,
            "cascadeCallbacks" => true,
        ]);

        $this->hasMany("Submissions", [
            "className" => "OfficerEventReporting.Submissions",
            "foreignKey" => "form_id",
            "dependent" => true,
            "cascadeCallbacks" => true,
        ]);

        $this->belongsTo("CreatedBy", [
            "className" => "Members",
            "foreignKey" => "created_by",
        ]);

        $this->belongsTo("ModifiedBy", [
            "className" => "Members",
            "foreignKey" => "modified_by",
        ]);

        $this->addBehavior("Timestamp");
        $this->addBehavior("Muffin/Footprint.Footprint", [
            "events" => [
                "Model.beforeSave" => [
                    "created_by" => "new",
                    "modified_by" => "always",
                ],
            ],
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
            ->integer("id")
            ->allowEmptyString("id", null, "create");

        $validator
            ->scalar("title")
            ->maxLength("title", 255)
            ->requirePresence("title", "create")
            ->notEmptyString("title");

        $validator
            ->scalar("description")
            ->allowEmptyString("description");

        $validator
            ->scalar("form_type")
            ->maxLength("form_type", 50)
            ->requirePresence("form_type", "create")
            ->notEmptyString("form_type")
            ->inList("form_type", ["ad-hoc", "event", "injury", "equipment-failure"]);

        $validator
            ->scalar("status")
            ->maxLength("status", 20)
            ->requirePresence("status", "create")
            ->notEmptyString("status")
            ->inList("status", ["active", "inactive", "archived"]);

        $validator
            ->scalar("assignment_type")
            ->maxLength("assignment_type", 20)
            ->requirePresence("assignment_type", "create")
            ->notEmptyString("assignment_type")
            ->inList("assignment_type", ["open", "assigned", "office-specific"]);

        $validator
            ->scalar("assigned_members")
            ->allowEmptyString("assigned_members");

        $validator
            ->scalar("assigned_offices")
            ->allowEmptyString("assigned_offices");

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
        $rules->add($rules->existsIn("created_by", "CreatedBy"), ["errorField" => "created_by"]);
        $rules->add($rules->existsIn("modified_by", "ModifiedBy"), ["errorField" => "modified_by"]);

        return $rules;
    }

    /**
     * Find forms available to a specific user
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query object
     * @param array $options Options including 'user_id' and 'member_offices'
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findAvailableToUser(SelectQuery $query, array $options): SelectQuery
    {
        $userId = $options['user_id'] ?? null;
        $memberOffices = $options['member_offices'] ?? [];

        return $query
            ->where(['Forms.status' => 'active'])
            ->where(function ($exp) use ($userId, $memberOffices) {
                // Open forms available to all
                $conditions = $exp->eq('Forms.assignment_type', 'open');
                
                if ($userId) {
                    // Forms assigned to specific user
                    $conditions = $conditions->or(function ($subExp) use ($userId) {
                        return $subExp
                            ->eq('Forms.assignment_type', 'assigned')
                            ->like('Forms.assigned_members', '%"' . $userId . '"%');
                    });
                }

                if (!empty($memberOffices)) {
                    // Forms assigned to user's offices
                    foreach ($memberOffices as $officeId) {
                        $conditions = $conditions->or(function ($subExp) use ($officeId) {
                            return $subExp
                                ->eq('Forms.assignment_type', 'office-specific')
                                ->like('Forms.assigned_offices', '%"' . $officeId . '"%');
                        });
                    }
                }

                return $conditions;
            });
    }
}