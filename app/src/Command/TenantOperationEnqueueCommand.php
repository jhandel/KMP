<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Entity\PlatformAdmin;
use App\Services\Platform\TenantOperationCommandCatalog;
use App\Services\Platform\TenantOperationGatewayService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\ORM\Locator\LocatorAwareTrait;
use RuntimeException;
use Throwable;

/**
 * Submit approved tenant operation requests into the durable operation queue.
 */
class TenantOperationEnqueueCommand extends Command
{
    use LocatorAwareTrait;

    /**
     * @return string
     */
    public static function defaultName(): string
    {
        return 'tenant_operation:enqueue';
    }

    /**
     * @param \Cake\Console\ConsoleOptionParser $parser Parser
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $approvedOperations = implode(', ', TenantOperationCommandCatalog::allowedGatewayOperations());

        return parent::buildOptionParser($parser)
            ->setDescription('Queue an approved tenant operation request through the operations gateway.')
            ->addOption('operation', [
                'required' => true,
                'help' => sprintf('Approved operation: %s.', $approvedOperations),
            ])
            ->addOption('tenant', [
                'default' => null,
                'help' => 'Tenant slug for single target mode.',
            ])
            ->addOption('tenants', [
                'default' => null,
                'help' => 'Comma-separated tenant slugs for selected target mode.',
            ])
            ->addOption('all-tenants', [
                'boolean' => true,
                'default' => false,
                'help' => 'Target all tenants (all-tenant mode).',
            ])
            ->addOption('parameters-json', [
                'default' => '{}',
                'help' => 'Operation parameters JSON payload (for example {"status":"maintenance"}).',
            ])
            ->addOption('requester-email', [
                'required' => true,
                'help' => 'Platform admin email that requested the operation.',
            ])
            ->addOption('approved-by-email', [
                'default' => null,
                'help' => 'Platform admin email that approved the operation. Defaults to requester.',
            ])
            ->addOption('idempotency-key', [
                'default' => null,
                'help' => 'Optional idempotency key. If omitted, one is derived from payload.',
            ])
            ->addOption('idempotency-scope', [
                'default' => 'tenant',
                'help' => 'Idempotency scope to store on the job row (must match catalog policy).',
            ])
            ->addOption('correlation-id', [
                'default' => null,
                'help' => 'Correlation id override. If omitted, a UUID is generated.',
            ])
            ->addOption('batch-size', [
                'default' => 25,
                'help' => 'Tenant targets processed per submission batch for selected/all-tenant mode.',
            ])
            ->addOption('batch-pause-ms', [
                'default' => 0,
                'help' => 'Pause between submission batches in milliseconds.',
            ])
            ->addOption('max-targets', [
                'default' => null,
                'help' => 'Safety guard: fail if resolved targets exceed this number.',
            ])
            ->addOption('continue-on-error', [
                'boolean' => true,
                'default' => true,
                'help' => 'Continue submitting other tenants when one tenant submission fails.',
            ]);
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return int|null
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            $tenantTargetMode = $this->targetMode($args);
            $tenantSlugs = $this->tenantSlugsForMode($tenantTargetMode, $args);
            $parameters = $this->decodeParameters((string)$args->getOption('parameters-json'));
            $requester = $this->resolveAdminByEmail((string)$args->getOption('requester-email'));
            $approvedByOption = $args->getOption('approved-by-email');
            $approver = $approvedByOption !== null && (string)$approvedByOption !== ''
                ? $this->resolveAdminByEmail((string)$approvedByOption)
                : $requester;

            $submission = (new TenantOperationGatewayService())->submitApprovedRequest(
                operation: (string)$args->getOption('operation'),
                requester: $requester,
                tenantTargetMode: $tenantTargetMode,
                parameters: $parameters,
                tenantSlugs: $tenantSlugs,
                approvedBy: $approver,
                correlationId: $this->nullableString($args->getOption('correlation-id')),
                idempotencyKey: $this->nullableString($args->getOption('idempotency-key')),
                idempotencyScope: (string)$args->getOption('idempotency-scope'),
                bulkOptions: [
                    'batch_size' => max(1, (int)$args->getOption('batch-size')),
                    'pause_ms' => max(0, (int)$args->getOption('batch-pause-ms')),
                    'max_targets' => $this->nullableInt($args->getOption('max-targets')),
                    'continue_on_error' => (bool)$args->getOption('continue-on-error'),
                ],
            );
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::CODE_ERROR;
        }

        $io->success(sprintf(
            'Submitted %d operation job(s), reused %d existing job(s), failed %d tenant(s).',
            (int)$submission['created_count'],
            (int)$submission['deduplicated_count'],
            (int)($submission['failed_count'] ?? 0),
        ));
        $io->out(sprintf('Correlation ID: %s', (string)$submission['correlation_id']));
        if (!empty($submission['parent_job_id'])) {
            $io->out(sprintf('Bulk parent job: %d', (int)$submission['parent_job_id']));
        }
        foreach ((array)($submission['failures'] ?? []) as $failure) {
            $io->warning(sprintf(
                '- tenant=%s error=%s',
                (string)($failure['tenant_slug'] ?? 'unknown'),
                (string)($failure['message'] ?? 'unknown failure'),
            ));
        }
        foreach ($submission['jobs'] as $job) {
            $tenantSlug = is_array($job->input ?? null) ? (string)($job->input['tenant_slug'] ?? '') : '';
            $io->out(sprintf(
                '- job=%d tenant=%s state=%s',
                (int)$job->id,
                $tenantSlug,
                (string)$job->lifecycle_state,
            ));
        }

        return Command::CODE_SUCCESS;
    }

    /**
     * @param \Cake\Console\Arguments $args Arguments
     * @return string
     */
    private function targetMode(Arguments $args): string
    {
        $allTenants = (bool)$args->getOption('all-tenants');
        $tenants = $this->nullableString($args->getOption('tenants'));
        $tenant = $this->nullableString($args->getOption('tenant'));
        if ($allTenants) {
            if ($tenants !== null || $tenant !== null) {
                throw new RuntimeException('--all-tenants cannot be combined with --tenant or --tenants.');
            }

            return 'all-tenant';
        }
        if ($tenants !== null) {
            if ($tenant !== null) {
                throw new RuntimeException('--tenants cannot be combined with --tenant.');
            }

            return 'selected';
        }
        if ($tenant === null) {
            throw new RuntimeException('Provide exactly one target mode: --tenant, --tenants, or --all-tenants.');
        }

        return 'single';
    }

