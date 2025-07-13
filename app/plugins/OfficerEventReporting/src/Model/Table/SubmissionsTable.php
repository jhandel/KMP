<?php

declare(strict_types=1);

namespace OfficerEventReporting\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use App\Model\Table\BaseTable;

/**
 * Submissions Model
 *
 * @property \OfficerEventReporting\Model\Table\FormsTable&\Cake\ORM\Association\BelongsTo $Forms
 * @property \App\Model\Table\MembersTable&\Cake\ORM\Association\BelongsTo $SubmittedBy
 * @property \App\Model\Table\MembersTable&\Cake\ORM\Association\BelongsTo $Reviewer
 * @property \OfficerEventReporting\Model\Table\SubmissionValuesTable&\Cake\ORM\Association\HasMany $SubmissionValues
 *
 * @method \OfficerEventReporting\Model\Entity\Submission newEmptyEntity()
 * @method \OfficerEventReporting\Model\Entity\Submission newEntity(array $data, array $options = [])
 * @method array<\OfficerEventReporting\Model\Entity\Submission> newEntities(array $data, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\Submission get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \OfficerEventReporting\Model\Entity\Submission findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\Submission patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\OfficerEventReporting\Model\Entity\Submission> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\Submission|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\Submission saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 */
class SubmissionsTable extends BaseTable
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

        $this->setTable("officer_event_reporting_submissions");
        $this->setDisplayField("id");
        $this->setPrimaryKey("id");

        $this->belongsTo("Forms", [
            "className" => "OfficerEventReporting.Forms",
            "foreignKey" => "form_id",
        ]);

        $this->belongsTo("SubmittedBy", [
            "className" => "Members",
            "foreignKey" => "submitted_by",
        ]);

        $this->belongsTo("Reviewer", [
            "className" => "Members",
            "foreignKey" => "reviewer_id",
        ]);

        $this->hasMany("SubmissionValues", [
            "className" => "OfficerEventReporting.SubmissionValues",
            "foreignKey" => "submission_id",
            "dependent" => true,
            "cascadeCallbacks" => true,
        ]);

        $this->addBehavior("Timestamp");
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
            ->scalar("status")
            ->maxLength("status", 20)
            ->requirePresence("status", "create")
            ->notEmptyString("status")
            ->inList("status", ["submitted", "reviewed", "approved", "rejected"]);

        $validator
            ->scalar("review_notes")
            ->allowEmptyString("review_notes");

        $validator
            ->dateTime("reviewed_at")
            ->allowEmptyDateTime("reviewed_at");

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
        $rules->add($rules->existsIn("form_id", "Forms"), ["errorField" => "form_id"]);
        $rules->add($rules->existsIn("submitted_by", "SubmittedBy"), ["errorField" => "submitted_by"]);
        $rules->add($rules->existsIn("reviewer_id", "Reviewer"), ["errorField" => "reviewer_id"]);

        return $rules;
    }

    /**
     * Find submissions for a specific user
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query object
     * @param array $options Options including 'user_id'
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findForUser(SelectQuery $query, array $options): SelectQuery
    {
        $userId = $options['user_id'] ?? null;
        
        if ($userId) {
            $query->where(['Submissions.submitted_by' => $userId]);
        }
        
        return $query->contain(['Forms', 'SubmittedBy']);
    }

    /**
     * Find submissions that need review by officers
     *
     * @param \Cake\ORM\Query\SelectQuery $query The query object
     * @param array $options Options
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findPendingReview(SelectQuery $query, array $options): SelectQuery
    {
        return $query
            ->where(['Submissions.status' => 'submitted'])
            ->contain(['Forms', 'SubmittedBy'])
            ->order(['Submissions.created' => 'ASC']);
    }
}