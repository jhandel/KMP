const { createBdd } = require('playwright-bdd');
const { expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const { Given, When, Then } = createBdd();

const APP_ROOT = path.resolve(__dirname, '../../../..');

const SETUP_FIXTURE_PHP = String.raw`
require 'vendor/autoload.php';
require 'config/bootstrap.php';

$input = json_decode((string)getenv('FIXTURE_JSON'), true, 512, JSON_THROW_ON_ERROR);
$locator = \Cake\ORM\TableRegistry::getTableLocator();
$definitions = $locator->get('WorkflowDefinitions');
$branches = $locator->get('Branches');
$offices = $locator->get('Officers.Offices');
$officers = $locator->get('Officers.Officers');
$members = $locator->get('Members');
$roles = $locator->get('Roles');

foreach ([
    'officers-release' => true,
    'officer-hire' => false,
    'warrants-roster-approval' => true,
] as $slug => $isActive) {
    $definition = $definitions->find()->where(['slug' => $slug])->firstOrFail();
    $definition->is_active = $isActive;
    $definitions->saveOrFail($definition);
}

$selectedBranch = null;
$selectedOffice = null;
$branchCandidates = $branches->find()
    ->select(['id', 'public_id', 'name', 'type'])
    ->where(['can_have_officers' => true])
    ->orderBy(['id' => 'ASC'])
    ->all();
$officeCandidates = $offices->find()
    ->select(['id', 'name', 'grants_role_id', 'requires_warrant', 'only_one_per_branch', 'applicable_branch_types'])
    ->where([
        'requires_warrant' => true,
        'grants_role_id IS NOT' => null,
        'deleted IS' => null,
    ])
    ->orderBy(['id' => 'ASC'])
    ->all();

foreach ($branchCandidates as $branch) {
    foreach ($officeCandidates as $office) {
        $applicable = (string)($office->applicable_branch_types ?? '');
        if ($applicable !== '' && strpos($applicable, '"' . $branch->type . '"') === false) {
            continue;
        }
        if ($office->only_one_per_branch) {
            $hasCurrentOfficer = $officers->find()
                ->where([
                    'office_id' => $office->id,
                    'branch_id' => $branch->id,
                    'status' => \App\Model\Entity\ActiveWindowBaseEntity::CURRENT_STATUS,
                ])
                ->count() > 0;
            if ($hasCurrentOfficer) {
                continue;
            }
        }

        $selectedBranch = $branch;
        $selectedOffice = $office;
        break 2;
    }
}

if ($selectedBranch === null || $selectedOffice === null) {
    throw new \RuntimeException('Could not find a branch/office pair for the officer lifecycle fixture.');
}

$token = preg_replace('/[^a-z0-9]/', '', strtolower((string)($input['token'] ?? uniqid('officer', true))));
$member = $members->newEntity([
    'password' => 'TestPassword',
    'sca_name' => 'Officer Workflow ' . substr($token, -10),
    'first_name' => 'Officer',
    'middle_name' => '',
    'last_name' => 'Workflow',
    'street_address' => '123 Test Street',
    'city' => 'Austin',
    'state' => 'TX',
    'zip' => '78701',
    'phone_number' => '5551234567',
    'email_address' => substr($token, 0, 32) . '@ampdemo.com',
    'membership_number' => null,
    'membership_expires_on' => new \Cake\I18n\Date('+1 year'),
    'branch_id' => $selectedBranch->id,
    'parent_name' => '',
    'background_check_expires_on' => new \Cake\I18n\Date('+1 year'),
    'birth_month' => 1,
    'birth_year' => 1985,
    'status' => \App\Model\Entity\Member::STATUS_VERIFIED_MEMBERSHIP,
    'title' => '',
    'pronouns' => '',
    'pronunciation' => '',
    'timezone' => 'America/Chicago',
    'created_by' => 1,
    'modified_by' => 1,
], [
    'accessibleFields' => ['created_by' => true, 'modified_by' => true],
]);

if (!$members->save($member)) {
    throw new \RuntimeException('Fixture member creation failed: ' . json_encode($member->getErrors(), JSON_THROW_ON_ERROR));
}

$role = $roles->get($selectedOffice->grants_role_id, ['fields' => ['id', 'name']]);

echo json_encode([
    'branchId' => (int)$selectedBranch->id,
    'branchPublicId' => (string)$selectedBranch->public_id,
    'branchName' => (string)$selectedBranch->name,
    'officeId' => (int)$selectedOffice->id,
    'officeName' => (string)$selectedOffice->name,
    'roleId' => (int)$role->id,
    'roleName' => (string)$role->name,
    'memberId' => (int)$member->id,
    'memberName' => (string)$member->sca_name,
    'memberEmail' => (string)$member->email_address,
    'startDate' => (new \Cake\I18n\Date('today'))->format('Y-m-d'),
], JSON_THROW_ON_ERROR);
`;

const INSPECT_FIXTURE_PHP = String.raw`
require 'vendor/autoload.php';
require 'config/bootstrap.php';

$input = json_decode((string)getenv('FIXTURE_JSON'), true, 512, JSON_THROW_ON_ERROR);
$locator = \Cake\ORM\TableRegistry::getTableLocator();
$members = $locator->get('Members');
$officers = $locator->get('Officers.Officers');
$memberRoles = $locator->get('MemberRoles');
$warrants = $locator->get('Warrants');
$workflowInstances = $locator->get('WorkflowInstances');
$workflowApprovals = $locator->get('WorkflowApprovals');

$formatDateTime = static function ($value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    if ($value instanceof \DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    return (string)$value;
};

$member = $members->find()
    ->select(['id', 'sca_name', 'email_address'])
    ->where(['email_address' => $input['memberEmail']])
    ->first();

if ($member === null) {
    echo json_encode(['memberFound' => false], JSON_THROW_ON_ERROR);
    return;
}

$officer = $officers->find()
    ->contain(['Offices'])
    ->where([
        'member_id' => $member->id,
        'office_id' => (int)$input['officeId'],
        'branch_id' => (int)$input['branchId'],
    ])
    ->orderBy(['Officers.id' => 'DESC'])
    ->first();

$memberRole = null;
if ($officer?->granted_member_role_id) {
    $memberRole = $memberRoles->find()
        ->contain(['Roles'])
        ->where(['MemberRoles.id' => $officer->granted_member_role_id])
        ->first();
}

$warrant = null;
if ($officer !== null) {
    $warrant = $warrants->find()
        ->where([
            'entity_type' => 'Officers.Officers',
            'entity_id' => $officer->id,
        ])
        ->orderBy(['Warrants.id' => 'DESC'])
        ->first();
}

$workflowInstance = null;
$workflowApproval = null;
if ($warrant?->warrant_roster_id) {
    $instances = $workflowInstances->find()
        ->orderBy(['WorkflowInstances.id' => 'DESC'])
        ->all();

    foreach ($instances as $instance) {
        $context = $instance->context ?? [];
        $triggerRosterId = $context['trigger']['rosterId'] ?? null;
        if ((int)$triggerRosterId !== (int)$warrant->warrant_roster_id) {
            continue;
        }

        $workflowInstance = $instance;
        $workflowApproval = $workflowApprovals->find()
            ->where(['workflow_instance_id' => $instance->id])
            ->orderBy(['WorkflowApprovals.id' => 'DESC'])
            ->first();
        break;
    }
}

echo json_encode([
    'memberFound' => true,
    'memberId' => (int)$member->id,
    'memberName' => (string)$member->sca_name,
    'memberEmail' => (string)$member->email_address,
    'expectedHireSubject' => 'Appointment Notification: ' . $input['officeName'],
    'expectedReleaseSubject' => 'Release from Office Notification: ' . $input['officeName'],
    'expectedWarrantSubject' => $warrant ? 'Warrant Issued: ' . $warrant->name : null,
    'officer' => $officer ? [
        'id' => (int)$officer->id,
        'status' => (string)$officer->status,
        'startOn' => $formatDateTime($officer->start_on),
        'expiresOn' => $formatDateTime($officer->expires_on),
        'revokedReason' => $officer->revoked_reason,
        'revokerId' => $officer->revoker_id !== null ? (int)$officer->revoker_id : null,
        'grantedMemberRoleId' => $officer->granted_member_role_id !== null ? (int)$officer->granted_member_role_id : null,
    ] : null,
    'memberRole' => $memberRole ? [
        'id' => (int)$memberRole->id,
        'roleId' => (int)$memberRole->role_id,
        'roleName' => (string)($memberRole->role->name ?? ''),
        'startOn' => $formatDateTime($memberRole->start_on),
        'expiresOn' => $formatDateTime($memberRole->expires_on),
        'revokerId' => $memberRole->revoker_id !== null ? (int)$memberRole->revoker_id : null,
        'entityType' => $memberRole->entity_type ?? null,
    ] : null,
    'warrant' => $warrant ? [
        'id' => (int)$warrant->id,
        'name' => (string)$warrant->name,
        'status' => (string)$warrant->status,
        'startOn' => $formatDateTime($warrant->start_on),
        'expiresOn' => $formatDateTime($warrant->expires_on),
        'approvedDate' => $formatDateTime($warrant->approved_date),
        'revokedReason' => $warrant->revoked_reason,
        'revokerId' => $warrant->revoker_id !== null ? (int)$warrant->revoker_id : null,
        'memberRoleId' => $warrant->member_role_id !== null ? (int)$warrant->member_role_id : null,
        'warrantRosterId' => $warrant->warrant_roster_id !== null ? (int)$warrant->warrant_roster_id : null,
    ] : null,
    'workflowInstance' => $workflowInstance ? [
        'id' => (int)$workflowInstance->id,
        'status' => (string)$workflowInstance->status,
    ] : null,
    'workflowApproval' => $workflowApproval ? [
        'id' => (int)$workflowApproval->id,
        'status' => (string)$workflowApproval->status,
        'requiredCount' => (int)$workflowApproval->required_count,
        'approvedCount' => (int)$workflowApproval->approved_count,
        'currentApproverId' => $workflowApproval->current_approver_id !== null ? (int)$workflowApproval->current_approver_id : null,
    ] : null,
], JSON_THROW_ON_ERROR);
`;

const normalizeText = (value) => value.replace(/[·\u00B7\u00A0]/g, ' ').replace(/\s+/g, ' ').trim();

const runPhpJson = (script, payload = {}) => {
    const output = execFileSync(
        'php',
        ['-d', 'xdebug.mode=off', '-r', script],
        {
            cwd: APP_ROOT,
            env: {
                ...process.env,
                FIXTURE_JSON: JSON.stringify(payload),
            },
            encoding: 'utf8',
        },
    ).trim();

    return output === '' ? {} : JSON.parse(output);
};

const ensureFixture = (page) => {
    const fixture = page.__officerLifecycleFixture;
    if (!fixture) {
        throw new Error('Officer lifecycle fixture has not been prepared.');
    }

    return fixture;
};

const refreshFixtureState = (page) => {
    const fixture = ensureFixture(page);
    fixture.state = runPhpJson(INSPECT_FIXTURE_PHP, {
        memberEmail: fixture.memberEmail,
        officeId: fixture.officeId,
        officeName: fixture.officeName,
        branchId: fixture.branchId,
    });

    return fixture.state;
};

const waitForFixtureState = async (page, predicate, errorMessage, attempts = 10) => {
    let lastState = null;
    for (let attempt = 0; attempt < attempts; attempt += 1) {
        lastState = refreshFixtureState(page);
        if (predicate(lastState)) {
            return lastState;
        }
        await page.waitForTimeout(1000);
    }

    throw new Error(`${errorMessage}\nLast state: ${JSON.stringify(lastState)}`);
};

const openBranchOfficersTab = async (page) => {
    const fixture = ensureFixture(page);
    await page.goto(`/branches/view/${fixture.branchPublicId}`, { waitUntil: 'networkidle' });
    await page.getByRole('tab', { name: 'Officers', exact: true }).click();
    await expect(page.getByRole('button', { name: /Assign Officer/i })).toBeVisible({ timeout: 15000 });
};

const chooseAutocompleteOption = async (scope, label, queryText, optionText) => {
    const input = scope.getByLabel(label, { exact: true });
    await input.click();
    await input.fill(queryText);
    const option = scope.locator('[role="option"]').filter({ hasText: optionText }).first();
    await expect(option).toBeVisible({ timeout: 15000 });
    await option.click();
};

const setOfficerAssignee = async (modal, fixture) => {
    const assignee = modal.locator('[data-officers-assign-officer-target="assignee"]');
    await assignee.evaluate((element, values) => {
        const hiddenId = element.querySelector('input[name="member_id"]');
        const hiddenText = element.querySelector('input[name="sca_name"]');
        const displayInput = element.querySelector('input[type="text"]');

        if (!hiddenId || !hiddenText || !displayInput) {
            throw new Error('Could not find assignee inputs in officer assignment modal.');
        }

        hiddenId.value = String(values.memberId);
        hiddenText.value = values.memberName;
        displayInput.value = values.memberName;

        for (const target of [hiddenId, hiddenText, displayInput, element]) {
            target.dispatchEvent(new Event('input', { bubbles: true }));
            target.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }, {
        memberId: fixture.memberId,
        memberName: fixture.memberName,
    });
};

const runQueueWorker = () => {
    execFileSync(
        'php',
        ['-d', 'xdebug.mode=off', 'bin/cake', 'queue', 'run', '-q'],
        {
            cwd: APP_ROOT,
            env: process.env,
            stdio: 'pipe',
        },
    );
};

const assertEmailMessage = async (page, subject, recipient, snippets) => {
    await page.goto('http://localhost:8025', { waitUntil: 'networkidle' });
    const emailRow = page.locator(`.subject b:has-text("${subject}")`).first();
    await expect(emailRow).toBeVisible({ timeout: 15000 });
    await emailRow.click();
    const toCell = page.locator('table tr').filter({ hasText: 'To' }).getByRole('link', { name: recipient, exact: true });
    await expect(toCell).toBeVisible();
    const emailBody = normalizeText(await page.locator('#nav-plain-text div').textContent());
    for (const snippet of snippets) {
        expect(emailBody).toContain(normalizeText(snippet));
    }
};

Given('I prepare the officer lifecycle fixture', async ({ page }) => {
    const token = `officerworkflow${Date.now()}`;
    page.__officerLifecycleFixture = runPhpJson(SETUP_FIXTURE_PHP, { token });
    page.__officerLifecycleFixture.releaseReason = 'Workflow release regression coverage';
    refreshFixtureState(page);
});

When('I assign the officer lifecycle member', async ({ page }) => {
    const fixture = ensureFixture(page);
    await openBranchOfficersTab(page);
    await page.getByRole('button', { name: /Assign Officer/i }).click();

    const modal = page.locator('#assignOfficerModal');
    await expect(modal).toBeVisible({ timeout: 10000 });
    await chooseAutocompleteOption(modal, 'Office', fixture.officeName, fixture.officeName);
    await setOfficerAssignee(modal, fixture);
    await modal.getByLabel('Start Date', { exact: true }).fill(fixture.startDate);
    await expect(modal.getByRole('button', { name: /^Submit$/ })).toBeEnabled({ timeout: 10000 });

    await Promise.all([
        page.waitForLoadState('networkidle'),
        modal.getByRole('button', { name: /^Submit$/ }).click(),
    ]);
});

Then('the officer lifecycle should have a pending warrant approval', async ({ page }) => {
    const state = await waitForFixtureState(
        page,
        (current) => Boolean(
            current.officer &&
            current.officer.grantedMemberRoleId &&
            current.warrant &&
            current.warrant.status === 'Pending' &&
            current.workflowApproval &&
            current.workflowApproval.status === 'pending',
        ),
        'Officer lifecycle never reached a pending warrant approval state.',
    );

    expect(state.workflowApproval.requiredCount).toBe(1);
});

When('I approve the officer lifecycle warrant', async ({ page }) => {
    const state = await waitForFixtureState(
        page,
        (current) => Boolean(current.workflowApproval && current.workflowApproval.status === 'pending'),
        'No pending warrant approval was available to approve.',
    );

    await page.goto('/approvals', { waitUntil: 'networkidle' });
    const respondButton = page.locator(
        `button[data-outlet-btn-btn-data-value*='"id":${state.workflowApproval.id}']`,
    ).first();
    await expect(respondButton).toBeVisible({ timeout: 15000 });
    await respondButton.click();

    const modal = page.locator('#approvalResponseModal');
    await expect(modal).toBeVisible({ timeout: 10000 });
    await modal.locator('#decisionApprove').click();
    await Promise.all([
        page.waitForLoadState('networkidle'),
        modal.locator('button[type="submit"]').click(),
    ]);
});

Then('the officer lifecycle should have an active warrant', async ({ page }) => {
    const fixture = ensureFixture(page);
    const state = await waitForFixtureState(
        page,
        (current) => Boolean(
            current.officer &&
            current.officer.status === 'Current' &&
            current.warrant &&
            current.warrant.status === 'Current' &&
            current.warrant.approvedDate &&
            current.memberRole &&
            current.memberRole.id === current.officer.grantedMemberRoleId &&
            current.memberRole.id === current.warrant.memberRoleId,
        ),
        'Officer lifecycle never reached an active warrant state.',
    );

    expect(state.memberRole.roleId).toBe(fixture.roleId);
});

When('I release the officer lifecycle member', async ({ page }) => {
    const fixture = ensureFixture(page);
    await openBranchOfficersTab(page);

    const row = page.locator('table tbody tr').filter({ hasText: fixture.memberName }).first();
    await expect(row).toBeVisible({ timeout: 15000 });
    await row.getByRole('button', { name: 'Release', exact: true }).click();

    const modal = page.locator('#releaseModal');
    await expect(modal).toBeVisible({ timeout: 10000 });
    await modal.getByLabel('Reason for Release', { exact: true }).fill(fixture.releaseReason);
    await Promise.all([
        page.waitForLoadState('networkidle'),
        modal.getByRole('button', { name: /^Submit$/ }).click(),
    ]);
});

When('I process queued emails for the officer lifecycle', async ({ page }) => {
    for (let attempt = 0; attempt < 3; attempt += 1) {
        runQueueWorker();
        await page.waitForTimeout(250);
    }
});

Then('the officer lifecycle database records should show the full lifecycle', async ({ page }) => {
    const fixture = ensureFixture(page);
    const state = await waitForFixtureState(
        page,
        (current) => Boolean(
            current.officer &&
            current.officer.status === 'Released' &&
            current.officer.revokedReason === fixture.releaseReason &&
            current.warrant &&
            current.warrant.status === 'Deactivated' &&
            current.warrant.revokedReason === fixture.releaseReason &&
            current.memberRole &&
            current.memberRole.id === current.officer.grantedMemberRoleId &&
            current.memberRole.id === current.warrant.memberRoleId &&
            current.memberRole.expiresOn &&
            current.memberRole.revokerId,
        ),
        'Officer lifecycle did not finish with the expected released database state.',
    );

    expect(state.officer.startOn).toBeTruthy();
    expect(state.officer.expiresOn).toBeTruthy();
    expect(state.warrant.startOn).toBeTruthy();
    expect(state.warrant.expiresOn).toBeTruthy();
    expect(state.warrant.approvedDate).toBeTruthy();
    expect(state.memberRole.roleId).toBe(fixture.roleId);
    expect(state.memberRole.roleName).toBe(fixture.roleName);
});

Then('I should see the officer lifecycle emails', async ({ page }) => {
    const fixture = ensureFixture(page);
    const state = refreshFixtureState(page);

    await assertEmailMessage(
        page,
        state.expectedHireSubject,
        fixture.memberEmail,
        [fixture.officeName, fixture.branchName],
    );
    await assertEmailMessage(
        page,
        state.expectedWarrantSubject,
        fixture.memberEmail,
        [state.warrant.name],
    );
    await assertEmailMessage(
        page,
        state.expectedReleaseSubject,
        fixture.memberEmail,
        [fixture.releaseReason, fixture.officeName],
    );
});
