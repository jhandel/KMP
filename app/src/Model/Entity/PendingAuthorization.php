<?php

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * PendingAuthorization Entity
 *
 * @property int $id
 * @property int $Member_id
 * @property int $Member_marshal_id
 * @property int $activity_id
 * @property string $authorization_token
 * @property \Cake\I18n\Time $requested_on
 *
 * @property \App\Model\Entity\Member $Member
 * @property \App\Model\Entity\Activity $activity
 */
class PendingAuthorization extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * Note that when '*' is set to true, this allows all unspecified fields to
     * be mass assigned. For security purposes, it is advised to set '*' to false
     * (or remove it), and explicitly make individual fields accessible as needed.
     *
     * @var array
     */
    protected array $_accessible = [
        "*" => true,
        "id" => false,
    ];
}
