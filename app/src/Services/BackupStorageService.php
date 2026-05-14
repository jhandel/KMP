<?php
declare(strict_types=1);

namespace App\Services;

use App\Services\Tenant\TenantContext;
use Aws\S3\S3Client;
use AzureOss\FlysystemAzureBlobStorage\AzureBlobStorageAdapter;
use AzureOss\Storage\Blob\BlobServiceClient;
use Cake\Core\Configure;
use Cake\Log\Log;
use Exception;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem as FlysystemFilesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use RuntimeException;

/**
 * Manages backup file storage using the same adapter pattern as DocumentService.
 *
 * Stores backups in a `backups/` prefix within the configured storage backend.
 */
class BackupStorageService
{
    private FlysystemFilesystem $filesystem;
    private string $adapter;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->initializeFilesystem();
    }

    /**
     * Write a backup file to storage.
     */
    public function write(string $filename, string $data): void
    {
        $filename = $this->tenantPath($filename);
        try {
            $this->filesystem->write($filename, $data);
        } catch (UnableToWriteFile $e) {
            $message = "Unable to write backup '{$filename}' to {$this->adapter} storage";
            if ($e->reason()) {
                $message .= ": {$e->reason()}";
            }
            $previous = $e->getPrevious();
            if ($previous) {
                $message .= " (Underlying error: {$previous->getMessage()})";
            }
            throw new RuntimeException($message, 0, $e);
        }
    }

    /**
     * Read a backup file from storage.
     */
    public function read(string $filename): string
    {
        $filename = $this->tenantPath($filename);

        return $this->filesystem->read($filename);
    }

    /**
     * Delete a backup file from storage.
     */
    public function delete(string $filename): void
    {
        $filename = $this->tenantPath($filename);
        $this->filesystem->delete($filename);
    }

    /**
     * Check if a backup file exists.
     */
    public function exists(string $filename): bool
    {
        $filename = $this->tenantPath($filename);

        return $this->filesystem->fileExists($filename);
    }

    /**
     * List all backup files.
     *
     * @return array<string> Filenames
     */
    public function listFiles(): array
    {
        $files = [];
        $prefix = $this->tenantStoragePrefix();
        $listing = $this->filesystem->listContents(rtrim($prefix, '/'), $prefix !== '');
        foreach ($listing as $item) {
            if ($item->isFile()) {
                $files[] = $item->path();
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Get the active storage adapter type.
     */
    public function getAdapterType(): string
    {
        return $this->adapter;
    }

    /**
     * Build a tenant-specific backup path/filename.
     */
    public function buildBackupFilename(?int $timestamp = null): string
    {
        $context = TenantContext::getCurrent();
        $time = date('Ymd-His', $timestamp ?? time());
        $basename = $context === null
            ? "kmp-backup-{$time}.kmpbackup"
            : sprintf('kmp-backup-%s-%s.kmpbackup', $this->safeTenantSlug($context->slug), $time);

        return $this->tenantPath($basename);
    }

    /**
     * Return current tenant metadata for backup records/payloads.
     *
     * @return array<string, mixed>
     */
    public function getTenantMetadata(): array
    {
        $context = TenantContext::getCurrent();
        if ($context === null) {
            return [];
        }

        return [
            'tenant_id' => $context->id,
            'tenant_slug' => $context->slug,
            'tenant_display_name' => $context->displayName,
        ];
    }

    /**
     * Initialize filesystem.
     *
     * @return void
     */
    private function initializeFilesystem(): void
    {
        $config = Configure::read('Documents.storage', []);
        $this->adapter = $config['adapter'] ?? 'local';

        if ($this->adapter === 'azure') {
            $azureConfig = $config['azure'] ?? [];
            $connectionString = $azureConfig['connectionString'] ?? null;
            $container = $azureConfig['container'] ?? 'documents';

            if (empty($connectionString)) {
                Log::warning('Azure not configured for backups, falling back to local');
                $this->adapter = 'local';
                $this->initializeLocalAdapter();

                return;
            }

            try {
                $blobServiceClient = BlobServiceClient::fromConnectionString($connectionString);
                $containerClient = $blobServiceClient->getContainerClient($container);
                try {
                    $containerClient->createIfNotExists();
                } catch (Exception $e) {
                    Log::warning('Azure backup container ensure step skipped: ' . $e->getMessage());
                }
                $adapter = new AzureBlobStorageAdapter($containerClient, 'backups/');
                $this->filesystem = new FlysystemFilesystem($adapter);
            } catch (Exception $e) {
                Log::error('Azure backup storage init failed: ' . $e->getMessage());
                $this->adapter = 'local';
                $this->initializeLocalAdapter();
            }
        } elseif ($this->adapter === 's3') {
            $s3Config = $config['s3'] ?? [];
            $bucket = $s3Config['bucket'] ?? null;

            if (empty($bucket)) {
                Log::warning('S3 not configured for backups, falling back to local');
                $this->adapter = 'local';
                $this->initializeLocalAdapter();

                return;
            }

            try {
                $clientConfig = [
                    'version' => 'latest',
                    'region' => $s3Config['region'] ?? 'us-east-1',
                ];
                if (!empty($s3Config['key']) && !empty($s3Config['secret'])) {
                    $clientConfig['credentials'] = [
                        'key' => $s3Config['key'],
                        'secret' => $s3Config['secret'],
                    ];
                }
                if (!empty($s3Config['endpoint'])) {
                    $clientConfig['endpoint'] = $s3Config['endpoint'];
                    $clientConfig['use_path_style_endpoint'] = true;
                }

                $s3Client = new S3Client($clientConfig);
                $adapter = new AwsS3V3Adapter($s3Client, $bucket, 'backups/');
                $this->filesystem = new FlysystemFilesystem($adapter);
            } catch (Exception $e) {
                Log::error('S3 backup storage init failed: ' . $e->getMessage());
                $this->adapter = 'local';
                $this->initializeLocalAdapter();
            }
        } else {
            $this->initializeLocalAdapter();
        }
    }

    /**
     * Initialize local adapter.
     *
     * @return void
     */
    private function initializeLocalAdapter(): void
    {
        $backupDir = ROOT . DS . 'backups';
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
                throw new RuntimeException("Cannot create backup directory: {$backupDir}");
            }
        }

        $adapter = new LocalFilesystemAdapter($backupDir);
        $this->filesystem = new FlysystemFilesystem($adapter);
    }

    /**
     * Apply the active tenant namespace to a backup storage path.
     */
    private function tenantPath(string $filename): string
    {
        $filename = ltrim(str_replace('\\', '/', $filename), '/');
        $prefix = $this->tenantStoragePrefix();
        if ($prefix === '' || str_starts_with($filename, $prefix)) {
            return $filename;
        }

        return $prefix . $filename;
    }

    /**
     * Return the tenant storage namespace prefix, or empty string in legacy mode.
     */
    private function tenantStoragePrefix(): string
    {
        $context = TenantContext::getCurrent();
        if ($context === null) {
            return '';
        }

        return 'tenants/' . $this->safeTenantSlug($context->slug) . '/';
    }

    /**
     * Normalize a tenant slug for object/file paths.
     */
    private function safeTenantSlug(string $slug): string
    {
        $safe = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '-', $slug) ?? '');
        $safe = trim($safe, '-_');

        return $safe !== '' ? $safe : 'tenant';
    }
}
