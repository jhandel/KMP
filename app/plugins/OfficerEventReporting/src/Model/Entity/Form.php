<?php

declare(strict_types=1);

namespace OfficerEventReporting\Model\Entity;

use App\Model\Entity\BaseEntity;
use Cake\I18n\DateTime;

/**
 * Form Entity
 *
 * @property int $id
 * @property string $title
 * @property string|null $description
 * @property string $form_type
 * @property string $status
 * @property string $assignment_type
 * @property string|null $assigned_members
 * @property string|null $assigned_offices
 * @property int $created_by
 * @property int|null $modified_by
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 *
 * @property \OfficerEventReporting\Model\Entity\FormField[] $form_fields
 * @property \OfficerEventReporting\Model\Entity\Submission[] $submissions
 * @property \App\Model\Entity\Member $created_by_member
 * @property \App\Model\Entity\Member|null $modified_by_member
 */
class Form extends BaseEntity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'title' => true,
        'description' => true,
        'form_type' => true,
        'status' => true,
        'assignment_type' => true,
        'assigned_members' => true,
        'assigned_offices' => true,
        'form_fields' => true,
        'created' => true,
        'modified' => true,
    ];

    /**
     * Get the assigned members as an array
     *
     * @return array
     */
    protected function _getAssignedMembersArray(): array
    {
        if (empty($this->assigned_members)) {
            return [];
        }
        
        $decoded = json_decode($this->assigned_members, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Get the assigned offices as an array
     *
     * @return array
     */
    protected function _getAssignedOfficesArray(): array
    {
        if (empty($this->assigned_offices)) {
            return [];
        }
        
        $decoded = json_decode($this->assigned_offices, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Set assigned members from array
     *
     * @param array $members
     * @return string
     */
    protected function _setAssignedMembersArray(array $members): string
    {
        return json_encode(array_filter($members));
    }

    /**
     * Set assigned offices from array
     *
     * @param array $offices
     * @return string
     */
    protected function _setAssignedOfficesArray(array $offices): string
    {
        return json_encode(array_filter($offices));
    }

    /**
     * Check if the form is available to a specific user
     *
     * @param int $userId
     * @param array $userOffices
     * @return bool
     */
    public function isAvailableToUser(int $userId, array $userOffices = []): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        switch ($this->assignment_type) {
            case 'open':
                return true;
            
            case 'assigned':
                $assignedMembers = $this->assigned_members_array;
                return in_array($userId, $assignedMembers);
            
            case 'office-specific':
                $assignedOffices = $this->assigned_offices_array;
                return !empty(array_intersect($userOffices, $assignedOffices));
            
            default:
                return false;
        }
    }
}