<?php

declare(strict_types=1);

namespace App\Services;

use Cake\Log\Log;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;

/**
 * Discovers all Mailer classes + methods and ensures a matching
 * email_templates row exists for each one.
 *
 * Used by EmailTemplatesController::sync() and by the installer
 * to seed email templates on a fresh database.
 */
class EmailTemplateSyncService
{
    /**
     * Discover all mailer methods and insert templates for any that are missing.
     *
     * @return array{created: int, skipped: int}
     */
    public function sync(): array
    {
        /** @var \App\Model\Table\EmailTemplatesTable $templatesTable */
        $templatesTable = TableRegistry::getTableLocator()->get('EmailTemplates');

        $discoveryService = new MailerDiscoveryService();
        $allMailers = $discoveryService->discoverAllMailers();

        $created = 0;
        $skipped = 0;

        foreach ($allMailers as $mailer) {
            foreach ($mailer['methods'] as $method) {
                $existing = $templatesTable->findForMailer(
                    $mailer['class'],
                    $method['name'],
                );

                if ($existing !== null) {
                    $skipped++;
                    continue;
                }

                $emailTemplate = $templatesTable->newEntity([
                    'mailer_class' => $mailer['class'],
                    'action_method' => $method['name'],
                    'subject_template' => $this->convertSubjectVariables(
                        $method['defaultSubject'] ?? 'Email from ' . $mailer['shortName'],
                    ),
                    'available_vars' => $method['availableVars'],
                    'is_active' => true,
                ]);

                $this->prefillFromFileTemplates($emailTemplate);

                if ($templatesTable->save($emailTemplate)) {
                    $created++;
                }
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Load default HTML/text content from file-based email templates.
     *
     * @param \App\Model\Entity\EmailTemplate $emailTemplate
     * @return void
     */
    public function prefillFromFileTemplates(\App\Model\Entity\EmailTemplate $emailTemplate): void
    {
        $mailerClass = $emailTemplate->mailer_class;
        $actionMethod = $emailTemplate->action_method;
        $templateName = Inflector::underscore($actionMethod);

        if (str_starts_with($mailerClass, 'App\\Mailer\\')) {
            $templatesPath = ROOT . DS . 'templates' . DS . 'email';
        } elseif (preg_match('/^([^\\\\]+)\\\\Mailer\\\\/', $mailerClass, $matches)) {
            $pluginName = $matches[1];
            $templatesPath = ROOT . DS . 'plugins' . DS . $pluginName . DS . 'templates' . DS . 'email';
        } else {
            return;
        }

        $htmlPath = $templatesPath . DS . 'html' . DS . $templateName . '.php';
        if (file_exists($htmlPath)) {
            $emailTemplate->html_template = $this->convertTemplateVariables(
                (string)file_get_contents($htmlPath),
            );
        }

        $textPath = $templatesPath . DS . 'text' . DS . $templateName . '.php';
        if (file_exists($textPath)) {
            $emailTemplate->text_template = $this->convertTemplateVariables(
                (string)file_get_contents($textPath),
            );
        }

        // Fall back: use text template as HTML when no HTML file exists.
        if (empty($emailTemplate->html_template) && !empty($emailTemplate->text_template)) {
            $emailTemplate->html_template = $emailTemplate->text_template;
        }
    }

    /**
     * Convert CakePHP PHP template syntax to double-curly-brace variable syntax.
     *
     * @param string $content
     * @return string
     */
    public function convertTemplateVariables(string $content): string
    {
        $content = preg_replace('/\<\?=\s*\$([a-zA-Z_][a-zA-Z0-9_]*)\s*\?\>/', '{{$1}}', $content);
        $content = preg_replace('/\<\?=\s*h\(\s*\$([a-zA-Z_][a-zA-Z0-9_]*)\s*\)\s*\?\>/', '{{$1}}', $content);

        $content = preg_replace_callback(
            '/<\?php\s+if\s*\((.+?)\)\s*:\s*\?>/s',
            function ($matches) {
                $condition = preg_replace('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', '$1', $matches[1]);

                return '{{#if ' . trim($condition) . '}}';
            },
            $content,
        );

        $content = preg_replace('/<\?php\s+endif;\s*\?>/', '{{/if}}', $content);

        if (preg_match('/<\?php\s+else\s*:\s*\?>/', $content)) {
            Log::warning('Email template conversion encountered <?php else : ?> block — converting to {{else}}');
            $content = preg_replace('/<\?php\s+else\s*:\s*\?>/', '{{else}}', $content);
        }

        if (preg_match('/<\?php\s+elseif\s*\((.+?)\)\s*:\s*\?>/', $content)) {
            Log::warning('Email template conversion encountered <?php elseif (...) : ?> — stripping (not supported in safe DSL)');
            $content = preg_replace('/<\?php\s+elseif\s*\((.+?)\)\s*:\s*\?>/', '', $content);
        }

        return (string)$content;
    }

    /**
     * Convert $variableName placeholders in a subject string to {{variableName}}.
     *
     * @param string $subject
     * @return string
     */
    public function convertSubjectVariables(string $subject): string
    {
        return (string)preg_replace('/\$([a-zA-Z_][a-zA-Z0-9_]*)/', '{{$1}}', $subject);
    }
}
