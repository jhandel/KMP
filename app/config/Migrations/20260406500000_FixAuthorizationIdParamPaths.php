<?php

declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Fix authorizationId param paths in authorization request workflow.
 *
 * The activate-authorization and handle-denial nodes were referencing
 * $.trigger.authorizationId, but the authorizationId isn't in the trigger
 * data — it's created by the validate-request node. Change references to
 * $.nodes.validate-request.result.authorizationId.
 */
class FixAuthorizationIdParamPaths extends AbstractMigration
{
    public function up(): void
    {
        // Load the updated JSON seed
        $jsonPath = ROOT . '/config/Seeds/WorkflowDefinitions/activities-authorization-request.json';
        if (!file_exists($jsonPath)) {
            return;
        }

        $definitionData = file_get_contents($jsonPath);

        // Update versions using ORM to avoid SQL escaping issues
        $versionsTable = \Cake\ORM\TableRegistry::getTableLocator()->get('WorkflowVersions');
        $versions = $versionsTable->find()
            ->where(['workflow_definition_id' => 4])
            ->all();

        foreach ($versions as $version) {
            $version->definition = $definitionData;
            $versionsTable->save($version);
        }

        // Fix pending instances with null entity_id
        $instancesTable = \Cake\ORM\TableRegistry::getTableLocator()->get('WorkflowInstances');
        $instances = $instancesTable->find()
            ->where([
                'entity_type IN' => ['Activities', 'Activities.Authorizations'],
                'status' => 'waiting',
                'entity_id IS' => null,
            ])
            ->all();

        foreach ($instances as $instance) {
            $context = $instance->context ?? [];
            $authId = $context['nodes']['validate-request']['result']['authorizationId'] ?? null;
            if ($authId) {
                $instance->entity_id = (int)$authId;
                $instancesTable->save($instance);
            }
        }
    }

    public function down(): void
    {
        // Not reversible — the old param paths were incorrect
    }
}
