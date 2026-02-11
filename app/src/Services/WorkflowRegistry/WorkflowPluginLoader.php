<?php

declare(strict_types=1);

namespace App\Services\WorkflowRegistry;

use App\KMP\KMPWorkflowPluginInterface;
use App\Services\WorkflowEngine\Providers\WarrantWorkflowProvider;
use Activities\Services\ActivitiesWorkflowProvider;
use Cake\Core\PluginCollection;
use Cake\Core\PluginInterface;
use Officers\Services\OfficersWorkflowProvider;

/**
 * Discovers and loads workflow registrations from plugins.
 *
 * Called during application bootstrap to populate the workflow registries
 * with triggers, actions, conditions, and entities from all plugins.
 */
class WorkflowPluginLoader
{
    /**
     * Load workflow registrations from all plugins that implement KMPWorkflowPluginInterface.
     *
     * @param \Cake\Core\PluginCollection $plugins The application's plugin collection
     * @return void
     */
    public static function loadFromPlugins(PluginCollection $plugins): void
    {
        foreach ($plugins as $plugin) {
            if ($plugin instanceof KMPWorkflowPluginInterface) {
                $source = self::getPluginName($plugin);
                self::loadPlugin($source, $plugin);
            }
        }

        // Also load core workflow components
        self::loadCoreComponents();
    }

    /**
     * Load registrations from a single plugin.
     *
     * @param string $source Source identifier
     * @param \App\KMP\KMPWorkflowPluginInterface $plugin Plugin instance
     * @return void
     */
    private static function loadPlugin(string $source, KMPWorkflowPluginInterface $plugin): void
    {
        $triggers = $plugin->getWorkflowTriggers();
        if (!empty($triggers)) {
            WorkflowTriggerRegistry::register($source, $triggers);
        }

        $actions = $plugin->getWorkflowActions();
        if (!empty($actions)) {
            WorkflowActionRegistry::register($source, $actions);
        }

        $conditions = $plugin->getWorkflowConditions();
        if (!empty($conditions)) {
            WorkflowConditionRegistry::register($source, $conditions);
        }

        $entities = $plugin->getWorkflowEntities();
        if (!empty($entities)) {
            WorkflowEntityRegistry::register($source, $entities);
        }
    }

    /**
     * Load core workflow components (email, notes, role assignment, etc.)
     *
     * @return void
     */
    private static function loadCoreComponents(): void
    {
        self::loadCoreTriggers();
        self::loadCoreActions();
        self::loadCoreConditions();
        self::loadCoreEntities();

        // Register plugin workflow providers
        OfficersWorkflowProvider::register();
        WarrantWorkflowProvider::register();
        ActivitiesWorkflowProvider::register();
    }

    /**
     * Register core triggers.
     *
     * @return void
     */
    private static function loadCoreTriggers(): void
    {
        WorkflowTriggerRegistry::register('Core', [
            [
                'event' => 'Core.ManualStart',
                'label' => 'Manual Start',
                'description' => 'Workflow started manually by an administrator',
                'payloadSchema' => [
                    'startedBy' => ['type' => 'entity', 'entity' => 'Members', 'label' => 'Started By'],
                    'entityType' => ['type' => 'string', 'label' => 'Entity Type'],
                    'entityId' => ['type' => 'integer', 'label' => 'Entity ID'],
                ],
            ],
        ]);
    }

