<?php

declare(strict_types=1);

namespace OfficerEventReporting\Model\Entity;

use App\Model\Entity\BaseEntity;
use Cake\I18n\DateTime;

/**
 * FormField Entity
 *
 * @property int $id
 * @property int $form_id
 * @property string $field_name
 * @property string $field_label
 * @property string $field_type
 * @property string|null $field_options
 * @property bool $is_required
 * @property int $sort_order
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime|null $modified
 *
 * @property \OfficerEventReporting\Model\Entity\Form $form
 * @property \OfficerEventReporting\Model\Entity\SubmissionValue[] $submission_values
 */
class FormField extends BaseEntity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'form_id' => true,
        'field_name' => true,
        'field_label' => true,
        'field_type' => true,
        'field_options' => true,
        'is_required' => true,
        'sort_order' => true,
        'created' => true,
        'modified' => true,
    ];

    /**
     * Get field options as an array
     *
     * @return array
     */
    protected function _getFieldOptionsArray(): array
    {
        if (empty($this->field_options)) {
            return [];
        }
        
        $decoded = json_decode($this->field_options, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Set field options from array
     *
     * @param array $options
     * @return string
     */
    protected function _setFieldOptionsArray(array $options): string
    {
        return json_encode($options);
    }

    /**
     * Get select/radio options for rendering
     *
     * @return array
     */
    public function getSelectOptions(): array
    {
        $options = $this->field_options_array;
        
        if (isset($options['choices']) && is_array($options['choices'])) {
            return $options['choices'];
        }
        
        return [];
    }

    /**
     * Get file upload restrictions
     *
     * @return array
     */
    public function getFileRestrictions(): array
    {
        $options = $this->field_options_array;
        
        return [
            'allowed_types' => $options['allowed_types'] ?? ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'],
            'max_size' => $options['max_size'] ?? 10485760, // 10MB default
        ];
    }

    /**
     * Check if this field type requires options
     *
     * @return bool
     */
    public function requiresOptions(): bool
    {
        return in_array($this->field_type, ['select', 'radio', 'checkbox']);
    }
}