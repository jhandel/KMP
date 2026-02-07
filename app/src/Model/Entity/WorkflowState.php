<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class WorkflowState extends Entity
{
    // State type constants
    public const TYPE_INITIAL = 'initial';
    public const TYPE_INTERMEDIATE = 'intermediate';
    public const TYPE_APPROVAL = 'approval';
    public const TYPE_TERMINAL = 'terminal';

    public const VALID_STATE_TYPES = [
        self::TYPE_INITIAL,
        self::TYPE_INTERMEDIATE,
        self::TYPE_APPROVAL,
        self::TYPE_TERMINAL,
    ];

    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    /**
     * Decode metadata JSON to array.
     */
    protected function _getDecodedMetadata(): array
    {
        $metadata = $this->metadata;
        if (empty($metadata)) {
            return [];
        }
        if (is_string($metadata)) {
            return json_decode($metadata, true) ?? [];
        }
        return (array)$metadata;
    }

    /**
     * Decode on_enter_actions JSON to array.
     */
    protected function _getDecodedOnEnterActions(): array
    {
        $actions = $this->on_enter_actions;
        if (empty($actions)) {
            return [];
        }
        if (is_string($actions)) {
            return json_decode($actions, true) ?? [];
        }
        return (array)$actions;
    }

    /**
     * Decode on_exit_actions JSON to array.
     */
    protected function _getDecodedOnExitActions(): array
    {
        $actions = $this->on_exit_actions;
        if (empty($actions)) {
            return [];
        }
        if (is_string($actions)) {
            return json_decode($actions, true) ?? [];
        }
        return (array)$actions;
    }

    /**
     * Check if this is an initial state.
     */
    protected function _getIsInitial(): bool
    {
        return $this->state_type === self::TYPE_INITIAL;
    }

    /**
     * Check if this is a terminal state.
     */
    protected function _getIsTerminal(): bool
    {
        return $this->state_type === self::TYPE_TERMINAL;
    }

    /**
     * Check if this is an approval state.
     */
    protected function _getIsApproval(): bool
    {
        return $this->state_type === self::TYPE_APPROVAL;
    }
}