    /**
     * Register core actions.
     *
     * @return void
     */
    private static function loadCoreActions(): void
    {
        $coreActions = \App\Services\WorkflowEngine\Actions\CoreActions::class;

        WorkflowActionRegistry::register('Core', [
            [
                'action' => 'Core.SendEmail',
                'label' => 'Send Email',
                'description' => 'Send an email notification using a configured template',
                'inputSchema' => [
                    'to' => ['type' => 'string', 'label' => 'Recipient Email', 'required' => true],
                    'mailer' => ['type' => 'string', 'label' => 'Mailer Class', 'required' => true],
                    'action' => ['type' => 'string', 'label' => 'Mailer Action', 'required' => true],
                    'vars' => ['type' => 'object', 'label' => 'Template Variables'],
                ],
                'outputSchema' => [
                    'sent' => ['type' => 'boolean', 'label' => 'Email Sent'],
                ],
                'serviceClass' => $coreActions,
                'serviceMethod' => 'sendEmail',
                'isAsync' => true,
            ],
            [
                'action' => 'Core.CreateNote',
                'label' => 'Create Note',
                'description' => 'Add a note to an entity',
                'inputSchema' => [
                    'entityType' => ['type' => 'string', 'label' => 'Entity Type', 'required' => true],
                    'entityId' => ['type' => 'integer', 'label' => 'Entity ID', 'required' => true],
                    'subject' => ['type' => 'string', 'label' => 'Note Subject', 'required' => true],
                    'body' => ['type' => 'string', 'label' => 'Note Body', 'required' => true],
                ],
                'outputSchema' => [
                    'noteId' => ['type' => 'integer', 'label' => 'Note ID'],
                ],
                'serviceClass' => $coreActions,
                'serviceMethod' => 'createNote',
                'isAsync' => false,
            ],
            [
                'action' => 'Core.UpdateEntity',
                'label' => 'Update Entity',
                'description' => 'Update fields on an entity',
                'inputSchema' => [
                    'entityType' => ['type' => 'string', 'label' => 'Entity Type', 'required' => true],
                    'entityId' => ['type' => 'integer', 'label' => 'Entity ID', 'required' => true],
                    'fields' => ['type' => 'object', 'label' => 'Fields to Update', 'required' => true],
                ],
                'outputSchema' => [
                    'updated' => ['type' => 'boolean', 'label' => 'Update Successful'],
                ],
                'serviceClass' => $coreActions,
                'serviceMethod' => 'updateEntity',
                'isAsync' => false,
            ],
            [
                'action' => 'Core.AssignRole',
                'label' => 'Assign Role to Member',
                'description' => 'Assign a role to a member with optional start/end dates',
                'inputSchema' => [
                    'memberId' => ['type' => 'integer', 'label' => 'Member ID', 'required' => true],
                    'roleId' => ['type' => 'integer', 'label' => 'Role ID', 'required' => true],
                    'startOn' => ['type' => 'datetime', 'label' => 'Start Date'],
                    'expiresOn' => ['type' => 'datetime', 'label' => 'Expiry Date'],
                    'entityType' => ['type' => 'string', 'label' => 'Granting Entity Type'],
                    'entityId' => ['type' => 'integer', 'label' => 'Granting Entity ID'],
                ],
                'outputSchema' => [
                    'memberRoleId' => ['type' => 'integer', 'label' => 'Member Role ID'],
                ],
                'serviceClass' => $coreActions,
                'serviceMethod' => 'assignRole',
                'isAsync' => false,
            ],
            [
                'action' => 'Core.SetVariable',
                'label' => 'Set Workflow Variable',
                'description' => 'Set a variable in the workflow context',
                'inputSchema' => [
                    'name' => ['type' => 'string', 'label' => 'Variable Name', 'required' => true],
                    'value' => ['type' => 'mixed', 'label' => 'Value', 'required' => true],
                ],
                'outputSchema' => [],
                'serviceClass' => $coreActions,
                'serviceMethod' => 'setVariable',
                'isAsync' => false,
            ],
        ]);
    }

