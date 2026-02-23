<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Interface for provider-specific update mechanisms.
 */
interface UpdateProviderInterface
{
    /**
     * Trigger an update to the specified image tag.
     *
     * @return array{status: string, message: string}
     */
    public function triggerUpdate(string $tag): array;

    /**
     * Get the current update/deployment status.
     *
     * @return array{status: string, message: string, progress: int}
     */
    public function getStatus(): array;

    /**
     * Rollback to a previous image tag.
     *
     * @return array{status: string, message: string}
     */
    public function rollback(string $tag): array;

    /**
     * Check if this provider supports web-triggered updates.
     */
    public function supportsWebUpdate(): bool;
}
