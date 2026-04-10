<?php
declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

/**
 * Thrown when an email template cannot be resolved by slug.
 */
class EmailTemplateNotFoundException extends RuntimeException
{
    /**
     * @param string $slug Template slug
     * @param int|null $kingdomId Kingdom scope attempted (null = global)
     * @return self
     */
    public static function forSlug(string $slug, ?int $kingdomId = null): self
    {
        $scope = $kingdomId !== null ? " for kingdom #{$kingdomId} or global fallback" : ' (global)';

        return new self("No active email template found for slug '{$slug}'{$scope}.");
    }

}
