<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Add kingdom_id to workflow_definitions and app_settings for multi-tenancy.
 *
 * Nullable kingdom_id (FK to branches) enables per-kingdom configuration.
 * NULL kingdom_id means "global/default" — used as fallback when no
 * kingdom-specific override exists.
 */
class AddKingdomScopingToWorkflowDefinitionsAndAppSettings extends BaseMigration
{
    /**
     * @return void
     */
    public function change(): void
    {
        // --- workflow_definitions: add kingdom_id ---
        $wfDef = $this->table('workflow_definitions');

        $wfDef
            ->addColumn('kingdom_id', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'entity_type',
                'comment' => 'FK to branches — NULL means global/default workflow',
            ])
            ->addForeignKey('kingdom_id', 'branches', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_wf_definitions_kingdom',
            ])
            ->addIndex(['kingdom_id'], [
                'name' => 'idx_wf_definitions_kingdom',
            ])
            ->update();

        // Drop the old unique index on slug alone
        $wfDef->removeIndexByName('idx_wf_definitions_slug')->update();

        // Add composite unique index on (slug, kingdom_id)
        $wfDef
            ->addIndex(['slug', 'kingdom_id'], [
                'unique' => true,
                'name' => 'idx_wf_definitions_slug_kingdom',
            ])
            ->update();

        // --- app_settings: add kingdom_id ---
        $appSettings = $this->table('app_settings');

        $appSettings
            ->addColumn('kingdom_id', 'integer', [
                'null' => true,
                'default' => null,
                'after' => 'required',
                'comment' => 'FK to branches — NULL means global/default setting',
            ])
            ->addForeignKey('kingdom_id', 'branches', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'NO_ACTION',
                'constraint' => 'fk_app_settings_kingdom',
            ])
            ->addIndex(['kingdom_id'], [
                'name' => 'idx_app_settings_kingdom',
            ])
            ->update();

        // Drop the old unique index on name alone
        $appSettings->removeIndexByName('name')->update();

        // Add composite unique index on (name, kingdom_id)
        $appSettings
            ->addIndex(['name', 'kingdom_id'], [
                'unique' => true,
                'name' => 'idx_app_settings_name_kingdom',
            ])
            ->update();
    }
}
