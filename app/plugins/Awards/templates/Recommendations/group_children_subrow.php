<?php
/**
 * Sub-row template for grouped recommendation children.
 *
 * @var \App\View\AppView $this
 * @var \Awards\Model\Entity\Recommendation[] $children
 * @var int $headId
 * @var bool $canEdit
 */
?>
<div class="p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="mb-0">
            <i class="bi bi-collection"></i>
            <?= __('Grouped Recommendations ({0})', count($children)) ?>
        </h6>
        <?php if ($canEdit) : ?>
            <?= $this->Form->postLink(
                '<i class="bi bi-x-circle"></i> ' . __('Ungroup All'),
                ['action' => 'ungroupRecommendations'],
                [
                    'data' => ['recommendation_id' => $headId],
                    'confirm' => __('Ungroup all children? They will be restored to their previous states.'),
                    'class' => 'btn btn-outline-warning btn-sm',
                    'escape' => false,
                ]
            ) ?>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-striped mb-0">
            <thead>
                <tr>
                    <th><?= __('Award') ?></th>
                    <th><?= __('For') ?></th>
                    <th><?= __('Reason') ?></th>
                    <th><?= __('Requester') ?></th>
                    <th><?= __('Submitted') ?></th>
                    <?php if ($canEdit) : ?>
                        <th class="text-end"><?= __('Actions') ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($children as $child) : ?>
                    <tr>
                        <td><?= h($child->award->abbreviation ?? '—') ?></td>
                        <td><?= h($child->member_sca_name) ?></td>
                        <td>
                            <?php
                            $reason = h($child->reason ?? '');
                            echo mb_strlen($reason) > 150
                                ? mb_substr($reason, 0, 150) . '…'
                                : $reason;
                            ?>
                        </td>
                        <td><?= h($child->requester->sca_name ?? $child->requester_sca_name) ?></td>
                        <td><?= $child->created ? $child->created->format('Y-m-d') : '—' ?></td>
                        <?php if ($canEdit) : ?>
                            <td class="text-end text-nowrap">
                                <?= $this->Html->link(
                                    '<i class="bi bi-eye"></i>',
                                    ['action' => 'view', $child->id],
                                    ['class' => 'btn btn-sm btn-outline-secondary', 'escape' => false, 'title' => __('View')]
                                ) ?>
                                <?= $this->Form->postLink(
                                    '<i class="bi bi-x-lg"></i>',
                                    ['action' => 'removeFromGroup'],
                                    [
                                        'data' => ['recommendation_id' => $child->id],
                                        'confirm' => __('Remove this recommendation from the group?'),
                                        'class' => 'btn btn-sm btn-outline-danger',
                                        'escape' => false,
                                        'title' => __('Remove from group'),
                                    ]
                                ) ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
