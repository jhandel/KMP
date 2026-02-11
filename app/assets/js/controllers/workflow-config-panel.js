/**
 * WorkflowConfigPanel
 *
 * Renders per-node-type configuration forms inside the side panel.
 */
export default class WorkflowConfigPanel {
    /**
     * @param {object} registryData - { triggers, actions, conditions, entities }
     */
    constructor(registryData, policyClasses) {
        this.registryData = registryData
        this.policyClasses = policyClasses || []
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
        const permInitSel = config.approverType === 'permission' && config.approverValue
            ? JSON.stringify({value: config.approverValue, text: config.approverValue}).replace(/'/g, '&#39;') : ''
        const roleInitSel = config.approverType === 'role' && config.approverValue
            ? JSON.stringify({value: config.approverValue, text: config.approverValue}).replace(/'/g, '&#39;') : ''
        const memberInitSel = config.approverType === 'member' && config.approverValue
            ? JSON.stringify({value: config.approverValue, text: config.approverValue}).replace(/'/g, '&#39;') : ''

        return `<div class="mb-3">
            <label class="form-label">Approver Type</label>
            <select class="form-select form-select-sm" name="approverType" data-action="change->workflow-designer#onApproverTypeChange">
                <option value="permission" ${config.approverType === 'permission' || !config.approverType ? 'selected' : ''}>By Permission</option>
                <option value="role" ${config.approverType === 'role' ? 'selected' : ''}>By Role</option>
                <option value="member" ${config.approverType === 'member' ? 'selected' : ''}>Specific Member</option>
                <option value="dynamic" ${config.approverType === 'dynamic' ? 'selected' : ''}>Dynamic (from context)</option>
                <option value="policy" ${config.approverType === 'policy' ? 'selected' : ''}>By Policy</option>
            </select>
        </div>
        <div data-approver-section="permission" style="display:${config.approverType === 'permission' || !config.approverType ? 'block' : 'none'};">
          <div class="mb-3">
            <label class="form-label">Permission</label>
            <div data-controller="auto-complete"
                 data-auto-complete-url-value="/permissions/auto-complete"
                 data-auto-complete-min-length-value="2"
                 data-auto-complete-allow-other-value="true"
                 data-auto-complete-init-selection-value='${permInitSel}'
                 style="position:relative;">
              <input type="text" class="form-control form-control-sm"
                     data-auto-complete-target="input" placeholder="Search permissions...">
              <input type="hidden" name="approverValue" value="${config.approverType === 'permission' ? (config.approverValue || '') : ''}"
                     data-auto-complete-target="hidden"
                     data-action="change->workflow-designer#updateNodeConfig">
              <input type="hidden" data-auto-complete-target="hiddenText">
              <button type="button" class="btn btn-sm btn-link text-muted p-0"
                      data-auto-complete-target="clearBtn" style="display:none;position:absolute;right:8px;top:8px;">
                <i class="bi bi-x-lg"></i>
              </button>
              <ul class="list-group shadow-sm" data-auto-complete-target="results" style="position:absolute;z-index:1050;width:100%;"></ul>
            </div>
          </div>
        </div>
        <div data-approver-section="role" style="display:${config.approverType === 'role' ? 'block' : 'none'};">
          <div class="mb-3">
            <label class="form-label">Role</label>
            <div data-controller="auto-complete"
                 data-auto-complete-url-value="/roles/auto-complete"
                 data-auto-complete-min-length-value="2"
                 data-auto-complete-allow-other-value="true"
                 data-auto-complete-init-selection-value='${roleInitSel}'
                 style="position:relative;">
              <input type="text" class="form-control form-control-sm"
                     data-auto-complete-target="input" placeholder="Search roles...">
              <input type="hidden" name="approverValue" value="${config.approverType === 'role' ? (config.approverValue || '') : ''}"
                     data-auto-complete-target="hidden"
                     data-action="change->workflow-designer#updateNodeConfig">
              <input type="hidden" data-auto-complete-target="hiddenText">
              <button type="button" class="btn btn-sm btn-link text-muted p-0"
                      data-auto-complete-target="clearBtn" style="display:none;position:absolute;right:8px;top:8px;">
                <i class="bi bi-x-lg"></i>
              </button>
              <ul class="list-group shadow-sm" data-auto-complete-target="results" style="position:absolute;z-index:1050;width:100%;"></ul>
            </div>
          </div>
        </div>
        <div data-approver-section="member" style="display:${config.approverType === 'member' ? 'block' : 'none'};">
          <div class="mb-3">
            <label class="form-label">Member</label>
            <div data-controller="auto-complete"
                 data-auto-complete-url-value="/members/auto-complete"
                 data-auto-complete-min-length-value="2"
                 data-auto-complete-allow-other-value="false"
                 data-auto-complete-init-selection-value='${memberInitSel}'
                 style="position:relative;">
              <input type="text" class="form-control form-control-sm"
                     data-auto-complete-target="input" placeholder="Search members...">
              <input type="hidden" name="approverValue" value="${config.approverType === 'member' ? (config.approverValue || '') : ''}"
                     data-auto-complete-target="hidden"
                     data-action="change->workflow-designer#updateNodeConfig">
              <input type="hidden" data-auto-complete-target="hiddenText">
              <button type="button" class="btn btn-sm btn-link text-muted p-0"
                      data-auto-complete-target="clearBtn" style="display:none;position:absolute;right:8px;top:8px;">
                <i class="bi bi-x-lg"></i>
              </button>
              <ul class="list-group shadow-sm" data-auto-complete-target="results" style="position:absolute;z-index:1050;width:100%;"></ul>
            </div>
          </div>
        </div>
        <div data-approver-section="dynamic" style="display:${config.approverType === 'dynamic' ? 'block' : 'none'};">
          <div class="mb-3">
            <label class="form-label">Context Path</label>
            <input type="text" class="form-control form-control-sm" name="approverValue"
                   value="${config.approverType === 'dynamic' ? (config.approverValue || '') : ''}"
                   placeholder="$.initiator.id"
                   data-action="change->workflow-designer#updateNodeConfig" data-variable-picker="true">
          </div>
        </div>
        <div data-approver-section="policy" style="display:${config.approverType === 'policy' ? 'block' : 'none'};">
          <div class="mb-3">
            <label class="form-label">Policy Class</label>
            <select class="form-select form-select-sm" name="policyClass"
                    data-action="change->workflow-designer#onPolicyClassChange">
              <option value="">Select a policy class...</option>
              ${this.policyClasses.map(p =>
                `<option value="${p.class}" ${config.policyClass === p.class ? 'selected' : ''}>${p.label}</option>`
              ).join('')}
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Policy Action</label>
            <select class="form-select form-select-sm" name="policyAction"
                    data-action="change->workflow-designer#updateNodeConfig">
              <option value="">Select a policy class first...</option>
              ${config.policyAction ? `<option value="${config.policyAction}" selected>${config.policyAction}</option>` : ''}
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Entity Table</label>
            <input type="text" class="form-control form-control-sm" name="entityTable"
                   value="${config.entityTable || ''}" placeholder="e.g. WarrantRosters"
                   data-action="change->workflow-designer#updateNodeConfig">
          </div>
          <div class="mb-3">
            <label class="form-label">Entity ID Key</label>
            <input type="text" class="form-control form-control-sm" name="entityIdKey"
                   value="${config.entityIdKey || ''}" placeholder="e.g. trigger.rosterId"
                   data-action="change->workflow-designer#updateNodeConfig" data-variable-picker="true">
          </div>
          <div class="mb-3">
            <label class="form-label">Permission Label</label>
            <input type="text" class="form-control form-control-sm" name="permission"
                   value="${config.permission || ''}" placeholder="e.g. Can Approve Warrant Rosters"
                   data-action="change->workflow-designer#updateNodeConfig">
          </div>
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
