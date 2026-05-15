<?php
declare(strict_types=1);

namespace App\Mailer;

use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\Mailer\Mailer;

class PlatformAdminMailer extends Mailer
{
    /**
     * Send a short-lived platform admin verification code.
     */
    public function emailCode(
        string $to,
        string $displayName,
        string $code,
        string $purpose,
        string $actionLabel,
        DateTime $expiresAt,
        ?string $ipAddress,
        string $userAgent,
    ): void {
        if (Configure::read('Email.platform') !== null) {
            $this->setProfile('platform');
        }
        $from = Configure::read('Email.platform.from')
            ?: Configure::read('Email.default.from')
            ?: 'platform-admin@localhost';
        $subject = $purpose === 'action'
            ? 'Your platform admin action verification code'
            : 'Your platform admin sign-in code';

        $this->setTo($to)
            ->setFrom($from)
            ->setSubject($subject)
            ->setEmailFormat('both')
            ->setViewVars(compact(
                'displayName',
                'code',
                'purpose',
                'actionLabel',
                'expiresAt',
                'ipAddress',
                'userAgent',
            ));
        $this->viewBuilder()->setTemplate('platform_admin_email_code');
    }
}
