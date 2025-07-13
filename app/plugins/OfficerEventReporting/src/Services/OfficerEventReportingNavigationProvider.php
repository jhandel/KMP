<?php

declare(strict_types=1);

namespace OfficerEventReporting\Services;

/**
 * Navigation provider for Officer Event Reporting plugin
 */
class OfficerEventReportingNavigationProvider
{
    /**
     * Get navigation items for the plugin
     *
     * @param object|null $user The current user
     * @param array $params URL parameters
     * @return array Navigation items
     */
    public static function getNavigationItems($user = null, array $params = []): array
    {
        $items = [];

        if ($user && method_exists($user, 'can')) {
            // Forms management for officers
            if ($user->can('index', 'OfficerEventReporting.Forms')) {
                $items[] = [
                    'title' => 'Report Forms',
                    'url' => ['plugin' => 'OfficerEventReporting', 'controller' => 'Forms', 'action' => 'index'],
                    'icon' => 'fas fa-file-alt',
                    'weight' => 100,
                ];
            }

            // Submissions for members and officers
            if ($user->can('index', 'OfficerEventReporting.Submissions')) {
                $items[] = [
                    'title' => 'My Reports',
                    'url' => ['plugin' => 'OfficerEventReporting', 'controller' => 'Submissions', 'action' => 'index'],
                    'icon' => 'fas fa-list-alt',
                    'weight' => 110,
                ];
            }

            // Create new form for officers
            if ($user->can('add', 'OfficerEventReporting.Forms')) {
                $items[] = [
                    'title' => 'Create Report Form',
                    'url' => ['plugin' => 'OfficerEventReporting', 'controller' => 'Forms', 'action' => 'add'],
                    'icon' => 'fas fa-plus-circle',
                    'weight' => 105,
                ];
            }
        }

        return $items;
    }
}