<?php

declare(strict_types=1);

namespace App\Policy;

use App\KMP\KmpIdentityInterface;
use Authorization\Policy\ResultInterface;

class SystemUpdateControllerPolicy extends BasePolicy
{
    /**
     * Check if user can access check action.
     *
     * @param \App\KMP\KmpIdentityInterface $user User
     * @param array $urlProps URL parameters
     * @return \Authorization\Policy\ResultInterface|bool
     */
    public function canCheck(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    /**
     * Check if user can access trigger action.
     *
     * @param \App\KMP\KmpIdentityInterface $user User
     * @param array $urlProps URL parameters
     * @return \Authorization\Policy\ResultInterface|bool
     */
    public function canTrigger(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    /**
     * Check if user can access status action.
     *
     * @param \App\KMP\KmpIdentityInterface $user User
     * @param array $urlProps URL parameters
     * @return \Authorization\Policy\ResultInterface|bool
     */
    public function canStatus(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }

    /**
     * Check if user can access rollback action.
     *
     * @param \App\KMP\KmpIdentityInterface $user User
     * @param array $urlProps URL parameters
     * @return \Authorization\Policy\ResultInterface|bool
     */
    public function canRollback(
        KmpIdentityInterface $user,
        array $urlProps,
    ): ResultInterface|bool {
        return $this->_hasPolicyForUrl($user, __FUNCTION__, $urlProps);
    }
}
