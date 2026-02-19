<?php

declare(strict_types=1);

namespace App\Controller;

use App\KMP\StaticHelpers;
use App\Services\Updater\ManifestClient;
use Cake\Core\Configure;
use Throwable;

/**
 * Admin-facing release updater UI.
 */
class UpdatesController extends AppController
{
    /**
     * Allowed updater channels users can select.
     */
    private const ALLOWED_CHANNELS = ['stable', 'beta', 'dev', 'nightly'];

    /**
     * Display updater dashboard.
     *
     * @return void
     */
    public function index(): void
    {
        $this->authorizeCurrentUrl();
        $channel = $this->resolveUpdaterChannel();
        $installedReleaseHash = $this->resolveInstalledReleaseHash();
        $installedReleaseTag = trim((string)Configure::read('App.releaseTag', ''));

        $this->set([
            'currentVersion' => trim((string)Configure::read('App.version', '0.0.0')),
            'channel' => $channel,
            'githubRepository' => (string)Configure::read('Updater.githubRepository', ''),
            'installedReleaseHash' => $installedReleaseHash,
            'installedReleaseTag' => $installedReleaseTag,
            'releaseIdentityStatus' => null,
            'availableChannels' => $this->channelOptions(),
            'availableRelease' => null,
        ]);
    }

    /**
     * Fetch GitHub release metadata and render updater dashboard with latest release data.
     *
     * @return \Cake\Http\Response|null|void
     */
    public function check()
    {
        $this->request->allowMethod(['post']);
        $this->authorizeCurrentUrl();
        $channel = $this->resolveUpdaterChannel();
        $installedReleaseHash = $this->resolveInstalledReleaseHash();
        $installedReleaseTag = trim((string)Configure::read('App.releaseTag', ''));

        $githubRepository = trim((string)Configure::read('Updater.githubRepository', ''));
        if ($githubRepository === '') {
            $this->Flash->error(__('Updater GitHub repository is not configured.'));

            return $this->redirect(['action' => 'index']);
        }

        try {
            $manifest = (new ManifestClient())->fetchManifest($githubRepository);
            $latestVersion = (string)($manifest['latestVersion'] ?? '');
            $availableRelease = null;
            if (!empty($manifest['releases']) && is_array($manifest['releases'])) {
                $availableRelease = $manifest['releases'][0];
            }
            $releaseIdentityStatus = $this->compareReleaseIdentity(
                $installedReleaseHash,
                $installedReleaseTag,
                $availableRelease
            );

            if ($latestVersion !== '') {
                $this->Flash->success(__('Latest available version: {0}', $latestVersion));
            } else {
                $this->Flash->warning(__('GitHub release data loaded, but no matching release was found for this channel.'));
            }
            if ($releaseIdentityStatus === 'same') {
                $this->Flash->info(__('You are on this release.'));
            } elseif ($releaseIdentityStatus === 'different') {
                $this->Flash->warning(__('Installed release identity differs from the latest release.'));
            }

            $this->set([
                'currentVersion' => trim((string)Configure::read('App.version', '0.0.0')),
                'channel' => $channel,
                'githubRepository' => $githubRepository,
                'installedReleaseHash' => $installedReleaseHash,
                'installedReleaseTag' => $installedReleaseTag,
                'releaseIdentityStatus' => $releaseIdentityStatus,
                'availableChannels' => $this->channelOptions(),
                'availableRelease' => $availableRelease,
                'manifest' => $manifest,
            ]);
        } catch (Throwable $exception) {
            $this->Flash->error(__('Failed to check updates: {0}', $exception->getMessage()));

            return $this->redirect(['action' => 'index']);
        }

        $this->render('index');
    }

    /**
     * Persist selected updater channel.
     *
     * @return \Cake\Http\Response
     */
    public function setChannel()
    {
        $this->request->allowMethod(['post']);
        $this->authorizeCurrentUrl();

        $selectedChannel = $this->normalizeUpdaterChannel((string)$this->request->getData('channel', ''));
        if ($selectedChannel === '') {
            $this->Flash->error(__('Invalid update channel selection.'));

            return $this->redirect(['action' => 'index']);
        }

        if (!StaticHelpers::setAppSetting('Updater.Channel', $selectedChannel, null, true)) {
            $this->Flash->error(__('Failed to save update channel.'));

            return $this->redirect(['action' => 'index']);
        }

        $this->Flash->success(__('Update channel set to {0}.', $selectedChannel));

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Placeholder update execution endpoint.
     *
     * @return \Cake\Http\Response
     */
    public function apply()
    {
        $this->request->allowMethod(['post']);
        $this->authorizeCurrentUrl();
        $this->Flash->info(__('Update execution pipeline is not implemented yet.'));

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Resolve updater channel from AppSettings with config fallback.
     *
     * @return string
     */
    private function resolveUpdaterChannel(): string
    {
        $fallback = $this->normalizeUpdaterChannel((string)Configure::read('Updater.channel', 'stable'));
        $channel = $this->normalizeUpdaterChannel((string)StaticHelpers::getAppSetting('Updater.Channel', $fallback));

        if ($channel !== '') {
            return $channel;
        }

        return $fallback !== '' ? $fallback : 'stable';
    }

    /**
     * Resolve installed release hash from config.
     *
     * @return string
     */
    private function resolveInstalledReleaseHash(): string
    {
        return $this->normalizeReleaseHash((string)Configure::read('App.releaseHash', ''));
    }

    /**
     * Normalize and validate a channel value.
     *
     * @param string $channel
     * @return string
     */
    private function normalizeUpdaterChannel(string $channel): string
    {
        $normalized = strtolower(trim($channel));
        if (!$this->isAllowedChannel($normalized)) {
            return '';
        }

        return $normalized;
    }

    /**
     * Check if a channel is allowed.
     *
     * @param string $channel
     * @return bool
     */
    private function isAllowedChannel(string $channel): bool
    {
        return in_array($channel, self::ALLOWED_CHANNELS, true);
    }

    /**
     * Normalize a release hash.
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
     * Compare installed identity against latest release identity.
     *
     * @param string $installedReleaseHash
     * @param string $installedReleaseTag
     * @param array<string, mixed>|null $availableRelease
     * @return string|null
     */
    private function compareReleaseIdentity(
        string $installedReleaseHash,
        string $installedReleaseTag,
        ?array $availableRelease
    ): ?string {
        if ($availableRelease === null) {
            return null;
        }

        $latestReleaseHash = $this->normalizeReleaseHash((string)($availableRelease['releaseHash'] ?? ''));
        if ($installedReleaseHash !== '' && $latestReleaseHash !== '') {
            return hash_equals($installedReleaseHash, $latestReleaseHash) ? 'same' : 'different';
        }

        $latestReleaseTag = strtolower(trim((string)($availableRelease['tag'] ?? '')));
        $normalizedInstalledTag = strtolower(trim($installedReleaseTag));
        if ($normalizedInstalledTag !== '' && $latestReleaseTag !== '') {
            return hash_equals($normalizedInstalledTag, $latestReleaseTag) ? 'same' : 'different';
        }

        return null;
    }

    /**
     * Build select options for channel picker.
     *
     * @return array<string, string>
     */
    private function channelOptions(): array
    {
        return [
            'stable' => __('Stable'),
            'beta' => __('Beta'),
            'dev' => __('Dev'),
            'nightly' => __('Nightly'),
        ];
    }
}
