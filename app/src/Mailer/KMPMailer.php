<?php
declare(strict_types=1);

namespace App\Mailer;

use App\KMP\StaticHelpers;
use App\Model\Table\AppSettingsTable;
use Cake\Mailer\Mailer;

class KMPMailer extends Mailer
{
    use TemplateAwareMailerTrait;

    protected AppSettingsTable $appSettings;

    /**
     * Constructor.
     */
    public function __construct()
    {
        parent::__construct();
        $this->appSettings = $this->getTableLocator()->get('AppSettings');
    }

    /**
     * Reset password.
     *
     * @param mixed $to
     * @param mixed $url
     * @return void
     */
    public function resetPassword($to, $url): void
    {
        $sendFrom = StaticHelpers::getAppSetting('Email.SystemEmailFromAddress');
        $this->setTo($to)
            ->setFrom($sendFrom)
            ->setSubject('Reset password')
            ->setViewVars([
                'email' => $to,
                'passwordResetUrl' => $url,
                'siteAdminSignature' => StaticHelpers::getAppSetting('Email.SiteAdminSignature'),
            ]);
    }

    /**
     * Mobile card.
     *
     * @param mixed $to
     * @param mixed $url
     * @return void
     */
    public function mobileCard($to, $url): void
    {
        $sendFrom = StaticHelpers::getAppSetting('Email.SystemEmailFromAddress');
        $this->setTo($to)
            ->setFrom($sendFrom)
            ->setSubject('Your Mobile Card URL')
            ->setViewVars([
                'email' => $to,
                'mobileCardUrl' => $url,
                'siteAdminSignature' => StaticHelpers::getAppSetting('Email.SiteAdminSignature'),
            ]);
    }

    /**
     * New registration.
     *
     * @param mixed $to
     * @param mixed $url
     * @param mixed $sca_name
     * @return void
     */
    public function newRegistration($to, $url, $sca_name): void
    {
        $sendFrom = StaticHelpers::getAppSetting('Email.SystemEmailFromAddress');
        $portalName = StaticHelpers::getAppSetting('KMP.LongSiteTitle');
        $this->setTo($to)
            ->setFrom($sendFrom)
            ->setSubject('Welcome ' . $sca_name . ' to ' . $portalName)
            ->setViewVars([
                'email' => $to,
                'passwordResetUrl' => $url,
                'portalName' => $portalName,
                'memberScaName' => $sca_name,
                'siteAdminSignature' => StaticHelpers::getAppSetting('Email.SiteAdminSignature'),
            ]);
    }

    /**
     * Notify secretary of new member.
     *
     * @param mixed $to
     * @param mixed $url
     * @param mixed $sca_name
     * @param mixed $membershipCardPresent
     * @return void
     */
    public function notifySecretaryOfNewMember($to, $url, $sca_name, $membershipCardPresent): void
    {
        $sendFrom = StaticHelpers::getAppSetting('Email.SystemEmailFromAddress');
        $to = StaticHelpers::getAppSetting('Members.NewMemberSecretaryEmail');
        $this->setTo($to)
            ->setFrom($sendFrom)
            ->setSubject('New Member Registration')
            ->setViewVars([
                'memberViewUrl' => $url,
                'memberScaName' => $sca_name,
                'memberCardPresent' => $membershipCardPresent ? 'uploaded' : 'not uploaded',
                'siteAdminSignature' => StaticHelpers::getAppSetting('Email.SiteAdminSignature'),
            ]);
    }

    /**
     * Notify secretary of new minor member.
     *
     * @param mixed $to
     * @param mixed $url
     * @param mixed $sca_name
     * @param mixed $membershipCardPresent
     * @return void
     */
    public function notifySecretaryOfNewMinorMember($to, $url, $sca_name, $membershipCardPresent): void
    {
        $sendFrom = StaticHelpers::getAppSetting('Email.SystemEmailFromAddress');
        $to = StaticHelpers::getAppSetting('Members.NewMinorSecretaryEmail');
        $this->setTo($to)
            ->setFrom($sendFrom)
            ->setSubject('New Minor Member Registration')
            ->setViewVars([
                'memberViewUrl' => $url,
                'memberScaName' => $sca_name,
                'memberCardPresent' => $membershipCardPresent ? 'uploaded' : 'not uploaded',
                'siteAdminSignature' => StaticHelpers::getAppSetting('Email.SiteAdminSignature'),
            ]);
    }

    /**
     * Generic workflow email sender — loads a DB template by ID and renders it.
     *
     * Called via Core.SendEmail workflow action when a template ID is specified.
     * Extra named args are collected as template variables for {{var}} substitution.
     *
     * @param string $to Recipient email
     * @param string|int $_templateId Email template ID from the email_templates table
     * @param string|null $_replyTo Optional reply-to address
     * @param mixed ...$templateVars Named template variables for substitution
     * @return void
     */
    public function sendFromTemplate(string $to, string|int $_templateId, ?string $_replyTo = null, mixed ...$templateVars): void
    {
        $templateId = (int)$_templateId;
        $template = $this->getTableLocator()->get('EmailTemplates')->get($templateId);

        $sendFrom = StaticHelpers::getAppSetting('Email.SystemEmailFromAddress');
        $this->setTo($to)
            ->setFrom($sendFrom);

        if ($_replyTo) {
            $this->setReplyTo($_replyTo);
        }

        // Pre-load template so TemplateAwareMailerTrait uses it directly
        $this->_preloadedTemplate = $template;

        // Set vars for the renderer (subject + body substitution)
        $this->setViewVars($templateVars);
    }

    /**
     * Notify crown of a new award recommendation.
     *
     * @param string $to Recipient email address
     * @param string $memberScaName The SCA name of the person recommended
     * @param string $awardName The award being recommended
     * @param string $reason The reason for the recommendation
     * @param string $contactEmail Contact email of the recommender
     * @return void
     */
    public function notifyOfRecommendation(
        string $to,
        string $memberScaName,
        string $awardName,
        string $reason,
        string $contactEmail,
    ): void {
        $sendFrom = StaticHelpers::getAppSetting('Email.SystemEmailFromAddress');

        $this->setTo($to)
            ->setFrom($sendFrom)
            ->setSubject("New Award Recommendation: {$awardName} for {$memberScaName}")
            ->setViewVars([
                'memberScaName' => $memberScaName,
                'awardName' => $awardName,
                'reason' => $reason,
                'contactEmail' => $contactEmail,
                'siteAdminSignature' => StaticHelpers::getAppSetting('Email.SiteAdminSignature'),
            ]);
    }

    /**
     * Notify of warrant.
     *
     * @param string $to
     * @param string $memberScaName
     * @param string $warrantName
     * @param string $warrantStart
     * @param string $warrantExpires
     * @return void
     */
    public function notifyOfWarrant(
        string $to,
        string $memberScaName,
        string $warrantName,
        string $warrantStart,
        string $warrantExpires,
    ): void {
        $sendFrom = StaticHelpers::getAppSetting('Email.SystemEmailFromAddress');

        $this->setTo($to)
            ->setFrom($sendFrom)
            ->setSubject("Warrant Issued: $warrantName")
            ->setViewVars([
                'memberScaName' => $memberScaName,
                'warrantName' => $warrantName,
                'warrantExpires' => $warrantExpires,
                'warrantStart' => $warrantStart,
                'siteAdminSignature' => StaticHelpers::getAppSetting('Email.SiteAdminSignature'),
            ]);
    }
}
