<?php

declare(strict_types=1);

namespace OfficerEventReporting\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use App\Model\Table\BaseTable;

/**
 * FormFields Model
 *
 * @property \OfficerEventReporting\Model\Table\FormsTable&\Cake\ORM\Association\BelongsTo $Forms
 * @property \OfficerEventReporting\Model\Table\SubmissionValuesTable&\Cake\ORM\Association\HasMany $SubmissionValues
 *
 * @method \OfficerEventReporting\Model\Entity\FormField newEmptyEntity()
 * @method \OfficerEventReporting\Model\Entity\FormField newEntity(array $data, array $options = [])
 * @method array<\OfficerEventReporting\Model\Entity\FormField> newEntities(array $data, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\FormField get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \OfficerEventReporting\Model\Entity\FormField findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\FormField patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\OfficerEventReporting\Model\Entity\FormField> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\FormField|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \OfficerEventReporting\Model\Entity\FormField saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 */
class FormFieldsTable extends BaseTable
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

        $this->setTable("officer_event_reporting_form_fields");
        $this->setDisplayField("field_label");
        $this->setPrimaryKey("id");

        $this->belongsTo("Forms", [
            "className" => "OfficerEventReporting.Forms",
            "foreignKey" => "form_id",
        ]);

        $this->hasMany("SubmissionValues", [
            "className" => "OfficerEventReporting.SubmissionValues",
            "foreignKey" => "form_field_id",
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
            ->scalar("field_name")
            ->maxLength("field_name", 100)
            ->requirePresence("field_name", "create")
            ->notEmptyString("field_name")
            ->alphaNumeric("field_name", "Field name must be alphanumeric");

        $validator
            ->scalar("field_label")
            ->maxLength("field_label", 255)
            ->requirePresence("field_label", "create")
            ->notEmptyString("field_label");

        $validator
            ->scalar("field_type")
            ->maxLength("field_type", 50)
            ->requirePresence("field_type", "create")
            ->notEmptyString("field_type")
            ->inList("field_type", [
                "text", "textarea", "select", "radio", "checkbox", 
                "date", "datetime", "file", "email", "number"
            ]);

        $validator
            ->scalar("field_options")
            ->allowEmptyString("field_options");

        $validator
            ->boolean("is_required")
            ->notEmptyString("is_required");

        $validator
            ->integer("sort_order")
            ->notEmptyString("sort_order");

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

        return $rules;
    }
}