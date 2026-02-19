<?php

declare(strict_types=1);

namespace App\Services\Updater;

use App\KMP\StaticHelpers;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Http\Client;
use RuntimeException;

class ManifestClient
{
    /**
     * Allowed updater channels.
     */
    private const ALLOWED_CHANNELS = ['stable', 'beta', 'dev', 'nightly'];

    /**
     * Fetch release metadata and map to updater manifest shape.
     *
     * @param string|null $repository
     * @return array<string, mixed>
     */
    public function fetchManifest(?string $repository = null): array
    {
        $repositoryName = $this->normalizeRepository((string)($repository ?? Configure::read('Updater.githubRepository', '')));
        $channel = $this->resolveChannel();
        $url = $this->buildReleasesUrl($repositoryName);
        $cacheSeed = $url . '|' . $channel;

        $cacheKey = 'updater_manifest_' . sha1($cacheSeed);
        $cachedPayload = Cache::read($cacheKey, 'default');
        if (
            is_array($cachedPayload)
            && isset($cachedPayload['expiresAt'], $cachedPayload['manifest'])
            && is_array($cachedPayload['manifest'])
            && (int)$cachedPayload['expiresAt'] >= time()
        ) {
            return $cachedPayload['manifest'];
        }

        $this->assertRateLimit($cacheSeed);
        $this->assertAllowedHost($url);

        $client = new Client(['timeout' => 15]);
        $response = $client->get($url, [], [
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'KMP-Updater',
            ],
        ]);
        if (!$response->isOk()) {
            throw new RuntimeException(sprintf('GitHub releases request failed with HTTP %d.', $response->getStatusCode()));
        }

        $payload = json_decode($response->getStringBody(), true);
        if (!is_array($payload)) {
            throw new RuntimeException('GitHub releases payload is not valid JSON.');
        }
        $manifest = $this->mapReleasesToManifest($payload, $channel);

        $cacheTtlSeconds = max(1, (int)Configure::read('Updater.manifestCacheSeconds', 300));
        Cache::write(
            $cacheKey,
            [
                'expiresAt' => time() + $cacheTtlSeconds,
                'manifest' => $manifest,
            ],
            'default'
        );

