<?php

declare(strict_types=1);

namespace App\Model\Entity;

/**
 * WorkflowDefinition Entity
 *
 * Represents a versioned workflow definition that defines states and transitions
 * for entity lifecycle management.
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
 * @property \Cake\I18n\DateTime|null $created
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

    /**
     * Whether this definition is published (active and not draft).
     */
    protected function _getIsPublished(): bool
    {
        return $this->is_active && !$this->_getIsDraft();
    }

    /**
     * Whether this definition is still in draft (version < 1).
     */
    protected function _getIsDraft(): bool
    {
        return $this->version < 1;
    }
}
