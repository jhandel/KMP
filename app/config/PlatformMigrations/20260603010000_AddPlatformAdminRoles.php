<?php
declare(strict_types=1);

use Migrations\BaseMigration;

class AddPlatformAdminRoles extends BaseMigration
{
    /**
     * Add role metadata for platform admin RBAC.
     */
    public function change(): void
    {
        $table = $this->table('platform_admins');
        if (!$table->hasColumn('role')) {
            $table->addColumn('role', 'string', [
                'default' => 'break_glass',
                'limit' => 32,
                'null' => false,
                'after' => 'status',
            ]);
        }
        if (!$table->hasIndex(['role'])) {
            $table->addIndex(['role'], [
                'name' => 'idx_platform_admins_role',
            ]);
        }
        $table->update();
    }
}
