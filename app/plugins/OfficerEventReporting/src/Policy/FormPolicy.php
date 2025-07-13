<?php

declare(strict_types=1);

namespace OfficerEventReporting\Policy;

use App\Policy\BasePolicy;
use App\KMP\KmpIdentityInterface;
use App\Model\Entity\BaseEntity;
use OfficerEventReporting\Model\Entity\Form;

/**
 * Form entity policy
 */
class FormPolicy extends BasePolicy
{
    /**
     * Check if $user can view Form
     *
     * @param \App\KMP\KmpIdentityInterface $user The user.
     * @param \OfficerEventReporting\Model\Entity\Form $entity
     * @param mixed ...$optionalArgs Optional arguments
     * @return bool
     */
    public function canView(KmpIdentityInterface $user, BaseEntity $entity, ...$optionalArgs): bool
    {
        if (!($entity instanceof Form)) {
            return false;
        }

        // Officers can view all forms
        if ($this->_hasOfficerPermission($user)) {
            return true;
        }

        // Members can view forms available to them
        $userId = $user->getIdentifier();
        $userOffices = $this->_getUserOffices($user);
        
        return $entity->isAvailableToUser($userId, $userOffices);
    }

    /**
     * Check if $user can edit Form
     *
     * @param \App\KMP\KmpIdentityInterface $user The user.
     * @param \OfficerEventReporting\Model\Entity\Form $entity
     * @param mixed ...$optionalArgs Optional arguments
     * @return bool
     */
    public function canEdit(KmpIdentityInterface $user, BaseEntity $entity, ...$optionalArgs): bool
    {
        if (!($entity instanceof Form)) {
            return false;
        }

        // Only officers can edit forms
        if (!$this->_hasOfficerPermission($user)) {
            return false;
        }

        // Form creator can edit their own forms
        if ($entity->created_by === $user->getIdentifier()) {
            return true;
        }

        // Check for specific edit permissions
        return $this->_hasPolicy($user, __FUNCTION__, $entity);
    }

    /**
     * Check if $user can delete Form
     *
     * @param \App\KMP\KmpIdentityInterface $user The user.
     * @param \OfficerEventReporting\Model\Entity\Form $entity
     * @param mixed ...$optionalArgs Optional arguments
     * @return bool
     */
    public function canDelete(KmpIdentityInterface $user, BaseEntity $entity, ...$optionalArgs): bool
    {
        if (!($entity instanceof Form)) {
            return false;
        }

        // Only officers can delete forms
        if (!$this->_hasOfficerPermission($user)) {
            return false;
        }

        // Form creator can delete their own forms
        if ($entity->created_by === $user->getIdentifier()) {
            return true;
        }

        // Check for specific delete permissions
        return $this->_hasPolicy($user, __FUNCTION__, $entity);
    }

    /**
     * Check if user has officer-level permissions
     *
     * @param \App\KMP\KmpIdentityInterface $user The user
     * @return bool
     */
    protected function _hasOfficerPermission(KmpIdentityInterface $user): bool
    {
        // Check for specific permissions first
        if ($this->_hasPolicy($user, 'canManage', null)) {
            return true;
        }

        // Check if user has any warrant (officer role)
        $userModel = $user->getOriginalData();
        
        // Check for officer role via permissions system
        return $this->_hasPermissionByName($user, 'OfficerEventReporting.Forms.manage');
    }

    /**
     * Get user's offices for office-specific form assignments
     *
     * @param \App\KMP\KmpIdentityInterface $user The user
     * @return array Array of office IDs
     */
    protected function _getUserOffices(KmpIdentityInterface $user): array
    {
        // TODO: Implement getting user's offices from warrants/roles
        // This would query the warrants table for active warrants
        return [];
    }

    /**
     * Check if user has a specific permission by name
     *
     * @param \App\KMP\KmpIdentityInterface $user The user
     * @param string $permissionName The permission name
     * @return bool
     */
    protected function _hasPermissionByName(KmpIdentityInterface $user, string $permissionName): bool
    {
        // This would integrate with the existing permissions system
        $userModel = $user->getOriginalData();
        
        // Check if user has admin role or other officer privileges
        return isset($userModel['role']) && in_array($userModel['role'], ['admin', 'officer']);
    }
}