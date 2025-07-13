<?php

declare(strict_types=1);

namespace OfficerEventReporting\Model\Entity;

use App\Model\Entity\BaseEntity;
use Cake\I18n\DateTime;

/**
 * SubmissionValue Entity
 *
 * @property int $id
 * @property int $submission_id
 * @property int $form_field_id
 * @property string|null $field_value
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 *
 * @property \OfficerEventReporting\Model\Entity\Submission $submission
 * @property \OfficerEventReporting\Model\Entity\FormField $form_field
 */
class SubmissionValue extends BaseEntity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'submission_id' => true,
        'form_field_id' => true,
        'field_value' => true,
        'created' => true,
        'modified' => true,
    ];

    /**
     * Get the value formatted for display based on field type
     *
     * @return string
     */
    public function getFormattedValue(): string
    {
        if (empty($this->field_value)) {
            return '';
        }

        if (!empty($this->form_field)) {
            switch ($this->form_field->field_type) {
                case 'date':
                    if ($date = DateTime::createFromFormat('Y-m-d', $this->field_value)) {
                        return $date->format('M j, Y');
                    }
                    break;
                
                case 'datetime':
                    if ($datetime = DateTime::createFromFormat('Y-m-d H:i:s', $this->field_value)) {
                        return $datetime->format('M j, Y g:i A');
                    }
                    break;
                
                case 'checkbox':
                    return $this->field_value ? 'Yes' : 'No';
                
                case 'file':
                    // For file uploads, the value would be the filename
                    return basename($this->field_value);
            }
        }

        return (string)$this->field_value;
    }
}