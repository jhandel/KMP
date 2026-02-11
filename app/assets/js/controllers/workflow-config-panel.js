import { renderAutoComplete } from '../autocomplete-helper.js'

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
            case 'subworkflow': return this._subworkflowHTML(config)
            case 'fork': return this._forkHTML(config)
            case 'join': return this._joinHTML(config)
            case 'end': return this._endHTML(config)
            default: return ''
        }
    }

    _triggerHTML(config) {
        let options = '<option value="">Select a trigger...</option>'
        this.registryData.triggers?.forEach(t => {
            const selected = config.event === t.event ? 'selected' : ''
            options += `<option value="${t.event}" ${selected}>${t.label}</option>`
        })
        let html = `<div class="mb-3">
            <label class="form-label">Trigger Event</label>
            <select class="form-select form-select-sm" name="event" data-action="change->workflow-designer#updateNodeConfig">${options}</select>
        </div>`

        if (config.event) {
            const trigger = this.registryData.triggers?.find(t => t.event === config.event)
            if (trigger?.payloadSchema) {
                html += '<h6 class="mt-3 mb-2 text-muted small">Input Mapping</h6>'
                html += '<small class="form-text text-muted d-block mb-2">Map trigger event data to context variables</small>'
                const mapping = config.inputMapping || {}
                for (const [key, meta] of Object.entries(trigger.payloadSchema)) {
                    const currentVal = mapping[key] || `$.event.${key}`
                    const escapedVal = this._escapeAttr(currentVal)
                    html += `<div class="mb-2">
                        <label class="form-label form-label-sm mb-0">
                            ${meta.label || key} <span class="text-muted small">(${meta.type})</span>
                        </label>
                        <input type="text" class="form-control form-control-sm"
                            name="inputMapping.${key}" value="${escapedVal}"
                            placeholder="$.event.${key}"
                            data-action="change->workflow-designer#updateNodeConfig"
                            data-variable-picker="true">
                    </div>`
                }
            }
        }

        return html
    }

    _actionHTML(config) {
        let options = '<option value="">Select an action...</option>'
        this.registryData.actions?.forEach(a => {
            const selected = config.action === a.action ? 'selected' : ''
            options += `<option value="${a.action}" ${selected}>${a.label}</option>`
        })

        let html = `<div class="mb-3">
            <label class="form-label">Action</label>
            <select class="form-select form-select-sm" name="action"
                data-action="change->workflow-designer#updateNodeConfig">${options}</select>
        </div>`

        if (config.action) {
            const action = this.registryData.actions?.find(a => a.action === config.action)
            if (action?.inputSchema) {
                html += '<h6 class="mt-3 mb-2 text-muted small">Input Parameters</h6>'
                const params = config.params || {}
                for (const [key, meta] of Object.entries(action.inputSchema)) {
                    const currentVal = params[key] || ''
                    const escapedVal = this._escapeAttr(currentVal)
                    const required = meta.required ? '<span class="text-danger">*</span>' : ''
                    const typeHint = `<span class="text-muted small">(${meta.type})</span>`
                    const desc = meta.description ? `<small class="form-text text-muted">${meta.description}</small>` : ''
                    html += `<div class="mb-2">
                        <label class="form-label form-label-sm mb-0">
                            ${meta.label || key} ${required} ${typeHint}
                        </label>
                        <input type="text" class="form-control form-control-sm"
                            name="params.${key}" value="${escapedVal}"
                            placeholder="${meta.description || '$.path or literal value'}"
                            data-action="change->workflow-designer#updateNodeConfig"
                            data-variable-picker="true">
                        ${desc}
                    </div>`
                }
            }
        }

        return html
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
        const isCore = !config.condition || config.condition.startsWith('Core.')
        let html = `<div class="mb-3">
            <label class="form-label">Condition</label>
            <select class="form-select form-select-sm" name="condition" data-action="change->workflow-designer#updateNodeConfig">${options}</select>
        </div>`

        if (isCore) {
            html += `<div class="mb-3">
                <label class="form-label">Field Path</label>
                <input type="text" class="form-control form-control-sm" name="field" value="${config.field || ''}" placeholder="$.entity.field_name" data-action="change->workflow-designer#updateNodeConfig" data-variable-picker="true">
            </div>
            <div class="mb-3">
                <label class="form-label">Expected Value</label>
                <input type="text" class="form-control form-control-sm" name="expectedValue" value="${config.expectedValue || ''}" data-action="change->workflow-designer#updateNodeConfig" data-variable-picker="true">
            </div>`
        }

        if (config.condition && !config.condition.startsWith('Core.')) {
            const cond = this.registryData.conditions?.find(c => c.condition === config.condition)
            if (cond?.inputSchema) {
                html += '<h6 class="mt-3 mb-2 text-muted small">Condition Parameters</h6>'
                const params = config.params || {}
                for (const [key, meta] of Object.entries(cond.inputSchema)) {
                    const currentVal = params[key] || config[key] || ''
                    const escapedVal = this._escapeAttr(currentVal)
                    const required = meta.required ? '<span class="text-danger">*</span>' : ''
                    html += `<div class="mb-2">
                        <label class="form-label form-label-sm mb-0">
                            ${meta.label || key} ${required} <span class="text-muted small">(${meta.type})</span>
                        </label>
                        <input type="text" class="form-control form-control-sm"
                            name="params.${key}" value="${escapedVal}"
                            placeholder="$.path or literal value"
                            data-action="change->workflow-designer#updateNodeConfig"
                            data-variable-picker="true">
                    </div>`
                }
            }
        }

        return html
    }

    _approvalHTML(config) {
        // Build initSelection for whichever approver type is active
        const acInitSelection = (type) =>
            config.approverType === type && config.approverValue
                ? { value: config.approverValue, text: config.approverValue } : null;

        const acSharedOpts = {
            size: 'sm',
            name: 'approverValue',
            minLength: 2,
            hiddenAttrs: 'data-action="change->workflow-designer#updateNodeConfig"',
        };

        const permissionAC = renderAutoComplete({
            ...acSharedOpts,
            url: '/permissions/auto-complete',
            allowOther: true,
            value: config.approverType === 'permission' ? (config.approverValue || '') : '',
            placeholder: 'Search permissions...',
            initSelection: acInitSelection('permission'),
        });

        const roleAC = renderAutoComplete({
            ...acSharedOpts,
            url: '/roles/auto-complete',
            allowOther: true,
            value: config.approverType === 'role' ? (config.approverValue || '') : '',
            placeholder: 'Search roles...',
            initSelection: acInitSelection('role'),
        });

        const memberAC = renderAutoComplete({
            ...acSharedOpts,
            url: '/members/auto-complete',
            allowOther: false,
            value: config.approverType === 'member' ? (config.approverValue || '') : '',
            placeholder: 'Search members...',
            initSelection: acInitSelection('member'),
        });

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
            ${permissionAC}
          </div>
        </div>
        <div data-approver-section="role" style="display:${config.approverType === 'role' ? 'block' : 'none'};">
          <div class="mb-3">
            <label class="form-label">Role</label>
            ${roleAC}
          </div>
        </div>
        <div data-approver-section="member" style="display:${config.approverType === 'member' ? 'block' : 'none'};">
          <div class="mb-3">
            <label class="form-label">Member</label>
            ${memberAC}
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
        ${this._requiredCountHTML(config)}
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
            <input type="text" class="form-control form-control-sm" name="duration"
                value="${config.duration || ''}" placeholder="e.g. 1h, 2d, 30m, or $.path"
                data-action="change->workflow-designer#updateNodeConfig" data-variable-picker="true">
        </div>
        <div class="mb-3">
            <label class="form-label">Wait For Event (optional)</label>
            <input type="text" class="form-control form-control-sm" name="waitEvent"
                value="${config.waitEvent || ''}" placeholder="Event to resume on"
                data-action="change->workflow-designer#updateNodeConfig" data-variable-picker="true">
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

    _subworkflowHTML(config) {
        return `<div class="mb-3">
            <label class="form-label">Workflow Slug</label>
            <input type="text" class="form-control form-control-sm" name="workflowSlug"
                value="${config.workflowSlug || ''}" placeholder="e.g. warrant-approval"
                data-action="change->workflow-designer#updateNodeConfig">
            <small class="form-text text-muted">The slug of the child workflow to execute</small>
        </div>`
    }

    _forkHTML(config) {
        return `<div class="mb-3">
            <small class="form-text text-muted">
                <i class="bi bi-info-circle me-1"></i>
                Executes all connected output paths in parallel. No additional configuration needed.
            </small>
        </div>`
    }

    _joinHTML(config) {
        return `<div class="mb-3">
            <small class="form-text text-muted">
                <i class="bi bi-info-circle me-1"></i>
                Waits for all incoming paths to complete before advancing.
            </small>
        </div>`
    }

    _endHTML(config) {
        let options = ''
        const statuses = ['completed', 'cancelled', 'failed']
        statuses.forEach(s => {
            const selected = config.status === s ? 'selected' : ''
            options += `<option value="${s}" ${selected}>${s.charAt(0).toUpperCase() + s.slice(1)}</option>`
        })
        return `<div class="mb-3">
            <label class="form-label">End Status</label>
            <select class="form-select form-select-sm" name="status"
                data-action="change->workflow-designer#updateNodeConfig">
                ${options}
            </select>
        </div>`
    }

    _requiredCountHTML(config) {
        const rc = config.requiredCount
        let rcType = 'fixed'
        let rcFixedVal = 1
        let rcSettingKey = ''
        let rcContextPath = ''

        if (typeof rc === 'object' && rc !== null) {
            rcType = rc.type || 'fixed'
            if (rcType === 'app_setting') rcSettingKey = rc.key || ''
            else if (rcType === 'context') rcContextPath = rc.path || ''
            else if (rcType === 'fixed') rcFixedVal = rc.value || 1
        } else if (rc !== undefined && rc !== null && rc !== '') {
            rcFixedVal = parseInt(rc, 10) || 1
        }

        return `<div class="mb-3">
            <label class="form-label">Required Approvals</label>
            <select class="form-select form-select-sm mb-2" name="requiredCountType"
                data-action="change->workflow-designer#onRequiredCountTypeChange">
                <option value="fixed" ${rcType === 'fixed' ? 'selected' : ''}>Fixed Value</option>
                <option value="app_setting" ${rcType === 'app_setting' ? 'selected' : ''}>App Setting</option>
                <option value="context" ${rcType === 'context' ? 'selected' : ''}>Context Path</option>
            </select>
            <div data-rc-section="fixed" style="display:${rcType === 'fixed' ? 'block' : 'none'};">
                <input type="number" class="form-control form-control-sm" name="requiredCountFixedValue"
                    value="${rcFixedVal}" min="1"
                    data-action="change->workflow-designer#updateNodeConfig">
            </div>
            <div data-rc-section="app_setting" style="display:${rcType === 'app_setting' ? 'block' : 'none'};">
                <select class="form-select form-select-sm" name="requiredCountSettingKey"
                    data-action="change->workflow-designer#updateNodeConfig"
                    data-rc-settings-select="true">
                    <option value="">Loading settings...</option>
                    ${rcSettingKey ? `<option value="${this._escapeAttr(rcSettingKey)}" selected>${this._escapeAttr(rcSettingKey)}</option>` : ''}
                </select>
            </div>
            <div data-rc-section="context" style="display:${rcType === 'context' ? 'block' : 'none'};">
                <input type="text" class="form-control form-control-sm" name="requiredCountContextPath"
                    value="${this._escapeAttr(rcContextPath)}" placeholder="$.someField"
                    data-action="change->workflow-designer#updateNodeConfig"
                    data-variable-picker="true">
            </div>
        </div>`
    }

    _escapeAttr(str) {
        if (!str) return ''
        return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    }
}
