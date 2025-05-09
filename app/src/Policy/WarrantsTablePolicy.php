<?php

declare(strict_types=1);

namespace App\Policy;

use App\Model\Tables\WarrantsTable;
use Authorization\IdentityInterface;

class WarrantsTablePolicy extends BasePolicy
{
    protected string $REQUIRED_PERMISSION = "Can Manage Warrants";
    protected string $REQUIRED_VIEW_PERMISSION = "Can View Warrants";

    public function canView(IdentityInterface $user, $entity, ...$optionalArgs)
    {
        if ($this->_hasNamedPermission($user, $this->REQUIRED_PERMISSION)) {
            return true;
        }
        if ($this->_hasNamedPermission($user, $this->REQUIRED_VIEW_PERMISSION)) {
            return true;
        }
        return false;
    }

    public function canIndex(IdentityInterface $user, $entity, ...$optionalArgs)
    {
        if ($this->_hasNamedPermission($user, $this->REQUIRED_PERMISSION)) {
            return true;
        }
        if ($this->_hasNamedPermission($user, $this->REQUIRED_VIEW_PERMISSION)) {
            return true;
        }
        return false;
    }

    public function canDeclineWarrantInRoster(IdentityInterface $user, $entity, ...$optionalArgs)
    {
        return $this->_hasNamedPermission($user, $this->REQUIRED_PERMISSION);
    }

    public function canDeactivate(IdentityInterface $user, $entity, ...$optionalArgs)
    {
        return $this->_hasNamedPermission($user, $this->REQUIRED_PERMISSION);
    }
}
