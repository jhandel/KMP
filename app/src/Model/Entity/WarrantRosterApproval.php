<?php
declare(strict_types=1);

namespace App\Model\Entity;

/**
 * WarrantRosterApproval Entity
 *
 * @property int $id
 * @property int $warrant_roster_id
 * @property int $approver_id
 * @property \Cake\I18n\DateTime|null $approved_on
 *
 * @property \App\Model\Entity\WarrantRoster $warrant_roster
 * @property \App\Model\Entity\Member $member
 */
class WarrantRosterApproval extends BaseEntity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'warrant_roster_id' => true,
        'approver_id' => true,
        'approved_on' => true,
    ];
}
