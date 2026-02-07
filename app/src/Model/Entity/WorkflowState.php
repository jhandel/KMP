<?php

declare(strict_types=1);

namespace App\Model\Entity;

/**
 * WorkflowState Entity
 *
 * Represents a state within a workflow definition. States have a type
 * (initial, intermediate, final) and optional enter/exit actions.
 *
 * @property int $id
 * @property int $workflow_definition_id
 * @property string $name
 * @property string $slug
 * @property string $label
 * @property string|null $description
 * @property string $state_type
 * @property string|null $status_category
 * @property string|null $metadata
 * @property int|null $position_x
 * @property int|null $position_y
 * @property string|null $on_enter_actions
 * @property string|null $on_exit_actions
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property int|null $created_by
 * @property int|null $modified_by
 *
 * @property \App\Model\Entity\WorkflowDefinition $workflow_definition
 * @property \App\Model\Entity\WorkflowTransition[] $from_state_transitions
 * @property \App\Model\Entity\WorkflowTransition[] $to_state_transitions
 * @property \App\Model\Entity\WorkflowVisibilityRule[] $workflow_visibility_rules
 * @property \App\Model\Entity\WorkflowApprovalGate[] $workflow_approval_gates
 */
class WorkflowState extends BaseEntity
{
    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    protected function _getDecodedMetadata(): array
    {
        $raw = $this->metadata;
        if ($raw === null || $raw === '') {
            return [];
        }

        return json_decode($raw, true) ?? [];
    }

    protected function _getDecodedOnEnterActions(): array
    {
        $raw = $this->on_enter_actions;
        if ($raw === null || $raw === '') {
            return [];
        }

        return json_decode($raw, true) ?? [];
    }

    protected function _getDecodedOnExitActions(): array
    {
        $raw = $this->on_exit_actions;
        if ($raw === null || $raw === '') {
            return [];
        }

        return json_decode($raw, true) ?? [];
    }
}
