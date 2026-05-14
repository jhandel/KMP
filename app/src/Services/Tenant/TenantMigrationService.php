<?php
declare(strict_types=1);

namespace App\Services\Tenant;

use App\KMP\KMPPluginInterface;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Database\Connection;
use Cake\Database\SchemaCache;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Migrations\Migrations;
use RuntimeException;

/**
 * Runs core and plugin migrations for a selected datasource connection.
 */
class TenantMigrationService
{
    /**
     * Run platform registry migrations against the platform datastore.
     *
     * Platform migrations are intentionally stored outside config/Migrations so
     * tenant databases never receive global tenant registry tables.
     *
     * @return array<int, string> Migrated scopes
     */
    public function migratePlatform(): array
    {
        $this->clearSchemaCache('platform');
        $migrations = new Migrations();
        if (
            !$migrations->migrate([
                'connection' => 'platform',
                'source' => 'PlatformMigrations',
            ])
        ) {
            throw new RuntimeException('Platform migrations failed on connection "platform".');
        }
        TableRegistry::getTableLocator()->clear();

        return ['platform'];
    }

    /**
     * Run core migrations followed by KMP/plugin migrations.
     *
     * @param string $connection Connection name
     * @param string|null $plugin Optional plugin limit
     * @return array<int, string> Migrated scopes
     */
    public function migrate(string $connection = 'tenant', ?string $plugin = null): array
    {
        $this->clearSchemaCache($connection);
        $migrations = new Migrations();
        $scopes = [];

        if ($plugin === null) {
            if (!$migrations->migrate(['connection' => $connection])) {
                throw new RuntimeException(sprintf('Core migrations failed on connection "%s".', $connection));
            }
            $scopes[] = 'app';
        }

        $plugins = $plugin === null ? $this->pluginsToMigrate() : [$plugin => 0];
        foreach (array_keys($plugins) as $pluginName) {
            if (!$migrations->migrate(['connection' => $connection, 'plugin' => $pluginName])) {
                throw new RuntimeException(sprintf(
                    'Plugin migrations failed for "%s" on connection "%s".',
                    $pluginName,
                    $connection,
                ));
            }
            $scopes[] = $pluginName;
        }

        TableRegistry::getTableLocator()->clear();

        return $scopes;
    }

    /**
     * Return plugin migration order using the legacy update_database rules.
     *
     * @return array<string, int>
     */
    public function pluginsToMigrate(): array
    {
        $pluginsToMigrate = [];
        foreach (Plugin::getCollection() as $name => $plugin) {
            if ($plugin instanceof KMPPluginInterface) {
                $pluginsToMigrate[$name] = $plugin->getMigrationOrder();
                continue;
            }
            if (is_dir($plugin->getPath() . 'config' . DS . 'Migrations')) {
                $pluginsToMigrate[$name] = 100;
            }
        }
        asort($pluginsToMigrate);

        return $pluginsToMigrate;
    }

    /**
     * Compute the schema version to store in platform tenant records.
     *
     * @return string
     */
    public function targetSchemaVersion(): string
    {
        $configured = Configure::read('Tenancy.requiredSchemaVersion');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $versions = [];
        foreach (glob(CONFIG . 'Migrations' . DS . '*.php') ?: [] as $file) {
            $versions[] = basename($file, '.php');
        }
        foreach (Plugin::getCollection() as $plugin) {
            $path = $plugin->getPath() . 'config' . DS . 'Migrations' . DS . '*.php';
            foreach (glob($path) ?: [] as $file) {
                $versions[] = basename($file, '.php');
            }
        }
        rsort($versions);

        return (string)($versions[0] ?? date('YmdHis'));
    }

    /**
     * Clear metadata cache for a connection when possible.
     *
     * @param string $connection Connection name
     * @return void
     */
    private function clearSchemaCache(string $connection): void
    {
        $db = ConnectionManager::get($connection);
        if ($db instanceof Connection && !empty($db->config()['cacheMetadata'])) {
            (new SchemaCache($db))->clear();
        }
    }
}
