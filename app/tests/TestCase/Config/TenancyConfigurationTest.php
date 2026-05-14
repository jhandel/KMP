<?php
declare(strict_types=1);

namespace App\Test\TestCase\Config;

use Cake\TestSuite\TestCase;

class TenancyConfigurationTest extends TestCase
{
    public function testPlatformDatasourceDoesNotFallbackToTenantDatabaseEnvironment(): void
    {
        $appConfig = (string)file_get_contents(CONFIG . 'app.php');
        $platformBlock = $this->extractArrayBlock($appConfig, '"platform" => [');

        $this->assertStringContainsString('PLATFORM_DB_DATABASE', $platformBlock);
        $this->assertStringContainsString('"kmp_platform"', $platformBlock);
        $this->assertStringContainsString('PLATFORM_DATABASE_URL', $platformBlock);
        $this->assertStringNotContainsString('env("DB_DATABASE"', $platformBlock);
        $this->assertStringNotContainsString('env("MYSQL_DB_NAME"', $platformBlock);
        $this->assertStringNotContainsString('env("DATABASE_URL"', $platformBlock);
    }

    public function testPlatformMigrationIsSeparatedFromTenantMigrations(): void
    {
        $this->assertFileExists(CONFIG . 'PlatformMigrations' . DS . '20260414000000_CreatePlatformTenancy.php');
        $this->assertFileDoesNotExist(CONFIG . 'Migrations' . DS . '20260414000000_CreatePlatformTenancy.php');

        $migrationService = (string)file_get_contents(ROOT . DS . 'src' . DS . 'Services' . DS . 'Tenant' . DS . 'TenantMigrationService.php');
        $this->assertStringContainsString("'connection' => 'platform'", $migrationService);
        $this->assertStringContainsString("'source' => 'PlatformMigrations'", $migrationService);
    }

    private function extractArrayBlock(string $contents, string $needle): string
    {
        $start = strpos($contents, $needle);
        $this->assertNotFalse($start);
        $offset = (int)$start + strlen($needle);
        $depth = 1;
        $length = strlen($contents);

        for ($i = $offset; $i < $length; $i++) {
            if ($contents[$i] === '[') {
                $depth++;
            }
            if ($contents[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($contents, (int)$start, $i - (int)$start + 1);
                }
            }
        }

        $this->fail(sprintf('Unable to extract config block for %s.', $needle));
    }
}
