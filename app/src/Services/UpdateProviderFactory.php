<?php

declare(strict_types=1);

namespace App\Services;

use Cake\Core\Configure;

/**
 * Returns the appropriate UpdateProvider based on the configured deployment provider.
 */
class UpdateProviderFactory
{
    /**
     * Create an UpdateProvider for the current deployment.
     */
    public static function create(): UpdateProviderInterface
    {
        $provider = strtolower(trim((string)Configure::read('App.deploymentProvider', 'docker')));
        $provider = match ($provider) {
            'vpc' => 'docker',
            'shared-hosting', 'shared_hosting', 'sharedhosting' => 'shared',
            default => $provider,
        };

        return match ($provider) {
            'railway' => new RailwayUpdateProvider(),
            'docker' => new DockerUpdateProvider(),
            'shared' => new SharedHostingUpdateProvider(),
            default => new ManualUpdateProvider($provider),
        };
    }
}
