<?php

declare(strict_types=1);

namespace OfficerEventReporting\Policy;

use App\Policy\BasePolicy;
use App\KMP\KmpIdentityInterface;
use App\Model\Entity\BaseEntity;
use Cake\ORM\Table;

/**
 * Forms table policy
 */
class FormsTablePolicy extends BasePolicy
{
    /**
     * Check if $user can index Forms
     *
     * @param \App\KMP\KmpIdentityInterface $user The user.
     * @param \Cake\ORM\Table $entity
     * @param mixed ...$optionalArgs Optional arguments
     * @return bool
     */
    public function canIndex(KmpIdentityInterface $user, Table $entity, ...$optionalArgs): bool
    {
        // All authenticated users can view the forms index
        return true;
    }

    /**
     * Check if $user can add Forms
     *
     * @param \App\KMP\KmpIdentityInterface $user The user.
     * @param \Cake\ORM\Table $entity
     * @param mixed ...$optionalArgs Optional arguments
     * @return bool
     */
    public function canAdd(KmpIdentityInterface $user, Table $entity, ...$optionalArgs): bool
    {
        // Check if user has officer privileges
        return $this->_hasOfficerPermission($user, 'canAdd', $entity);
    }

    /**
     * Check if user has officer-level permissions
     *
     * @param \App\KMP\KmpIdentityInterface $user The user
     * @param string $method The method being checked
     * @param \Cake\ORM\Table $entity The entity
     * @return bool
     */
    protected function _hasOfficerPermission(KmpIdentityInterface $user, string $method, Table $entity): bool
    {
        // Check for specific permissions first
        if ($this->_hasPolicy($user, $method, $entity)) {
            return true;
        }

        // Check if user has any warrant (officer role)
        // This is a simplified check - in reality, you'd check active warrants
        $userModel = $user->getOriginalData();
        
        // Check for officer role via permissions system
        return $this->_hasPermissionByName($user, 'OfficerEventReporting.Forms.manage');
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
        // For now, we'll use a basic role check
        $userModel = $user->getOriginalData();
        
        // Check if user has admin role or other officer privileges
        // This is simplified - in reality, you'd check the permissions table
        return isset($userModel['role']) && in_array($userModel['role'], ['admin', 'officer']);
    }
}