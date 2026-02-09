<?php

declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Add "Can Approve Warrant Rosters" permission and assign it to roles
 * that already have "Can Manage Warrants".
 */
class AddApproveWarrantRostersPermission extends AbstractMigration
{
    public function up(): void
    {
        // Create the permission
        $this->execute(
            "INSERT INTO permissions (name, is_system, is_super_user, require_active_membership, created, modified)
             VALUES ('Can Approve Warrant Rosters', 1, 0, 1, NOW(), NOW())"
        );

        // Find the new permission ID
        $row = $this->fetchRow("SELECT id FROM permissions WHERE name = 'Can Approve Warrant Rosters'");
        if (!$row) {
            return;
        }
        $newPermId = $row['id'];

        // Find roles that have "Can Manage Warrants" and grant them approval too
        $manageRow = $this->fetchRow("SELECT id FROM permissions WHERE name = 'Can Manage Warrants'");
        if ($manageRow) {
            $rows = $this->fetchAll(
                "SELECT role_id FROM roles_permissions WHERE permission_id = " . (int)$manageRow['id']
            );
            foreach ($rows as $r) {
                $this->execute(
                    "INSERT IGNORE INTO roles_permissions (role_id, permission_id)
                     VALUES ({$r['role_id']}, {$newPermId})"
                );
            }
        }
    }

    public function down(): void
    {
        $row = $this->fetchRow("SELECT id FROM permissions WHERE name = 'Can Approve Warrant Rosters'");
        if ($row) {
            $this->execute("DELETE FROM roles_permissions WHERE permission_id = " . (int)$row['id']);
            $this->execute("DELETE FROM permissions WHERE id = " . (int)$row['id']);
        }
    }
}
