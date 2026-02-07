<?php

declare(strict_types=1);

namespace App\Services\WorkflowEngine;

/**
 * Resolves {{template}} patterns and dot-notation paths from workflow context.
 *
 * Used by action and condition implementations to interpolate dynamic values
 * from the runtime context array.
 */
trait ContextResolverTrait
{
    /**
     * Resolve a value that may contain {{template}} patterns.
     *
     * @param mixed $value Raw value from workflow definition
     * @param array $context Runtime context
     * @return mixed Resolved value
     */
    protected function resolveValue(mixed $value, array $context): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        // Handle special values
        if ($value === '{{now}}') {
            return new \DateTime();
        }
        if ($value === '{{increment}}') {
            return 'increment';
        }

        // Handle full template replacement (preserves non-string types)
        if (preg_match('/^\{\{(.+)\}\}$/', $value, $matches)) {
            return $this->resolveContextPath($matches[1], $context);
        }

        // Handle inline template interpolation
        return preg_replace_callback('/\{\{(.+?)\}\}/', function ($matches) use ($context) {
            $resolved = $this->resolveContextPath($matches[1], $context);

            return is_scalar($resolved) ? (string)$resolved : json_encode($resolved);
        }, $value);
    }

    /**
     * Walk a dot-notation path through the context array/object tree.
     *
     * Supports a `setting:` prefix to read from app settings.
     *
     * @param string $path Dot-notation path (e.g. "entity.requester.email")
     * @param array $context Runtime context
     * @return mixed Resolved value or null
     */
    protected function resolveContextPath(string $path, array $context): mixed
    {
        // Handle setting: prefix
        if (str_starts_with($path, 'setting:')) {
            $settingKey = substr($path, 8);

            return \App\KMP\StaticHelpers::getAppSetting($settingKey);
        }

        $parts = explode('.', $path);
        $current = $context;
        foreach ($parts as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } elseif (is_object($current) && isset($current->{$part})) {
                $current = $current->{$part};
            } else {
                return null;
            }
        }

        return $current;
    }
}
