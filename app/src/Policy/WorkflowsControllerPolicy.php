<?php

declare(strict_types=1);

namespace App\Policy;

use App\KMP\KmpIdentityInterface;
use Authorization\Policy\ResultInterface;

/**
 * Controller-level policy for WorkflowsController.
 *
 * Resolved by ControllerResolver when nav cells check canAccessUrl
 * for Workflows routes. Only defines methods for actions not already
 * in BasePolicy (index, add, edit, delete, view are inherited).
 */
class WorkflowsControllerPolicy extends BasePolicy
{
    public function canApprovals(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    public function canInstances(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    public function canLoadVersion(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    public function canDesigner(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    public function canVersions(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    public function canViewInstance(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    public function canRegistry(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    public function canSave(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    public function canPublish(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    public function canRecordApproval(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    public function canToggleActive(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    public function canCreateDraft(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    public function canCompareVersions(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    public function canMigrateInstances(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }
}
