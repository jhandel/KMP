<?php

declare(strict_types=1);

namespace App\Policy;

use App\KMP\KmpIdentityInterface;
use Authorization\IdentityInterface;
use Authorization\Policy\BeforePolicyInterface;
use Authorization\Policy\ResultInterface;

/**
 * Controller-level policy for WorkflowDefinitionsController.
 *
 * All actions are admin-only; requires super user or explicit policy grant.
 */
class WorkflowDefinitionsControllerPolicy implements BeforePolicyInterface
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

    public function canIndex(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canAdd(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canDesigner(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canLoadVersion(KmpIdentityInterface $user, mixed $resource): bool
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

    public function canVersions(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    public function canCompareVersions(KmpIdentityInterface $user, mixed $resource): bool
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

    public function canMigrateInstances(KmpIdentityInterface $user, mixed $resource): bool
    {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $resource);
    }

    /**
     * Check if user has explicit policy grant for a URL action.
     */
    private function _hasPolicyForUrl(KmpIdentityInterface $user, string $method, mixed $resource): bool
    {
        if (!is_array($resource)) {
            return false;
        }

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
