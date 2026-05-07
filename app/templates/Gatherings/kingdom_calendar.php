<?php

/**
 * Kingdom Calendar - List View
 *
 * Displays all gatherings marked as kingdom calendar events
 *
 * @var \App\View\AppView $this
 * @var \Cake\Datasource\ResultSetInterface $gatherings
 */
?>
<?php
$this->extend("/layout/TwitterBootstrap/dashboard");

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Kingdom Calendar';
$this->KMP->endBlock();
?>

<div class="row align-items-start mb-3">
    <div class="col">
        <h3>Kingdom Calendar</h3>
    </div>
    <div class="col text-end">
        <?= $this->Html->link(
            '<i class="bi bi-calendar-event"></i> Calendar View',
            ['action' => 'calendar'],
            ['class' => 'btn btn-info', 'escape' => false]
        ) ?>
    </div>
</div>

<?= $this->element('dv_grid', [
    'gridKey' => 'Gatherings.kingdomCalendar.main',
    'frameId' => 'kingdom-calendar-grid',
    'dataUrl' => $this->Url->build(['action' => 'kingdomCalendarGridData']),
]) ?>