<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Providers;

use App\KMP\StaticHelpers;
use App\KMP\TimezoneHelper;
use App\Model\Entity\Warrant;
use App\Model\Entity\WarrantRoster;
use App\Services\WarrantManager\WarrantManagerInterface;
use App\Services\WarrantManager\WarrantRequest;
use App\Services\WorkflowEngine\WorkflowContextAwareTrait;
use Cake\Core\App;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * Workflow action implementations for warrant operations.
 *
 * Delegates roster creation, approval, and decline to WarrantManagerInterface
 * to avoid duplicating warrant lifecycle logic.
 */
class WarrantWorkflowActions
{
    use WorkflowContextAwareTrait;

    private WarrantManagerInterface $warrantManager;

    public function __construct(WarrantManagerInterface $warrantManager)
    {
        $this->warrantManager = $warrantManager;
    }

    /**
     * Create a warrant roster with a single warrant request for approval.
     *
     * Delegates to WarrantManagerInterface::request().
     *
     * @param array $context Current workflow context
     * @param array $config Config with name, description, entityType, entityId, memberId, startOn, expiresOn, memberRoleId
     * @return array Output with rosterId
     */
    public function createWarrantRoster(array $context, array $config): array
    {
        try {
            $name = $this->resolveValue($config['name'], $context);
            $desc = $this->resolveValue($config['description'] ?? '', $context);
            $entityType = $this->resolveValue($config['entityType'], $context);
            $entityId = (int)$this->resolveValue($config['entityId'], $context);
            $memberId = (int)$this->resolveValue($config['memberId'], $context);

            $startOnRaw = $this->resolveValue($config['startOn'], $context);
            $startOn = $startOnRaw instanceof DateTime ? $startOnRaw : new DateTime($startOnRaw);

            $expiresOnRaw = $this->resolveValue($config['expiresOn'] ?? null, $context);
            $expiresOn = null;
            if ($expiresOnRaw !== null) {
                $expiresOn = $expiresOnRaw instanceof DateTime ? $expiresOnRaw : new DateTime($expiresOnRaw);
            }

            $memberRoleId = $this->resolveValue($config['memberRoleId'] ?? null, $context);

            $warrantRequest = new WarrantRequest(
                $name,
                $entityType,
                $entityId,
                $context['triggeredBy'] ?? 0,
                $memberId,
                $startOn,
                $expiresOn,
                $memberRoleId ? (int)$memberRoleId : null,
            );

            $result = $this->warrantManager->request($name, (string)$desc, [$warrantRequest]);

            return ['rosterId' => $result->success ? $result->data : null];
        } catch (\Throwable $e) {
            Log::error('Workflow CreateWarrantRoster failed: ' . $e->getMessage());

            return ['rosterId' => null];
        }
    }

