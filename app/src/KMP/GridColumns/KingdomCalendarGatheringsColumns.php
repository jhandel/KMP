<?php

declare(strict_types=1);

namespace App\KMP\GridColumns;

class KingdomCalendarGatheringsColumns extends BaseGridColumns
{
    public function getColumns(): array
    {
        return [
            'id' => [
                'key' => 'id',
                'label' => 'ID',
                'type' => 'number',
                'sortable' => true,
                'filterable' => false,
                'defaultVisible' => false,
                'width' => '80px',
                'alignment' => 'right',
            ],

            'public_id' => [
                'key' => 'public_id',
                'label' => 'Public ID',
                'type' => 'string',
                'sortable' => false,
                'filterable' => false,
                'defaultVisible' => false,
                'width' => '120px',
                'alignment' => 'left',
            ],

            'name' => [
                'key' => 'name',
                'label' => 'Name',
                'type' => 'string',
                'sortable' => true,
                'filterable' => true,
                'searchable' => true,
                'defaultVisible' => true,
                'required' => true,
                'width' => '260px',
                'alignment' => 'left',
                'clickAction' => 'navigate:/gatherings/view/:public_id',
            ],

            'branch_id' => [
                'key' => 'branch_id',
                'label' => 'Branch',
                'type' => 'relation',
                'sortable' => true,
                'filterable' => true,
                'filterType' => 'dropdown',
                'filterOptionsSource' => [
                    'table' => 'Branches',
                    'valueField' => 'public_id',
                    'labelField' => 'name',
                ],
                'defaultVisible' => true,
                'width' => '220px',
                'alignment' => 'left',
                'renderField' => 'branch.name',
                'queryField' => 'Branches.public_id',
            ],

            'gathering_type_id' => [
                'key' => 'gathering_type_id',
                'label' => 'Type',
                'type' => 'relation',
                'sortable' => true,
                'filterable' => true,
                'filterType' => 'dropdown',
                'filterOptionsSource' => 'GatheringTypes',
                'defaultVisible' => true,
                'width' => '180px',
                'alignment' => 'left',
                'renderField' => 'gathering_type.name',
                'queryField' => 'GatheringTypes.id',
            ],

            'start_date' => [
                'key' => 'start_date',
                'label' => 'Start',
                'type' => 'datetime',
                'sortable' => true,
                'filterable' => true,
                'filterType' => 'date-range',
                'defaultVisible' => true,
                'width' => '170px',
                'alignment' => 'left',
            ],

            'end_date' => [
                'key' => 'end_date',
                'label' => 'End',
                'type' => 'datetime',
                'sortable' => true,
                'filterable' => true,
                'filterType' => 'date-range',
                'defaultVisible' => true,
                'width' => '170px',
                'alignment' => 'left',
            ],

            'location' => [
                'key' => 'location',
                'label' => 'Location',
                'type' => 'string',
                'sortable' => false,
                'filterable' => true,
                'searchable' => true,
                'defaultVisible' => false,
                'width' => '240px',
                'alignment' => 'left',
            ],

            'activity_filter' => [
                'key' => 'activity_filter',
                'label' => 'Activity',
                'type' => 'relation',
                'sortable' => false,
                'filterable' => true,
                'filterType' => 'dropdown',
                'filterOptionsSource' => 'GatheringActivities',
                'defaultVisible' => false,
                'alignment' => 'left',
                'queryField' => 'GatheringActivities.id',
            ],

            'activity_count' => [
                'key' => 'activity_count',
                'label' => '# Activities',
                'type' => 'number',
                'sortable' => false,
                'filterable' => false,
                'defaultVisible' => false,
                'width' => '120px',
                'alignment' => 'center',
            ],

            'created' => [
                'key' => 'created',
                'label' => 'Created',
                'type' => 'datetime',
                'sortable' => true,
                'filterable' => false,
                'defaultVisible' => false,
                'width' => '170px',
                'alignment' => 'left',
            ],

            'cancelled_at' => [
                'key' => 'cancelled_at',
                'label' => 'Status',
                'type' => 'badge',
                'sortable' => true,
                'filterable' => true,
                'filterType' => 'dropdown',
                'filterOptions' => [
                    ['value' => '', 'label' => 'All'],
                    ['value' => 'active', 'label' => 'Active'],
                    ['value' => 'cancelled', 'label' => 'Cancelled'],
                ],
                'defaultVisible' => true,
                'width' => '100px',
                'alignment' => 'center',
                'badgeConfig' => [
                    'nullValue' => ['text' => 'Active', 'class' => 'bg-success'],
                    'hasValue' => ['text' => 'Cancelled', 'class' => 'bg-danger'],
                ],
                'skipAutoFilter' => true,
                'customFilterHandler' => [
                    'class' => self::class,
                    'method' => 'filterByCancelledStatus',
                ],
            ],

            'kingdom_calendar_event' => [
                'label' => 'Kingdom Calendar',
                'searchable' => false,
                'sortable' => true,
                'filterType' => 'checkbox',
            ],
        ];
    }
}