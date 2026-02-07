<?php

declare(strict_types=1);

namespace App\Model\Entity;

/**
 * WorkflowTransition Entity
 *
 * Represents a transition between two workflow states. Transitions may be
 * manual or automatic, with optional conditions, actions, and trigger config.
 *
 * @property int $id
 * @property int $workflow_definition_id
 * @property int $from_state_id
 * @property int $to_state_id
 * @property string $name
 * @property string $slug
 * @property string $label
 * @property string|null $description
 * @property int $priority
 * @property string|null $conditions
 * @property string|null $actions
 * @property bool $is_automatic
 * @property string $trigger_type
 * @property string|null $trigger_config
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property int|null $created_by
 * @property int|null $modified_by
 *
 * @property \App\Model\Entity\WorkflowDefinition $workflow_definition
 * @property \App\Model\Entity\WorkflowState $from_state
 * @property \App\Model\Entity\WorkflowState $to_state
 */
class WorkflowTransition extends BaseEntity
{
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    protected function _getDecodedConditions(): array
    {
        $raw = $this->conditions;
        if ($raw === null || $raw === '') {
            return [];
        }

        return json_decode($raw, true) ?? [];
    }

    protected function _getDecodedActions(): array
    {
        $raw = $this->actions;
        if ($raw === null || $raw === '') {
            return [];
        }

        return json_decode($raw, true) ?? [];
    }

    protected function _getDecodedTriggerConfig(): array
    {
        $raw = $this->trigger_config;
        if ($raw === null || $raw === '') {
            return [];
        }

        return json_decode($raw, true) ?? [];
    }
}
