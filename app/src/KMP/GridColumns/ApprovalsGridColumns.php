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
                'width' => '200px',
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
                'width' => '250px',
                'alignment' => 'left',
                // Virtual field set by controller — not queryable
                'skipAutoFilter' => true,
            ],

            'status_label' => [
                'key' => 'status_label',
                'label' => 'Status',
                'type' => 'string',
                'sortable' => true,
                'filterable' => true,
                'filterType' => 'dropdown',
                'defaultVisible' => true,
                'width' => '180px',
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
                'width' => '150px',
                'alignment' => 'left',
                'filterType' => 'date-range',
            ],

            'modified' => [
                'key' => 'modified',
                'label' => 'Last Action',
                'type' => 'datetime',
                'sortable' => true,
                'filterable' => true,
                'defaultVisible' => true,
                'width' => '150px',
                'alignment' => 'left',
                'filterType' => 'date-range',
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
}
