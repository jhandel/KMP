<?php

declare(strict_types=1);

namespace App\KMP\GridColumns;

use App\Model\Entity\WorkflowApproval;

/**
 * Approvals Grid Column Metadata
 *
 * Defines column configuration for the My Approvals Dataverse grid.
 * Used for both "Pending Approvals" and "Decisions" system views.
 */
class ApprovalsGridColumns extends BaseGridColumns
{
    public static function getColumns(): array
    {
        return [
            'workflow_name' => [
                'key' => 'workflow_name',
                'label' => 'Workflow',
                'type' => 'string',
                'sortable' => true,
                'filterable' => true,
                'searchable' => true,
                'defaultVisible' => true,
                'required' => true,
                'width' => '180px',
                'alignment' => 'left',
                'queryField' => 'WorkflowDefinitions.name',
            ],

            'request' => [
                'key' => 'request',
                'label' => 'Request',
                'type' => 'string',
                'sortable' => false,
                'filterable' => false,
                'searchable' => false,
                'defaultVisible' => true,
                'width' => '220px',
                'alignment' => 'left',
                'skipAutoFilter' => true,
            ],

            'requester' => [
                'key' => 'requester',
                'label' => 'Requester',
                'type' => 'string',
                'sortable' => false,
                'filterable' => false,
                'searchable' => false,
                'defaultVisible' => true,
                'width' => '150px',
                'alignment' => 'left',
                'skipAutoFilter' => true,
            ],

            'current_approver' => [
                'key' => 'current_approver',
                'label' => 'Assigned To',
                'type' => 'string',
                'sortable' => true,
                'filterable' => true,
                'searchable' => true,
                'defaultVisible' => false,
                'width' => '150px',
                'alignment' => 'left',
                'queryField' => 'CurrentApprover.sca_name',
            ],

            'status_label' => [
                'key' => 'status_label',
                'label' => 'Status',
                'type' => 'string',
                'sortable' => true,
                'filterable' => true,
                'filterType' => 'dropdown',
                'defaultVisible' => true,
                'width' => '150px',
                'alignment' => 'center',
                'queryField' => 'WorkflowApprovals.status',
                'filterOptions' => [
                    ['value' => WorkflowApproval::STATUS_PENDING, 'label' => 'Pending'],
                    ['value' => WorkflowApproval::STATUS_APPROVED, 'label' => 'Approved'],
                    ['value' => WorkflowApproval::STATUS_REJECTED, 'label' => 'Rejected'],
                    ['value' => WorkflowApproval::STATUS_EXPIRED, 'label' => 'Expired'],
                    ['value' => WorkflowApproval::STATUS_CANCELLED, 'label' => 'Cancelled'],
                ],
            ],

            'created' => [
                'key' => 'created',
                'label' => 'Created',
                'type' => 'datetime',
                'sortable' => true,
                'filterable' => true,
                'defaultVisible' => true,
                'width' => '140px',
                'alignment' => 'left',
                'filterType' => 'date-range',
            ],

            'modified' => [
                'key' => 'modified',
                'label' => 'Last Action',
                'type' => 'datetime',
                'sortable' => true,
                'filterable' => true,
                'defaultVisible' => false,
                'width' => '140px',
                'alignment' => 'left',
                'filterType' => 'date-range',
            ],
        ];
    }