    /**
     * Register core conditions.
     *
     * @return void
     */
    private static function loadCoreConditions(): void
    {
        $coreConditions = \App\Services\WorkflowEngine\Conditions\CoreConditions::class;

        WorkflowConditionRegistry::register('Core', [
            [
                'condition' => 'Core.FieldEquals',
                'label' => 'Field Equals Value',
                'description' => 'Check if a context field equals a specific value',
                'inputSchema' => [
                    'field' => ['type' => 'string', 'label' => 'Field Path', 'required' => true],
                    'value' => ['type' => 'mixed', 'label' => 'Expected Value', 'required' => true],
                ],
                'evaluatorClass' => $coreConditions,
                'evaluatorMethod' => 'fieldEquals',
            ],
            [
                'condition' => 'Core.FieldNotEmpty',
                'label' => 'Field Is Not Empty',
                'description' => 'Check if a context field has a non-empty value',
                'inputSchema' => [
                    'field' => ['type' => 'string', 'label' => 'Field Path', 'required' => true],
                ],
                'evaluatorClass' => $coreConditions,
                'evaluatorMethod' => 'fieldNotEmpty',
            ],
            [
                'condition' => 'Core.FieldGreaterThan',
                'label' => 'Field Greater Than',
                'description' => 'Check if a numeric field exceeds a threshold',
                'inputSchema' => [
                    'field' => ['type' => 'string', 'label' => 'Field Path', 'required' => true],
                    'value' => ['type' => 'number', 'label' => 'Threshold', 'required' => true],
                ],
                'evaluatorClass' => $coreConditions,
                'evaluatorMethod' => 'fieldGreaterThan',
            ],
            [
                'condition' => 'Core.MemberHasPermission',
                'label' => 'Member Has Permission',
                'description' => 'Check if a member has a specific permission',
                'inputSchema' => [
                    'memberId' => ['type' => 'integer', 'label' => 'Member ID', 'required' => true],
                    'permission' => ['type' => 'string', 'label' => 'Permission Name', 'required' => true],
                ],
                'evaluatorClass' => $coreConditions,
                'evaluatorMethod' => 'memberHasPermission',
            ],
            [
                'condition' => 'Core.Expression',
                'label' => 'Custom Expression',
                'description' => 'Evaluate a custom boolean expression against the workflow context',
                'inputSchema' => [
                    'expression' => ['type' => 'string', 'label' => 'Expression', 'required' => true],
                ],
                'evaluatorClass' => $coreConditions,
                'evaluatorMethod' => 'evaluateExpression',
            ],
        ]);
    }

    /**
     * Register core entity types.
     *
     * @return void
     */
    private static function loadCoreEntities(): void
    {
        WorkflowEntityRegistry::register('Core', [
            [
                'entityType' => 'Core.Members',
                'label' => 'Member',
                'description' => 'KMP member/user',
                'tableClass' => \App\Model\Table\MembersTable::class,
                'fields' => [
                    'id' => ['type' => 'integer', 'label' => 'ID'],
                    'sca_name' => ['type' => 'string', 'label' => 'SCA Name'],
                    'email_address' => ['type' => 'string', 'label' => 'Email'],
                    'branch_id' => ['type' => 'integer', 'label' => 'Branch ID'],
                    'status' => ['type' => 'string', 'label' => 'Status'],
                ],
            ],
            [
                'entityType' => 'Core.Branches',
                'label' => 'Branch',
                'description' => 'Organizational branch',
                'tableClass' => \App\Model\Table\BranchesTable::class,
                'fields' => [
                    'id' => ['type' => 'integer', 'label' => 'ID'],
                    'name' => ['type' => 'string', 'label' => 'Name'],
                    'branch_type_id' => ['type' => 'integer', 'label' => 'Branch Type'],
                    'parent_id' => ['type' => 'integer', 'label' => 'Parent Branch'],
                ],
            ],
            [
                'entityType' => 'Core.WarrantRosters',
                'label' => 'Warrant Roster',
                'description' => 'Batch of warrants for approval',
                'tableClass' => \App\Model\Table\WarrantRostersTable::class,
                'fields' => [
                    'id' => ['type' => 'integer', 'label' => 'ID'],
                    'name' => ['type' => 'string', 'label' => 'Name'],
                    'status' => ['type' => 'string', 'label' => 'Status'],
                    'approvals_required' => ['type' => 'integer', 'label' => 'Required Approvals'],
                    'approval_count' => ['type' => 'integer', 'label' => 'Current Approvals'],
                ],
            ],
            [
                'entityType' => 'Core.Warrants',
                'label' => 'Warrant',
                'description' => 'Individual warrant for a member',
                'tableClass' => \App\Model\Table\WarrantsTable::class,
                'fields' => [
                    'id' => ['type' => 'integer', 'label' => 'ID'],
                    'member_id' => ['type' => 'integer', 'label' => 'Member ID'],
                    'status' => ['type' => 'string', 'label' => 'Status'],
                    'expires_on' => ['type' => 'datetime', 'label' => 'Expires On'],
                ],
            ],
        ]);
    }

    /**
     * Get the short name of a plugin from its class.
     *
     * @param \Cake\Core\PluginInterface $plugin Plugin instance
     * @return string Plugin short name
     */
    private static function getPluginName(PluginInterface $plugin): string
    {
        $className = get_class($plugin);
        $parts = explode('\\', $className);

        // Return the plugin namespace (e.g., 'Officers' from 'Officers\Plugin')
        return $parts[0] ?? $className;
    }
}
