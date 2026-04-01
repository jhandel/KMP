<?php

declare(strict_types=1);

namespace App\Test\TestCase\Services\WorkflowEngine;

use App\Test\TestCase\BaseTestCase;
use Cake\ORM\TableRegistry;

/**
 * Tests for kingdom-scoped AppSettings lookup.
 */
class AppSettingsKingdomScopingTest extends BaseTestCase
{
    private $settingsTable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsTable = TableRegistry::getTableLocator()->get('AppSettings');
    }

    /**
     * Helper: create an app setting with optional kingdom_id.
     */
    private function createSetting(string $name, string $value, ?int $kingdomId = null): \App\Model\Entity\AppSetting
    {
        $entity = $this->settingsTable->newEmptyEntity();
        $entity->saving = true;
        $entity->name = $name;
        $entity->value = $value;
        $entity->type = 'string';
        $entity->required = false;
        $entity->kingdom_id = $kingdomId;

        return $this->settingsTable->saveOrFail($entity);
    }

    // =========================================================
    // getSettingForKingdom() tests
    // =========================================================

    /**
     * Returns kingdom-specific setting when it exists.
     */
    public function testGetSettingForKingdomReturnsKingdomSpecific(): void
    {
        $key = 'Test.Setting.' . uniqid();
        $kingdomId = self::KINGDOM_BRANCH_ID;

        $this->createSetting($key, 'global-value');
        $this->createSetting($key, 'kingdom-value', $kingdomId);

        $result = $this->settingsTable->getSettingForKingdom($key, $kingdomId);

        $this->assertEquals('kingdom-value', $result);
    }

    /**
     * Falls back to global when no kingdom-specific setting exists.
     */
    public function testGetSettingForKingdomFallsBackToGlobal(): void
    {
        $key = 'Test.Setting.' . uniqid();
        $kingdomId = self::KINGDOM_BRANCH_ID;

        $this->createSetting($key, 'global-value');

        $result = $this->settingsTable->getSettingForKingdom($key, $kingdomId);

        $this->assertEquals('global-value', $result);
    }

    /**
     * Returns default when neither kingdom nor global setting exists.
     */
    public function testGetSettingForKingdomReturnsDefaultWhenNotFound(): void
    {
        $key = 'Test.Nonexistent.' . uniqid();
        $kingdomId = self::KINGDOM_BRANCH_ID;

        $result = $this->settingsTable->getSettingForKingdom($key, $kingdomId, 'my-default');

        $this->assertEquals('my-default', $result);
    }

    /**
     * Returns null default when nothing found and no default specified.
     */
    public function testGetSettingForKingdomReturnsNullByDefault(): void
    {
        $key = 'Test.Nonexistent.' . uniqid();
        $kingdomId = self::KINGDOM_BRANCH_ID;

        $result = $this->settingsTable->getSettingForKingdom($key, $kingdomId);

        $this->assertNull($result);
    }

    /**
     * Kingdom-specific setting does not bleed to other kingdoms.
     */
    public function testGetSettingForKingdomIgnoresOtherKingdoms(): void
    {
        $key = 'Test.Setting.' . uniqid();
        $otherKingdomId = self::TEST_BRANCH_LOCAL_ID;

        $this->createSetting($key, 'other-kingdom-value', $otherKingdomId);

        $result = $this->settingsTable->getSettingForKingdom($key, self::KINGDOM_BRANCH_ID, 'fallback');

        $this->assertEquals('fallback', $result);
    }

    /**
     * Kingdom-specific value takes priority over global for same name.
     */
    public function testGetSettingForKingdomPrefersKingdomOverGlobal(): void
    {
        $key = 'Test.Priority.' . uniqid();
        $kingdomId = self::KINGDOM_BRANCH_ID;

        $this->createSetting($key, 'global');
        $this->createSetting($key, 'kingdom', $kingdomId);

        // Verify global exists for a different branch
        $global = $this->settingsTable->getSettingForKingdom($key, self::TEST_BRANCH_LOCAL_ID);
        $this->assertEquals('global', $global);

        // But kingdom gets its own value
        $result = $this->settingsTable->getSettingForKingdom($key, $kingdomId);
        $this->assertEquals('kingdom', $result);
    }

    // =========================================================
    // setSettingForKingdom() tests
    // =========================================================

    /**
     * Can set a kingdom-specific setting.
     */
    public function testSetSettingForKingdomCreatesKingdomSetting(): void
    {
        $key = 'Test.Create.' . uniqid();
        $kingdomId = self::KINGDOM_BRANCH_ID;

        $result = $this->settingsTable->setSettingForKingdom($key, 'kingdom-val', $kingdomId);

        $this->assertTrue($result);

        $setting = $this->settingsTable->find()
            ->where(['name' => $key, 'kingdom_id' => $kingdomId])
            ->first();
        $this->assertNotNull($setting);
    }

    /**
     * Setting kingdom-specific does not affect global.
     */
    public function testSetSettingForKingdomDoesNotAffectGlobal(): void
    {
        $key = 'Test.Isolate.' . uniqid();
        $kingdomId = self::KINGDOM_BRANCH_ID;

        $this->createSetting($key, 'original-global');
        $this->settingsTable->setSettingForKingdom($key, 'kingdom-override', $kingdomId);

        // Global unchanged
        $global = $this->settingsTable->find()
            ->where(['name' => $key, 'kingdom_id IS' => null])
            ->first();
        $this->assertNotNull($global);
        $this->assertEquals('original-global', $global->value);

        // Kingdom has its own
        $kingdom = $this->settingsTable->find()
            ->where(['name' => $key, 'kingdom_id' => $kingdomId])
            ->first();
        $this->assertNotNull($kingdom);
    }
}
