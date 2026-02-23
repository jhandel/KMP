<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Canonical capability matrix for deployment update scenarios.
 */
class DeploymentUpdateCapabilityService
{
    /**
     * Resolve normalized capabilities for a deployment provider.
     *
     * @return array<string, mixed>
     */
    public function getCapabilitiesForProvider(string $provider): array
    {
        $normalized = $this->normalizeProvider($provider);
        $matrix = $this->getMatrix();

        if (isset($matrix[$normalized])) {
            return $matrix[$normalized];
        }

        return $this->buildManualOnlyCapabilities($normalized);
    }

    /**
     * Return full capability matrix keyed by provider id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getMatrix(): array
    {
        return [
            'docker' => [
                'provider' => 'docker',
                'label' => 'Docker / VPC',
                'web_update' => true,
                'requires_root_access' => true,
                'update_mode' => 'updater-sidecar',
                'components' => [
                    'app' => $this->component(true, 'automated', 'Update sidecar pulls image and recreates app container.'),
                    'database_migrations' => $this->component(true, 'automated-on-app-start', 'Migrations run via app startup/update commands.'),
                    'database_engine' => $this->component(true, 'manual-planned', 'DB engine upgrades require planned compose/image change and downtime window.'),
                    'proxy' => $this->component(true, 'manual-or-cli', 'Caddy image and config upgrades managed with compose update/restart.'),
                    'updater' => $this->component(true, 'manual-or-cli', 'Updater sidecar image can be upgraded with compose pull/up.'),
                ],
            ],
            'railway' => [
                'provider' => 'railway',
                'label' => 'Railway',
                'web_update' => true,
                'requires_root_access' => false,
                'update_mode' => 'platform-api',
                'components' => [
                    'app' => $this->component(true, 'automated', 'Railway deploys new image via API/CLI.'),
                    'database_migrations' => $this->component(true, 'automated-post-deploy', 'Installer/provider runs plugin migrations through Railway SSH.'),
                    'database_engine' => $this->component(false, 'platform-managed', 'Managed by Railway service lifecycle.'),
                    'proxy' => $this->component(false, 'platform-managed', 'Railway edge proxy/TLS is platform-managed.'),
                    'updater' => $this->component(false, 'not-applicable', 'No updater sidecar in Railway architecture.'),
                ],
            ],
            'shared' => [
                'provider' => 'shared',
                'label' => 'Shared hosting (no root)',
                'web_update' => false,
                'requires_root_access' => false,
                'update_mode' => 'manual-guided',
                'components' => [
                    'app' => $this->component(true, 'manual-upload', 'Operator uploads prepared release artifact via host panel/FTP.'),
                    'database_migrations' => $this->component(true, 'manual-guided', 'Run migration endpoint/script with least-privilege DB user when available.'),
                    'database_engine' => $this->component(false, 'provider-managed', 'DB engine upgrades are controlled by hosting provider.'),
                    'proxy' => $this->component(false, 'provider-managed', 'Web server/proxy updates are controlled by hosting provider.'),
                    'updater' => $this->component(false, 'not-applicable', 'No sidecar or host-level updater access expected.'),
                ],
            ],
            'fly' => $this->buildDeferredCapabilities('fly', 'Fly.io'),
            'aws' => $this->buildDeferredCapabilities('aws', 'AWS'),
            'azure' => $this->buildDeferredCapabilities('azure', 'Azure'),
            'vps' => $this->buildDeferredCapabilities('vps', 'Cloud VM (VPS)'),
        ];
    }

    /**
     * Normalize provider aliases to canonical ids.
     */
    public function normalizeProvider(string $provider): string
    {
        $normalized = strtolower(trim($provider));
        return match ($normalized) {
            'vpc' => 'docker',
            'shared-hosting', 'shared_hosting', 'sharedhosting' => 'shared',
            default => $normalized !== '' ? $normalized : 'docker',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function component(bool $supported, string $mode, string $notes): array
    {
        return [
            'supported' => $supported,
            'mode' => $mode,
            'notes' => $notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDeferredCapabilities(string $provider, string $label): array
    {
        return [
            'provider' => $provider,
            'label' => $label,
            'web_update' => false,
            'requires_root_access' => true,
            'update_mode' => 'deferred-implementation',
            'components' => [
                'app' => $this->component(false, 'deferred', 'Automated provider adapter not implemented yet.'),
                'database_migrations' => $this->component(false, 'deferred', 'Automated migration orchestration not implemented yet.'),
                'database_engine' => $this->component(false, 'deferred', 'Engine update strategy not implemented yet.'),
                'proxy' => $this->component(false, 'deferred', 'Proxy/update strategy not implemented yet.'),
                'updater' => $this->component(false, 'deferred', 'Updater strategy not implemented yet.'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildManualOnlyCapabilities(string $provider): array
    {
        return [
            'provider' => $provider,
            'label' => ucfirst($provider),
            'web_update' => false,
            'requires_root_access' => false,
            'update_mode' => 'manual-only',
            'components' => [
                'app' => $this->component(true, 'manual', 'Use manual deployment instructions for this environment.'),
                'database_migrations' => $this->component(false, 'manual', 'No automated migration orchestration available.'),
                'database_engine' => $this->component(false, 'manual', 'Database engine lifecycle is outside app automation.'),
                'proxy' => $this->component(false, 'manual', 'Proxy lifecycle is outside app automation.'),
                'updater' => $this->component(false, 'manual', 'No updater-sidecar integration available.'),
            ],
        ];
    }
}
