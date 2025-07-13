import { Controller } from "@hotwired/stimulus"

class DynamicFormController extends Controller {
    static targets = ["fieldsContainer", "fieldTemplate", "noFieldsMessage", "assignmentFields"]
    
    static values = {
        fieldCount: { type: Number, default: 0 }
    }

    connect() {
        console.log("DynamicFormController connected")
        this.updateFieldsVisibility()
        this.toggleAssignmentFields()
    }

    addField() {
        const template = this.fieldTemplateTarget.cloneNode(true)
        template.style.display = 'block'
        template.id = ''
        
        // Update field names and IDs
        const fieldIndex = this.fieldCountValue
        this.updateFieldInputs(template, fieldIndex)
        
        // Add to container
        this.fieldsContainerTarget.appendChild(template)
        this.fieldCountValue++
        
        this.updateFieldsVisibility()
    }

    removeField(event) {
        const fieldCard = event.target.closest('.field-card')
        if (fieldCard) {
            fieldCard.remove()
            this.updateFieldsVisibility()
        }
    }

    updateFieldInputs(template, index) {
        const inputs = template.querySelectorAll('input, select, textarea')
        inputs.forEach(input => {
            const originalName = input.className.split(' ').find(cls => cls.startsWith('field-'))
            if (originalName) {
                const fieldName = originalName.replace('field-', '')
                input.name = `form_fields[${index}][${fieldName}]`
                input.id = `form-fields-${index}-${fieldName}`
            }
        })
    }

    updateFieldTitle(event) {
        const fieldCard = event.target.closest('.field-card')
        const titleSpan = fieldCard.querySelector('.field-title')
        const value = event.target.value.trim()
        
        if (value) {
            titleSpan.textContent = value
        } else {
            titleSpan.textContent = 'New Field'
        }
    }

    toggleFieldOptions(event) {
        const fieldCard = event.target.closest('.field-card')
        const optionsDiv = fieldCard.querySelector('.field-options')
        const fieldType = event.target.value
        
        if (['select', 'radio', 'checkbox'].includes(fieldType)) {
            optionsDiv.style.display = 'block'
        } else {
            optionsDiv.style.display = 'none'
        }
    }

    toggleAssignmentFields() {
        const assignmentTypeSelect = document.querySelector('select[name="assignment_type"]')
        if (!assignmentTypeSelect) return
        
        const assignmentType = assignmentTypeSelect.value
        const fieldsDiv = this.assignmentFieldsTarget
        
        if (assignmentType === 'assigned' || assignmentType === 'office-specific') {
            fieldsDiv.style.display = 'block'
        } else {
            fieldsDiv.style.display = 'none'
        }
    }

    updateFieldsVisibility() {
        const hasFields = this.fieldsContainerTarget.children.length > 0
        
        if (hasFields) {
            this.noFieldsMessageTarget.style.display = 'none'
        } else {
            this.noFieldsMessageTarget.style.display = 'block'
        }
    }
}

// Add to global controllers registry
if (!window.Controllers) {
    window.Controllers = {};
}
window.Controllers["dynamic-form"] = DynamicFormController;