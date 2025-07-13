<?php

declare(strict_types=1);

namespace OfficerEventReporting\Model\Entity;

use App\Model\Entity\BaseEntity;
use Cake\I18n\DateTime;

/**
 * Submission Entity
 *
 * @property int $id
 * @property int $form_id
 * @property int $submitted_by
 * @property string $status
 * @property int|null $reviewer_id
 * @property string|null $review_notes
 * @property \Cake\I18n\DateTime|null $reviewed_at
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 *
 * @property \OfficerEventReporting\Model\Entity\Form $form
 * @property \App\Model\Entity\Member $submitted_by_member
 * @property \App\Model\Entity\Member|null $reviewer
 * @property \OfficerEventReporting\Model\Entity\SubmissionValue[] $submission_values
 */
class Submission extends BaseEntity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'form_id' => true,
        'submitted_by' => true,
        'status' => true,
        'reviewer_id' => true,
        'review_notes' => true,
        'reviewed_at' => true,
        'submission_values' => true,
        'created' => true,
        'modified' => true,
    ];

    /**
     * Check if the submission can be reviewed
     *
     * @return bool
     */
    public function canBeReviewed(): bool
    {
        return $this->status === 'submitted';
    }

    /**
     * Check if the submission has been reviewed
     *
     * @return bool
     */
    public function isReviewed(): bool
    {
        return in_array($this->status, ['reviewed', 'approved', 'rejected']);
    }

    /**
     * Get submission values indexed by field name
     *
     * @return array
     */
    public function getValuesByFieldName(): array
    {
        $values = [];
        
        if (!empty($this->submission_values)) {
            foreach ($this->submission_values as $value) {
                if (!empty($value->form_field)) {
                    $values[$value->form_field->field_name] = $value->field_value;
                }
            }
        }
        
        return $values;
    }

    /**
     * Get formatted submission data for display
     *
     * @return array
     */
    public function getFormattedData(): array
    {
        $data = [];
        
        if (!empty($this->submission_values)) {
            foreach ($this->submission_values as $value) {
                if (!empty($value->form_field)) {
                    $field = $value->form_field;
                    $data[] = [
                        'label' => $field->field_label,
                        'type' => $field->field_type,
                        'value' => $value->field_value,
                        'required' => $field->is_required,
                    ];
                }
            }
        }
        
        return $data;
    }
}