    /**
     * @param string $targetMode Target mode
     * @param \Cake\Console\Arguments $args Arguments
     * @return array<int, string>|null
     */
    private function tenantSlugsForMode(string $targetMode, Arguments $args): ?array
    {
        return match ($targetMode) {
            'single' => [(string)$args->getOption('tenant')],
            'selected' => array_values(array_filter(array_map(
                static fn (string $slug): string => trim($slug),
                explode(',', (string)$args->getOption('tenants')),
            ), static fn (string $slug): bool => $slug !== '')),
            'all-tenant' => null,
            default => null,
        };
    }

    /**
     * @param string $rawJson Raw JSON payload
     * @return array<string, mixed>
     */
    private function decodeParameters(string $rawJson): array
    {
        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('parameters-json must decode to a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param string $email Admin email
     * @return \App\Model\Entity\PlatformAdmin
     */
    private function resolveAdminByEmail(string $email): PlatformAdmin
    {
        $admin = $this->fetchTable('PlatformAdmins')->find()
            ->where(['email' => strtolower(trim($email)), 'status' => PlatformAdmin::STATUS_ACTIVE])
            ->first();
        if (!$admin instanceof PlatformAdmin) {
            throw new RuntimeException(sprintf('Active platform admin not found: %s', $email));
        }

        return $admin;
    }

    /**
     * @param mixed $value Candidate value
     * @return string|null
     */
    private function nullableString(mixed $value): ?string
    {
        $candidate = trim((string)$value);

        return $candidate === '' ? null : $candidate;
    }

    /**
     * @param mixed $value Candidate integer value
     * @return int|null
     */
    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || trim((string)$value) === '') {
            return null;
        }

        return (int)$value;
    }
}
