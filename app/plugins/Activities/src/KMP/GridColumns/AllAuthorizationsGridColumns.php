<?php

declare(strict_types=1);

namespace Activities\KMP\GridColumns;

use App\KMP\GridColumns\BaseGridColumns;

/**
 * Grid column definitions for the All Authorizations admin report.
 *
 * Provides system-wide authorization listing with status-based views.
 */
class AllAuthorizationsGridColumns extends BaseGridColumns
{
    /**
     * Get all available columns for the all authorizations grid
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getColumns(): array
    {
        return [
            'member_name' => [
                'key' => 'member_name',
                'queryField' => 'Members.sca_name',
                'renderField' => 'member.sca_name',
                'label' => 'Member',
                'type' => 'relation',
                'sortable' => true,
                'filterable' => false,
                'searchable' => true,
                'defaultVisible' => true,
                'clickAction' => 'navigate:/members/view/:member_id',
            ],
            'activity_name' => [
                'key' => 'activity_name',
                'queryField' => 'Activities.name',
                'renderField' => 'activity.name',
                'label' => 'Activity',
                'type' => 'relation',
                'sortable' => true,
                'filterable' => true,
                'filterType' => 'dropdown',
                'filterOptionsSource' => [
                    'table' => 'Activities.Activities',
                    'valueField' => 'name',
                    'labelField' => 'name',
                ],
                'searchable' => false,
                'defaultVisible' => true,
            ],
            'status' => [
                'key' => 'status',
                'queryField' => 'Authorizations.status',
                'label' => 'Status',
                'type' => 'string',
                'sortable' => true,
                'filterable' => true,
                'filterType' => 'dropdown',
                'filterOptions' => [
                    ['value' => 'Pending', 'label' => 'Pending'],
                    ['value' => 'Approved', 'label' => 'Approved'],
                    ['value' => 'Denied', 'label' => 'Denied'],
                    ['value' => 'Expired', 'label' => 'Expired'],
                    ['value' => 'Revoked', 'label' => 'Revoked'],
                    ['value' => 'Retracted', 'label' => 'Retracted'],
                    ['value' => 'Replaced', 'label' => 'Replaced'],
                ],
                'defaultVisible' => true,
            ],
            'start_on' => [
                'key' => 'start_on',
                'queryField' => 'Authorizations.start_on',
                'label' => 'Start Date',
                'type' => 'date',
                'sortable' => true,
                'filterable' => true,
                'filterType' => 'dateRange',
                'defaultVisible' => true,
            ],
            'expires_on' => [
                'key' => 'expires_on',
                'queryField' => 'Authorizations.expires_on',
                'label' => 'Expires',
                'type' => 'date',
                'sortable' => true,
                'filterable' => true,
                'filterType' => 'dateRange',
                'defaultVisible' => true,
            ],
            'requested_on' => [
                'key' => 'requested_on',
                'queryField' => 'Authorizations.created',
                'label' => 'Requested',
                'type' => 'date',
                'sortable' => true,
                'filterable' => false,
                'defaultVisible' => false,
            ],
            'branch_name' => [
                'key' => 'branch_name',
                'queryField' => 'Branches.name',
                'renderField' => 'member.branch.name',
                'label' => 'Branch',
                'type' => 'relation',
                'sortable' => true,
                'filterable' => true,
                'filterType' => 'dropdown',
                'filterOptionsSource' => [
                    'table' => 'Branches',
                    'valueField' => 'name',
                    'labelField' => 'name',
                ],
                'searchable' => false,
                'defaultVisible' => false,
            ],
        ];
    }

    /**
     * Get system views for the all authorizations report
     *
     * @param array $options Optional configuration
     * @return array<string, array<string, mixed>>
     */
    public static function getSystemViews(array $options = []): array
    {
        return [
            'all' => [
                'id' => 'all',
                'name' => __('All'),
                'description' => __('All authorizations'),
                'canManage' => false,
                'config' => [
                    'columns' => ['member_name', 'activity_name', 'status', 'start_on', 'expires_on', 'requested_on', 'branch_name'],
                    'filters' => [],
                ],
            ],
            'active' => [
                'id' => 'active',
                'name' => __('Active'),
                'description' => __('Currently approved authorizations'),
                'canManage' => false,
                'config' => [
                    'columns' => ['member_name', 'activity_name', 'start_on', 'expires_on', 'branch_name'],
                    'filters' => [
                        [
                            'field' => 'status',
                            'operator' => 'eq',
                            'value' => 'Approved',
                        ],
                    ],
                ],
            ],
            'pending' => [
                'id' => 'pending',
                'name' => __('Pending'),
                'description' => __('Authorizations awaiting approval'),
                'canManage' => false,
                'config' => [
                    'columns' => ['member_name', 'activity_name', 'requested_on'],
                    'filters' => [
                        [
                            'field' => 'status',
                            'operator' => 'eq',
                            'value' => 'Pending',
                        ],
                    ],
                ],
            ],
            'denied' => [
                'id' => 'denied',
                'name' => __('Denied'),
                'description' => __('Denied authorizations'),
                'canManage' => false,
                'config' => [
                    'columns' => ['member_name', 'activity_name', 'requested_on'],
                    'filters' => [
                        [
                            'field' => 'status',
                            'operator' => 'eq',
                            'value' => 'Denied',
                        ],
                    ],
                ],
            ],
            'retracted' => [
                'id' => 'retracted',
                'name' => __('Retracted'),
                'description' => __('Retracted authorizations'),
                'canManage' => false,
                'config' => [
                    'columns' => ['member_name', 'activity_name', 'requested_on'],
                    'filters' => [
                        [
                            'field' => 'status',
                            'operator' => 'eq',
                            'value' => 'Retracted',
                        ],
                    ],
                ],
            ],
            'expired' => [
                'id' => 'expired',
                'name' => __('Expired'),
                'description' => __('Expired authorizations'),
                'canManage' => false,
                'config' => [
                    'columns' => ['member_name', 'activity_name', 'start_on', 'expires_on'],
                    'filters' => [
                        [
                            'field' => 'status',
                            'operator' => 'eq',
                            'value' => 'Expired',
                        ],
                    ],
                ],
            ],
            'revoked' => [
                'id' => 'revoked',
                'name' => __('Revoked'),
                'description' => __('Revoked authorizations'),
                'canManage' => false,
                'config' => [
                    'columns' => ['member_name', 'activity_name', 'start_on', 'expires_on'],
                    'filters' => [
                        [
                            'field' => 'status',
                            'operator' => 'eq',
                            'value' => 'Revoked',
                        ],
                    ],
                ],
            ],
        ];
    }
}
