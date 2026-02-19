<?php

declare(strict_types=1);

namespace App\Services\Updater;

use App\KMP\StaticHelpers;
use Cake\Core\Configure;
use Cake\Http\Client;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Executes in-place update workflow for the latest release in the selected channel.
 */
class UpgradePipelineService
{
    /**
     * Allowed updater channels.
     */
    private const ALLOWED_CHANNELS = ['stable', 'beta', 'dev', 'nightly'];

    /**
     * Paths that are not overwritten by package sync.
     *
     * @var array<int, string>
     */
    private const EXCLUDED_PATHS = [
        '.git',
        'dist',
        'node_modules',
        'app/tmp',
        'app/logs',
        'app/config/.env',
        'app/config/app_local.php',
        'app/config/release_hash.txt',
        'app/config/release_tag.txt',
        'app/images/uploaded',
        'app/webroot/img/custom',
    ];

    /**
     * Lock file path used for update execution guard.
     *
     * @var string|null
     */
    private ?string $lockFile = null;

    /**
     * Runtime working directory for updater operations.
     *
     * @var string|null
     */
    private ?string $runtimeDirectory = null;

    /**
     * Apply the latest matching release to this installation.
     *
     * @param string|null $repository Override repository in owner/repo format.
     * @return array<string, string>
     */
    public function applyLatestRelease(?string $repository = null): array
    {
        $this->acquireLock();
        $workingDir = $this->createWorkingDirectory();
        $backupDir = $workingDir . DS . 'backup';
        $extractDir = $workingDir . DS . 'extract';

        try {
            $release = $this->resolveLatestRelease($repository);
            $releaseTag = $release['tag'];
            $releaseHash = $release['releaseHash'];

            if ($this->isAlreadyInstalled($releaseHash, $releaseTag)) {
                return [
                    'status' => 'current',
                    'releaseTag' => $releaseTag,
                    'releaseHash' => $releaseHash,
                ];
            }

            $packageFile = $workingDir . DS . 'release.zip';
            $this->downloadFile($release['packageUrl'], $packageFile);
            $downloadedHash = strtolower((string)hash_file('sha256', $packageFile));
            if ($downloadedHash === '') {
                throw new RuntimeException('Downloaded package hash could not be calculated.');
            }

            $this->assertPackageHash(
                $downloadedHash,
                $release['checksumHash'],
                $release['digestHash']
            );

            $packageRoot = $this->extractArchive($packageFile, $extractDir);
            $installationRoot = $this->resolveInstallationRoot();
            $this->ensureDirectory($backupDir);

            $syncState = $this->synchronizePackage($packageRoot, $installationRoot, $backupDir);
            try {
                $this->runPostUpgradeCommands();
                $identityHash = $releaseHash !== '' ? $releaseHash : $downloadedHash;
                $this->writeReleaseIdentity($identityHash, $releaseTag);
            } catch (Throwable $exception) {
                $this->rollbackSynchronization($installationRoot, $backupDir, $syncState);
                throw $exception;
            }

            return [
                'status' => 'updated',
                'releaseTag' => $releaseTag,
                'releaseHash' => $releaseHash !== '' ? $releaseHash : $downloadedHash,
            ];
        } finally {
            $this->deleteDirectory($workingDir);
            $this->releaseLock();
        }
    }

