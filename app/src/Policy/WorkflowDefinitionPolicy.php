<?php

declare(strict_types=1);

namespace App\Policy;

use App\KMP\KmpIdentityInterface;
use App\Model\Entity\BaseEntity;
use Cake\ORM\Table;

/**
 * WorkflowDefinition policy
 *
 * Restricts workflow definition management to super users.
 */
class WorkflowDefinitionPolicy extends BasePolicy
{
    /**
     * Check if $user can edit a workflow definition.
     *
     * @param \App\KMP\KmpIdentityInterface $user The user.
     * @param \App\Model\Entity\BaseEntity $entity The entity.
     * @param mixed ...$optionalArgs Optional arguments.
     * @return bool
     */
    public function canEditor(KmpIdentityInterface $user, BaseEntity $entity, mixed ...$optionalArgs): bool
    {
        $method = __FUNCTION__;

        return $this->_hasPolicy($user, $method, $entity, ...$optionalArgs);
    }
}
