<?php

declare(strict_types=1);

namespace App\KMP\GridColumns;

class KingdomCalendarGatheringsGridColumns extends GatheringsGridColumns
{
    public function schema(): array
    {

        $schema = parent::schema();

        // extend the base gatherings grid with the kingdom calendar event column
        $schema->addColumn('kingdom_calendar_event', [
            'label' => 'Kingdom Calendar',
            'searchable' => false,
            'sortable' => true,
            'filterType' => 'checkbox',
        ]);

        return $schema;
    }
}