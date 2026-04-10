<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Finalize email_templates around slug-only identity.
 *
 * Before dropping the legacy provenance columns, every remaining slugless row is
 * assigned a stable slug so existing template content remains editable and addressable.
 */
class RemoveLegacyMailerIdentityFromEmailTemplates extends BaseMigration
{
    /**
     * @return void
     */
    public function up(): void
    {
        $this->backfillMissingSlugs();

        $table = $this->table('email_templates');
        $table
            ->changeColumn('slug', 'string', [
                'null' => false,
                'default' => null,
                'limit' => 100,
                'comment' => 'Stable workflow-native key (e.g. warrant-issued). Unique per kingdom_id.',
            ])
            ->removeColumn('mailer_class')
            ->removeColumn('action_method')
            ->update();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $table = $this->table('email_templates');
        $table
            ->addColumn('mailer_class', 'string', [
                'null' => true,
                'default' => null,
                'limit' => 255,
                'comment' => 'Legacy: Mailer class for pre-slug templates',
            ])
            ->addColumn('action_method', 'string', [
                'null' => true,
                'default' => null,
                'limit' => 255,
                'comment' => 'Legacy: Mailer action method for pre-slug templates',
            ])
            ->changeColumn('slug', 'string', [
                'null' => true,
                'default' => null,
                'limit' => 100,
                'comment' => 'Stable workflow-native key (e.g. warrant-issued). Unique per kingdom_id.',
            ])
            ->update();
    }

    /**
     * @return void
     */
    private function backfillMissingSlugs(): void
    {
        $rows = $this->fetchAll(
            "SELECT id, kingdom_id, mailer_class, action_method
               FROM email_templates
              WHERE slug IS NULL OR slug = ''
              ORDER BY id ASC",
        );

        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $templateId = (int)$row['id'];
            $kingdomId = $row['kingdom_id'] !== null ? (int)$row['kingdom_id'] : null;
            $baseSlug = $this->knownSlugFor(
                $row['mailer_class'] ?? null,
                $row['action_method'] ?? null,
                $templateId,
            );
            $slug = $this->ensureUniqueSlug($baseSlug, $templateId, $kingdomId);

            $this->execute(
                "UPDATE email_templates
                    SET slug = '" . addslashes($slug) . "',
                        modified = '{$now}',
                        modified_by = 1
                  WHERE id = {$templateId}",
            );
        }
    }

    /**
     * @param string|null $mailerClass
     * @param string|null $actionMethod
     * @param int $templateId
     * @return string
     */
    private function knownSlugFor(?string $mailerClass, ?string $actionMethod, int $templateId): string
    {
        $mapping = [
            'App\Mailer\KMPMailer::resetPassword' => 'password-reset',
            'App\Mailer\KMPMailer::mobileCard' => 'mobile-card-url',
            'App\Mailer\KMPMailer::newRegistration' => 'member-registration-welcome',
            'App\Mailer\KMPMailer::notifySecretaryOfNewMember' => 'member-registration-secretary',
            'App\Mailer\KMPMailer::notifySecretaryOfNewMinorMember' => 'member-registration-secretary-minor',
            'App\Mailer\KMPMailer::notifyOfWarrant' => 'warrant-issued',
            'App\Mailer\KMPMailer::sendFromTemplate' => 'award-recommendation-submitted',
            'Officers\Mailer\OfficersMailer::notifyOfHire' => 'officer-hire-notification',
            'Officers\Mailer\OfficersMailer::notifyOfRelease' => 'officer-release-notification',
            'Activities\Mailer\ActivitiesMailer::notifyApprover' => 'authorization-approval-request',
            'Activities\Mailer\ActivitiesMailer::notifyRequester' => 'authorization-request-update',
            'Activities\Mailer\ActivitiesMailer::notifyApproverOfRetraction' => 'authorization-request-retracted',
        ];

        $key = ($mailerClass ?? '') . '::' . ($actionMethod ?? '');
        if (isset($mapping[$key])) {
            return $mapping[$key];
        }

        if (!empty($mailerClass) && !empty($actionMethod)) {
            $shortMailer = preg_replace('/Mailer$/', '', basename(str_replace('\\', '/', $mailerClass))) ?: 'template';
            $actionSlug = $this->slugify((string)$actionMethod);

            return 'legacy-' . $this->slugify($shortMailer) . '-' . $actionSlug;
        }

        return 'legacy-template-' . $templateId;
    }

    /**
     * @param string $baseSlug
     * @param int $templateId
     * @param int|null $kingdomId
     * @return string
     */
    private function ensureUniqueSlug(string $baseSlug, int $templateId, ?int $kingdomId): string
    {
        $slug = $baseSlug;
        $suffix = 1;

        while ($this->slugExists($slug, $templateId, $kingdomId)) {
            $suffix++;
            $slug = $baseSlug . '-' . $suffix;
        }

        return $slug;
    }

    /**
     * @param string $slug
     * @param int $templateId
     * @param int|null $kingdomId
     * @return bool
     */
    private function slugExists(string $slug, int $templateId, ?int $kingdomId): bool
    {
        $kingdomClause = $kingdomId === null
            ? 'kingdom_id IS NULL'
            : 'kingdom_id = ' . $kingdomId;

        $row = $this->fetchRow(
            "SELECT id
               FROM email_templates
              WHERE slug = '" . addslashes($slug) . "'
                AND {$kingdomClause}
                AND id != {$templateId}
              LIMIT 1",
        );

        return $row !== false && $row !== null;
    }

    /**
     * @param string $value
     * @return string
     */
    private function slugify(string $value): string
    {
        $value = preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $value) ?? $value;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;

        return trim($value, '-');
    }
}
