<?php

declare(strict_types=1);

namespace App\Controller;

use App\KMP\KmpIdentityInterface;
use App\Services\BackupService;
use App\Services\BackupStorageService;
use App\Services\ContainerRegistryService;
use App\Services\UpdateProviderFactory;
use Cake\Http\Exception\ForbiddenException;
use Cake\Http\Response;
use Cake\I18n\DateTime;
use Cake\Log\Log;
use Exception;

/**
 * Super-user page for viewing current version, checking for updates,
 * and triggering container upgrades with auto-backup and rollback.
 */
class SystemUpdateController extends AppController
{
    public function initialize(): void
    {
        parent::initialize();
        $this->fetchTable('SystemUpdates');
    }

    /**
     * Main update dashboard: current version, update history, and provider info.
     */
    public function index(): void
    {
        $this->authorizeCurrentUrl();
        $this->requireSuperUser();

        $registryService = new ContainerRegistryService();
        $currentInfo = $registryService->getCurrentInfo();

        $provider = UpdateProviderFactory::create();
        $supportsWebUpdate = $provider->supportsWebUpdate();
        $capabilities = $provider->getCapabilities();

        $recentUpdates = $this->fetchTable('SystemUpdates')
            ->find()
            ->contain(['Members'])
            ->orderBy(['SystemUpdates.created' => 'DESC'])
            ->limit(10)
            ->all();

        // Get the last successful update for rollback info
        $lastSuccess = $this->fetchTable('SystemUpdates')
            ->find()
            ->where(['status' => 'completed'])
            ->orderBy(['completed_at' => 'DESC'])
            ->first();

        $this->set(compact(
            'currentInfo',
            'supportsWebUpdate',
            'capabilities',
            'recentUpdates',
            'lastSuccess',
        ));
    }

    /**
     * AJAX: Check for available updates from the container registry.
     */
    public function check(): Response
    {
        $this->request->allowMethod(['get']);
        $this->authorizeCurrentUrl();
        $this->requireSuperUser();

        $registryService = new ContainerRegistryService();
        $versions = $registryService->getAvailableVersions();
        $currentInfo = $registryService->getCurrentInfo();

        // Group by channel
        $channels = [];
        foreach ($versions as $v) {
            $channels[$v['channel']][] = $v;
        }

        return $this->response
            ->withType('application/json')
            ->withStringBody(json_encode([
                'current' => $currentInfo,
                'channels' => $channels,
                'versions' => $versions,
            ]));
    }

