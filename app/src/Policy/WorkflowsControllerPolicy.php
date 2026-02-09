<?php

declare(strict_types=1);

namespace App\Policy;

use App\KMP\KmpIdentityInterface;
use Authorization\IdentityInterface;
use Authorization\Policy\BeforePolicyInterface;
use Authorization\Policy\ResultInterface;

/**
 * Controller-level policy for WorkflowsController.
 *
 * Does NOT extend BasePolicy because controller policies receive
 * array URL props (not Entity|Table), which conflicts with BasePolicy signatures.
 */
class WorkflowsControllerPolicy implements BeforePolicyInterface
{
    public function before(
        ?IdentityInterface $user,
        mixed $resource,
        string $action,
    ): ResultInterface|bool|null {
        if ($user instanceof KmpIdentityInterface && $user->isSuperUser()) {
            return true;
        }

        return null;
    }

    /**
     * Any authenticated user can view workflow listings and their approvals.
     */
    public function canIndex(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canAdd(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canApprovals(KmpIdentityInterface $user, mixed $resource): bool
    {
        // All authenticated users can view their own approvals
        return true;
    }

    public function canRecordApproval(KmpIdentityInterface $user, mixed $resource): bool
    {
        // All authenticated users can respond to approvals they're eligible for
        return true;
    }

    public function canInstances(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canLoadVersion(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canDesigner(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canVersions(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canViewInstance(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canRegistry(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canSave(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canPublish(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canToggleActive(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canCreateDraft(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canCompareVersions(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canMigrateInstances(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    /**
     * Check if user has explicit policy grant for a URL action.
     * For a standalone controller policy without BasePolicy, we check
     * the user's loaded policy grants.
     */
    private function _hasPolicyForUrl(KmpIdentityInterface $user, string $method, mixed $resource): bool
    {
        if (!is_array($resource)) {
            return false;
        }

        // Check the user's loaded permission policies
        $policyClass = static::class;
        $policies = $user->getPolicies();
        if (empty($policies)) {
            return false;
        }
        $policyClassData = $policies[$policyClass] ?? null;
        if (empty($policyClassData)) {
            return false;
        }
        $policyMethodData = $policyClassData[$method] ?? null;

        return !empty($policyMethodData);
    }
}
