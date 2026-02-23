<?php

declare(strict_types=1);

namespace App\Services;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Log\Log;

/**
 * Queries GHCR OCI API for available image tags and GitHub Releases for metadata.
 *
 * Caches results for 5 minutes to avoid API rate limits.
 */
class ContainerRegistryService
{
    private const CACHE_KEY_TAGS = 'container_registry_tags';
    private const CACHE_KEY_RELEASES = 'container_registry_releases';
    private const CACHE_CONFIG = 'default';
    private const CACHE_TTL = 300; // 5 minutes

    private string $registry;
    private string $ghRepo;
    private Client $httpClient;

    public function __construct()
    {
        $this->registry = trim((string)Configure::read('App.containerRegistry', 'ghcr.io/jhandel/kmp'));
        $this->ghRepo = 'jhandel/KMP';
        $this->httpClient = new Client(['timeout' => 10]);
    }

    /**
     * Get available image tags from GHCR, enriched with release metadata.
     *
     * @return array<int, array{tag: string, channel: string, published: string|null, releaseNotes: string|null, isCurrent: bool}>
     */
    public function getAvailableVersions(): array
    {
        $tags = $this->fetchTags();
        $releases = $this->fetchReleases();

        $currentTag = trim((string)Configure::read('App.imageTag', 'unknown'));

        $releaseMap = [];
        foreach ($releases as $release) {
            $releaseMap[$release['tag']] = $release;
        }

        $versions = [];
        foreach ($tags as $tag) {
            $channel = $this->classifyChannel($tag);
            $release = $releaseMap[$tag] ?? null;

            $versions[] = [
                'tag' => $tag,
                'channel' => $channel,
                'published' => $release['published'] ?? null,
                'releaseNotes' => $release['body'] ?? null,
                'prerelease' => $release['prerelease'] ?? ($channel !== 'release'),
                'isCurrent' => ($tag === $currentTag),
            ];
        }

        // Sort: current channel first, then by tag descending
        usort($versions, function ($a, $b) {
            if ($a['isCurrent'] !== $b['isCurrent']) {
                return $a['isCurrent'] ? -1 : 1;
            }

            return version_compare($b['tag'], $a['tag']);
        });

        return $versions;
    }

    /**
     * Get the latest available tag for a given channel.
     */
    public function getLatestForChannel(string $channel): ?array
    {
        $versions = $this->getAvailableVersions();
        foreach ($versions as $version) {
            if ($version['channel'] === $channel && !$version['isCurrent']) {
                return $version;
            }
        }

        return null;
    }

    /**
     * Get current deployment info.
     *
     * @return array{version: string, imageTag: string, channel: string, registry: string, provider: string}
     */
    public function getCurrentInfo(): array
    {
        return [
            'version' => trim((string)Configure::read('App.version', 'unknown')),
            'imageTag' => trim((string)Configure::read('App.imageTag', 'unknown')),
            'channel' => trim((string)Configure::read('App.releaseChannel', 'release')),
            'registry' => $this->registry,
            'provider' => trim((string)Configure::read('App.deploymentProvider', 'docker')),
        ];
    }

    /**
     * Fetch image tags from GHCR OCI Distribution API.
     *
     * @return array<string>
     */
    private function fetchTags(): array
    {
        $cached = Cache::read(self::CACHE_KEY_TAGS, self::CACHE_CONFIG);
        if ($cached !== null) {
            return $cached;
        }

        try {
            // GHCR OCI Distribution API: /v2/{owner}/{repo}/tags/list
            $parts = explode('/', $this->registry, 2);
            $registryHost = $parts[0] ?? 'ghcr.io';
            $imagePath = $parts[1] ?? 'jhandel/kmp';

            $url = "https://{$registryHost}/v2/{$imagePath}/tags/list";
            $response = $this->httpClient->get($url, [], [
                'headers' => ['Accept' => 'application/json'],
            ]);

            if (!$response->isOk()) {
                Log::warning("GHCR tag fetch failed: HTTP {$response->getStatusCode()}");

                return [];
            }

            $data = $response->getJson();
            $tags = $data['tags'] ?? [];

            // Filter out non-app image tags (base images, digests, sidecar/installer tags)
            $tags = array_filter($tags, function (string $tag) {
                return !str_starts_with($tag, 'php')
                    && !str_starts_with($tag, 'sha256-')
                    && !str_starts_with($tag, 'sha-')
                    && !str_starts_with($tag, 'installer-')
                    && !str_starts_with($tag, 'updater-');
            });
            $tags = array_values($tags);
            rsort($tags);

            Cache::write(self::CACHE_KEY_TAGS, $tags, self::CACHE_CONFIG);

            return $tags;
        } catch (\Throwable $e) {
            Log::error('GHCR tag fetch error: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Fetch release metadata from GitHub Releases API.
     *
     * @return array<int, array{tag: string, name: string, published: string, body: string, prerelease: bool}>
     */
    private function fetchReleases(): array
    {
        $cached = Cache::read(self::CACHE_KEY_RELEASES, self::CACHE_CONFIG);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $url = "https://api.github.com/repos/{$this->ghRepo}/releases";
            $response = $this->httpClient->get($url, ['per_page' => 50], [
                'headers' => [
                    'Accept' => 'application/vnd.github.v3+json',
                    'User-Agent' => 'KMP-App',
                ],
            ]);

            if (!$response->isOk()) {
                Log::warning("GitHub releases fetch failed: HTTP {$response->getStatusCode()}");

                return [];
            }

            $releases = [];
            foreach ($response->getJson() as $r) {
                $tag = $r['tag_name'] ?? '';
                // Skip non-app releases (installer CLI, updater sidecar)
                if (str_starts_with($tag, 'installer-') || str_starts_with($tag, 'updater-')) {
                    continue;
                }
                $releases[] = [
                    'tag' => $tag,
                    'name' => $r['name'] ?? '',
                    'published' => $r['published_at'] ?? '',
                    'body' => $r['body'] ?? '',
                    'prerelease' => $r['prerelease'] ?? false,
                ];
            }

            Cache::write(self::CACHE_KEY_RELEASES, $releases, self::CACHE_CONFIG);

            return $releases;
        } catch (\Throwable $e) {
            Log::error('GitHub releases fetch error: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * Classify an image tag into a release channel.
     */
    private function classifyChannel(string $tag): string
    {
        $lower = strtolower($tag);
        if (str_contains($lower, 'nightly')) {
            return 'nightly';
        }
        if (str_contains($lower, 'dev')) {
            return 'dev';
        }
        if (str_contains($lower, 'beta') || str_contains($lower, 'rc') || str_contains($lower, 'alpha')) {
            return 'beta';
        }
        if ($tag === 'latest') {
            return 'release';
        }

        // Semver-like tags (v1.2.3, 1.2.3) are release channel
        if (preg_match('/^v?\d+\.\d+/', $tag)) {
            return 'release';
        }

        return 'release';
    }
}
