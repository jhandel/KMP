<?php

declare(strict_types=1);

namespace OfficerEventReporting\Policy;

use App\Policy\BasePolicy;
use App\KMP\KmpIdentityInterface;
use App\Model\Entity\BaseEntity;
use Cake\ORM\Table;

/**
 * Submissions table policy
 */
class SubmissionsTablePolicy extends BasePolicy
{
    /**
     * Check if $user can index Submissions
     *
     * @param \App\KMP\KmpIdentityInterface $user The user.
     * @param \Cake\ORM\Table $entity
     * @param mixed ...$optionalArgs Optional arguments
     * @return bool
     */
    public function canIndex(KmpIdentityInterface $user, Table $entity, ...$optionalArgs): bool
    {
        // All authenticated users can view their own submissions
        return true;
    }

    /**
     * Check if $user can add Submissions
     *
     * @param \App\KMP\KmpIdentityInterface $user The user.
     * @param \Cake\ORM\Table $entity
     * @param mixed ...$optionalArgs Optional arguments
     * @return bool
     */
    public function canAdd(KmpIdentityInterface $user, Table $entity, ...$optionalArgs): bool
    {
        // All authenticated users can submit forms
        return true;
    }

    /**
     * Check if $user can view all submissions (officer feature)
     *
     * @param \App\KMP\KmpIdentityInterface $user The user.
     * @param \Cake\ORM\Table $entity
     * @param mixed ...$optionalArgs Optional arguments
     * @return bool
     */
    public function canViewAll(KmpIdentityInterface $user, Table $entity, ...$optionalArgs): bool
    {
        // Only officers can view all submissions
        return $this->_hasOfficerPermission($user);
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

        $userModel = $user->getOriginalData();
        
        // Check for officer role via permissions system
        return $this->_hasPermissionByName($user, 'OfficerEventReporting.Submissions.review');
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
        $userModel = $user->getOriginalData();
        
        // Check if user has admin role or other officer privileges
        return isset($userModel['role']) && in_array($userModel['role'], ['admin', 'officer']);
    }
}