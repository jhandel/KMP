<?php

/**
 * Workflow Approvals - Dataverse Grid View
 *
 * @var \App\View\AppView $this
 */
?>
<?php $this->extend('/layout/TwitterBootstrap/dashboard');

echo $this->KMP->startBlock("title");
echo $this->KMP->getAppSetting("KMP.ShortSiteTitle") . ': Approvals';
$this->KMP->endBlock(); ?>

<h3><i class="bi bi-check2-square me-2"></i><?= __('My Approvals') ?></h3>

<?= $this->element('dv_grid', [
    'frameId' => 'approvals-grid',
    'dataUrl' => $this->Url->build(['controller' => 'Workflows', 'action' => 'approvalsGridData']),
]) ?>
