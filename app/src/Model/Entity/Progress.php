<?php

declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;
use App\Model\Entity\BaseEntity;

/**
 * Progress Entity - 
 *
 * 
 *
 * @property int $id Primary key
 * @property int $gathering_id Foreign key to gathering
 * @property int $attendance_id Foreign key to attendance
 * @property int $office_id Foreign key to office
 * @property int $member_id Foreign key to assigned member
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 * @property int|null $created_by
 * @property int|null $modified_by
 * @property \Cake\I18n\DateTime|null $deleted
 * 
 * @property \App\Model\Entity\Gathering $gathering
 * @property \App\Model\Entity\GatheringAttendance $attendance
 * @property \Officers\Model\Entity\Officer $officer
 * @property \App\Model\Entity\Member $member
 * 
 * 
 */
class Progress extends BaseEntity
{
    /**
     * Fields that can be mass assigned.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'gathering_id' => true,
        'attendance_id' => true,
        'officer_id' => true,
        'member_id' => true,
        'sort_order' => true,
        'created' => true,
        'modified' => true,
        'created_by' => true,
        'modified_by' => true,
        'deleted' => true,

        'gathering' => true,
        'attendance' => true,
        'officer' => true,
        'member' => true,

    ];
}