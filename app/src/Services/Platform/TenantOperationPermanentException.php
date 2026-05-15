<?php
declare(strict_types=1);

namespace App\Services\Platform;

use RuntimeException;

/**
 * Marks tenant operation failures as non-retryable.
 */
class TenantOperationPermanentException extends RuntimeException
{
}