        return $manifest;
    }

    /**
     * Build API URL for repository releases.
     *
     * @param string $repository
     * @return string
     */
    private function buildReleasesUrl(string $repository): string
    {
        $baseUrl = rtrim(trim((string)Configure::read('Updater.githubApiBaseUrl', 'https://api.github.com')), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('Updater GitHub API base URL is not configured.');
        }

        [$owner, $repo] = explode('/', $repository, 2);

        return sprintf('%s/repos/%s/%s/releases', $baseUrl, rawurlencode($owner), rawurlencode($repo));
    }

    /**
     * Normalize and validate configured repository.
     *
     * @param string $repository
     * @return string
     */
    private function normalizeRepository(string $repository): string
    {
        $normalizedRepository = trim($repository);
        if ($normalizedRepository === '') {
            throw new RuntimeException('Updater GitHub repository is not configured.');
        }

        $parsedUrl = parse_url($normalizedRepository);
        if (is_array($parsedUrl) && isset($parsedUrl['host'])) {
            $normalizedRepository = trim((string)($parsedUrl['path'] ?? ''), '/');
        }

        if (preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $normalizedRepository) !== 1) {
            throw new RuntimeException('Updater GitHub repository must use "owner/repo" format.');
        }

        return $normalizedRepository;
    }

    /**
     * Map release payload into the updater manifest structure.
     *
     * @param array<mixed> $payload
     * @param string $channel
     * @return array<string, mixed>
     */
    private function mapReleasesToManifest(array $payload, string $channel): array
    {
        $releases = [];
        foreach ($payload as $release) {
            if (!is_array($release)) {
                continue;
            }

            if ((bool)($release['draft'] ?? false)) {
                continue;
            }

            $tagName = trim((string)($release['tag_name'] ?? ''));
            if ($tagName === '' || !$this->matchesReleaseTrain($tagName, $channel)) {
                continue;
            }

            $releases[] = [
                'version' => $this->versionFromTag($tagName, $channel),
                'tag' => $tagName,
                'releaseHash' => $this->normalizeReleaseHash((string)($release['target_commitish'] ?? '')),
                'name' => trim((string)($release['name'] ?? '')),
                'publishedAt' => (string)($release['published_at'] ?? ''),
                'releaseUrl' => (string)($release['html_url'] ?? ''),
                'package' => [
                    'url' => $this->resolvePackageUrl($release),
                ],
            ];
        }

        return [
            'latestVersion' => (string)($releases[0]['version'] ?? ''),
            'releases' => $releases,
            'channel' => $channel,
        ];
    }

    /**
     * Resolve package URL from release assets or fallback archive URLs.
     *
     * @param array<string, mixed> $release
     * @return string
     */
    private function resolvePackageUrl(array $release): string
    {
        $assets = $release['assets'] ?? null;
        if (is_array($assets)) {
            foreach ($assets as $asset) {
                if (!is_array($asset)) {
                    continue;
                }

                $assetUrl = trim((string)($asset['browser_download_url'] ?? ''));
                if ($assetUrl !== '') {
                    return $assetUrl;
                }
            }
        }

        return (string)($release['zipball_url'] ?? $release['tarball_url'] ?? '');
    }

    /**
     * Resolve the configured updater channel.
     *
     * @return string
     */
    private function resolveChannel(): string
    {
        $fallback = $this->normalizeChannel((string)Configure::read('Updater.channel', 'stable'));
        $channel = $this->normalizeChannel((string)StaticHelpers::getAppSetting('Updater.Channel', $fallback));

        if ($channel !== '') {
            return $channel;
        }

        return $fallback !== '' ? $fallback : 'stable';
    }

    /**
     * Normalize channel value and validate against allowed channels.
     *
     * @param string $channel
     * @return string
     */
    private function normalizeChannel(string $channel): string
    {
        $normalized = strtolower(trim($channel));
        if (!in_array($normalized, self::ALLOWED_CHANNELS, true)) {
            return '';
        }

        return $normalized;
    }

    /**
     * Normalize release hash string.
     *
     * @param string $hash
     * @return string
     */
    private function normalizeReleaseHash(string $hash): string
    {
        $normalized = strtolower(trim($hash));
        if (preg_match('/^[0-9a-f]{7,64}$/', $normalized) !== 1) {
            return '';
        }

        return $normalized;
    }

    /**
     * Determine if a release tag belongs to the configured release train.
     *
     * @param string $tagName
     * @param string $channel
     * @return bool
     */
    private function matchesReleaseTrain(string $tagName, string $channel): bool
    {
        $normalizedTag = strtolower(trim($tagName));
        if ($normalizedTag === '') {
            return false;
        }

        if ($normalizedTag === $channel) {
            return true;
        }

        foreach ([$channel . '/', $channel . '-', $channel . '_'] as $prefix) {
            if (str_starts_with($normalizedTag, $prefix)) {
                return true;
            }
        }

        if ($channel === 'stable') {
            return preg_match('/^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', trim($tagName)) === 1;
        }

        return false;
    }

    /**
     * Parse version value from release tag.
     *
     * @param string $tagName
     * @param string $channel
     * @return string
     */
    private function versionFromTag(string $tagName, string $channel): string
    {
        $trimmedTag = trim($tagName);
        $normalizedTag = strtolower($trimmedTag);
        foreach ([$channel . '/', $channel . '-', $channel . '_'] as $prefix) {
            if (str_starts_with($normalizedTag, $prefix)) {
                $candidate = substr($trimmedTag, strlen($prefix));

                return $candidate !== '' ? ltrim($candidate, 'vV') : $trimmedTag;
            }
        }

        return ltrim($trimmedTag, 'vV');
    }

    /**
     * Validate updater host against allowlist.
     *
     * @param string $url
     * @return void
     */
    private function assertAllowedHost(string $url): void
    {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            throw new RuntimeException('Updater URL must include a valid host.');
        }

        $allowedHosts = Configure::read('Updater.allowedHosts', []);
        if (!is_array($allowedHosts) || $allowedHosts === []) {
            return;
        }

        foreach ($allowedHosts as $allowedHost) {
            if (!is_string($allowedHost)) {
                continue;
            }

            $normalized = strtolower(trim($allowedHost));
            if ($normalized === '') {
                continue;
            }

            if ($host === $normalized || str_ends_with($host, '.' . $normalized)) {
                return;
            }
        }

        throw new RuntimeException(sprintf('Updater host "%s" is not in Updater.allowedHosts.', $host));
    }

    /**
     * Enforce minimum interval between outbound update checks.
     *
     * @param string $cacheSeed
     * @return void
     */
    private function assertRateLimit(string $cacheSeed): void
    {
        $minInterval = max(1, (int)Configure::read('Updater.checkIntervalSeconds', 60));
        $rateKey = 'updater_manifest_last_check_' . sha1($cacheSeed);
        $lastCheck = (int)(Cache::read($rateKey, 'default') ?? 0);
        $now = time();

        if ($lastCheck > 0 && ($now - $lastCheck) < $minInterval) {
            throw new RuntimeException(
                sprintf('Update checks are rate limited. Try again in %d seconds.', $minInterval - ($now - $lastCheck))
            );
        }

        Cache::write($rateKey, $now, 'default');
    }
}