    /**
     * Resolve and validate latest release metadata for update application.
     *
     * @param string|null $repository
     * @return array<string, string>
     */
    protected function resolveLatestRelease(?string $repository = null): array
    {
        $repositoryName = $this->normalizeRepository((string)($repository ?? Configure::read('Updater.githubRepository', '')));
        $channel = $this->resolveChannel();
        $releases = $this->fetchReleases($repositoryName);
        $release = $this->findLatestRelease($releases, $channel);

        if ($release === null) {
            throw new RuntimeException(sprintf('No matching release found for channel "%s".', $channel));
        }

        $tag = trim((string)($release['tag_name'] ?? ''));
        if ($tag === '') {
            throw new RuntimeException('Latest release has no tag.');
        }

        $zipAsset = $this->findZipAsset($release);
        $zipAssetName = trim((string)($zipAsset['name'] ?? ''));
        $packageUrl = $this->resolvePackageUrl($release, $zipAsset);
        if ($packageUrl === '') {
            throw new RuntimeException('Latest release does not provide a package URL.');
        }
        $this->assertAllowedHost($packageUrl);

        $digestHash = $this->extractSha256FromDigest((string)($zipAsset['digest'] ?? ''));
        $checksumHash = $this->fetchZipChecksumHash($release, $zipAssetName);
        if ($checksumHash !== '' && $digestHash !== '' && $checksumHash !== $digestHash) {
            throw new RuntimeException('Release checksum does not match release digest metadata.');
        }

        $releaseHashFromText = $this->fetchNamedHashAsset($release, 'release_hash.txt');
        $releaseHash = $checksumHash
            ?: $releaseHashFromText
            ?: $digestHash
            ?: $this->normalizeReleaseHash((string)($release['target_commitish'] ?? ''));

        return [
            'tag' => $tag,
            'packageUrl' => $packageUrl,
            'checksumHash' => $checksumHash,
            'digestHash' => $digestHash,
            'releaseHash' => $releaseHash,
        ];
    }

    /**
     * Download and decode release list from GitHub API.
     *
     * @param string $repository
     * @return array<int, mixed>
     */
    private function fetchReleases(string $repository): array
    {
        $url = $this->buildReleasesUrl($repository);
        $this->assertAllowedHost($url);

        $client = new Client(['timeout' => 20]);
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

        return $payload;
    }

    /**
     * Build repository releases API URL.
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
     * Normalize and validate repository name.
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
     * Resolve updater channel from AppSetting with config fallback.
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
     * Validate channel name.
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
     * Pick latest non-draft release matching selected channel.
     *
     * @param array<int, mixed> $releases
     * @param string $channel
     * @return array<string, mixed>|null
     */
    private function findLatestRelease(array $releases, string $channel): ?array
    {
        foreach ($releases as $release) {
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

            return $release;
        }

        return null;
    }

    /**
     * Check whether a tag belongs to channel release train.
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
     * Find primary zip asset from release assets.
     *
     * @param array<string, mixed> $release
     * @return array<string, mixed>
     */
    private function findZipAsset(array $release): array
    {
        $assets = $release['assets'] ?? [];
        if (!is_array($assets)) {
            return [];
        }

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $name = strtolower(trim((string)($asset['name'] ?? '')));
            $contentType = strtolower(trim((string)($asset['content_type'] ?? '')));
            $downloadUrl = trim((string)($asset['browser_download_url'] ?? ''));
            if ($downloadUrl === '') {
                continue;
            }

            if (str_ends_with($name, '.zip') || str_contains($contentType, 'zip')) {
                return $asset;
            }
        }

