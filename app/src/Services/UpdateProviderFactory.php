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
        $provider = trim((string)Configure::read('App.deploymentProvider', 'docker'));

        return match ($provider) {
            'railway' => new RailwayUpdateProvider(),
            default => new DockerUpdateProvider(),
        };
    }
}
