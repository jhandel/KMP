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
     * @return array<int, array{tag: string, version: string|null, channel: string, published: string|null, releaseNotes: string|null, isCurrent: bool}>
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
                'version' => $this->extractVersionFromTag($tag),
                'channel' => $channel,
                'published' => $release['published'] ?? null,
                'releaseNotes' => $release['body'] ?? null,
                'prerelease' => $release['prerelease'] ?? ($channel !== 'release'),
                'isCurrent' => ($tag === $currentTag),
            ];
        }

        // Sort current first, then explicit semantic versions, then published/tag fallback.
        usort($versions, function ($a, $b) {
            if ($a['isCurrent'] !== $b['isCurrent']) {
                return $a['isCurrent'] ? -1 : 1;
            }

            $aVersion = $a['version'] ?? null;
            $bVersion = $b['version'] ?? null;
            if (is_string($aVersion) && is_string($bVersion)) {
                $versionComparison = version_compare($bVersion, $aVersion);
                if ($versionComparison !== 0) {
                    return $versionComparison;
                }
            } elseif (is_string($aVersion) xor is_string($bVersion)) {
                return is_string($aVersion) ? -1 : 1;
            }

            $publishedComparison = strcmp((string)($b['published'] ?? ''), (string)($a['published'] ?? ''));
            if ($publishedComparison !== 0) {
                return $publishedComparison;
            }

            return strcmp($b['tag'], $a['tag']);
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
        $cached = $this->readCachedArray(self::CACHE_KEY_TAGS);
        if ($cached !== null) {
            return $cached;
        }

        try {
            // GHCR OCI Distribution API: /v2/{owner}/{repo}/tags/list
            $parts = explode('/', $this->registry, 2);
            $registryHost = $parts[0] ?? 'ghcr.io';
            $imagePath = $parts[1] ?? 'jhandel/kmp';

            $url = "https://{$registryHost}/v2/{$imagePath}/tags/list";
            $headers = [
                'Accept' => 'application/json',
                'User-Agent' => 'KMP-App',
            ];
            $configuredToken = trim((string)env('GHCR_TOKEN', ''));
            if ($configuredToken !== '') {
                $headers['Authorization'] = "Bearer {$configuredToken}";
            }

            $response = $this->httpClient->get($url, [], ['headers' => $headers]);
            if ($response->getStatusCode() === 401) {
                $challenge = $response->getHeaderLine('WWW-Authenticate');
                $token = $this->fetchGhcrAuthToken($challenge);
                if ($token !== null) {
                    $headers['Authorization'] = "Bearer {$token}";
                    $response = $this->httpClient->get($url, [], ['headers' => $headers]);
                }
            }

            if (!$response->isOk()) {
                Log::warning("GHCR tag fetch failed: HTTP {$response->getStatusCode()}");

                return [];
            }

            $data = $response->getJson();
            $tags = is_array($data['tags'] ?? null) ? $data['tags'] : [];

            // Filter out non-app image tags (base images, digests, sidecar/installer tags)
            $tags = array_filter($tags, function (mixed $tag) {
                if (!is_string($tag)) {
                    return false;
                }
                return !str_starts_with($tag, 'php')
                    && !str_starts_with($tag, 'sha256-')
                    && !str_starts_with($tag, 'sha-')
                    && !str_starts_with($tag, 'installer-')
                    && !str_starts_with($tag, 'updater-');
            });
            $tags = array_values($tags);
            rsort($tags);

            $this->writeCachedArray(self::CACHE_KEY_TAGS, $tags);

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
        $cached = $this->readCachedArray(self::CACHE_KEY_RELEASES);
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

            $this->writeCachedArray(self::CACHE_KEY_RELEASES, $releases);

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

    /**
     * Extract semantic version metadata from image tags when present.
     */
    private function extractVersionFromTag(string $tag): ?string
    {
        $trimmedTag = trim($tag);
        if (preg_match('/^v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z\.-]+)?)$/', $trimmedTag, $matches)) {
            return $matches[1];
        }

        if (preg_match('/^(?:dev|nightly|beta|release)-v?(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z\.-]+)?)$/i', $trimmedTag, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Read cached array values with service-specific TTL control.
     *
     * @return array<mixed>|null
     */
    private function readCachedArray(string $key): ?array
    {
        $cached = Cache::read($key, self::CACHE_CONFIG);
        if (!is_array($cached)) {
            return null;
        }

        if (array_key_exists('cachedAt', $cached) && array_key_exists('value', $cached)) {
            $cachedAt = (int)$cached['cachedAt'];
            if ((time() - $cachedAt) > self::CACHE_TTL) {
                return null;
            }

            return is_array($cached['value']) ? $cached['value'] : null;
        }

        // Legacy cache format (plain array). Treat as expired so we can migrate safely.
        return null;
    }

    /**
     * Write cached array values with timestamp for explicit TTL handling.
     *
     * @param array<mixed> $value
     */
    private function writeCachedArray(string $key, array $value): void
    {
        Cache::write($key, [
            'cachedAt' => time(),
            'value' => $value,
        ], self::CACHE_CONFIG);
    }

    /**
     * Exchange GHCR Bearer challenge for an access token.
     */
    private function fetchGhcrAuthToken(string $challenge): ?string
    {
        if (!preg_match('/^Bearer\s+(.+)$/i', trim($challenge), $match)) {
            return null;
        }

        $attributes = [];
        preg_match_all('/([a-zA-Z][a-zA-Z0-9_-]*)="([^"]*)"/', $match[1], $pairs, PREG_SET_ORDER);
        foreach ($pairs as $pair) {
            $attributes[strtolower($pair[1])] = $pair[2];
        }

        $realm = $attributes['realm'] ?? '';
        if ($realm === '') {
            return null;
        }

        $query = [];
        if (!empty($attributes['service'])) {
            $query['service'] = $attributes['service'];
        }
        if (!empty($attributes['scope'])) {
            $query['scope'] = $attributes['scope'];
        }

        $response = $this->httpClient->get($realm, $query, [
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'KMP-App',
            ],
        ]);
        if (!$response->isOk()) {
            Log::warning("GHCR token fetch failed: HTTP {$response->getStatusCode()}");

            return null;
        }

        $tokenPayload = $response->getJson();
        $token = $tokenPayload['token'] ?? $tokenPayload['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            return null;
        }

        return $token;
    }
}
