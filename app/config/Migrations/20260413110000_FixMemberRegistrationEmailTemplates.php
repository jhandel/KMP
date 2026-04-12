<?php
declare(strict_types=1);

use Cake\ORM\TableRegistry;
use Migrations\AbstractMigration;

class FixMemberRegistrationEmailTemplates extends AbstractMigration
{
    public function up(): void
    {
        $templatesTable = TableRegistry::getTableLocator()->get('EmailTemplates');

        $updates = [
            'member-registration-welcome' => [
                'name' => 'Member Registration Welcome',
                'description' => 'Welcome email sent to a newly registered adult member with a password-reset link.',
                'subject_template' => 'Welcome {{memberScaName}} to {{portalName}}',
                'text_template' =>
                    "Welcome, {{memberScaName}}!\n\n"
                    . "To verify your email address please use the link below to set your password.\n\n"
                    . "{{passwordResetUrl}}\n\n"
                    . "This link will be good for 1 day. If you do not set your password within that time frame "
                    . "you will need to request a new password reset email from the \"forgot password\" link on "
                    . "the login page.\n\n\n"
                    . "Thank you\n{{siteAdminSignature}}.",
            ],
            'member-registration-secretary' => [
                'name' => 'New Member Secretary Notification',
                'description' => 'Sent to the kingdom secretary when a new adult member registers.',
                'subject_template' => 'New Member Registration: {{memberScaName}}',
                'text_template' =>
                    "Good day,\n\n"
                    . "{{memberScaName}} has recently registered. They have been emailed to set their password "
                    . "and their membership card was {{memberCardPresent}}.\n\n"
                    . "You can view their information at the link below:\n"
                    . "{{memberViewUrl}}\n\n\n"
                    . "Thank you\n{{siteAdminSignature}}.",
            ],
            'member-registration-secretary-minor' => [
                'name' => 'New Minor Member Secretary Notification',
                'description' => 'Sent to the kingdom secretary when a new minor member registers.',
                'subject_template' => 'New Minor Member Registration: {{memberScaName}}',
                'text_template' =>
                    "Good day,\n\n"
                    . "A new minor named {{memberScaName}} has recently registered. Their account is currently "
                    . "inaccessible and they have been notified you will follow up. Their membership card "
                    . "was {{memberCardPresent}} at the time of registration.\n\n"
                    . "You can view their information at the link below:\n"
                    . "{{memberViewUrl}}\n\n\n"
                    . "Thank you\n{{siteAdminSignature}}.",
            ],
        ];

        foreach ($updates as $slug => $fields) {
            $templates = $templatesTable->find()->where(['slug' => $slug])->all();
            foreach ($templates as $template) {
                foreach ($fields as $field => $value) {
                    $template->set($field, $value);
                }
                $templatesTable->saveOrFail($template);
            }
        }
    }

    public function down(): void
    {
        $templatesTable = TableRegistry::getTableLocator()->get('EmailTemplates');

        $updates = [
            'member-registration-secretary' => [
                'subject_template' => 'New Member Registration',
                'text_template' =>
                    "Good day,\n\n{{memberScaName}} has recently registered. They have been emailed to set their password and their membership card\n"
                    . "was <?= \$memberCardPresent ? \"uploaded\" : \"not uploaded\" ?>.\n\nYou can view their information at the link below:\n"
                    . "{{memberViewUrl}}\n\n\nThank you\n{{siteAdminSignature}}.",
            ],
            'member-registration-secretary-minor' => [
                'subject_template' => 'New Minor Member Registration',
                'text_template' =>
                    "Good day,\n\nA new minor named {{memberScaName}} has recently registered. Their account is currently inaccessable and they have\n"
                    . "been notified you will follow up. Their membership card\nwas <?= \$memberCardPresent ? \"uploaded\" : \"not uploaded\" ?> at the time of registration.\n\n"
                    . "You can view their information at the link below:\n{{memberViewUrl}}\n\n\nThank you\n{{siteAdminSignature}}.",
            ],
        ];

        foreach ($updates as $slug => $fields) {
            $templates = $templatesTable->find()->where(['slug' => $slug])->all();
            foreach ($templates as $template) {
                foreach ($fields as $field => $value) {
                    $template->set($field, $value);
                }
                $templatesTable->saveOrFail($template);
            }
        }
    }
}
