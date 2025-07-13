<?php

declare(strict_types=1);

namespace OfficerEventReporting\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use App\Model\Table\BaseTable;

/**
 * SubmissionValues Model
 *
 * @property \OfficerEventReporting\Model\Table\SubmissionsTable&\Cake\ORM\Association\BelongsTo $Submissions
 * @property \OfficerEventReporting\Model\Table\FormFieldsTable&\Cake\ORM\Association\BelongsTo $FormFields
 *
 * @method \OfficerEventReporting\Model\Entity\SubmissionValue newEmptyEntity()
 * @method \OfficerEventReporting\Model\Entity\SubmissionValue newEntity(array $data, array $options = [])
 * @method array<\OfficerEventReporting\Model\Entity\SubmissionValue> newEntities(array $data, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\SubmissionValue get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \OfficerEventReporting\Model\Entity\SubmissionValue findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\SubmissionValue patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\OfficerEventReporting\Model\Entity\SubmissionValue> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\SubmissionValue|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\SubmissionValue saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 */
class SubmissionValuesTable extends BaseTable
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

        $this->setTable("officer_event_reporting_submission_values");
        $this->setDisplayField("id");
        $this->setPrimaryKey("id");

        $this->belongsTo("Submissions", [
            "className" => "OfficerEventReporting.Submissions",
            "foreignKey" => "submission_id",
        ]);

        $this->belongsTo("FormFields", [
            "className" => "OfficerEventReporting.FormFields",
            "foreignKey" => "form_field_id",
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
            ->scalar("field_value")
            ->allowEmptyString("field_value");

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
        $rules->add($rules->existsIn("submission_id", "Submissions"), ["errorField" => "submission_id"]);
        $rules->add($rules->existsIn("form_field_id", "FormFields"), ["errorField" => "form_field_id"]);

        return $rules;
    }
}