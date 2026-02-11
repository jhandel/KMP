<?php

declare(strict_types=1);

namespace App\Policy;

use App\KMP\KmpIdentityInterface;
use App\Model\Entity\BaseEntity;
use Cake\ORM\Table;

/**
 * WorkflowDefinitionsTable policy — restricts workflow admin actions to super users.
 */
class WorkflowDefinitionsTablePolicy extends BasePolicy
{
    public function canIndex(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    public function canDesigner(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    public function canRegistry(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    public function canSave(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    public function canPublish(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    public function canAdd(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    public function canInstances(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    public function canViewInstance(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    public function canVersions(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    public function canCompareVersions(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    public function canToggleActive(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    public function canCreateDraft(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    public function canMigrateInstances(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    public function canApprovals(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $user->getIdentifier() !== null;
    }

    public function canRecordApproval(KmpIdentityInterface $user, BaseEntity|Table $entity, ...$optionalArgs): bool
    {
        return $user->getIdentifier() !== null;
    }
}
