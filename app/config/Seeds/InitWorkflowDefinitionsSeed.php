<?php

declare(strict_types=1);

use Cake\I18n\DateTime;
use Migrations\BaseSeed;

/**
 * Seeds all workflow definitions with published versions.
 *
 * Loads JSON graph definitions from config/Seeds/WorkflowDefinitions/ and inserts
 * each as a workflow_definition + workflow_version pair. Skips any workflow whose
 * slug already exists to support safe re-running.
 */
class InitWorkflowDefinitionsSeed extends BaseSeed
{
    /**
     * @return array<array{name: string, slug: string, description: string, trigger_type: string, trigger_config: array, entity_type: string, json_file: string, execution_mode?: string, is_active?: bool}>
     */
    public function getWorkflowMeta(): array
    {
        return [
            [
                'name' => 'Authorization Request (Multi-level Approval)',
                'slug' => 'activities-authorization-request',
                'description' => 'Handles activity authorization requests with validation, approver resolution, ' .
                    'and serial multi-level approval chain.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Activities.AuthorizationRequested'],
                'entity_type' => 'Activities.Authorizations',
                'json_file' => 'activities-authorization-request.json',
                'execution_mode' => 'durable',
                'is_active' => true,
            ],
            [
                'name' => 'Award Recommendation Submitted',
                'slug' => 'awards-recommendation-submitted',
                'description' => 'Creates a recommendation from submitted form data and runs ' .
                    'post-create workflow steps.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Awards.RecommendationCreateRequested'],
                'entity_type' => 'Awards',
                'json_file' => 'awards-recommendation-submitted.json',
                'execution_mode' => 'ephemeral',
                'is_active' => true,
            ],
            [
                'name' => 'Award Recommendation Updated',
                'slug' => 'awards-recommendation-updated',
                'description' => 'Updates an existing recommendation from edit form data using ' .
                    'the shared mutation service.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Awards.RecommendationUpdateRequested'],
                'entity_type' => 'Awards',
                'json_file' => 'awards-recommendation-updated.json',
                'execution_mode' => 'ephemeral',
                'is_active' => true,
            ],
            [
                'name' => 'Award Recommendation State Changed',
                'slug' => 'awards-recommendation-state-changed',
                'description' => 'Transitions a single recommendation using the shared transition semantics.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Awards.RecommendationTransitionRequested'],
                'entity_type' => 'Awards',
                'json_file' => 'awards-recommendation-state-changed.json',
                'execution_mode' => 'ephemeral',
                'is_active' => true,
            ],
            [
                'name' => 'Award Recommendation Bulk Transition',
                'slug' => 'awards-recommendation-bulk-transition',
                'description' => 'Applies a bulk recommendation transition using the shared transition semantics.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Awards.RecommendationBulkTransitionRequested'],
                'entity_type' => 'Awards',
                'json_file' => 'awards-recommendation-bulk-transition.json',
                'execution_mode' => 'ephemeral',
                'is_active' => true,
            ],
            [
                'name' => 'Award Recommendations Group',
                'slug' => 'awards-recommendations-group',
                'description' => 'Groups selected recommendations under a shared head recommendation.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Awards.RecommendationsGroupRequested'],
                'entity_type' => 'Awards',
                'json_file' => 'awards-recommendations-group.json',
                'execution_mode' => 'ephemeral',
                'is_active' => true,
            ],
            [
                'name' => 'Award Recommendations Ungroup',
                'slug' => 'awards-recommendations-ungroup',
                'description' => 'Restores all children from a grouped recommendation back to their origin states.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Awards.RecommendationsUngroupRequested'],
                'entity_type' => 'Awards',
                'json_file' => 'awards-recommendations-ungroup.json',
                'execution_mode' => 'ephemeral',
                'is_active' => true,
            ],
            [
                'name' => 'Award Recommendation Remove From Group',
                'slug' => 'awards-recommendation-remove-from-group',
                'description' => 'Restores a single grouped recommendation, auto-restoring the ' .
                    'final child when needed.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Awards.RecommendationRemoveFromGroupRequested'],
                'entity_type' => 'Awards',
                'json_file' => 'awards-recommendation-remove-from-group.json',
                'execution_mode' => 'ephemeral',
                'is_active' => true,
            ],
            [
                'name' => 'Award Recommendation Deleted',
                'slug' => 'awards-recommendation-deleted',
                'description' => 'Soft deletes a recommendation and restores grouped children ' .
                    'when deleting a group head.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Awards.RecommendationDeleteRequested'],
                'entity_type' => 'Awards',
                'json_file' => 'awards-recommendation-deleted.json',
                'execution_mode' => 'ephemeral',
                'is_active' => true,
            ],
            [
                'name' => 'Officer Hire',
                'slug' => 'officer-hire',
                'description' => 'Full officer hire process: conflict resolution, warrant validation, ' .
                    'officer creation, reporting field calculation, and notification.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Officers.HireRequested'],
                'entity_type' => 'Officers',
                'json_file' => 'officers-hire.json',
                'execution_mode' => 'ephemeral',
            ],
            [
                'name' => 'Officer Release',
                'slug' => 'officers-release',
                'description' => 'Releases an officer, recalculates office assignments, and sends ' .
                    'release notification.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Officers.Released'],
                'entity_type' => 'Officers',
                'json_file' => 'officers-release.json',
                'execution_mode' => 'ephemeral',
            ],
            [
                'name' => 'Warrant Roster Approval',
                'slug' => 'warrants-roster-approval',
                'description' => 'Batch approval workflow for warrant rosters: approval gate → ' .
                    'activate warrants (forEach) → notify each holder, or decline.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Warrants.RosterCreated'],
                'entity_type' => 'Warrants',
                'json_file' => 'warrants-roster-approval.json',
                'execution_mode' => 'durable',
            ],
            [
                'name' => 'Member Registration',
                'slug' => 'member-registration',
                'description' => 'New member registration: checks age (minor vs. adult), assigns ' .
                    'appropriate role and status, sends welcome email.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Members.Registered'],
                'entity_type' => 'Members',
                'json_file' => 'member-registration.json',
                'execution_mode' => 'ephemeral',
            ],
            [
                'name' => 'Waiver Collection Closure',
                'slug' => 'waiver-closure',
                'description' => 'Closes a waiver collection when ready and notifies the gathering organizer.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Waivers.ReadyToClose'],
                'entity_type' => 'Waivers',
                'json_file' => 'waiver-closure.json',
                'execution_mode' => 'ephemeral',
            ],
        ];
    }

    /**
     * Seed workflow definitions and their initial published versions.
     *
     * @return void
     */
    public function run(): void
    {
        $now = DateTime::now()->toDateTimeString();
        $jsonDir = dirname(__FILE__) . '/WorkflowDefinitions/';
        $definitionsTable = $this->table('workflow_definitions');
        $versionsTable = $this->table('workflow_versions');

        foreach ($this->getWorkflowMeta() as $meta) {
            // Skip if already seeded
            $exists = $this->fetchRow(
                "SELECT id FROM workflow_definitions WHERE slug = '{$meta['slug']}'",
            );
            if ($exists) {
                continue;
            }

            // Load JSON definition from file
            $jsonPath = $jsonDir . $meta['json_file'];
            if (!file_exists($jsonPath)) {
                throw new RuntimeException("Workflow definition file not found: {$jsonPath}");
            }
            $definitionJson = file_get_contents($jsonPath);

            // Validate JSON
            $decoded = json_decode($definitionJson, true);
            if ($decoded === null) {
                throw new RuntimeException("Invalid JSON in {$meta['json_file']}: " . json_last_error_msg());
            }

            // Insert workflow definition
            $definitionsTable->insert([
                'name' => $meta['name'],
                'slug' => $meta['slug'],
                'description' => $meta['description'],
                'trigger_type' => $meta['trigger_type'],
                'trigger_config' => json_encode($meta['trigger_config']),
                'entity_type' => $meta['entity_type'],
                'is_active' => $meta['is_active'] ?? false,
                'execution_mode' => $meta['execution_mode'] ?? 'durable',
                'current_version_id' => null,
                'created_by' => 1,
                'modified_by' => 1,
                'created' => $now,
                'modified' => $now,
            ])->save();

            // Get the inserted definition ID
            $defRow = $this->fetchRow(
                "SELECT id FROM workflow_definitions WHERE slug = '{$meta['slug']}'",
            );
            $defId = $defRow['id'];

            // Insert published version
            $versionsTable->insert([
                'workflow_definition_id' => $defId,
                'version_number' => 1,
                'definition' => json_encode($decoded),
                'canvas_layout' => '{}',
                'status' => 'published',
                'published_at' => $now,
                'published_by' => 1,
                'change_notes' => 'Initial seed version',
                'created_by' => 1,
                'created' => $now,
                'modified' => $now,
            ])->save();

            // Get version ID and link back to definition
            $versionRow = $this->fetchRow(
                "SELECT id FROM workflow_versions WHERE workflow_definition_id = {$defId} AND version_number = 1",
            );
            $this->execute(
                "UPDATE workflow_definitions SET current_version_id = {$versionRow['id']} WHERE id = {$defId}",
            );
        }
    }
}
