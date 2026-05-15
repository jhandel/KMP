<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Entity\PlatformAdmin;
use App\Services\Platform\PlatformAdminAuthService;
use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Throwable;

/**
 * Create a break-glass platform admin account.
 */
class PlatformAdminCreateCommand extends Command
{
    /**
     * Get default command name.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'platform_admin:create';
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
            ->setDescription(
                'Create a platform admin account. Email codes are sent during login and action verification.',
            )
            ->addArgument('email', ['required' => true, 'help' => 'Platform admin email address.'])
            ->addOption('display-name', ['required' => true, 'help' => 'Display name.'])
            ->addOption('password', [
                'required' => true,
                'help' => 'Initial password. Must be at least 14 characters.',
            ])
            ->addOption('role', [
                'default' => PlatformAdmin::ROLE_BREAK_GLASS,
                'help' => 'Role: viewer|operator|provisioner|security_admin|break_glass.',
            ]);
    }

    /**
     * Execute platform admin creation.
     *
     * @param \Cake\Console\Arguments $args Arguments
     * @param \Cake\Console\ConsoleIo $io Console IO
     * @return int|null
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        try {
            $result = (new PlatformAdminAuthService())->createAdmin(
                (string)$args->getArgument('email'),
                (string)$args->getOption('display-name'),
                (string)$args->getOption('password'),
                false,
                (string)$args->getOption('role'),
            );
            $io->success(sprintf('Platform admin %s created.', $result['admin']->email));
            $io->out('Login and action verification codes will be emailed to the platform admin address.');

            return Command::CODE_SUCCESS;
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::CODE_ERROR;
        }
    }
}
