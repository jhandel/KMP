<?php

declare(strict_types=1);

namespace OfficerEventReporting\Policy;

use App\Policy\BasePolicy;
use App\KMP\KmpIdentityInterface;
use App\Model\Entity\BaseEntity;
use OfficerEventReporting\Model\Entity\Submission;

/**
 * Submission entity policy
 */
class SubmissionPolicy extends BasePolicy
{
    /**
     * Check if $user can view Submission
     *
     * @param \App\KMP\KmpIdentityInterface $user The user.
     * @param \OfficerEventReporting\Model\Entity\Submission $entity
     * @param mixed ...$optionalArgs Optional arguments
     * @return bool
     */
    public function canView(KmpIdentityInterface $user, BaseEntity $entity, ...$optionalArgs): bool
    {
        if (!($entity instanceof Submission)) {
            return false;
        }

        // Users can view their own submissions
        if ($entity->submitted_by === $user->getIdentifier()) {
            return true;
        }

        // Officers can view all submissions
        return $this->_hasOfficerPermission($user);
    }

    /**
     * Check if $user can edit Submission
     *
     * @param \App\KMP\KmpIdentityInterface $user The user.
     * @param \OfficerEventReporting\Model\Entity\Submission $entity
     * @param mixed ...$optionalArgs Optional arguments
     * @return bool
     */
    public function canEdit(KmpIdentityInterface $user, BaseEntity $entity, ...$optionalArgs): bool
    {
        if (!($entity instanceof Submission)) {
            return false;
        }

        // Users can edit their own submissions if they haven't been reviewed yet
        if ($entity->submitted_by === $user->getIdentifier() && $entity->status === 'submitted') {
            return true;
        }

        // Officers can edit any submission
        return $this->_hasOfficerPermission($user);
    }

    /**
     * Check if $user can review Submission
     *
     * @param \App\KMP\KmpIdentityInterface $user The user.
     * @param \OfficerEventReporting\Model\Entity\Submission $entity
     * @param mixed ...$optionalArgs Optional arguments
     * @return bool
     */
    public function canReview(KmpIdentityInterface $user, BaseEntity $entity, ...$optionalArgs): bool
    {
        if (!($entity instanceof Submission)) {
            return false;
        }

        // Only officers can review submissions
        if (!$this->_hasOfficerPermission($user)) {
            return false;
        }

        // Can't review your own submission
        if ($entity->submitted_by === $user->getIdentifier()) {
            return false;
        }

        // Can only review submissions that are in 'submitted' status
        return $entity->canBeReviewed();
    }

    /**
     * Check if $user can delete Submission
     *
     * @param \App\KMP\KmpIdentityInterface $user The user.
     * @param \OfficerEventReporting\Model\Entity\Submission $entity
     * @param mixed ...$optionalArgs Optional arguments
     * @return bool
     */
    public function canDelete(KmpIdentityInterface $user, BaseEntity $entity, ...$optionalArgs): bool
    {
        if (!($entity instanceof Submission)) {
            return false;
        }

        // Users can delete their own submissions if they haven't been reviewed yet
        if ($entity->submitted_by === $user->getIdentifier() && $entity->status === 'submitted') {
            return true;
        }

        // Officers can delete submissions
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