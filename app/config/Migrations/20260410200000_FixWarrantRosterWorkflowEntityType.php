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

        // Fix any existing workflow instances created from this definition.
        // Use a subquery form instead of UPDATE...JOIN, which is MySQL-only.
        $this->execute(
            "UPDATE workflow_instances SET entity_type = 'WarrantRosters' " .
            "WHERE entity_type = 'Warrants' " .
            "AND workflow_definition_id IN (" .
                "SELECT id FROM workflow_definitions WHERE slug = 'warrants-roster-approval'" .
            ")"
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
            "UPDATE workflow_instances SET entity_type = 'Warrants' " .
            "WHERE entity_type = 'WarrantRosters' " .
            "AND workflow_definition_id IN (" .
                "SELECT id FROM workflow_definitions WHERE slug = 'warrants-roster-approval'" .
            ")"
        );
    }
}
