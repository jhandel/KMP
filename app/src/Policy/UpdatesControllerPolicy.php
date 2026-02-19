<?php

declare(strict_types=1);

namespace App\Policy;

use App\KMP\KmpIdentityInterface;

class UpdatesControllerPolicy extends BasePolicy
{
    /**
     * Restrict updater dashboard to super users.
     *
     * @param \App\KMP\KmpIdentityInterface $user
     * @param array $urlProps
     * @return bool
     */
    public function canIndex(KmpIdentityInterface $user, mixed $resource, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    /**
     * Restrict update checks to super users.
     *
     * @param \App\KMP\KmpIdentityInterface $user
     * @param array $urlProps
     * @return bool
     */
    public function canCheck(KmpIdentityInterface $user, mixed $resource, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    /**
     * Restrict update apply action to super users.
     *
     * @param \App\KMP\KmpIdentityInterface $user
     * @param array $urlProps
     * @return bool
     */
    public function canApply(KmpIdentityInterface $user, mixed $resource, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }

    /**
     * Restrict updater channel changes to super users.
     *
     * @param \App\KMP\KmpIdentityInterface $user
     * @param array $urlProps
     * @return bool
     */
    public function canSetChannel(KmpIdentityInterface $user, mixed $resource, ...$optionalArgs): bool
    {
        return $this->_isSuperUser($user);
    }
}
