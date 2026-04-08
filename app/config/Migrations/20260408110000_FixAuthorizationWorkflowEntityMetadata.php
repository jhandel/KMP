<?php

declare(strict_types=1);

use Cake\ORM\TableRegistry;
use Migrations\AbstractMigration;

/**
 * Correct authorization workflow entity metadata and backfill existing instances.
 */
class FixAuthorizationWorkflowEntityMetadata extends AbstractMigration
{
    private const WORKFLOW_SLUG = 'activities-authorization-request';
    private const ENTITY_TYPE = 'Activities.Authorizations';

    public function up(): void
    {
        $definitionsTable = TableRegistry::getTableLocator()->get('WorkflowDefinitions');
        $definition = $definitionsTable->find()
            ->where(['slug' => self::WORKFLOW_SLUG])
            ->first();

        if ($definition === null) {
            return;
        }

        if ($definition->entity_type !== self::ENTITY_TYPE) {
            $definition->entity_type = self::ENTITY_TYPE;
            $definitionsTable->saveOrFail($definition);
        }

        $instancesTable = TableRegistry::getTableLocator()->get('WorkflowInstances');
        $instances = $instancesTable->find()
            ->where(['workflow_definition_id' => $definition->id])
            ->all();

        foreach ($instances as $instance) {
            $context = $instance->context ?? [];
            $triggerData = is_array($context['trigger'] ?? null) ? $context['trigger'] : [];
            $nodeResult = $context['nodes']['validate-request']['result'] ?? [];
            $resolvedAuthorizationId = $triggerData['authorizationId']
                ?? $nodeResult['authorizationId']
                ?? $instance->entity_id;

            $dirty = false;

            if ($instance->entity_type !== self::ENTITY_TYPE) {
                $instance->entity_type = self::ENTITY_TYPE;
                $dirty = true;
            }

            if ($instance->entity_id === null && is_numeric($resolvedAuthorizationId)) {
                $instance->entity_id = (int)$resolvedAuthorizationId;
                $dirty = true;
            }

            if (is_numeric($resolvedAuthorizationId) && ($triggerData['authorizationId'] ?? null) === null) {
                $context['trigger'] = $triggerData + ['authorizationId' => (int)$resolvedAuthorizationId];
                $instance->context = $context;
                $dirty = true;
            }

            if ($dirty) {
                $instancesTable->saveOrFail($instance);
            }
        }
    }

    public function down(): void
    {
        // Not reversible.
    }
}
