<?php

declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * Tracks container update history including status, backup reference, and rollback info.
 *
 * @property int $id
 * @property string $from_tag
 * @property string $to_tag
 * @property string|null $channel
 * @property string $provider
 * @property string $status
 * @property int|null $backup_id
 * @property string|null $error_message
 * @property int $initiated_by
 * @property \Cake\I18n\DateTime|null $started_at
 * @property \Cake\I18n\DateTime|null $completed_at
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 * @property \App\Model\Entity\Backup|null $backup
 * @property \App\Model\Entity\Member $member
 */
class SystemUpdate extends Entity
{
    protected array $_accessible = [
        'from_tag' => true,
        'to_tag' => true,
        'channel' => true,
        'provider' => true,
        'status' => true,
        'backup_id' => true,
        'error_message' => true,
        'initiated_by' => true,
        'started_at' => true,
        'completed_at' => true,
    ];
}
