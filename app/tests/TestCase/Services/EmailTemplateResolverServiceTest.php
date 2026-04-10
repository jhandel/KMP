<?php
declare(strict_types=1);

namespace App\Test\TestCase\Services;

use App\Exception\EmailTemplateNotFoundException;
use App\Model\Table\EmailTemplatesTable;
use App\Services\EmailTemplateResolverService;
use App\Test\TestCase\BaseTestCase;

/**
 * Tests for EmailTemplateResolverService.
 */
class EmailTemplateResolverServiceTest extends BaseTestCase
{
    protected EmailTemplatesTable $EmailTemplates;
    protected EmailTemplateResolverService $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipIfPostgres();
        $config = $this->getTableLocator()->exists('EmailTemplates')
            ? []
            : ['className' => EmailTemplatesTable::class];
        $this->EmailTemplates = $this->getTableLocator()->get('EmailTemplates', $config);
        $this->resolver = new EmailTemplateResolverService($this->EmailTemplates);
    }

    protected function tearDown(): void
    {
        unset($this->EmailTemplates, $this->resolver);
        $this->getTableLocator()->clear();
        parent::tearDown();
    }

    private function saveTemplate(array $data): object
    {
        $defaults = [
            'subject_template' => 'Test subject',
            'html_template' => 'Test body',
            'is_active' => true,
        ];
        $entity = $this->EmailTemplates->newEntity(array_merge($defaults, $data));
        $result = $this->EmailTemplates->save($entity);
        $this->assertNotFalse($result, 'Fixture template failed to save: ' . json_encode($entity->getErrors()));

        return $result;
    }

    public function testResolveBySlugReturnsGlobalTemplate(): void
    {
        $this->saveTemplate(['slug' => 'test-global-template', 'kingdom_id' => null]);

        $template = $this->resolver->resolveBySlug('test-global-template');
        $this->assertSame('test-global-template', $template->slug);
        $this->assertNull($template->kingdom_id);
    }

    public function testResolveBySlugWithKingdomFallsBackToGlobal(): void
    {
        $this->saveTemplate(['slug' => 'test-fallback-template', 'kingdom_id' => null]);

        $template = $this->resolver->resolveBySlug('test-fallback-template', 999);
        $this->assertSame('test-fallback-template', $template->slug);
        $this->assertNull($template->kingdom_id);
    }

    public function testResolveBySlugPrefersKingdomOverGlobal(): void
    {
        $this->saveTemplate(['slug' => 'test-override-template', 'kingdom_id' => null]);

        $branches = $this->getTableLocator()->get('Branches');
        $kingdom = $branches->find()->where(['type' => 'Kingdom'])->first();
        if ($kingdom === null) {
            $this->markTestSkipped('No kingdom branch available in test database');
        }

        $this->saveTemplate(['slug' => 'test-override-template', 'kingdom_id' => $kingdom->id]);

        $template = $this->resolver->resolveBySlug('test-override-template', $kingdom->id);
        $this->assertSame('test-override-template', $template->slug);
        $this->assertSame($kingdom->id, $template->kingdom_id);
    }

    public function testResolveBySlugThrowsWhenNotFound(): void
    {
        $this->expectException(EmailTemplateNotFoundException::class);
        $this->expectExceptionMessageMatches("/no active email template found for slug 'nonexistent-slug'/i");

        $this->resolver->resolveBySlug('nonexistent-slug');
    }

    public function testResolveBySlugThrowsWhenInactive(): void
    {
        $this->saveTemplate(['slug' => 'test-inactive-template', 'kingdom_id' => null, 'is_active' => false]);

        $this->expectException(EmailTemplateNotFoundException::class);
        $this->resolver->resolveBySlug('test-inactive-template');
    }

    public function testResolveBySlugExceptionMentionsKingdomScope(): void
    {
        $this->expectException(EmailTemplateNotFoundException::class);
        $this->expectExceptionMessageMatches('/kingdom #42/');

        $this->resolver->resolveBySlug('missing-slug', 42);
    }
}
