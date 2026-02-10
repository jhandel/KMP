/**
 * WorkflowConfigPanel
 *
 * Renders per-node-type configuration forms inside the side panel.
 */
export default class WorkflowConfigPanel {
    /**
     * @param {object} registryData - { triggers, actions, conditions, entities }
     */
    constructor(registryData) {
        this.registryData = registryData
    }

    /**
     * Render the full config panel HTML for a selected node.
     */
    renderConfigHTML(nodeId, nodeData) {
        const type = nodeData.data?.type || 'unknown'
        const typeLabels = {
            trigger: 'Trigger', action: 'Action', condition: 'Condition',
            approval: 'Approval Gate', fork: 'Parallel Fork', join: 'Parallel Join',
            loop: 'Loop', delay: 'Delay', subworkflow: 'Sub-workflow', end: 'End'
        }
        let html = `
            <div class="config-panel-header">
                <h6><i class="bi bi-sliders me-1"></i>${typeLabels[type] || type} Configuration</h6>
            </div>
            <div class="config-panel-body" aria-live="polite">
                <form data-node-id="${nodeId}">
                    <div class="mb-3">
                        <label class="form-label">Label</label>
                        <input type="text" class="form-control form-control-sm" name="label" value="${nodeData.name || ''}"
                            data-action="change->workflow-designer#updateNodeConfig">
                    </div>`

        html += this.getTypeSpecificHTML(type, nodeData.data?.config || {})
        html += `</form></div>`
        return html
    }

    /**
     * Return the empty-state HTML for the config panel.
     */
    renderEmptyHTML() {
        return `
            <div class="config-panel-header">
                <h6><i class="bi bi-sliders me-1"></i>Configuration</h6>
            </div>
            <div class="config-panel-empty">
                <i class="bi bi-hand-index"></i>
                <p>Select a node on the canvas to configure it</p>
            </div>`
    }

    getTypeSpecificHTML(type, config) {
        switch (type) {
            case 'trigger': return this._triggerHTML(config)
            case 'action': return this._actionHTML(config)
            case 'condition': return this._conditionHTML(config)
            case 'approval': return this._approvalHTML(config)
            case 'delay': return this._delayHTML(config)
            case 'loop': return this._loopHTML(config)
            default: return ''
        }
    }

    _triggerHTML(config) {
        let options = '<option value="">Select a trigger...</option>'
        this.registryData.triggers?.forEach(t => {
            const selected = config.event === t.event ? 'selected' : ''
            options += `<option value="${t.event}" ${selected}>${t.label}</option>`
        })
        return `<div class="mb-3">
            <label class="form-label">Trigger Event</label>
            <select class="form-select form-select-sm" name="event" data-action="change->workflow-designer#updateNodeConfig">${options}</select>
        </div>`
    }

    _actionHTML(config) {
        let options = '<option value="">Select an action...</option>'
        this.registryData.actions?.forEach(a => {
            const selected = config.action === a.action ? 'selected' : ''
            options += `<option value="${a.action}" ${selected}>${a.label}</option>`
        })
        return `<div class="mb-3">
            <label class="form-label">Action</label>
            <select class="form-select form-select-sm" name="action" data-action="change->workflow-designer#updateNodeConfig">${options}</select>
        </div>`
    }

    _conditionHTML(config) {
        let options = '<option value="">Select a condition...</option>'
        options += '<optgroup label="Built-in">'
        options += '<option value="Core.FieldEquals">Field Equals Value</option>'
        options += '<option value="Core.FieldNotEmpty">Field Is Not Empty</option>'
        options += '<option value="Core.Expression">Custom Expression</option>'
        options += '</optgroup>'
        if (this.registryData.conditions?.length > 0) {
            options += '<optgroup label="Plugin Conditions">'
            this.registryData.conditions.forEach(c => {
                const selected = config.condition === c.condition ? 'selected' : ''
                options += `<option value="${c.condition}" ${selected}>${c.label}</option>`
            })
            options += '</optgroup>'
        }
        return `<div class="mb-3">
            <label class="form-label">Condition</label>
            <select class="form-select form-select-sm" name="condition" data-action="change->workflow-designer#updateNodeConfig">${options}</select>
        </div>
        <div class="mb-3">
            <label class="form-label">Field Path</label>
            <input type="text" class="form-control form-control-sm" name="field" value="${config.field || ''}" placeholder="$.entity.field_name" data-action="change->workflow-designer#updateNodeConfig" data-variable-picker="true">
        </div>
        <div class="mb-3">
            <label class="form-label">Expected Value</label>
            <input type="text" class="form-control form-control-sm" name="expectedValue" value="${config.expectedValue || ''}" data-action="change->workflow-designer#updateNodeConfig" data-variable-picker="true">
        </div>`
    }

    _approvalHTML(config) {
        return `<div class="mb-3">
            <label class="form-label">Approver Type</label>
            <select class="form-select form-select-sm" name="approverType" data-action="change->workflow-designer#updateNodeConfig">
                <option value="permission" ${config.approverType === 'permission' ? 'selected' : ''}>By Permission</option>
                <option value="role" ${config.approverType === 'role' ? 'selected' : ''}>By Role</option>
                <option value="member" ${config.approverType === 'member' ? 'selected' : ''}>Specific Member</option>
                <option value="dynamic" ${config.approverType === 'dynamic' ? 'selected' : ''}>Dynamic (from context)</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Permission/Role</label>
            <input type="text" class="form-control form-control-sm" name="approverValue" value="${config.approverValue || ''}" data-action="change->workflow-designer#updateNodeConfig" data-variable-picker="true">
        </div>
        <div class="mb-3">
            <label class="form-label">Required Approvals</label>
            <input type="number" class="form-control form-control-sm" name="requiredCount" value="${config.requiredCount || 1}" min="1" data-action="change->workflow-designer#updateNodeConfig">
        </div>
        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" name="allowParallel" id="allowParallel" ${config.allowParallel !== false ? 'checked' : ''} data-action="change->workflow-designer#updateNodeConfig">
            <label class="form-check-label" for="allowParallel">Allow Parallel Approvals</label>
        </div>
        <div class="mb-3">
            <label class="form-label">Deadline</label>
            <input type="text" class="form-control form-control-sm" name="deadline" value="${config.deadline || ''}" placeholder="e.g. 7d, 24h" data-action="change->workflow-designer#updateNodeConfig">
        </div>`
    }

    _delayHTML(config) {
        return `<div class="mb-3">
            <label class="form-label">Duration</label>
            <input type="text" class="form-control form-control-sm" name="duration" value="${config.duration || ''}" placeholder="e.g. 1h, 2d, 30m" data-action="change->workflow-designer#updateNodeConfig">
        </div>
        <div class="mb-3">
            <label class="form-label">Wait For Event (optional)</label>
            <input type="text" class="form-control form-control-sm" name="waitEvent" value="${config.waitEvent || ''}" placeholder="Event to resume on" data-action="change->workflow-designer#updateNodeConfig">
        </div>`
    }

    _loopHTML(config) {
        return `<div class="mb-3">
            <label class="form-label">Max Iterations</label>
            <input type="number" class="form-control form-control-sm" name="maxIterations" value="${config.maxIterations || 100}" min="1" data-action="change->workflow-designer#updateNodeConfig">
        </div>
        <div class="mb-3">
            <label class="form-label">Exit Condition</label>
            <input type="text" class="form-control form-control-sm" name="exitCondition" value="${config.exitCondition || ''}" placeholder="Expression to evaluate" data-action="change->workflow-designer#updateNodeConfig" data-variable-picker="true">
        </div>`
    }
}
