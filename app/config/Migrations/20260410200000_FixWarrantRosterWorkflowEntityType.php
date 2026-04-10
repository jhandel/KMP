<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Fix entity_type on warrants-roster-approval workflow definition and instances.
 *
 * The seed incorrectly set entity_type to 'Warrants' instead of 'WarrantRosters',
 * causing the approval context renderer to fail canRender() and the unified
 * approvals page to show generic/missing info for warrant roster approvals.
 */
class FixWarrantRosterWorkflowEntityType extends AbstractMigration
{
    public function up(): void
    {
        // Fix the workflow definition
        $this->execute(
            "UPDATE workflow_definitions SET entity_type = 'WarrantRosters' " .
            "WHERE slug = 'warrants-roster-approval' AND entity_type = 'Warrants'"
        );

        // Fix any existing workflow instances created from this definition
        $this->execute(
            "UPDATE workflow_instances wi " .
            "INNER JOIN workflow_definitions wd ON wi.workflow_definition_id = wd.id " .
            "SET wi.entity_type = 'WarrantRosters' " .
            "WHERE wd.slug = 'warrants-roster-approval' AND wi.entity_type = 'Warrants'"
        );
    }

    public function down(): void
    {
        // Revert to original (incorrect) value
        $this->execute(
            "UPDATE workflow_definitions SET entity_type = 'Warrants' " .
            "WHERE slug = 'warrants-roster-approval' AND entity_type = 'WarrantRosters'"
        );

        $this->execute(
            "UPDATE workflow_instances wi " .
            "INNER JOIN workflow_definitions wd ON wi.workflow_definition_id = wd.id " .
            "SET wi.entity_type = 'Warrants' " .
            "WHERE wd.slug = 'warrants-roster-approval' AND wi.entity_type = 'WarrantRosters'"
        );
    }
}