        return [];
    }

    /**
     * Resolve package URL from zip asset or GitHub archive fallbacks.
     *
     * @param array<string, mixed> $release
     * @param array<string, mixed> $zipAsset
     * @return string
     */
    private function resolvePackageUrl(array $release, array $zipAsset): string
    {
        $zipUrl = trim((string)($zipAsset['browser_download_url'] ?? ''));
        if ($zipUrl !== '') {
            return $zipUrl;
        }

        $assets = $release['assets'] ?? [];
        if (is_array($assets)) {
            foreach ($assets as $asset) {
                if (!is_array($asset)) {
                    continue;
                }
                $name = strtolower((string)($asset['name'] ?? ''));
                $assetUrl = trim((string)($asset['browser_download_url'] ?? ''));
                if ($assetUrl !== '' && !str_ends_with($name, '.sha256')) {
                    return $assetUrl;
                }
            }
        }

        return trim((string)($release['zipball_url'] ?? $release['tarball_url'] ?? ''));
    }

    /**
     * Resolve checksum hash from .sha256 asset.
     *
     * @param array<string, mixed> $release
     * @param string $zipAssetName
     * @return string
     */
    private function fetchZipChecksumHash(array $release, string $zipAssetName): string
    {
        $checksumText = $this->fetchTextAssetByMatcher(
            $release,
            static function (string $assetName) use ($zipAssetName): bool {
                if ($zipAssetName !== '' && $assetName === strtolower($zipAssetName) . '.sha256') {
                    return true;
                }

                return str_ends_with($assetName, '.sha256');
            }
        );
        if ($checksumText === '') {
            return '';
        }

        return $this->parseSha256FileContent($checksumText, $zipAssetName);
    }

    /**
     * Resolve release hash value from a named text asset.
     *
     * @param array<string, mixed> $release
     * @param string $filename
     * @return string
     */
    private function fetchNamedHashAsset(array $release, string $filename): string
    {
        $content = $this->fetchTextAssetByMatcher(
            $release,
            static fn(string $assetName): bool => $assetName === strtolower($filename)
        );
        if ($content === '') {
            return '';
        }

        return $this->normalizeReleaseHash($content);
    }

    /**
     * Fetch plain-text content from matching release asset.
     *
     * @param array<string, mixed> $release
     * @param callable(string): bool $matcher
     * @return string
     */
    private function fetchTextAssetByMatcher(array $release, callable $matcher): string
    {
        $assets = $release['assets'] ?? [];
        if (!is_array($assets)) {
            return '';
        }

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $name = strtolower(trim((string)($asset['name'] ?? '')));
            $assetUrl = trim((string)($asset['browser_download_url'] ?? ''));
            if ($assetUrl === '' || !$matcher($name)) {
                continue;
            }

            $this->assertAllowedHost($assetUrl);
            $client = new Client(['timeout' => 20]);
            $response = $client->get($assetUrl, [], [
                'headers' => [
                    'Accept' => 'text/plain',
                    'User-Agent' => 'KMP-Updater',
                ],
            ]);
            if (!$response->isOk()) {
                return '';
            }

            return $response->getStringBody();
        }

        return '';
    }

    /**
     * Parse a sha256 file payload and resolve hash for package asset.
     *
     * @param string $content
     * @param string $zipAssetName
     * @return string
     */
    private function parseSha256FileContent(string $content, string $zipAssetName): string
    {
        $normalizedZipName = strtolower(trim($zipAssetName));
        $lines = preg_split('/\r?\n/', $content) ?: [];
        $firstValidHash = '';

        foreach ($lines as $line) {
            $trimmedLine = trim((string)$line);
            if ($trimmedLine === '') {
                continue;
            }

            if (preg_match('/^([0-9a-fA-F]{64})\s+[* ]?(.+)$/', $trimmedLine, $matches) === 1) {
                $parsedHash = $this->normalizeReleaseHash($matches[1] ?? '');
                $parsedFilename = strtolower(trim((string)($matches[2] ?? '')));
                if ($parsedHash === '') {
                    continue;
                }

                if (
                    $normalizedZipName === ''
                    || $parsedFilename === $normalizedZipName
                    || str_ends_with($parsedFilename, '/' . $normalizedZipName)
                ) {
                    return $parsedHash;
                }

                if ($firstValidHash === '') {
                    $firstValidHash = $parsedHash;
                }

                continue;
            }

            if (preg_match('/^([0-9a-fA-F]{64})$/', $trimmedLine, $matches) === 1) {
                return $this->normalizeReleaseHash($matches[1] ?? '');
            }
        }

        if ($firstValidHash !== '') {
            return $firstValidHash;
        }

        if (preg_match('/([0-9a-fA-F]{64})/', $content, $matches) !== 1) {
            return '';
        }

        return $this->normalizeReleaseHash($matches[1] ?? '');
    }

    /**
     * Extract normalized SHA-256 value from GitHub digest metadata.
     *
     * @param string $digest
     * @return string
     */
    private function extractSha256FromDigest(string $digest): string
    {
        if (preg_match('/^sha256:([0-9a-fA-F]{64})$/', trim($digest), $matches) !== 1) {
            return '';
        }

        return $this->normalizeReleaseHash($matches[1] ?? '');
    }

    /**
     * Normalize release hash.
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
     * Ensure package hash matches expected release hash metadata.
     *
     * @param string $downloadedHash
     * @param string $checksumHash
     * @param string $digestHash
     * @return void
     */
    private function assertPackageHash(string $downloadedHash, string $checksumHash, string $digestHash): void
    {
        if ($checksumHash !== '' && $downloadedHash !== $checksumHash) {
            throw new RuntimeException('Downloaded package hash does not match release checksum file.');
        }
        if ($digestHash !== '' && $downloadedHash !== $digestHash) {
            throw new RuntimeException('Downloaded package hash does not match release digest metadata.');
        }
    }

    /**
     * Download URL to local file path.
     *
     * @param string $url
     * @param string $destinationPath
     * @return void
     */
    private function downloadFile(string $url, string $destinationPath): void
    {
        $this->assertAllowedHost($url);
        $destinationDirectory = dirname($destinationPath);
        $this->ensureDirectory($destinationDirectory);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/octet-stream\r\nUser-Agent: KMP-Updater\r\n",
                'timeout' => 120,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $sourceHandle = @fopen($url, 'rb', false, $context);
        if ($sourceHandle === false) {
            throw new RuntimeException('Failed to download update package.');
        }

        $destinationHandle = @fopen($destinationPath, 'wb');
        if ($destinationHandle === false) {
            fclose($sourceHandle);
            throw new RuntimeException('Failed to open temporary package file for writing.');
        }

        $copied = stream_copy_to_stream($sourceHandle, $destinationHandle);
        fclose($sourceHandle);
        fclose($destinationHandle);

        if ($copied === false || $copied <= 0) {
            throw new RuntimeException('Downloaded package is empty or unreadable.');
        }
    }

    /**
     * Extract downloaded zip archive and return package root.
     *
     * @param string $archivePath
     * @param string $extractDir
     * @return string
     */
    private function extractArchive(string $archivePath, string $extractDir): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive extension is required for in-place updates.');
        }

        $this->ensureDirectory($extractDir);
        $zip = new ZipArchive();
        $openResult = $zip->open($archivePath);
        if ($openResult !== true) {
            throw new RuntimeException('Downloaded package could not be opened as a zip archive.');
        }

        $extracted = $zip->extractTo($extractDir);
        $zip->close();
        if (!$extracted) {
            throw new RuntimeException('Downloaded package could not be extracted.');
        }

        return $this->locatePackageRoot($extractDir);
    }

    /**
     * Find extracted directory containing app/config/app.php.
     *
     * @param string $extractDir
     * @return string
     */
    private function locatePackageRoot(string $extractDir): string
    {
        $normalizedExtractDir = rtrim($extractDir, DS);
        if (is_file($normalizedExtractDir . DS . 'app' . DS . 'config' . DS . 'app.php')) {
            return $normalizedExtractDir;
        }

        $candidates = glob($normalizedExtractDir . DS . '*', GLOB_ONLYDIR) ?: [];
        foreach ($candidates as $candidate) {
            if (is_file($candidate . DS . 'app' . DS . 'config' . DS . 'app.php')) {
                return $candidate;
            }
        }

        throw new RuntimeException('Extracted package does not contain an application root.');
    }

    /**
     * Resolve installation root directory (repository root around app/).
     *
     * @return string
     */
    private function resolveInstallationRoot(): string
    {
        $repositoryRoot = dirname(ROOT);
        if (is_file($repositoryRoot . DS . 'app' . DS . 'config' . DS . 'app.php')) {
            return $repositoryRoot;
        }

        if (is_file(ROOT . DS . 'config' . DS . 'app.php')) {
            return ROOT;
        }

        throw new RuntimeException('Unable to resolve installation root.');
    }

    /**
     * Copy package files into installation root with rollback metadata.
     *
     * @param string $sourceRoot
     * @param string $targetRoot
     * @param string $backupRoot
     * @return array{createdFiles: array<int, string>, backedUpFiles: array<int, string>}
     */
    private function synchronizePackage(string $sourceRoot, string $targetRoot, string $backupRoot): array
    {
        $createdFiles = [];
        $backedUpFiles = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $relativePath = ltrim(str_replace($sourceRoot, '', $sourcePath), DS);
            $relativePath = str_replace('\\', '/', $relativePath);
            if ($relativePath === '' || $this->shouldSkipPath($relativePath)) {
                continue;
            }

            $targetPath = $targetRoot . DS . str_replace('/', DS, $relativePath);
            if ($item->isDir()) {
                $this->ensureDirectory($targetPath);

                continue;
            }
            if (!$item->isFile()) {
                continue;
            }

            $this->ensureDirectory(dirname($targetPath));
            if (is_file($targetPath)) {
                $backupPath = $backupRoot . DS . str_replace('/', DS, $relativePath);
                $this->ensureDirectory(dirname($backupPath));
                if (!copy($targetPath, $backupPath)) {
                    throw new RuntimeException(sprintf('Failed to back up "%s".', $relativePath));
                }
                $backedUpFiles[] = $relativePath;
            } else {
                $createdFiles[] = $relativePath;
            }

            if (!copy($sourcePath, $targetPath)) {
                throw new RuntimeException(sprintf('Failed to copy "%s" into installation.', $relativePath));
            }
        }

        return [
            'createdFiles' => $createdFiles,
            'backedUpFiles' => $backedUpFiles,
        ];
    }

    /**
     * Roll back synchronized files after failed post-upgrade step.
     *
     * @param string $targetRoot
     * @param string $backupRoot
     * @param array{createdFiles: array<int, string>, backedUpFiles: array<int, string>} $syncState
     * @return void
     */
    private function rollbackSynchronization(string $targetRoot, string $backupRoot, array $syncState): void
    {
        foreach (array_reverse($syncState['createdFiles']) as $relativePath) {
            $path = $targetRoot . DS . str_replace('/', DS, $relativePath);
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException(sprintf('Rollback failed while deleting "%s".', $relativePath));
            }
        }

        foreach ($syncState['backedUpFiles'] as $relativePath) {
            $backupPath = $backupRoot . DS . str_replace('/', DS, $relativePath);
            $targetPath = $targetRoot . DS . str_replace('/', DS, $relativePath);
            if (!is_file($backupPath)) {
                continue;
            }
            $this->ensureDirectory(dirname($targetPath));
            if (!copy($backupPath, $targetPath)) {
                throw new RuntimeException(sprintf('Rollback failed while restoring "%s".', $relativePath));
            }
        }
    }

    /**
     * Run mandatory post-upgrade Cake commands.
     *
     * @return void
     */
    private function runPostUpgradeCommands(): void
    {
        $this->runCakeCommand(['updateDatabase']);
        $this->runCakeCommand(['cache', 'clear_all']);
    }

    /**
     * Execute bin/cake command and throw on non-zero exit.
     *
     * @param array<int, string> $arguments
     * @return void
     */
    private function runCakeCommand(array $arguments): void
    {
        if (!function_exists('exec')) {
            throw new RuntimeException('PHP exec() is disabled; cannot run post-upgrade commands.');
        }

        $root = escapeshellarg(ROOT);
        $php = escapeshellcmd((string)PHP_BINARY);
        $appName = escapeshellarg((string)Configure::read('App.name', 'KMP'));
        $escapedArgs = implode(' ', array_map(static fn(string $arg): string => escapeshellarg($arg), $arguments));
        $command = sprintf('cd %s && APP_NAME=%s %s bin/cake %s 2>&1', $root, $appName, $php, $escapedArgs);

        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            $tail = implode(PHP_EOL, array_slice($output, -20));
            throw new RuntimeException(
                sprintf(
                    'Post-upgrade command failed (%s): %s',
                    implode(' ', $arguments),
                    $tail !== '' ? $tail : 'unknown error'
                )
            );
        }
    }

    /**
     * Persist installed release identity marker files.
     *
     * @param string $releaseHash
     * @param string $releaseTag
     * @return void
     */
    private function writeReleaseIdentity(string $releaseHash, string $releaseTag): void
    {
        $releaseHashPath = ROOT . DS . 'config' . DS . 'release_hash.txt';
        $releaseTagPath = ROOT . DS . 'config' . DS . 'release_tag.txt';

        if ($releaseHash !== '') {
            if (file_put_contents($releaseHashPath, $releaseHash . PHP_EOL) === false) {
                throw new RuntimeException('Failed to persist installed release hash marker.');
            }
        }
        if ($releaseTag !== '') {
            if (file_put_contents($releaseTagPath, $releaseTag . PHP_EOL) === false) {
                throw new RuntimeException('Failed to persist installed release tag marker.');
            }
        }
    }

    /**
     * Check if latest release identity already matches installed identity.
     *
     * @param string $releaseHash
     * @param string $releaseTag
     * @return bool
     */
    private function isAlreadyInstalled(string $releaseHash, string $releaseTag): bool
    {
        $installedHash = $this->normalizeReleaseHash((string)Configure::read('App.releaseHash', ''));
        $installedTag = strtolower(trim((string)Configure::read('App.releaseTag', '')));
        if ($installedHash !== '' && $releaseHash !== '') {
            return $installedHash === $releaseHash;
        }
        if ($installedTag !== '' && $releaseTag !== '') {
            return $installedTag === strtolower(trim($releaseTag));
        }

        return false;
    }

    /**
     * Validate URL host against Updater.allowedHosts.
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
     * Determine whether relative path should be excluded from sync.
     *
     * @param string $relativePath
     * @return bool
     */
    private function shouldSkipPath(string $relativePath): bool
    {
        $normalizedRelativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($normalizedRelativePath === '') {
            return true;
        }

        foreach (self::EXCLUDED_PATHS as $excludedPath) {
            $normalizedExcludedPath = trim(str_replace('\\', '/', $excludedPath), '/');
            if (
                $normalizedRelativePath === $normalizedExcludedPath
                || str_starts_with($normalizedRelativePath, $normalizedExcludedPath . '/')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create temporary working directory for update run.
     *
     * @return string
     */
    private function createWorkingDirectory(): string
    {
        $baseDirectory = $this->resolveRuntimeDirectory() . DS . 'runs';
        $this->ensureDirectory($baseDirectory);
        $suffix = gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
        $workingDirectory = $baseDirectory . DS . $suffix;
        $this->ensureDirectory($workingDirectory);

        return $workingDirectory;
    }

    /**
     * Acquire single-update execution lock.
     *
     * @return void
     */
    private function acquireLock(): void
    {
        $attemptErrors = [];
        foreach ($this->runtimeDirectoryCandidates() as $lockDir) {
            try {
                $this->ensureDirectory($lockDir);
            } catch (RuntimeException $exception) {
                $attemptErrors[] = sprintf('%s (%s)', $lockDir, $exception->getMessage());
                continue;
            }
            if (!is_writable($lockDir)) {
                $attemptErrors[] = sprintf('%s (directory is not writable)', $lockDir);
                continue;
            }

            $lockFile = $lockDir . DS . 'apply.lock';
            if (is_file($lockFile) && $this->isStaleLockFile($lockFile)) {
                if (!@unlink($lockFile)) {
                    $attemptErrors[] = sprintf('%s (stale lock exists and could not be removed)', $lockFile);
                    continue;
                }
            }

            $lockHandle = @fopen($lockFile, 'xb');
            if ($lockHandle === false) {
                if (file_exists($lockFile)) {
                    throw new RuntimeException(sprintf('Another update operation is already running (lock: %s).', $lockFile));
                }
                $lastError = error_get_last();
                $detail = is_array($lastError) ? (string)($lastError['message'] ?? 'unknown error') : 'unknown error';
                $attemptErrors[] = sprintf('%s (%s)', $lockFile, $detail);
                continue;
            }

            $payload = json_encode(['startedAt' => gmdate(DATE_ATOM)], JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                fclose($lockHandle);
                @unlink($lockFile);
                throw new RuntimeException('Failed to initialize updater lock metadata.');
            }
            if (fwrite($lockHandle, $payload . PHP_EOL) === false) {
                fclose($lockHandle);
                @unlink($lockFile);
                $attemptErrors[] = sprintf('%s (failed to write lock metadata)', $lockFile);
                continue;
            }
            if (!fclose($lockHandle)) {
                @unlink($lockFile);
                $attemptErrors[] = sprintf('%s (failed to finalize lock file)', $lockFile);
                continue;
            }

            $this->lockFile = $lockFile;
            $this->runtimeDirectory = $lockDir;

            return;
        }

        throw new RuntimeException(sprintf(
            'Failed to create updater lock file. Attempts: %s',
            $attemptErrors !== [] ? implode('; ', $attemptErrors) : 'none'
        ));
    }

    /**
     * Resolve a writable runtime directory for updater lock/temp artifacts.
     *
     * @return string
     */
    private function resolveRuntimeDirectory(): string
    {
        if ($this->runtimeDirectory !== null) {
            return $this->runtimeDirectory;
        }

        $attempted = [];
        foreach ($this->runtimeDirectoryCandidates() as $candidate) {
            $attempted[] = $candidate;
            try {
                $this->ensureDirectory($candidate);
            } catch (RuntimeException) {
                continue;
            }

            if (is_writable($candidate)) {
                $this->runtimeDirectory = $candidate;

                return $this->runtimeDirectory;
            }
        }

        throw new RuntimeException(
            sprintf(
                'Failed to resolve writable updater runtime directory. Checked: %s',
                implode(', ', $attempted)
            )
        );
    }

    /**
     * Build normalized runtime directory candidates in priority order.
     *
     * @return array<int, string>
     */
    private function runtimeDirectoryCandidates(): array
    {
        $configuredRuntimeDir = trim((string)Configure::read('Updater.runtimeDirectory', ''));
        $systemTempDir = rtrim((string)sys_get_temp_dir(), '/\\');
        $candidates = [];
        if ($configuredRuntimeDir !== '') {
            $candidates[] = $configuredRuntimeDir;
        }
        $candidates[] = TMP . 'updater';
        if ($systemTempDir !== '') {
            $candidates[] = $systemTempDir . DS . 'kmp-updater';
        }

        $normalizedCandidates = [];
        foreach ($candidates as $candidate) {
            $normalized = rtrim(str_replace(['\\', '/'], DS, trim($candidate)), DS);
            if ($normalized === '') {
                continue;
            }
            if (!str_starts_with($normalized, DS)) {
                $normalized = ROOT . DS . ltrim($normalized, DS);
            }
            if (!in_array($normalized, $normalizedCandidates, true)) {
                $normalizedCandidates[] = $normalized;
            }
        }

        return $normalizedCandidates;
    }

    /**
     * Determine whether lock file is stale and can be replaced.
     *
     * @param string $lockFile
     * @return bool
     */
    private function isStaleLockFile(string $lockFile): bool
    {
        $timeoutSeconds = max(60, (int)Configure::read('Updater.lockTimeoutSeconds', 1800));
        $modifiedAt = @filemtime($lockFile);
        if ($modifiedAt === false) {
            return false;
        }

        return (time() - $modifiedAt) > $timeoutSeconds;
    }

    /**
     * Release update execution lock.
     *
     * @return void
     */
    private function releaseLock(): void
    {
        if ($this->lockFile === null) {
            return;
        }

        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
        $this->lockFile = null;
    }

    /**
     * Create directory recursively if needed.
     *
     * @param string $path
     * @return void
     */
    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Failed to create directory "%s".', $path));
        }
    }

    /**
     * Delete a directory tree.
     *
     * @param string $path
     * @return void
     */
    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
