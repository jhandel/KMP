<?php

declare(strict_types=1);

namespace App\Command;

use App\Model\Entity\Member;
use App\Model\Entity\Warrant;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\I18n\FrozenDate;
use Cake\I18n\FrozenTime;
use Cake\ORM\TableRegistry;
use RuntimeException;
use Throwable;

/**
 * Build deterministic post-install data used by install-first UI scenarios.
 */
class ConfigureInstallTestDataCommand extends Command
{
    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $adminEmail = (string)(env('KMP_INSTALL_ADMIN_EMAIL') ?: 'admin@test.com');
        $defaultPassword = (string)(env('KMP_INSTALL_ADMIN_PASSWORD') ?: 'Password123');
        $approverEmail = (string)(env('KMP_TEST_APPROVER_EMAIL') ?: 'Earl@test.com');
        $approverScaName = (string)(env('KMP_TEST_APPROVER_SCA_NAME') ?: 'Earl Realm');
        $approverBranchName = (string)(env('KMP_TEST_APPROVER_BRANCH') ?: 'Barony 2');
        $activityGroupName = (string)(env('KMP_TEST_ACTIVITY_GROUP') ?: 'Martial Activities');
        $activityName = (string)(env('KMP_TEST_ACTIVITY_NAME') ?: 'Armored Combat');
        $activityPermissionId = (int)(env('KMP_TEST_ACTIVITY_PERMISSION_ID') ?: 1);

        $membersTable = TableRegistry::getTableLocator()->get('Members');
        $branchesTable = TableRegistry::getTableLocator()->get('Branches');
        $memberRolesTable = TableRegistry::getTableLocator()->get('MemberRoles');
        $warrantsTable = TableRegistry::getTableLocator()->get('Warrants');
        $activityGroupsTable = TableRegistry::getTableLocator()->get('Activities.ActivityGroups');
        $activitiesTable = TableRegistry::getTableLocator()->get('Activities.Activities');
        $authorizationsTable = TableRegistry::getTableLocator()->get('Activities.Authorizations');
        $approvalTable = TableRegistry::getTableLocator()->get('Activities.AuthorizationApprovals');

