<?php

declare(strict_types=1);

namespace App\Services\Installer;

class InstallerPreflightService
{
    /**
     * Execute installer preflight checks.
     *
     * @return array<int, array{label:string,ok:bool,detail:string}>
     */
    public function runChecks(): array
    {
        $requiredExtensions = ['mbstring', 'intl', 'pdo_mysql', 'gd', 'zip', 'openssl', 'yaml'];
        $checks = [
            [
                'label' => 'PHP >= 8.1',
                'ok' => version_compare(PHP_VERSION, '8.1.0', '>='),
                'detail' => PHP_VERSION,
            ],
        ];

        foreach ($requiredExtensions as $extension) {
            $isLoaded = extension_loaded($extension);
            $checks[] = [
                'label' => sprintf('PHP extension: %s', $extension),
                'ok' => $isLoaded,
                'detail' => $isLoaded ? 'loaded' : 'missing',
            ];
        }

        foreach ([LOGS, TMP, WWW_ROOT . 'img'] as $path) {
            $isWritable = is_dir($path) && is_writable($path);
            $checks[] = [
                'label' => sprintf('Writable: %s', $path),
                'ok' => $isWritable,
                'detail' => $isWritable ? 'ok' : 'not writable',
            ];
        }

        // config/.env must be writable (create it if absent, update it if present)
        $envPath = CONFIG . '.env';
        if (file_exists($envPath)) {
            $envWritable = is_writable($envPath);
            $envDetail = $envWritable ? 'ok' : 'not writable — run: chmod 664 config/.env';
        } else {
            $envWritable = is_writable(CONFIG);
            $envDetail = $envWritable ? 'will be created' : 'config/ directory not writable — check permissions';
        }
        $checks[] = [
            'label' => 'Writable: config/.env',
            'ok' => $envWritable,
            'detail' => $envDetail,
        ];

        $isHttps = env('HTTPS') === 'on' || env('HTTPS') === '1';
        $checks[] = [
            'label' => 'HTTPS enabled',
            'ok' => $isHttps,
            'detail' => $isHttps ? 'enabled' : 'off (recommended for production)',
        ];

        return $checks;
    }
}
