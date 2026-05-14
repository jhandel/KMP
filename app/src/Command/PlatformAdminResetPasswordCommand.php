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
 * Reset a platform admin password from a trusted CLI session.
 */
class PlatformAdminResetPasswordCommand extends Command
{
    /**
     * Get default command name.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'platform_admin:reset_password';
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
            ->setDescription('Reset a platform admin password and rotate one-time recovery/MFA codes.')
            ->addArgument('email', ['required' => true, 'help' => 'Platform admin email address.'])
            ->addOption('password', [
                'default' => null,
                'help' => 'New password. If omitted, a random password is generated.',
            ])
            ->addOption('no-require-change', [
                'boolean' => true,
                'default' => false,
                'help' => 'Do not require the admin to change this password on next login.',
            ]);
    }

    /**
     * Execute trusted CLI password reset.
     *
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return int|null
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $password = (string)($args->getOption('password') ?: $this->generatePassword());
        try {
            $codes = (new PlatformAdminAuthService())->resetPassword(
                (string)$args->getArgument('email'),
                $password,
                !(bool)$args->getOption('no-require-change'),
            );
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::CODE_ERROR;
        }

        $io->success(sprintf('Password reset for %s.', $args->getArgument('email')));
        if (!(bool)$args->getOption('no-require-change')) {
            $io->warning('This account must change its password on next login.');
        }
        $io->out('New password: ' . $password);
        $io->warning('Store these replacement one-time MFA/recovery codes securely. They will not be shown again.');
        foreach ($codes as $code) {
            $io->out($code);
        }

        return Command::CODE_SUCCESS;
    }

    /**
     * Generate a random policy-compliant reset password.
     *
     * @return string
     */
    private function generatePassword(): string
    {
        return bin2hex(random_bytes(12)) . '!Aa1';
    }
}
