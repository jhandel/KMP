<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Providers;

use App\KMP\StaticHelpers;
use App\KMP\TimezoneHelper;
use App\Model\Entity\Warrant;
use App\Model\Entity\WarrantRoster;
use App\Services\WorkflowEngine\Conditions\CoreConditions;
use Cake\Core\App;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * Workflow action implementations for warrant operations.
 */
class WarrantWorkflowActions
{
    /**
     * Resolve a config value from context if it starts with '$.'.
     *
     * @param mixed $value Raw value or context path
     * @param array $context Current workflow context
     * @return mixed
     */
    private function resolveValue(mixed $value, array $context): mixed
    {
        if (is_string($value) && str_starts_with($value, '$.')) {
            return CoreConditions::resolveFieldPath($context, $value);
        }

        return $value;
    }

    /**
     * Create a warrant roster with a single warrant request for approval.
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

            $rosterTable = TableRegistry::getTableLocator()->get('WarrantRosters');
            $warrantTable = TableRegistry::getTableLocator()->get('Warrants');
            $connection = $rosterTable->getConnection();

            $connection->begin();

            // Create the roster
            $roster = $rosterTable->newEmptyEntity();
            $roster->created_on = new DateTime();
            $roster->status = WarrantRoster::STATUS_PENDING;
            $roster->name = $name;
            $roster->description = $desc;
            $roster->approvals_required = StaticHelpers::getAppSetting('Warrant.RosterApprovalsRequired', '2');

            if (!$rosterTable->save($roster)) {
                $connection->rollback();
                Log::error('Workflow CreateWarrantRoster: failed to save roster');

                return ['rosterId' => null];
            }

            // Create the warrant entry
            $warrant = $warrantTable->newEmptyEntity();
            $warrant->name = $name;
            $warrant->entity_type = $entityType;
            $warrant->entity_id = $entityId;
            $warrant->requester_id = $context['triggeredBy'] ?? null;
            $warrant->member_id = $memberId;
            $warrant->member_role_id = $memberRoleId ? (int)$memberRoleId : null;
            $warrant->start_on = $startOn;
            $warrant->expires_on = $expiresOn;
            $warrant->status = Warrant::PENDING_STATUS;
            $warrant->warrant_roster_id = $roster->id;

            if (!$warrantTable->save($warrant)) {
                $connection->rollback();
                Log::error('Workflow CreateWarrantRoster: failed to save warrant');

                return ['rosterId' => null];
            }

            $connection->commit();

            return ['rosterId' => $roster->id];
        } catch (\Throwable $e) {
            Log::error('Workflow CreateWarrantRoster failed: ' . $e->getMessage());

            return ['rosterId' => null];
        }
    }

    /**
     * Activate all pending warrants in an approved roster.
     *
     * @param array $context Current workflow context
     * @param array $config Config with rosterId, approverId
     * @return array Output with activated boolean and count
     */
    public function activateWarrants(array $context, array $config): array
    {
        try {
            $rosterId = (int)$this->resolveValue($config['rosterId'], $context);
            // Get approverId from config, or from resume data, or from triggeredBy
            $approverId = $this->resolveValue($config['approverId'] ?? null, $context);
            if (!$approverId) {
                $approverId = $context['resumeData']['approverId'] ?? $context['triggeredBy'] ?? null;
            }
            $approverId = (int)$approverId;

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

            if ($roster->status !== WarrantRoster::STATUS_PENDING) {
                return ['activated' => false, 'count' => 0];
            }

            $warrants = $warrantTable->find()
                ->where([
                    'warrant_roster_id' => $rosterId,
                    'status' => Warrant::PENDING_STATUS,
                ])
                ->all();

            $connection = $rosterTable->getConnection();
            $connection->begin();

            $count = 0;
            $now = new DateTime();

            foreach ($warrants as $warrant) {
                $warrant->status = Warrant::CURRENT_STATUS;
                $warrant->approved_date = $now;
                if ($warrant->start_on === null || $warrant->start_on < $now) {
                    $warrant->start_on = $now;
                }

                if (!$warrantTable->save($warrant)) {
                    $connection->rollback();
                    Log::error('Workflow ActivateWarrants: failed to activate warrant #' . $warrant->id);

                    return ['activated' => false, 'count' => 0];
                }

                // Expire prior warrants for the same entity/member
                $warrantTable->updateAll(
                    [
                        'expires_on' => $warrant->start_on,
                        'revoked_reason' => 'New Warrant Approved',
                        'revoker_id' => $approverId,
                    ],
                    [
                        'entity_type' => $warrant->entity_type,
                        'entity_id' => $warrant->entity_id,
                        'member_id' => $warrant->member_id,
                        'status' => Warrant::CURRENT_STATUS,
                        'expires_on >=' => $warrant->start_on,
                        'start_on <=' => $warrant->start_on,
                        'id !=' => $warrant->id,
                    ],
                );

                $count++;
            }

            $roster->status = WarrantRoster::STATUS_APPROVED;
            if (!$rosterTable->save($roster)) {
                $connection->rollback();

                return ['activated' => false, 'count' => 0];
            }

            $connection->commit();

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
            // Get rejecterId from config, or from resume data, or from triggeredBy
            $rejecterId = $this->resolveValue($config['rejecterId'] ?? null, $context);
            if (!$rejecterId) {
                $rejecterId = $context['resumeData']['approverId'] ?? $context['triggeredBy'] ?? null;
            }
            $rejecterId = (int)$rejecterId;

            $rosterTable = TableRegistry::getTableLocator()->get('WarrantRosters');
            $warrantTable = TableRegistry::getTableLocator()->get('Warrants');
            $roster = $rosterTable->get($rosterId);

            if ($roster->status !== WarrantRoster::STATUS_PENDING) {
                return ['declined' => false];
            }

            $connection = $rosterTable->getConnection();
            $connection->begin();

            // Cancel all pending warrants in the roster
            $warrants = $warrantTable->find()
                ->where([
                    'warrant_roster_id' => $rosterId,
                    'status' => Warrant::PENDING_STATUS,
                ])
                ->all();

            foreach ($warrants as $warrant) {
                $warrant->status = Warrant::CANCELLED_STATUS;
                $warrant->revoked_reason = 'Warrant Roster Declined: ' . $reason;
                $warrant->revoker_id = $rejecterId;
                if (!$warrantTable->save($warrant)) {
                    $connection->rollback();

                    return ['declined' => false];
                }
            }

            $roster->status = WarrantRoster::STATUS_DECLINED;
            if (!$rosterTable->save($roster)) {
                $connection->rollback();

                return ['declined' => false];
            }

            // Add a decline note
            $notesTable = TableRegistry::getTableLocator()->get('Notes');
            $note = $notesTable->newEmptyEntity();
            $note->entity_type = 'WarrantRosters';
            $note->entity_id = $roster->id;
            $note->subject = 'Warrant Roster declined';
            $note->body = $reason;
            $note->author_id = $rejecterId;
            if (!$notesTable->save($note)) {
                $connection->rollback();

                return ['declined' => false];
            }

            $connection->commit();

            return ['declined' => true];
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
