<?php
declare(strict_types=1);

namespace App\Command;

use App\Services\Platform\PlatformAdminAuthService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Throwable;

/**
 * Seed a first-login platform admin for local/dev or break-glass setup.
 */
class PlatformAdminSeedCommand extends Command
{
    /**
     * Get default command name.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'platform_admin:seed';
    }

    /**
     * Build command option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser Parser
     * @return \Cake\Console\ConsoleOptionParser
     */
    public function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return parent::buildOptionParser($parser)
            ->setDescription('Create a first-login platform admin if missing, printing initial credentials once.')
            ->addOption('email', [
                'default' => env('PLATFORM_ADMIN_SEED_EMAIL', 'platform-admin@localhost.test'),
                'help' => 'Seed platform admin email address.',
            ])
            ->addOption('display-name', [
                'default' => env('PLATFORM_ADMIN_SEED_DISPLAY_NAME', 'Local Platform Admin'),
                'help' => 'Seed platform admin display name.',
            ])
            ->addOption('password', [
                'default' => env('PLATFORM_ADMIN_SEED_PASSWORD', null),
                'help' => 'Initial password. If omitted, a random password is generated.',
            ])
            ->addOption('force', [
                'boolean' => true,
                'default' => false,
                'help' => 'Replace the seed admin password and recovery codes if the account already exists.',
            ]);
    }

    /**
     * Execute seed admin creation.
     *
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return int|null
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $password = (string)($args->getOption('password') ?: $this->generatePassword());
        try {
            $result = (new PlatformAdminAuthService())->seedAdmin(
                (string)$args->getOption('email'),
                (string)$args->getOption('display-name'),
                $password,
                (bool)$args->getOption('force'),
            );
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::CODE_ERROR;
        }

        if (!$result['created'] && !$result['updated']) {
            $io->out(sprintf('Platform admin %s already exists; no changes made.', $result['admin']->email));
            $io->out('Use platform_admin:reset_password if you need to recover access.');

            return Command::CODE_SUCCESS;
        }

        $io->success(sprintf('Platform admin %s is ready.', $result['admin']->email));
        $io->warning('This account must change its password on first login.');
        $io->out('Initial password: ' . $password);
        $io->warning('Store these one-time MFA/recovery codes securely. They will not be shown again.');
        foreach ($result['recoveryCodes'] as $code) {
            $io->out($code);
        }

        return Command::CODE_SUCCESS;
    }

    /**
     * Generate a random policy-compliant initial password.
     *
     * @return string
     */
    private function generatePassword(): string
    {
        return bin2hex(random_bytes(12)) . '!Aa1';
    }
}
