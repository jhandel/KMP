<?php

declare(strict_types=1);

namespace App\Policy;

use App\KMP\KmpIdentityInterface;
use App\Model\Entity\BaseEntity;
use Cake\ORM\Table;

/**
 * WorkflowApprovalsTable policy — table-level authorization for approvals.
 *
 * Any authenticated user can access their own approvals.
 * Admin actions require super user.
 */
class WorkflowApprovalsTablePolicy extends BasePolicy
{
    public function canApprovals(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $user->getIdentifier() !== null;
    }

    public function canRecordApproval(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $user->getIdentifier() !== null;
    }

    public function canAllApprovals(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    public function canAllApprovalsGridData(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    public function canReassignApproval(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }
}
