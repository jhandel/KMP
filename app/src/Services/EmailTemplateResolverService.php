<?php
declare(strict_types=1);

namespace App\Services;

use App\Exception\EmailTemplateNotFoundException;
use App\Model\Entity\EmailTemplate;
use App\Model\Table\EmailTemplatesTable;
use Cake\ORM\Locator\LocatorAwareTrait;

/**
 * Resolves active email templates by stable slug identity.
 *
 * Falls back to the global (kingdom_id = NULL) template when no kingdom-specific
 * override exists. Throws EmailTemplateNotFoundException (never returns null) so
 * callers can rely on a valid, active template or fail explicitly.
 *
 * @property \App\Model\Table\EmailTemplatesTable $EmailTemplates
 */
class EmailTemplateResolverService
{
    use LocatorAwareTrait;

    protected EmailTemplatesTable $emailTemplates;

    /**
     * @param \App\Model\Table\EmailTemplatesTable|null $emailTemplates Injected for testing; auto-fetched if null.
     */
    public function __construct(?EmailTemplatesTable $emailTemplates = null)
    {
        $this->emailTemplates = $emailTemplates ?? $this->fetchTable('EmailTemplates');
    }

    /**
     * Resolve an active template by slug with optional kingdom scope.
     *
     * If a kingdom-specific template exists it takes precedence; otherwise the
     * global (kingdom_id = NULL) template is returned.
     *
     * @param string $slug Stable template slug
     * @param int|null $kingdomId Kingdom branch ID, or null for global-only lookup
     * @return \App\Model\Entity\EmailTemplate
     * @throws \App\Exception\EmailTemplateNotFoundException
     */
    public function resolveBySlug(string $slug, ?int $kingdomId = null): EmailTemplate
    {
        $template = $this->emailTemplates->findForSlug($slug, $kingdomId);
        if ($template === null) {
            throw EmailTemplateNotFoundException::forSlug($slug, $kingdomId);
        }

        return $template;
    }

}
