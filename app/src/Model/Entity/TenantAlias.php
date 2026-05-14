<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * Tenant routing alias for platform resolution.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $alias_type
 * @property string $value
 * @property string $normalized_value
 * @property int $priority
 * @property bool $is_active
 * @property \App\Model\Entity\Tenant $tenant
 */
class TenantAlias extends BaseEntity
{
    public const TYPE_HOST = 'host';
    public const TYPE_PATH = 'path';

    protected array $_accessible = [
        'tenant_id' => true,
        'alias_type' => true,
        'value' => true,
        'normalized_value' => true,
        'priority' => true,
        'is_active' => true,
        'tenant' => true,
    ];
}
