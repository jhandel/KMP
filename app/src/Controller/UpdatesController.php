<?php

declare(strict_types=1);

namespace App\Controller;

use App\KMP\StaticHelpers;
use App\Services\Updater\UpgradePipelineService;
use Cake\Core\Configure;
use Cake\Http\Response;
use RuntimeException;
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
            'githubApiBaseUrl' => (string)Configure::read('Updater.githubApiBaseUrl', 'https://api.github.com'),
            'installedReleaseHash' => $installedReleaseHash,
            'installedReleaseTag' => $installedReleaseTag,
            'availableChannels' => $this->channelOptions(),
        ]);
    }

    /**
     * Legacy check endpoint now redirects to index (auto-check runs on page load).
     *
     * @return \Cake\Http\Response
     */
    public function check()
    {
        $this->request->allowMethod(['get', 'post']);
        $this->authorizeCurrentUrl();

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Persist selected updater channel.
     *
     * @return \Cake\Http\Response
     */
    public function setChannel()
    {
        $this->request->allowMethod(['get', 'post']);
        $this->authorizeCurrentUrl();
        $isJsonRequest = $this->request->is('ajax') || $this->request->is('json');

        $selectedChannel = $this->normalizeUpdaterChannel(
            (string)$this->request->getQuery('channel', (string)$this->request->getData('channel', ''))
        );
        if ($selectedChannel === '') {
            if ($isJsonRequest) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => (string)__('Invalid update channel selection.'),
                ], 400);
            }
            $this->Flash->error(__('Invalid update channel selection.'));

            return $this->redirect(['action' => 'index']);
        }

        if (!StaticHelpers::setAppSetting('Updater.Channel', $selectedChannel, null, true)) {
            if ($isJsonRequest) {
                return $this->jsonResponse([
                    'success' => false,
                    'message' => (string)__('Failed to save update channel.'),
                ], 500);
            }
            $this->Flash->error(__('Failed to save update channel.'));

            return $this->redirect(['action' => 'index']);
        }
        if ($isJsonRequest) {
            return $this->jsonResponse([
                'success' => true,
                'channel' => $selectedChannel,
                'message' => (string)__('Update channel set to {0}.', $selectedChannel),
            ]);
        }

        $this->Flash->success(__('Update channel set to {0}.', $selectedChannel));

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Execute update pipeline for latest release in selected channel.
     *
     * @return \Cake\Http\Response
     */
    public function apply()
    {
        $this->request->allowMethod(['get', 'post']);
        $this->authorizeCurrentUrl();
        try {
            $result = $this->upgradePipelineService()->applyLatestRelease();
            $status = (string)($result['status'] ?? '');
            $releaseTag = trim((string)($result['releaseTag'] ?? ''));

            if ($status === 'current') {
                $message = $releaseTag !== ''
                    ? __('You are already on release {0}.', $releaseTag)
                    : __('You are already on the latest release.');
                $this->Flash->success($message);
            } else {
                $message = $releaseTag !== ''
                    ? __('Update applied successfully: {0}.', $releaseTag)
                    : __('Update applied successfully.');
                $this->Flash->success($message);
            }
        } catch (Throwable $exception) {
            $this->Flash->error(__('Update failed: {0}', $exception->getMessage()));
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Create update pipeline service instance.
     *
     * @return \App\Services\Updater\UpgradePipelineService
     */
    private function upgradePipelineService(): UpgradePipelineService
    {
        $serviceClass = trim((string)Configure::read('Updater.pipelineServiceClass', ''));
        if ($serviceClass !== '') {
            if (!class_exists($serviceClass)) {
                throw new RuntimeException(sprintf('Updater service class "%s" was not found.', $serviceClass));
            }

            $service = new $serviceClass();
            if (!$service instanceof UpgradePipelineService) {
                throw new RuntimeException('Configured updater service must extend UpgradePipelineService.');
            }

            return $service;
        }

        return new UpgradePipelineService();
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

    /**
     * Build JSON response payload.
     *
     * @param array<string, mixed> $payload
     * @param int $status
     * @return \Cake\Http\Response
     */
    private function jsonResponse(array $payload, int $status = 200): Response
    {
        $json = json_encode($payload);
        if ($json === false) {
            $json = '{"success":false,"message":"JSON encoding failed."}';
            $status = 500;
        }

        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody($json);
    }
}
