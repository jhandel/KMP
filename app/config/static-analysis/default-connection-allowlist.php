<?php
declare(strict_types=1);

/**
 * Allow-list for intentional ConnectionManager::get('default') usage.
 *
 * Keys are paths relative to app/.
 */
return [
    'src/Controller/HealthController.php' => [
        'category' => 'health',
        'justification' => 'Infrastructure health probe validates baseline application DB availability.',
    ],
    'src/Services/Tenant/TenantConnectionAccessor.php' => [
        'category' => 'legacy',
        'justification' => 'Single, centralized compatibility fallback used by tenant-aware accessors in non-tenant contexts.',
    ],
];
