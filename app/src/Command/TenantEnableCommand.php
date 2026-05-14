<?php
declare(strict_types=1);

namespace App\Command;

use App\Model\Entity\Tenant;
use Cake\Command\Command;

/**
 * Enable tenant traffic.
 */
class TenantEnableCommand extends Command
{
    use TenantStatusCommandTrait;

    /**
     * Get the default command name.
     *
     * @return string
     */
    public static function defaultName(): string
    {
        return 'tenant:enable';
    }

    /**
     * Status applied by this command.
     *
     * @return string
     */
    protected function status(): string
    {
        return Tenant::STATUS_ACTIVE;
    }
}
