<?php

declare(strict_types=1);

namespace App\Services\Installer;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Throwable;

class InstallerLockService
{
    /**
     * Get installer lock file path from configuration.
     *
     * @return string
     */
    public function getLockFilePath(): string
    {
        $defaultPath = TMP . 'installer' . DS . 'install.lock';
        $configuredPath = Configure::read('Installer.lockFile', $defaultPath);
        if (!is_string($configuredPath) || $configuredPath === '') {
            return $defaultPath;
        }

        return $configuredPath;
    }

    /**
     * Check whether installer lock exists.
     *
     * @return bool
     */
    public function isLocked(): bool
    {
        return file_exists($this->getLockFilePath());
    }

    /**
     * Determine whether requests should be routed through installer.
     *
     * @return bool
     */
    public function isInstallerRequired(): bool
    {
        if ($this->isLocked()) {
            return false;
        }

        $requiredTables = Configure::read('Installer.requiredTables', ['members']);
        if (!is_array($requiredTables) || $requiredTables === []) {
            $requiredTables = ['members'];
        }

        return !$this->hasRequiredTables($requiredTables);
    }

    /**
     * Write lock metadata file.
     *
     * @param array<string, mixed> $metadata
     * @return bool
     */
    public function writeLock(array $metadata = []): bool
    {
        $lockFile = $this->getLockFilePath();
        $lockDir = dirname($lockFile);

        if (!is_dir($lockDir) && !mkdir($lockDir, 0775, true) && !is_dir($lockDir)) {
            return false;
        }

        $payload = array_merge([
            'installedAt' => gmdate(DATE_ATOM),
        ], $metadata);

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return false;
        }

        return file_put_contents($lockFile, $encoded . PHP_EOL) !== false;
    }

    /**
     * Remove installer lock file if present.
     *
     * @return bool
     */
    public function clearLock(): bool
    {
        $lockFile = $this->getLockFilePath();
        if (!file_exists($lockFile)) {
            return true;
        }

        return unlink($lockFile);
    }

    /**
     * Check if all required tables exist on default connection.
     *
     * @param array<int, string> $requiredTables
     * @return bool
     */
    private function hasRequiredTables(array $requiredTables): bool
    {
        try {
            $connection = ConnectionManager::get('default');
            $tables = array_map(
                static fn($tableName) => strtolower((string)$tableName),
                $connection->getSchemaCollection()->listTables()
            );
        } catch (Throwable) {
            return false;
        }

        foreach ($requiredTables as $requiredTable) {
            if (!is_string($requiredTable) || $requiredTable === '') {
                continue;
            }

            if (!in_array(strtolower($requiredTable), $tables, true)) {
                return false;
            }
        }

        return true;
    }
}
