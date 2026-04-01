<?php

declare(strict_types=1);

use Cake\I18n\DateTime;
use Migrations\BaseSeed;

/**
 * Seeds all 11 workflow definitions with published versions.
 *
 * Loads JSON graph definitions from config/Seeds/WorkflowDefinitions/ and inserts
 * each as a workflow_definition + workflow_version pair. Skips any workflow whose
 * slug already exists to support safe re-running.
 */
class InitWorkflowDefinitionsSeed extends BaseSeed
{
    /**
     * @return array<array{name: string, slug: string, description: string, trigger_type: string, trigger_config: array, entity_type: string, json_file: string}>
     */
    public function getWorkflowMeta(): array
    {
        return [
            [
                'name' => 'Authorization Request (Multi-level Approval)',
                'slug' => 'activities-authorization-request',
                'description' => 'Handles activity authorization requests with validation, approver resolution, and serial multi-level approval chain.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Activities.AuthorizationRequested'],
                'entity_type' => 'Activities',
                'json_file' => 'activities-authorization-request.json',
            ],
            [
                'name' => 'Authorization Renewal',
                'slug' => 'activities-authorization-renewal',
                'description' => 'Handles renewal of existing activity authorizations with eligibility check and streamlined approval.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Activities.AuthorizationRequested', 'filter' => ['isRenewal' => true]],
                'entity_type' => 'Activities',
                'json_file' => 'activities-authorization-renewal.json',
            ],
            [
                'name' => 'Award Recommendation Lifecycle',
                'slug' => 'awards-recommendation-lifecycle',
                'description' => 'State machine for award recommendations: submitted → under_review → scheduled → given (or declined). Manages transitions, field rules, and notifications.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Awards.RecommendationSubmitted'],
                'entity_type' => 'Awards',
                'json_file' => 'awards-recommendation-lifecycle.json',
            ],
            [
                'name' => 'Officer Hire',
                'slug' => 'officer-hire',
                'description' => 'Full officer hire process: conflict resolution, warrant validation, officer creation, reporting field calculation, and notification.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Officers.HireRequested'],
                'entity_type' => 'Officers',
                'json_file' => 'officers-hire.json',
            ],
            [
                'name' => 'Officer Release',
                'slug' => 'officers-release',
                'description' => 'Releases an officer, recalculates office assignments, and sends release notification.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Officers.Released'],
                'entity_type' => 'Officers',
                'json_file' => 'officers-release.json',
            ],
            [
                'name' => 'Warrant Roster Approval',
                'slug' => 'warrants-roster-approval',
                'description' => 'Batch approval workflow for warrant rosters: approval gate → activate warrants (forEach) → notify each holder, or decline.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Warrants.RosterCreated'],
                'entity_type' => 'Warrants',
                'json_file' => 'warrants-roster-approval.json',
            ],
            [
                'name' => 'Member Registration',
                'slug' => 'member-registration',
                'description' => 'New member registration: checks age (minor vs. adult), assigns appropriate role and status, sends welcome email.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Members.Registered'],
                'entity_type' => 'Members',
                'json_file' => 'member-registration.json',
            ],
            [
                'name' => 'Password Reset',
                'slug' => 'member-password-reset',
                'description' => 'Simple single-action workflow that sends a password reset email to the requesting member.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Members.PasswordResetRequested'],
                'entity_type' => 'Members',
                'json_file' => 'member-password-reset.json',
            ],
            [
                'name' => 'Minor-to-Adult Age-Up',
                'slug' => 'member-age-up',
                'description' => 'Scheduled daily workflow that transitions minors who have reached adulthood: upgrades role and syncs warrantable status.',
                'trigger_type' => 'scheduled',
                'trigger_config' => ['cron' => '0 2 * * *', 'triggerEvent' => 'Members.AgeUpTriggered'],
                'entity_type' => 'Members',
                'json_file' => 'member-age-up.json',
            ],
            [
                'name' => 'Waiver Collection Closure',
                'slug' => 'waiver-closure',
                'description' => 'Closes a waiver collection when ready and notifies the gathering organizer.',
                'trigger_type' => 'event',
                'trigger_config' => ['event' => 'Waivers.ReadyToClose'],
                'entity_type' => 'Waivers',
                'json_file' => 'waiver-closure.json',
            ],
            [
                'name' => 'Active Window Status Sync',
                'slug' => 'active-window-sync',
                'description' => 'Scheduled daily sync that updates active window statuses across all entities.',
                'trigger_type' => 'scheduled',
                'trigger_config' => ['cron' => '0 1 * * *', 'triggerEvent' => 'ActiveWindow.SyncTriggered'],
                'entity_type' => 'Core',
                'json_file' => 'active-window-sync.json',
            ],
        ];
    }

    public function run(): void
    {
        $now = DateTime::now()->toDateTimeString();
        $jsonDir = dirname(__FILE__) . '/WorkflowDefinitions/';
        $definitionsTable = $this->table('workflow_definitions');
        $versionsTable = $this->table('workflow_versions');

        foreach ($this->getWorkflowMeta() as $meta) {
            // Skip if already seeded
            $exists = $this->fetchRow(
                "SELECT id FROM workflow_definitions WHERE slug = '{$meta['slug']}'"
            );
            if ($exists) {
                continue;
            }

            // Load JSON definition from file
            $jsonPath = $jsonDir . $meta['json_file'];
            if (!file_exists($jsonPath)) {
                throw new \RuntimeException("Workflow definition file not found: {$jsonPath}");
            }
            $definitionJson = file_get_contents($jsonPath);

            // Validate JSON
            $decoded = json_decode($definitionJson, true);
            if ($decoded === null) {
                throw new \RuntimeException("Invalid JSON in {$meta['json_file']}: " . json_last_error_msg());
            }

            // Insert workflow definition
            $definitionsTable->insert([
                'name' => $meta['name'],
                'slug' => $meta['slug'],
                'description' => $meta['description'],
                'trigger_type' => $meta['trigger_type'],
                'trigger_config' => json_encode($meta['trigger_config']),
                'entity_type' => $meta['entity_type'],
                'is_active' => false,
                'current_version_id' => null,
                'created_by' => 1,
                'modified_by' => 1,
                'created' => $now,
                'modified' => $now,
            ])->save();

            // Get the inserted definition ID
            $defRow = $this->fetchRow(
                "SELECT id FROM workflow_definitions WHERE slug = '{$meta['slug']}'"
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
                "SELECT id FROM workflow_versions WHERE workflow_definition_id = {$defId} AND version_number = 1"
            );
            $this->execute(
                "UPDATE workflow_definitions SET current_version_id = {$versionRow['id']} WHERE id = {$defId}"
            );
        }
    }
}
