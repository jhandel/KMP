<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine\Actions;

use App\Services\WorkflowEngine\WorkflowContextAwareTrait;
use App\Services\WorkflowRegistry\WorkflowEntityRegistry;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * Core workflow actions: email, notes, entity updates, role assignment, and variable setting.
 */
class CoreActions
{
    use WorkflowContextAwareTrait;

    /**
     * Send an email notification using queued mailer infrastructure.
     *
     * @param array $context Current workflow context
     * @param array $config Action configuration with 'to', 'mailer', 'action', 'vars'
     * @return array Output with 'sent' boolean
     */
    public function sendEmail(array $context, array $config): array
    {
        try {
            $to = $this->resolveValue($config['to'] ?? '', $context);
            $mailerName = $config['mailer'] ?? '';
            $action = $config['action'] ?? '';
            $vars = [];
            foreach (($config['vars'] ?? []) as $key => $val) {
                $vars[$key] = $this->resolveValue($val, $context);
            }

            $queuedJobsTable = TableRegistry::getTableLocator()->get('Queue.QueuedJobs');
            $data = [
                'class' => $mailerName,
                'action' => $action,
                'vars' => array_merge($vars, ['to' => $to]),
            ];
            $queuedJobsTable->createJob('Queue.Mailer', $data);

            return ['sent' => true];
        } catch (\Throwable $e) {
            Log::error('Workflow SendEmail failed: ' . $e->getMessage());

            return ['sent' => false];
        }
    }

    /**
     * Create a note on an entity.
     *
     * @param array $context Current workflow context
     * @param array $config Action configuration with 'entityType', 'entityId', 'subject', 'body'
     * @return array Output with 'noteId'
     */
    public function createNote(array $context, array $config): array
    {
        $notesTable = TableRegistry::getTableLocator()->get('Notes');

        $note = $notesTable->newEntity([
            'topic_model' => $this->resolveValue($config['entityType'], $context),
            'topic_id' => $this->resolveValue($config['entityId'], $context),
            'subject' => $this->resolveValue($config['subject'], $context),
            'body' => $this->resolveValue($config['body'], $context),
            'author_id' => $context['triggeredBy'] ?? null,
        ]);

        $saved = $notesTable->save($note);

        return ['noteId' => $saved ? $saved->id : null];
    }

    /**
     * Update fields on an entity.
     *
     * @param array $context Current workflow context
     * @param array $config Action configuration with 'entityType', 'entityId', 'fields'
     * @return array Output with 'updated' boolean
     */
    public function updateEntity(array $context, array $config): array
    {
        try {
            $tableName = $this->resolveValue($config['entityType'], $context);

            // Validate entity type is registered in WorkflowEntityRegistry
            $registeredEntity = WorkflowEntityRegistry::getEntity($tableName);
            if ($registeredEntity === null) {
                Log::warning("Workflow UpdateEntity rejected: entity type '{$tableName}' is not registered in WorkflowEntityRegistry");

                return ['updated' => false, 'error' => "Entity type '{$tableName}' is not registered for workflow operations."];
            }

            // Validate fields against the registered entity's allowed field list
            $allowedFields = array_keys($registeredEntity['fields'] ?? []);
            if (!empty($allowedFields)) {
                $requestedFields = array_keys($config['fields'] ?? []);
                $disallowed = array_diff($requestedFields, $allowedFields);
                if (!empty($disallowed)) {
                    $fieldList = implode(', ', $disallowed);
                    Log::warning("Workflow UpdateEntity rejected: fields [{$fieldList}] are not in the allowed list for '{$tableName}'");

                    return ['updated' => false, 'error' => "Fields [{$fieldList}] are not allowed for entity type '{$tableName}'."];
                }
            }

            $table = TableRegistry::getTableLocator()->get($tableName);
            $entityId = $this->resolveValue($config['entityId'], $context);
            $entity = $table->get($entityId);
            $fields = [];
            foreach ($config['fields'] as $key => $val) {
                $fields[$key] = $this->resolveValue($val, $context);
            }
            $entity = $table->patchEntity($entity, $fields);
            $result = $table->save($entity);

            return ['updated' => $result !== false];
        } catch (\Throwable $e) {
            Log::error('Workflow UpdateEntity failed: ' . $e->getMessage());

            return ['updated' => false];
        }
    }

    /**
     * Assign a role to a member.
     *
     * @param array $context Current workflow context
     * @param array $config Action configuration with 'memberId', 'roleId', and optional date fields
     * @return array Output with 'memberRoleId'
     */
    public function assignRole(array $context, array $config): array
    {
        $memberRolesTable = TableRegistry::getTableLocator()->get('MemberRoles');

        $data = [
            'member_id' => $this->resolveValue($config['memberId'], $context),
            'role_id' => $this->resolveValue($config['roleId'], $context),
        ];

        if (!empty($config['startOn'])) {
            $data['start_on'] = $config['startOn'];
        }
        if (!empty($config['expiresOn'])) {
            $data['expires_on'] = $config['expiresOn'];
        }
        if (!empty($config['entityType'])) {
            $data['granting_model'] = $config['entityType'];
        }
        if (!empty($config['entityId'])) {
            $data['granting_id'] = $config['entityId'];
        }

        $memberRole = $memberRolesTable->newEntity($data);
        $saved = $memberRolesTable->save($memberRole);

        return ['memberRoleId' => $saved ? $saved->id : null];
    }

    /**
     * Set a variable in the workflow context.
     *
     * @param array $context Current workflow context
     * @param array $config Action configuration with 'name' and 'value'
     * @return array Output with the variable name and value
     */
    public function setVariable(array $context, array $config): array
    {
        return [
            $config['name'] => $this->resolveValue($config['value'], $context),
        ];
    }
}
