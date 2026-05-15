<?php
declare(strict_types=1);

use App\Migrations\CrossEngineMigrationTrait;
use Migrations\AbstractMigration;

class FixMemberRegistrationEmailTemplates extends AbstractMigration
{
    use CrossEngineMigrationTrait;

    /**
     * Apply corrected member registration email template content.
     *
     * @return void
     */
    public function up(): void
    {
        $updates = [
            'member-registration-welcome' => [
                'name' => 'Member Registration Welcome',
                'description' => 'Welcome email sent to a newly registered adult member with a password-reset link.',
                'subject_template' => 'Welcome {{memberScaName}} to {{portalName}}',
                'text_template' =>
                    "Welcome, {{memberScaName}}!\n\n"
                    . "To verify your email address please use the link below to set your password.\n\n"
                    . "{{passwordResetUrl}}\n\n"
                    . 'This link will be good for 1 day. If you do not set your password within that time frame '
                    . 'you will need to request a new password reset email from the "forgot password" link on '
                    . "the login page.\n\n\n"
                    . "Thank you\n{{siteAdminSignature}}.",
            ],
            'member-registration-secretary' => [
                'name' => 'New Member Secretary Notification',
                'description' => 'Sent to the kingdom secretary when a new adult member registers.',
                'subject_template' => 'New Member Registration: {{memberScaName}}',
                'text_template' =>
                    "Good day,\n\n"
                    . '{{memberScaName}} has recently registered. They have been emailed to set their password '
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
                    . 'A new minor named {{memberScaName}} has recently registered. Their account is currently '
                    . 'inaccessible and they have been notified you will follow up. Their membership card '
                    . "was {{memberCardPresent}} at the time of registration.\n\n"
                    . "You can view their information at the link below:\n"
                    . "{{memberViewUrl}}\n\n\n"
                    . "Thank you\n{{siteAdminSignature}}.",
            ],
        ];

        foreach ($updates as $slug => $fields) {
            $this->execute($this->buildUpdateSql($slug, $fields));
        }
    }

    /**
     * Restore prior member registration email template content.
     *
     * @return void
     */
    public function down(): void
    {
        $secretaryTemplate = "Good day,\n\n"
            . '{{memberScaName}} has recently registered. They have been emailed to set their password and '
            . "their membership card\n"
            . "was <?= \$memberCardPresent ? \"uploaded\" : \"not uploaded\" ?>.\n\n"
            . "You can view their information at the link below:\n"
            . "{{memberViewUrl}}\n\n\nThank you\n{{siteAdminSignature}}.";
        $minorSecretaryTemplate = "Good day,\n\n"
            . 'A new minor named {{memberScaName}} has recently registered. Their account is currently '
            . "inaccessable and they have\n"
            . "been notified you will follow up. Their membership card\n"
            . "was <?= \$memberCardPresent ? \"uploaded\" : \"not uploaded\" ?> at the time of registration.\n\n"
            . "You can view their information at the link below:\n"
            . "{{memberViewUrl}}\n\n\nThank you\n{{siteAdminSignature}}.";
        $updates = [
            'member-registration-secretary' => [
                'subject_template' => 'New Member Registration',
                'text_template' => $secretaryTemplate,
            ],
            'member-registration-secretary-minor' => [
                'subject_template' => 'New Minor Member Registration',
                'text_template' => $minorSecretaryTemplate,
            ],
        ];

        foreach ($updates as $slug => $fields) {
            $this->execute($this->buildUpdateSql($slug, $fields));
        }
    }

    /**
     * Build a raw update so this migration is independent of current ORM schema.
     *
     * @param string $slug Template slug.
     * @param array<string, string> $fields Fields to update.
     * @return string
     */
    private function buildUpdateSql(string $slug, array $fields): string
    {
        $sets = [];
        foreach ($fields as $field => $value) {
            $sets[] = sprintf('%s = \'%s\'', $field, $this->sqlEscape($value));
        }

        return sprintf(
            'UPDATE email_templates SET %s WHERE slug = \'%s\'',
            implode(', ', $sets),
            $this->sqlEscape($slug),
        );
    }
}
