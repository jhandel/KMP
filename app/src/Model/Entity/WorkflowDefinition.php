<?php

declare(strict_types=1);

namespace App\Model\Entity;

/**
 * WorkflowDefinition Entity
 *
 * Represents a versioned workflow blueprint that defines states and transitions
 * for a specific entity type.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string $entity_type
 * @property string|null $plugin_name
 * @property int $version
 * @property bool $is_active
 * @property bool $is_default
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property int|null $created_by
 * @property int|null $modified_by
 *
 * @property \App\Model\Entity\WorkflowState[] $workflow_states
 * @property \App\Model\Entity\WorkflowTransition[] $workflow_transitions
 * @property \App\Model\Entity\WorkflowInstance[] $workflow_instances
 */
class WorkflowDefinition extends BaseEntity
{
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];
}