    /**
     * Activate all pending warrants in an approved roster.
     *
     * Syncs workflow approval data to roster tables, then delegates
     * activation to WarrantManagerInterface::activateApprovedRoster().
     *
     * @param array $context Current workflow context
     * @param array $config Config with rosterId
     * @return array Output with activated boolean and count
     */
    public function activateWarrants(array $context, array $config): array
    {
        try {
            $rosterId = (int)$this->resolveValue($config['rosterId'], $context);
            // Derive approver from workflow context — whoever responded to the approval gate
            $approverId = (int)($context['resumeData']['approverId'] ?? $context['triggeredBy'] ?? 0);

            $warrantTable = TableRegistry::getTableLocator()->get('Warrants');
            $rosterTable = TableRegistry::getTableLocator()->get('WarrantRosters');
            $roster = $rosterTable->get($rosterId);

            // If roster was already approved (e.g. via direct WarrantManager path),
            // check if warrants are already Current and treat as success
            if ($roster->status === WarrantRoster::STATUS_APPROVED) {
                $currentCount = $warrantTable->find()
                    ->where([
                        'warrant_roster_id' => $rosterId,
                        'status' => Warrant::CURRENT_STATUS,
                    ])
                    ->count();

                return ['activated' => true, 'count' => $currentCount];
            }

            // Sync workflow approval data to roster tables
            $instanceId = $context['instanceId'] ?? null;
            if ($instanceId) {
                $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');
                $responsesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalResponses');

                $approval = $approvalsTable->find()
                    ->where([
                        'workflow_instance_id' => $instanceId,
                        'status' => 'approved',
                    ])
                    ->first();

                if ($approval) {
                    // Sync approvals_required from workflow config
                    if ($roster->approvals_required !== $approval->required_count) {
                        $roster->approvals_required = $approval->required_count;
                    }

                    // Sync each approval response to roster tables
                    $responses = $responsesTable->find()
                        ->where([
                            'workflow_approval_id' => $approval->id,
                            'decision' => 'approve',
                        ])
                        ->all();

                    foreach ($responses as $response) {
                        $this->warrantManager->syncWorkflowApprovalToRoster(
                            $rosterId,
                            $response->member_id,
                            $response->comment,
                            $response->responded_at,
                        );
                    }

                    // Set roster status to APPROVED and save
                    $roster->status = WarrantRoster::STATUS_APPROVED;
                    $rosterTable->save($roster);
                }
            }

            // Activate warrants via extracted method (no approval bookkeeping)
            $result = $this->warrantManager->activateApprovedRoster($rosterId, $approverId, false);

            if (!$result->success) {
                Log::warning('Workflow ActivateWarrants: activation returned: ' . $result->reason);

                return ['activated' => false, 'count' => 0];
            }

            $count = $warrantTable->find()
                ->where([
                    'warrant_roster_id' => $rosterId,
                    'status' => Warrant::CURRENT_STATUS,
                ])
                ->count();

            return ['activated' => true, 'count' => $count];
        } catch (\Throwable $e) {
            Log::error('Workflow ActivateWarrants failed: ' . $e->getMessage());

            return ['activated' => false, 'count' => 0];
        }
    }

    /**
     * Create and immediately activate a warrant without a roster.
     *
     * @param array $context Current workflow context
     * @param array $config Config with name, memberId, entityType, entityId, startOn, expiresOn, memberRoleId
     * @return array Output with warrantId
     */
    public function createDirectWarrant(array $context, array $config): array
    {
        try {
            $warrantTable = TableRegistry::getTableLocator()->get('Warrants');

            $startOnRaw = $this->resolveValue($config['startOn'], $context);
            $startOn = $startOnRaw instanceof DateTime ? $startOnRaw : new DateTime($startOnRaw);

            $expiresOnRaw = $this->resolveValue($config['expiresOn'] ?? null, $context);
            $expiresOn = null;
            if ($expiresOnRaw !== null) {
                $expiresOn = $expiresOnRaw instanceof DateTime ? $expiresOnRaw : new DateTime($expiresOnRaw);
            }

            $memberRoleId = $this->resolveValue($config['memberRoleId'] ?? null, $context);

            $warrant = $warrantTable->newEmptyEntity();
            $warrant->name = $this->resolveValue($config['name'], $context);
            $warrant->entity_type = $this->resolveValue($config['entityType'], $context);
            $warrant->entity_id = (int)$this->resolveValue($config['entityId'], $context);
            $warrant->requester_id = $context['triggeredBy'] ?? null;
            $warrant->member_id = (int)$this->resolveValue($config['memberId'], $context);
            $warrant->member_role_id = $memberRoleId ? (int)$memberRoleId : null;
            $warrant->start_on = $startOn;
            $warrant->expires_on = $expiresOn;
            $warrant->status = Warrant::CURRENT_STATUS;
            $warrant->approved_date = new DateTime();

            if (!$warrantTable->save($warrant)) {
                Log::error('Workflow CreateDirectWarrant: failed to save warrant');

                return ['warrantId' => null];
            }

            return ['warrantId' => $warrant->id];
        } catch (\Throwable $e) {
            Log::error('Workflow CreateDirectWarrant failed: ' . $e->getMessage());

            return ['warrantId' => null];
        }
    }

