<?php

declare(strict_types=1);

use Migrations\AbstractMigration;
use App\Migrations\CrossEngineMigrationTrait;

/**
 * Fix activities-authorization-request workflow definition:
 * adds missing approverId and isRenewal params to the validate-request node.
 */
class FixAuthorizationRequestWorkflowParams extends AbstractMigration
{
    use CrossEngineMigrationTrait;

    public function up(): void
    {
        // Update published versions for the authorization request workflow
        $rows = $this->fetchAll(
            "SELECT wv.id, wv.definition
             FROM workflow_versions wv
             JOIN workflow_definitions wd ON wv.workflow_definition_id = wd.id
             WHERE wd.slug = 'activities-authorization-request'"
        );

        foreach ($rows as $row) {
            $definition = json_decode($row['definition'], true);
            if (!$definition || !isset($definition['nodes']['validate-request']['config']['params'])) {
                continue;
            }

            $params = &$definition['nodes']['validate-request']['config']['params'];

            // Add missing params if not already present
            if (!isset($params['approverId'])) {
                $params['approverId'] = '$.trigger.approverId';
            }
            if (!isset($params['isRenewal'])) {
                $params['isRenewal'] = '$.trigger.isRenewal';
            }

            $encoded = json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $this->execute(sprintf(
                "UPDATE workflow_versions SET definition = '%s' WHERE id = %d",
                $this->sqlEscape($encoded),
                (int)$row['id']
            ));
        }
    }

    public function down(): void
    {
        // No rollback — the params were always supposed to be there
    }
}
