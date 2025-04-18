<?php

declare(strict_types=1);

namespace Officers\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;
use App\Model\Table\BaseTable;
use Cake\ORM\TableRegistry;

/**
 * Offices Model
 *
 * @property \App\Model\Table\DepartmentsTable&\Cake\ORM\Association\BelongsTo $Departments
 * @property \App\Model\Table\OfficersTable&\Cake\ORM\Association\HasMany $Officers
 *
 * @method \App\Model\Entity\Office newEmptyEntity()
 * @method \App\Model\Entity\Office newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\Office> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\Office get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\Office findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\Office patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\Office> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\Office|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\Office saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\Office>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Office>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Office>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Office> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Office>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Office>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\Office>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\Office> deleteManyOrFail(iterable $entities, array $options = [])
 */
class OfficesTable extends BaseTable
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

        $this->setTable('officers_offices');
        $this->setDisplayField('name');
        $this->setPrimaryKey('id');

        $this->belongsTo('Departments', [
            'className' => 'Officers.Departments',
            'foreignKey' => 'department_id',
        ]);
        $this->belongsTo("GrantsRole", [
            "className" => "Roles",
            "foreignKey" => "grants_role_id",
            "joinType" => "LEFT",
        ]);
        $this->belongsTo('DeputyTo', [
            'className' => 'Officers.Offices',
            'foreignKey' => 'deputy_to_id',
            'joinType' => 'LEFT',
        ]);
        $this->belongsTo('ReportsTo', [
            'className' => 'Officers.Offices',
            'foreignKey' => 'reports_to_id',
            'joinType' => 'LEFT',
        ]);
        $this->hasMany('Deputies', [
            'className' => 'Officers.Offices',
            'foreignKey' => 'deputy_to_id',
        ]);
        $this->hasMany('DirectReports', [
            'className' => 'Officers.Offices',
            'foreignKey' => 'reports_to_id',
        ]);
        $this->hasMany("Officers", [
            "className" => "Officers.Officers",
            "foreignKey" => "office_id"
        ]);
        $this->hasMany("CurrentOfficers", [
            "className" => "Officers.Officers",
            "foreignKey" => "office_id",
            "finder" => "current",
        ]);
        $this->hasMany("UpcomingOfficers", [
            "className" => "Officers.Officers",
            "foreignKey" => "office_id",
            "finder" => "upcoming",
        ]);
        $this->hasMany("PreviousOfficers", [
            "className" => "Officers.Officers",
            "foreignKey" => "office_id",
            "finder" => "previous",
        ]);
        $this->addBehavior("Timestamp");
        $this->addBehavior('Muffin/Footprint.Footprint');
        $this->addBehavior("Muffin/Trash.Trash");
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
            ->scalar('name')
            ->maxLength('name', 255)
            ->requirePresence('name', 'create')
            ->notEmptyString('name')
            ->add('name', 'unique', ['rule' => 'validateUnique', 'provider' => 'table']);

        $validator
            ->integer('department_id')
            ->notEmptyString('department_id');

        $validator
            ->boolean('requires_warrant')
            ->notEmptyString('requires_warrant');

        $validator
            ->boolean('only_one_per_branch')
            ->notEmptyString('only_one_per_branch');

        $validator
            ->integer('deputy_to_id')
            ->allowEmptyString('deputy_to_id');

        $validator
            ->integer('reports_to_id')
            ->allowEmptyString('reports_to_id');

        $validator
            ->integer('grants_role_id')
            ->allowEmptyString('grants_role_id');

        $validator
            ->integer('term_length')
            ->requirePresence('term_length', 'create')
            ->notEmptyString('term_length');

        $validator
            ->date('deleted')
            ->allowEmptyDate('deleted');

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
        $rules->add($rules->isUnique(['name']), ['errorField' => 'name']);
        $rules->add($rules->existsIn(['department_id'], 'Departments'), ['errorField' => 'department_id']);

        return $rules;
    }

    public function officesMemberCanWork($user, $branch_id)
    {
        $officersTbl = TableRegistry::getTableLocator()->get("Officers.Officers");
        $userOffices = $officersTbl->find("current")->where(['member_id' => $user->id])->select(['id', 'office_id', 'branch_id'])->toArray();
        $canHireOffices = [];
        foreach ($userOffices as $userOffice) {
            $myOffices[] = $userOffice->office_id;
            if ($user->checkCan("workWithOfficerDeputies", $userOffice, $branch_id, true)) {
                $deputies = $this->find('all')->where(['deputy_to_id' => $userOffice->office_id])->select(['id'])->toArray();
                // add deputies to the list of offices that can be hired
                foreach ($deputies as $deputy) {
                    if (!in_array($deputy->id, $canHireOffices)) {
                        $canHireOffices[] = $deputy->id;
                    }
                }
            }
            if ($user->checkCan("workWithOfficerDirectReports", $userOffice, $branch_id, true)) {
                $deputies = $this->find('all')->where(['OR' => ['deputy_to_id' => $userOffice->office_id, 'reports_to_id' => $userOffice->office_id]])->select(['id'])->toArray();
                // add deputies to the list of offices that can be hired
                foreach ($deputies as $deputy) {
                    if (!in_array($deputy->id, $canHireOffices)) {
                        $canHireOffices[] = $deputy->id;
                    }
                }
            }
            if ($user->checkCan("workWithOfficerReportingTree", $userOffice, $branch_id, true)) {
                $addedOffices = 0;
                $hireThread = [];
                //Get all of the top level office deputies and reports
                $reports = $this->find('all')->where(['OR' => ['deputy_to_id' => $userOffice->office_id, 'reports_to_id' => $userOffice->office_id]])->select(['id', 'reports_to_id'])->toArray();
                foreach ($reports as $report) {
                    if (!in_array($report->id, $canHireOffices)) {
                        $addedOffices++;
                        $hireThread[] = $report->id;
                    }
                }
                // if we added any then we are going to loop back to sql and grab more until we don't add anymore.
                while ($addedOffices != 0) {
                    $addedOffices = 0;
                    $reports = $this->find('all')->where(['OR' => ['deputy_to_id in ' => $hireThread, 'reports_to_id in ' => $hireThread]])->select(['id', 'reports_to_id'])->toArray();
                    foreach ($reports as $report) {
                        if (!in_array($report->id, $hireThread)) {
                            $addedOffices++;
                            $hireThread[] = $report->id;
                        }
                    }
                }
                // now we can add them all to the collected list.
                foreach ($hireThread as $office) {
                    if (!in_array($office, $canHireOffices)) {
                        $canHireOffices[] = $office;
                    }
                }
            }
        }
        return $canHireOffices;
    }
}