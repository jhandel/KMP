<?php

declare(strict_types=1);

namespace App\Services\WorkflowRegistry;

use InvalidArgumentException;

/**
 * Workflow Entity Registry
 *
 * Static registry for entity types that workflows can operate on.
 * Plugins register their entity types with schema info (e.g., 'Officers.Officers')
 * for use in the workflow variable picker. Follows the ViewCellRegistry pattern.
 */
class WorkflowEntityRegistry
{
    private static array $entities = [];

    private static bool $initialized = false;

    /**
     * Required fields for each entity registration.
     */
    private const REQUIRED_FIELDS = [
        'entityType',
        'label',
        'description',
        'tableClass',
        'fields',
    ];

    /**
     * Register entities from a source plugin.
     *
     * @param string $source Source identifier (e.g., 'Officers', 'Awards')
     * @param array $entities Array of entity configurations
     * @return void
     * @throws \InvalidArgumentException If required fields are missing
     */
    public static function register(string $source, array $entities): void
    {
        foreach ($entities as $entity) {
            self::validateRequiredFields($entity, self::REQUIRED_FIELDS, $source);
        }

        self::$entities[$source] = $entities;
    }

    /**
     * Get a single entity by entity type.
     *
     * @param string $entityType Entity type identifier (e.g., 'Officers.Officers')
     * @return array|null Entity configuration or null if not found
     */
    public static function getEntity(string $entityType): ?array
    {
        self::ensureInitialized();

        foreach (self::$entities as $source => $entities) {
            foreach ($entities as $entity) {
                if ($entity['entityType'] === $entityType) {
                    $entity['source'] = $source;
                    return $entity;
                }
            }
        }

        return null;
    }

    /**
     * Get all registered entities.
     *
     * @return array All entities keyed by source
     */
    public static function getAllEntities(): array
    {
        self::ensureInitialized();

        return self::$entities;
    }

    /**
     * Get entities for a specific source.
     *
     * @param string $source Source identifier
     * @return array Entities from the specified source
     */
    public static function getEntitiesBySource(string $source): array
    {
        self::ensureInitialized();

        return self::$entities[$source] ?? [];
    }

    /**
     * Get all registered source identifiers.
     *
     * @return array List of registered source identifiers
     */
    public static function getRegisteredSources(): array
    {
        self::ensureInitialized();

        return array_keys(self::$entities);
    }

    /**
     * Remove entities from a specific source.
     *
     * @param string $source Source identifier to remove
     * @return void
     */
    public static function unregister(string $source): void
    {
        unset(self::$entities[$source]);
    }

    /**
     * Clear all registered entities.
     *
     * @return void
     */
    public static function clear(): void
    {
        self::$entities = [];
        self::$initialized = false;
    }

    /**
     * Check if a source is registered.
     *
     * @param string $source Source identifier
     * @return bool True if registered
     */
    public static function isRegistered(string $source): bool
    {
        self::ensureInitialized();

        return isset(self::$entities[$source]);
    }

    /**
     * Get debug information about registered entities.
     *
     * @return array Debug information
     */
    public static function getDebugInfo(): array
    {
        self::ensureInitialized();

        $debug = [
            'sources' => [],
            'total_entities' => 0,
        ];

        foreach (self::$entities as $source => $entities) {
            $entityTypes = array_column($entities, 'entityType');
            $debug['sources'][$source] = [
                'entity_count' => count($entities),
                'entity_types' => $entityTypes,
            ];
            $debug['total_entities'] += count($entities);
        }

        return $debug;
    }

    /**
     * Get a simplified view for the visual designer UI.
     *
     * @return array Designer-safe entity data (no class names)
     */
    public static function getForDesigner(): array
    {
        self::ensureInitialized();

        $result = [];

        foreach (self::$entities as $source => $entities) {
            foreach ($entities as $entity) {
                $result[] = [
                    'entityType' => $entity['entityType'],
                    'label' => $entity['label'],
                    'description' => $entity['description'],
                    'fields' => $entity['fields'],
                    'source' => $source,
                ];
            }
        }

        return $result;
    }

    /**
     * Ensure the registry is initialized.
     *
     * @return void
     */
    private static function ensureInitialized(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;
    }

    /**
     * Validate that required fields are present in a registration entry.
     *
     * @param array $entry Registration entry to validate
     * @param array $requiredFields Required field names
     * @param string $source Source identifier for error messages
     * @return void
     * @throws \InvalidArgumentException If required fields are missing
     */
    private static function validateRequiredFields(array $entry, array $requiredFields, string $source): void
    {
        foreach ($requiredFields as $field) {
            if (!array_key_exists($field, $entry)) {
                throw new InvalidArgumentException(
                    sprintf("Missing required field '%s' in entity registration from source '%s'.", $field, $source)
                );
            }
        }
    }
}
