<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class WorkflowTransition extends Entity
{
    // Trigger type constants
    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_AUTOMATIC = 'automatic';
    public const TRIGGER_SCHEDULED = 'scheduled';
    public const TRIGGER_EVENT = 'event';

    public const VALID_TRIGGER_TYPES = [
        self::TRIGGER_MANUAL,
        self::TRIGGER_AUTOMATIC,
        self::TRIGGER_SCHEDULED,
        self::TRIGGER_EVENT,
    ];

    protected array $_accessible = [
        '*' => true,
        'id' => false,
    ];

    /**
     * Decode conditions JSON to array.
     */
    protected function _getDecodedConditions(): array
    {
        $conditions = $this->conditions;
        if (empty($conditions)) {
            return [];
        }
        if (is_string($conditions)) {
            return json_decode($conditions, true) ?? [];
        }
        return (array)$conditions;
    }

    /**
     * Decode actions JSON to array.
     */
    protected function _getDecodedActions(): array
    {
        $actions = $this->actions;
        if (empty($actions)) {
            return [];
        }
        if (is_string($actions)) {
            return json_decode($actions, true) ?? [];
        }
        return (array)$actions;
    }

    /**
     * Decode trigger_config JSON to array.
     */
    protected function _getDecodedTriggerConfig(): array
    {
        $config = $this->trigger_config;
        if (empty($config)) {
            return [];
        }
        if (is_string($config)) {
            return json_decode($config, true) ?? [];
        }
        return (array)$config;
    }

    /**
     * Check if this is a manual transition.
     */
    protected function _getIsManual(): bool
    {
        return $this->trigger_type === self::TRIGGER_MANUAL;
    }

    /**
     * Check if this transition has conditions.
     */
    protected function _getHasConditions(): bool
    {
        return !empty($this->decoded_conditions);
    }
}