    /**
     * Row actions for the approvals grid.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getRowActions(): array
    {
        return [
            'detail' => [
                'key' => 'detail',
                'type' => 'button',
                'label' => 'Details',
                'icon' => 'bi-chevron-down',
                'class' => 'btn btn-sm btn-outline-secondary',
                'dataAttributes' => [
                    'action' => 'click->approval-detail#toggle',
                ],
            ],
            'respond' => [
                'key' => 'respond',
                'type' => 'modal',
                'label' => 'Respond',
                'icon' => 'bi-reply-fill',
                'class' => 'btn btn-sm btn-primary',
                'modalTarget' => '#approvalResponseModal',
                'statusFilter' => [WorkflowApproval::STATUS_PENDING],
                'dataAttributes' => [
                    'controller' => 'outlet-btn',
                    'action' => 'click->outlet-btn#fireNotice',
                    'outlet-btn-btn-data-value' => [
                        'id' => 'id',
                        'approver_config' => 'approver_config',
                        'required_count' => 'required_count',
                        'approved_count' => 'approved_count',
                    ],
                ],
            ],
        ];
    }

    /**
     * System views for the approvals grid.
     *
     * @param array<string, mixed> $options
     * @return array<string, array<string, mixed>>
     */
    public static function getSystemViews(array $options = []): array
    {
        return [
            'sys-approvals-pending' => [
                'id' => 'sys-approvals-pending',
                'name' => __('Pending Approvals'),
                'description' => __('Approvals waiting for your response'),
                'canManage' => false,
                'config' => [
                    'filters' => [
                        ['field' => 'status_label', 'operator' => 'eq', 'value' => WorkflowApproval::STATUS_PENDING],
                    ],
                ],
            ],
            'sys-approvals-decisions' => [
                'id' => 'sys-approvals-decisions',
                'name' => __('Decisions'),
                'description' => __('Your past approval decisions'),
                'canManage' => false,
                'config' => [
                    'filters' => [
                        [
                            'field' => 'status_label',
                            'operator' => 'in',
                            'value' => [
                                WorkflowApproval::STATUS_APPROVED,
                                WorkflowApproval::STATUS_REJECTED,
                                WorkflowApproval::STATUS_EXPIRED,
                                WorkflowApproval::STATUS_CANCELLED,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Row actions for the admin All Approvals grid.
     * Includes reassignment for pending approvals with an assigned approver.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getAdminRowActions(): array
    {
        $base = self::getRowActions();
        $base['reassign'] = [
            'key' => 'reassign',
            'type' => 'modal',
            'label' => 'Reassign',
            'icon' => 'bi-person-gear',
            'class' => 'btn btn-sm btn-outline-warning',
            'modalTarget' => '#approvalReassignModal',
            'statusFilter' => [WorkflowApproval::STATUS_PENDING],
            'dataAttributes' => [
                'controller' => 'outlet-btn',
                'action' => 'click->outlet-btn#fireNotice',
                'outlet-btn-btn-data-value' => [
                    'id' => 'id',
                    'approver_config' => 'approver_config',
                    'current_approver_id' => 'current_approver_id',
                ],
            ],
        ];

        return $base;
    }

    /**
     * Admin columns include Assigned To visible by default.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getAdminColumns(): array
    {
        $columns = self::getColumns();
        $columns['current_approver']['defaultVisible'] = true;

        return $columns;
    }

    /**
     * System views for the admin All Approvals grid.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function getAdminSystemViews(): array
    {
        return [
            'sys-admin-pending' => [
                'id' => 'sys-admin-pending',
                'name' => __('Pending'),
                'description' => __('All pending approvals across the system'),
                'canManage' => false,
                'config' => [
                    'filters' => [
                        ['field' => 'status_label', 'operator' => 'eq', 'value' => WorkflowApproval::STATUS_PENDING],
                    ],
                ],
            ],
            'sys-admin-approved' => [
                'id' => 'sys-admin-approved',
                'name' => __('Approved'),
                'description' => __('All approved requests'),
                'canManage' => false,
                'config' => [
                    'filters' => [
                        ['field' => 'status_label', 'operator' => 'eq', 'value' => WorkflowApproval::STATUS_APPROVED],
                    ],
                ],
            ],
            'sys-admin-rejected' => [
                'id' => 'sys-admin-rejected',
                'name' => __('Rejected'),
                'description' => __('All rejected requests'),
                'canManage' => false,
                'config' => [
                    'filters' => [
                        ['field' => 'status_label', 'operator' => 'eq', 'value' => WorkflowApproval::STATUS_REJECTED],
                    ],
                ],
            ],
            'sys-admin-all' => [
                'id' => 'sys-admin-all',
                'name' => __('All'),
                'description' => __('All approvals regardless of status'),
                'canManage' => false,
                'config' => [],
            ],
        ];
    }
}
