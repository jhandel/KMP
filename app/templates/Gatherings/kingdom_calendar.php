<?php

/**
 * Kingdom Calendar - List View
 *
 * Displays all published gatherings, kingdom calendar events
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

<?= $this->element('gatherings/calendar_list', ['gatherings' => $gatherings]) ?>

<?php if ($this->Paginator->hasPage()): ?>
<nav class="mt-4" aria-label="pagination">
    <ul class="pagination justify-content-center">
        <?= $this->Paginator->first() ?>
        <?= $this->Paginator->prev() ?>
        <?= $this->Paginator->numbers() ?>
        <?= $this->Paginator->next() ?>
        <?= $this->Paginator->last() ?>
    </ul>
</nav>
<?php endif; ?>