    /**
     * Decline a warrant roster and cancel all its pending warrants.
     *
     * Syncs any approve responses that occurred before the decline,
     * then delegates to WarrantManagerInterface::decline().
     *
     * @param array $context Current workflow context
     * @param array $config Config with rosterId, reason, rejecterId
     * @return array Output with declined boolean
     */
    public function declineRoster(array $context, array $config): array
    {
        try {
            $rosterId = (int)$this->resolveValue($config['rosterId'], $context);
            $reason = $this->resolveValue($config['reason'] ?? '', $context);
            if (empty($reason)) {
                $reason = $context['resumeData']['comment'] ?? 'Declined via workflow';
            }
            $rejecterId = $this->resolveValue($config['rejecterId'] ?? null, $context);
            if (!$rejecterId) {
                $rejecterId = $context['resumeData']['approverId'] ?? $context['triggeredBy'] ?? null;
            }
            $rejecterId = (int)$rejecterId;

            // Sync any approve responses that happened before the decline
            $instanceId = $context['instanceId'] ?? null;
            if ($instanceId) {
                $approvalsTable = TableRegistry::getTableLocator()->get('WorkflowApprovals');
                $responsesTable = TableRegistry::getTableLocator()->get('WorkflowApprovalResponses');

                $approval = $approvalsTable->find()
                    ->where([
                        'workflow_instance_id' => $instanceId,
                        'status IN' => ['rejected', 'approved'],
                    ])
                    ->first();

                if ($approval) {
                    $responses = $responsesTable->find()
                        ->where(['workflow_approval_id' => $approval->id])
                        ->all();

                    foreach ($responses as $response) {
                        if ($response->decision === 'approve') {
                            $this->warrantManager->syncWorkflowApprovalToRoster(
                                $rosterId,
                                $response->member_id,
                                $response->comment,
                                $response->responded_at,
                            );
                        }
                    }
                }
            }

            $result = $this->warrantManager->decline($rosterId, $rejecterId, $reason);

            return ['declined' => $result->success];
        } catch (\Throwable $e) {
            Log::error('Workflow DeclineRoster failed: ' . $e->getMessage());

            return ['declined' => false];
        }
    }

    /**
     * Send warrant-issued notification emails to each member in the roster.
     *
     * @param array $context Current workflow context
     * @param array $config Config with rosterId
     * @return array Output with emailsSent count
     */
    public function notifyWarrantIssued(array $context, array $config): array
    {
        try {
            $rosterId = (int)$this->resolveValue($config['rosterId'], $context);

            $warrantTable = TableRegistry::getTableLocator()->get('Warrants');
            $warrants = $warrantTable->find()
                ->contain(['Members'])
                ->where([
                    'Warrants.warrant_roster_id' => $rosterId,
                    'Warrants.status' => Warrant::CURRENT_STATUS,
                ])
                ->all();

            $queuedJobsTable = TableRegistry::getTableLocator()->get('Queue.QueuedJobs');
            $mailerClass = App::className('KMP', 'Mailer', 'Mailer');
            $useQueue = (StaticHelpers::getAppSetting('Email.UseQueue', 'no', null, true) === 'yes');
            $sent = 0;

            foreach ($warrants as $warrant) {
                if (empty($warrant->member->email_address)) {
                    continue;
                }

                $vars = [
                    'to' => $warrant->member->email_address,
                    'memberScaName' => $warrant->member->sca_name,
                    'warrantName' => $warrant->name,
                    'warrantStart' => TimezoneHelper::formatDate($warrant->start_on),
                    'warrantExpires' => TimezoneHelper::formatDate($warrant->expires_on),
                ];

                $data = [
                    'class' => $mailerClass,
                    'action' => 'notifyOfWarrant',
                    'vars' => $vars,
                ];

                if ($useQueue) {
                    $queuedJobsTable->createJob('Queue.Mailer', $data);
                } else {
                    try {
                        $mailer = new $mailerClass();
                        $mailer->send('notifyOfWarrant', [
                            $vars['to'],
                            $vars['memberScaName'],
                            $vars['warrantName'],
                            $vars['warrantStart'],
                            $vars['warrantExpires'],
                        ]);
                    } catch (\Throwable $mailErr) {
                        Log::error('Workflow NotifyWarrantIssued mail send failed: ' . $mailErr->getMessage());
                    }
                }
                $sent++;
            }

            return ['emailsSent' => $sent];
        } catch (\Throwable $e) {
            Log::error('Workflow NotifyWarrantIssued failed: ' . $e->getMessage());

            return ['emailsSent' => 0];
        }
    }
}