    /**
     * AJAX POST: Trigger an update to the specified tag.
     * Creates an auto-backup first, then sends the update command.
     */
    public function trigger(): Response
    {
        $this->request->allowMethod(['post']);
        $this->authorizeCurrentUrl();
        $this->requireSuperUser();

        $targetTag = trim((string)$this->request->getData('tag', ''));
        if (empty($targetTag)) {
            return $this->jsonResponse(['status' => 'error', 'message' => 'No target tag specified'], 400);
        }

        $provider = UpdateProviderFactory::create();
        if (!$provider->supportsWebUpdate()) {
            return $this->jsonResponse([
                'status' => 'error',
                'message' => 'Web-triggered updates are not supported for this deployment provider.',
            ], 409);
        }

        $identity = $this->request->getAttribute('identity');
        $currentInfo = (new ContainerRegistryService())->getCurrentInfo();

        // Create the update record
        $systemUpdatesTable = $this->fetchTable('SystemUpdates');
        $updateRecord = $systemUpdatesTable->newEntity([
            'from_tag' => $currentInfo['imageTag'],
            'to_tag' => $targetTag,
            'channel' => $currentInfo['channel'],
            'provider' => $currentInfo['provider'],
            'status' => 'pending',
            'initiated_by' => $identity->getIdentifier(),
        ]);
        $systemUpdatesTable->save($updateRecord);

        // Auto-backup before update
        $backupId = null;
        try {
            $backupId = $this->createPreUpdateBackup();
            if ($backupId) {
                $updateRecord->backup_id = $backupId;
                $systemUpdatesTable->save($updateRecord);
            }
        } catch (Exception $e) {
            Log::error('Pre-update backup failed: ' . $e->getMessage());
            $updateRecord->status = 'failed';
            $updateRecord->error_message = 'Pre-update backup failed: ' . $e->getMessage();
            $systemUpdatesTable->save($updateRecord);

            return $this->jsonResponse([
                'status' => 'error',
                'message' => 'Pre-update backup failed: ' . $e->getMessage(),
                'updateId' => $updateRecord->id,
            ], 500);
        }

        // Trigger the actual update
        $updateRecord->status = 'running';
        $updateRecord->started_at = DateTime::now();
        $systemUpdatesTable->save($updateRecord);

        try {
            $result = $provider->triggerUpdate($targetTag);

            if ($result['status'] === 'error') {
                $updateRecord->status = 'failed';
                $updateRecord->error_message = $result['message'];
                $updateRecord->completed_at = DateTime::now();
                $systemUpdatesTable->save($updateRecord);
            }

            $result['updateId'] = $updateRecord->id;

            return $this->jsonResponse($result);
        } catch (Exception $e) {
            Log::error('Update trigger failed: ' . $e->getMessage());
            $updateRecord->status = 'failed';
            $updateRecord->error_message = $e->getMessage();
            $updateRecord->completed_at = DateTime::now();
            $systemUpdatesTable->save($updateRecord);

            return $this->jsonResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
                'updateId' => $updateRecord->id,
            ], 500);
        }
    }

    /**
     * AJAX GET: Poll update status from the provider.
     */
    public function status(): Response
    {
        $this->request->allowMethod(['get']);
        $this->authorizeCurrentUrl();
        $this->requireSuperUser();

        $provider = UpdateProviderFactory::create();
        $status = $provider->getStatus();
        $status['capabilities'] = $provider->getCapabilities();

        // Also check the latest update record
        $latestUpdate = $this->fetchTable('SystemUpdates')
            ->find()
            ->orderBy(['created' => 'DESC'])
            ->first();

        if ($latestUpdate) {
            $status['updateRecord'] = [
                'id' => $latestUpdate->id,
                'from_tag' => $latestUpdate->from_tag,
                'to_tag' => $latestUpdate->to_tag,
                'status' => $latestUpdate->status,
                'error_message' => $latestUpdate->error_message,
            ];
        }

        return $this->jsonResponse($status);
    }

    /**
     * AJAX POST: Rollback to a previous tag.
     */
    public function rollback(): Response
    {
        $this->request->allowMethod(['post']);
        $this->authorizeCurrentUrl();
        $this->requireSuperUser();

        $previousTag = trim((string)$this->request->getData('tag', ''));
        if (empty($previousTag)) {
            return $this->jsonResponse(['status' => 'error', 'message' => 'No rollback tag specified'], 400);
        }

        $provider = UpdateProviderFactory::create();
        if (!$provider->supportsWebUpdate()) {
            return $this->jsonResponse([
                'status' => 'error',
                'message' => 'Web-triggered rollback is not supported for this deployment provider.',
            ], 409);
        }

        $identity = $this->request->getAttribute('identity');
        $currentInfo = (new ContainerRegistryService())->getCurrentInfo();

        $systemUpdatesTable = $this->fetchTable('SystemUpdates');
        $updateRecord = $systemUpdatesTable->newEntity([
            'from_tag' => $currentInfo['imageTag'],
            'to_tag' => $previousTag,
            'channel' => $currentInfo['channel'],
            'provider' => $currentInfo['provider'],
            'status' => 'running',
            'initiated_by' => $identity->getIdentifier(),
            'started_at' => DateTime::now(),
        ]);
        $systemUpdatesTable->save($updateRecord);

        try {
            $result = $provider->rollback($previousTag);

            if ($result['status'] === 'error') {
                $updateRecord->status = 'failed';
                $updateRecord->error_message = $result['message'];
            } else {
                $updateRecord->status = 'rolled_back';
            }
            $updateRecord->completed_at = DateTime::now();
            $systemUpdatesTable->save($updateRecord);

            $result['updateId'] = $updateRecord->id;

            return $this->jsonResponse($result);
        } catch (Exception $e) {
            Log::error('Rollback failed: ' . $e->getMessage());
            $updateRecord->status = 'failed';
            $updateRecord->error_message = $e->getMessage();
            $updateRecord->completed_at = DateTime::now();
            $systemUpdatesTable->save($updateRecord);

            return $this->jsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Create a backup before update. Returns the backup ID or null.
     */
    private function createPreUpdateBackup(): ?int
    {
        $appSettings = $this->fetchTable('AppSettings');
        $encryptionKey = (string)$appSettings->getSetting('Backup.encryptionKey');

        if (empty($encryptionKey)) {
            Log::warning('No backup encryption key configured, skipping pre-update backup');

            return null;
        }

        $storage = new BackupStorageService();
        $backupService = new BackupService();

        $backupsTable = $this->fetchTable('Backups');
        $backup = $backupsTable->newEntity([
            'filename' => 'pre-update-' . date('Ymd-His') . '.kmpbackup',
            'storage_type' => $storage->getAdapterType(),
            'status' => 'running',
        ]);
        $backupsTable->save($backup);

        $result = $backupService->export($encryptionKey);
        $storage->write($backup->filename, $result['data']);

        $backup->size_bytes = $result['meta']['size_bytes'];
        $backup->table_count = $result['meta']['table_count'];
        $backup->row_count = $result['meta']['row_count'];
        $backup->status = 'completed';
        $backupsTable->save($backup);

        return $backup->id;
    }

    private function requireSuperUser(): void
    {
        $identity = $this->request->getAttribute('identity');
        if (!$identity instanceof KmpIdentityInterface || !$identity->isSuperUser()) {
            throw new ForbiddenException(__('Only super users can access System Update.'));
        }
    }

    private function jsonResponse(array $data, int $statusCode = 200): Response
    {
        return $this->response
            ->withType('application/json')
            ->withStatus($statusCode)
            ->withStringBody(json_encode($data));
    }
}