        try {
            /** @var \App\Model\Entity\Member|null $admin */
            $admin = $membersTable->find()
                ->where(['email_address' => $adminEmail])
                ->first();

            if (!$admin) {
                throw new RuntimeException("Admin member '{$adminEmail}' not found.");
            }

            $adminBirthYear = (int)FrozenDate::now()->year - 30;
            $admin = $membersTable->patchEntity($admin, [
                'birth_month' => $admin->birth_month ?: 1,
                'birth_year' => $admin->birth_year ?: $adminBirthYear,
                'status' => Member::STATUS_VERIFIED_MEMBERSHIP,
                'membership_expires_on' => $admin->membership_expires_on ?: FrozenDate::now()->addYears(5),
                'warrantable' => true,
            ]);
            $admin = $membersTable->saveOrFail($admin);

            $rootBranch = $branchesTable->find()
                ->where(['id' => $admin->branch_id])
                ->first() ?: $branchesTable->find()->where(['parent_id IS' => null])->first();
            if (!$rootBranch) {
                throw new RuntimeException('Root branch not found.');
            }

            $branch = $branchesTable->find()
                ->where(['name' => $approverBranchName])
                ->first();
            if (!$branch) {
                $branch = $branchesTable->newEntity([
                    'name' => $approverBranchName,
                    'location' => $approverBranchName,
                    'parent_id' => $rootBranch->id,
                    'domain' => '',
                ]);
                $branch = $branchesTable->saveOrFail($branch);
                $io->out("Created branch: {$approverBranchName}");
            }

            /** @var \App\Model\Entity\Member|null $approver */
            $approver = $membersTable->find()
                ->where(['email_address' => $approverEmail])
                ->first();

            if (!$approver) {
                $approver = $membersTable->newEntity([
                    'password' => $defaultPassword,
                    'sca_name' => $approverScaName,
                    'first_name' => 'Earl',
                    'last_name' => 'Realm',
                    'street_address' => '1 Installer Test Lane',
                    'city' => 'Austin',
                    'state' => 'TX',
                    'zip' => '78701',
                    'phone_number' => '5555551212',
                    'email_address' => $approverEmail,
                    'branch_id' => $branch->id,
                    'status' => Member::STATUS_VERIFIED_MEMBERSHIP,
                    'membership_expires_on' => FrozenDate::now()->addYears(5),
                    'verified_date' => FrozenTime::now(),
                    'verified_by' => $admin->id,
                    'warrantable' => true,
                ]);
            } else {
                $approver = $membersTable->patchEntity($approver, [
                    'password' => $defaultPassword,
                    'sca_name' => $approverScaName,
                    'first_name' => $approver->first_name ?: 'Earl',
                    'last_name' => $approver->last_name ?: 'Realm',
                    'street_address' => $approver->street_address ?: '1 Installer Test Lane',
                    'city' => $approver->city ?: 'Austin',
                    'state' => $approver->state ?: 'TX',
                    'zip' => $approver->zip ?: '78701',
                    'phone_number' => $approver->phone_number ?: '5555551212',
                    'branch_id' => $branch->id,
                    'status' => Member::STATUS_VERIFIED_MEMBERSHIP,
                    'membership_expires_on' => FrozenDate::now()->addYears(5),
                    'verified_date' => FrozenTime::now(),
                    'verified_by' => $admin->id,
                    'warrantable' => true,
                ]);
            }
            $approver = $membersTable->saveOrFail($approver);
            $membersTable->updateAll([
                'warrantable' => true,
                'birth_month' => 1,
                'birth_year' => (int)FrozenDate::now()->year - 30,
                'status' => Member::STATUS_VERIFIED_MEMBERSHIP,
                'membership_expires_on' => FrozenDate::now()->addYears(5),
                'verified_date' => FrozenTime::now(),
                'verified_by' => $admin->id,
            ], ['id' => $approver->id]);
            $approver = $membersTable->get($approver->id);

            $memberRole = $memberRolesTable->find()
                ->where([
                    'member_id' => $approver->id,
                    'role_id' => 1,
                    'revoker_id IS' => null,
                ])
                ->first();
            if (!$memberRole) {
                $now = FrozenTime::now();
                $memberRolesTable->getConnection()->insert('member_roles', [
                    'member_id' => $approver->id,
                    'role_id' => 1,
                    'approver_id' => $admin->id,
                    'branch_id' => $branch->id,
                    'entity_type' => 'Roles',
                    'entity_id' => 1,
                    'start_on' => $now->subDays(1),
                    'expires_on' => null,
                    'created' => $now,
                    'modified' => $now,
                ]);
                $memberRole = $memberRolesTable->find()
                    ->where([
                        'member_id' => $approver->id,
                        'role_id' => 1,
                        'revoker_id IS' => null,
                    ])
                    ->orderByDesc('id')
                    ->firstOrFail();
                $io->out('Created approver Admin role assignment.');
            }

            $activeWarrant = $warrantsTable->find()
                ->where([
                    'member_role_id' => $memberRole->id,
                    'status' => Warrant::CURRENT_STATUS,
                    'expires_on >' => FrozenTime::now(),
                ])
                ->first();
            if (!$activeWarrant) {
                $now = FrozenTime::now();
                $warrantsTable->getConnection()->insert('warrants', [
                    'name' => 'System Admin Warrant',
                    'member_id' => $approver->id,
                    'warrant_roster_id' => 1,
                    'entity_type' => 'Direct Grant',
                    'entity_id' => -1,
                    'member_role_id' => $memberRole->id,
                    'status' => Warrant::CURRENT_STATUS,
                    'start_on' => $now->subDays(1),
                    'expires_on' => $now->addYears(5),
                    'created' => $now,
                    'modified' => $now,
                ]);
                $io->out('Created active warrant for approver role.');
            }

            $activityGroup = $activityGroupsTable->find()
                ->where(['name' => $activityGroupName])
                ->first();
            if (!$activityGroup) {
                $activityGroup = $activityGroupsTable->newEntity(['name' => $activityGroupName]);
                $activityGroup = $activityGroupsTable->saveOrFail($activityGroup);
                $io->out("Created activity group: {$activityGroupName}");
            }

            $activity = $activitiesTable->find()
                ->where(['name' => $activityName])
                ->first();
            $activityData = [
                'name' => $activityName,
                'term_length' => 365,
                'activity_group_id' => $activityGroup->id,
                'minimum_age' => 0,
                'maximum_age' => 120,
                'num_required_authorizors' => 1,
                'num_required_renewers' => 1,
                'permission_id' => $activityPermissionId,
            ];
            if (!$activity) {
                $activity = $activitiesTable->newEntity($activityData);
            } else {
                $activity = $activitiesTable->patchEntity($activity, $activityData);
            }
            $activitiesTable->saveOrFail($activity);

            $existingAuthorizationIds = $authorizationsTable->find()
                ->where([
                    'member_id' => $admin->id,
                    'activity_id' => $activity->id,
                ])
                ->all()
                ->extract('id')
                ->toList();
            if (!empty($existingAuthorizationIds)) {
                $approvalTable->deleteAll(['authorization_id IN' => $existingAuthorizationIds]);
                $authorizationsTable->deleteAll(['id IN' => $existingAuthorizationIds]);
            }

            $io->success('Install test configurator completed.');
        } catch (Throwable $exception) {
            $io->error('Install test configurator failed: ' . $exception->getMessage());

            return Command::CODE_ERROR;
        }

        return Command::CODE_SUCCESS;
    }
}
