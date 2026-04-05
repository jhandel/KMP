/**
 * WorkflowNodeConfigHandler
 *
 * Manages node configuration panel interactions including form handling,
 * approver type changes, value pickers, and config panel resize.
 * Instantiated by the main WorkflowDesignerController.
 */
export default class WorkflowNodeConfigHandler {
    constructor(designer) {
        this.designer = designer
        this._boundResizeMove = null
        this._boundResizeEnd = null
    }

    get editor() { return this.designer.editor }
    get configPanel() { return this.designer._configPanel }
    get variablePicker() { return this.designer._variablePicker }

    get nodeConfigTarget() { return this.designer.nodeConfigTarget }
    get hasNodeConfigTarget() { return this.designer.hasNodeConfigTarget }

    // --- Node Selection ---

    onNodeSelected(nodeId) {
        this.designer._selectedNodes.add(nodeId)
        this._highlightSelectedNodes()
        const nodeData = this.editor.getNodeFromId(nodeId)
        if (this.hasNodeConfigTarget && this.configPanel) {
            this.nodeConfigTarget.innerHTML = this._resizeHandleHTML + this.configPanel.renderConfigHTML(nodeId, nodeData)
            this.variablePicker?.attachPickers(this.nodeConfigTarget, nodeId, this.editor)
            const nodeConfig = nodeData.data?.config || {}
            if (nodeConfig.approverType === 'policy' && nodeConfig.policyClass) {
                this._loadPolicyActions(nodeConfig.policyClass, nodeConfig.policyAction)
            }
            this.nodeConfigTarget.querySelectorAll('[data-vp-settings-select]').forEach(selectEl => {
                const currentVal = selectEl.value
                if (currentVal) {
                    this._loadAppSettingsForPicker(selectEl, currentVal)
                }
            })
        }
    }

    onNodeUnselected() {
        if (!this.designer._shiftHeld) {
            this.designer.clearMultiSelect()
        }
        if (this.hasNodeConfigTarget && this.configPanel) {
            this.nodeConfigTarget.innerHTML = this._resizeHandleHTML + this.configPanel.renderEmptyHTML()
        }
    }

    _highlightSelectedNodes() {
        this.designer.canvasTarget.querySelectorAll('.drawflow-node').forEach(el => {
            el.classList.remove('wf-multi-selected')
        })
        this.designer._selectedNodes.forEach(id => {
            const el = this.designer.canvasTarget.querySelector(`#node-${id}`)
            if (el) el.classList.add('wf-multi-selected')
        })
    }

    // --- Config Form Handlers ---

    onApproverTypeChange(event) {
        this.updateNodeConfig(event)
        const form = event.target.closest('form')
        const selectedType = event.target.value
        form.querySelectorAll('[data-approver-section]').forEach(section => {
            section.style.display = section.dataset.approverSection === selectedType ? 'block' : 'none'
        })
    }

    onSerialPickNextChange(event) {
        const form = event.target.closest('form')
        const checked = event.target.checked
        const allowParallel = form.querySelector('[name="allowParallel"]')
        if (checked && allowParallel) {
            allowParallel.checked = false
            allowParallel.disabled = true
        } else if (allowParallel) {
            allowParallel.disabled = false
        }
        this.updateNodeConfig(event)
    }

    onResolverChange(event) {
        const form = event.target.closest('form')
        const nodeId = form.dataset.nodeId
        const resolverKey = event.target.value
        const nodeData = this.editor.getNodeFromId(nodeId)

        if (!nodeData.data) nodeData.data = {}
        if (!nodeData.data.config) nodeData.data.config = {}

        if (resolverKey) {
            const resolver = this.configPanel.resolvers.find(r => r.resolver === resolverKey)
            nodeData.data.config.approverConfig = {
                service: resolverKey,
                method: resolver?.method || '',
            }
            if (resolver?.configSchema) {
                for (const key of Object.keys(resolver.configSchema)) {
                    nodeData.data.config.approverConfig[key] = nodeData.data.config.approverConfig[key] || ''
                }
            }
        } else {
            nodeData.data.config.approverConfig = {}
        }

        this.editor.updateNodeDataFromId(nodeId, nodeData.data)

        if (this.hasNodeConfigTarget && this.configPanel) {
            const updatedNode = this.editor.getNodeFromId(nodeId)
            this.nodeConfigTarget.innerHTML = this._resizeHandleHTML + this.configPanel.renderConfigHTML(nodeId, updatedNode)
            if (this.variablePicker) {
                this.variablePicker.attachPickers(this.nodeConfigTarget, nodeId, this.editor)
            }
        }
    }

    onValuePickerTypeChange(event) {
        const select = event.target
        const fieldName = select.dataset.vpType
        const selectedType = select.value
        const form = select.closest('form')
        const nodeId = form?.dataset.nodeId
        const inputGroup = select.closest('.input-group')

        const existingInput = inputGroup.querySelector(`[name="${fieldName}"]`)
        const isNumber = existingInput?.type === 'number'
        const dataType = isNumber ? 'integer' : 'string'

        const oldInputs = inputGroup.querySelectorAll(`[name="${fieldName}"]`)
        oldInputs.forEach(el => {
            if (el.type === 'checkbox') {
                el.closest('.form-check')?.remove()
            } else {
                el.remove()
            }
        })

        let newInputHTML = ''
        if (selectedType === 'context') {
            newInputHTML = `<input type="text" class="form-control form-control-sm"
                name="${fieldName}" value="" placeholder="$.path.to.value"
                data-action="change->workflow-designer#updateNodeConfig"
                data-variable-picker="true">`
        } else if (selectedType === 'app_setting') {
            newInputHTML = `<select class="form-select form-select-sm" name="${fieldName}"
                data-action="change->workflow-designer#updateNodeConfig"
                data-vp-settings-select="${fieldName}">
                <option value="">Loading settings...</option>
            </select>`
        } else {
            if (dataType === 'integer') {
                newInputHTML = `<input type="number" class="form-control form-control-sm"
                    name="${fieldName}" value=""
                    data-action="change->workflow-designer#updateNodeConfig">`
            } else {
                newInputHTML = `<input type="text" class="form-control form-control-sm"
                    name="${fieldName}" value=""
                    data-action="change->workflow-designer#updateNodeConfig">`
            }
        }

        inputGroup.insertAdjacentHTML('beforeend', newInputHTML)

        if (selectedType === 'app_setting') {
            this._loadAppSettingsForPicker(inputGroup.querySelector(`[data-vp-settings-select="${fieldName}"]`))
        }

        if (selectedType === 'context' && this.variablePicker && nodeId) {
            this.variablePicker.attachPickers(this.nodeConfigTarget, nodeId, this.editor)
        }

        this.updateNodeConfig(event)
    }

    async onPolicyClassChange(event) {
        this.updateNodeConfig(event)
        const form = event.target.closest('form')
        const policyClass = event.target.value
        const actionSelect = form.querySelector('[name="policyAction"]')

        if (!policyClass) {
            actionSelect.innerHTML = '<option value="">Select a policy class first...</option>'
            return
        }

        try {
            const response = await fetch('/workflows/policy-actions?class=' + encodeURIComponent(policyClass))
            const data = await response.json()
            let options = '<option value="">Select an action...</option>'
            data.policyActions.forEach(a => {
                options += `<option value="${a.action}">${a.label}</option>`
            })
            actionSelect.innerHTML = options
        } catch (error) {
            console.error('Failed to load policy actions:', error)
            actionSelect.innerHTML = '<option value="">Error loading actions</option>'
        }
    }

    async _loadPolicyActions(policyClass, selectedAction) {
        try {
            const response = await fetch('/workflows/policy-actions?class=' + encodeURIComponent(policyClass))
            const data = await response.json()
            const actionSelect = this.nodeConfigTarget.querySelector('[name="policyAction"]')
            if (actionSelect) {
                let options = '<option value="">Select an action...</option>'
                data.policyActions.forEach(a => {
                    const selected = a.action === selectedAction ? 'selected' : ''
                    options += `<option value="${a.action}" ${selected}>${a.label}</option>`
                })
                actionSelect.innerHTML = options
            }
        } catch (error) {
            console.error('Failed to load policy actions:', error)
        }
    }

    async _loadAppSettingsForPicker(selectEl, selectedKey) {
        if (!selectEl) return

        try {
            const response = await fetch('/workflows/app-settings', {
                headers: { 'Accept': 'application/json' }
            })
            if (!response.ok) throw new Error(`HTTP ${response.status}`)
            const data = await response.json()
            let options = '<option value="">Select a setting...</option>'
            const items = Array.isArray(data) ? data : (data.appSettings || data.settings || [])
            items.forEach(s => {
                const key = s.name || s.value || ''
                const label = s.name || key
                const selected = key === selectedKey ? 'selected' : ''
                options += `<option value="${key}" ${selected}>${label}</option>`
            })
            selectEl.innerHTML = options
        } catch (error) {
            console.error('Failed to load app settings:', error)
            if (!selectedKey) {
                selectEl.innerHTML = '<option value="">Settings unavailable</option>'
            } else {
                selectEl.innerHTML =
                    `<option value="">Settings unavailable</option>` +
                    `<option value="${selectedKey}" selected>${selectedKey}</option>`
            }
        }
    }

    updateNodeConfig(event) {
        const form = event.target.closest('form')
        const nodeId = form.dataset.nodeId
        const formData = new FormData(form)

        const nodeData = this.editor.getNodeFromId(nodeId)
        if (!nodeData.data) nodeData.data = {}
        if (!nodeData.data.config) nodeData.data.config = {}

        const newParams = {}
        let hasParams = false
        const newInputMapping = {}
        let hasInputMapping = false
        const newApproverConfig = {}
        let hasApproverConfig = false

        const vpTypeSelects = form.querySelectorAll('[data-vp-type]')
        const vpFields = new Set()
        vpTypeSelects.forEach(select => vpFields.add(select.dataset.vpType))

        for (const [key, value] of formData.entries()) {
            if (vpFields.has(key)) continue
            if (key.startsWith('params.') && vpFields.has(key)) continue
            if (key.startsWith('approverConfig.') && vpFields.has(key)) continue

            if (key.startsWith('params.')) {
                const paramKey = key.substring(7)
                newParams[paramKey] = value
                hasParams = true
            } else if (key.startsWith('approverConfig.')) {
                const acKey = key.substring(15)
                newApproverConfig[acKey] = value
                hasApproverConfig = true
            } else if (key.startsWith('inputMapping.')) {
                const mapKey = key.substring(13)
                newInputMapping[mapKey] = value
                hasInputMapping = true
            } else {
                nodeData.data.config[key] = value
            }
        }

        vpTypeSelects.forEach(select => {
            const fieldName = select.dataset.vpType
            const selectedType = select.value
            const container = select.closest('.value-picker')
            const input = container.querySelector(`[name="${fieldName}"]`)
            const rawValue = input?.value ?? ''

            let composedValue
            if (selectedType === 'fixed') {
                const isNumber = input?.type === 'number'
                composedValue = isNumber ? (rawValue === '' ? '' : Number(rawValue)) : rawValue
            } else if (selectedType === 'context') {
                composedValue = rawValue ? { type: 'context', path: rawValue } : ''
            } else if (selectedType === 'app_setting') {
                composedValue = rawValue ? { type: 'app_setting', key: rawValue } : ''
            }

            if (fieldName.startsWith('params.')) {
                const paramKey = fieldName.substring(7)
                newParams[paramKey] = composedValue
                hasParams = true
            } else if (fieldName.startsWith('approverConfig.')) {
                const acKey = fieldName.substring(15)
                newApproverConfig[acKey] = composedValue
                hasApproverConfig = true
            } else {
                nodeData.data.config[fieldName] = composedValue
            }
        })

        if (hasParams) {
            nodeData.data.config.params = newParams
        }
        if (hasInputMapping) {
            nodeData.data.config.inputMapping = newInputMapping
        }
        if (hasApproverConfig) {
            nodeData.data.config.approverConfig = newApproverConfig
        }

        // Extract key-value editor fields (e.g., vars for Core.SendEmail)
        this._extractKvFields(form, nodeData)

        nodeData.data.config.allowParallel = form.querySelector('[name="allowParallel"]')?.checked ?? true
        nodeData.data.config.serialPickNext = form.querySelector('[name="serialPickNext"]')?.checked ?? false

        this.editor.updateNodeDataFromId(nodeId, nodeData.data)

        const changedField = event.target?.name
        if (changedField === 'action' || changedField === 'condition' || changedField === 'event') {
            const updatedNode = this.editor.getNodeFromId(nodeId)
            if (this.hasNodeConfigTarget && this.configPanel) {
                this.nodeConfigTarget.innerHTML = this._resizeHandleHTML + this.configPanel.renderConfigHTML(nodeId, updatedNode)
                if (this.variablePicker) {
                    this.variablePicker.attachPickers(this.nodeConfigTarget, nodeId, this.editor)
                }
            }
        } else if (this.variablePicker && this.hasNodeConfigTarget) {
            this.variablePicker.attachPickers(this.nodeConfigTarget, nodeId, this.editor)
        }
    }

    // --- Config Panel Resize ---

    get _resizeHandleHTML() {
        return '<div class="config-panel-resize-handle" data-action="mousedown->workflow-designer#onResizeStart"></div>'
    }

    restoreConfigPanelWidth() {
        const saved = localStorage.getItem('wf-config-panel-width')
        if (saved && this.hasNodeConfigTarget) {
            const width = parseInt(saved, 10)
            if (width >= 300 && width <= window.innerWidth * 0.6) {
                this.nodeConfigTarget.style.width = width + 'px'
                this.nodeConfigTarget.style.minWidth = width + 'px'
            }
        }
    }

    onResizeStart(event) {
        event.preventDefault()
        this._resizeStartX = event.clientX
        this._resizeStartWidth = this.nodeConfigTarget.getBoundingClientRect().width
        const handle = event.currentTarget
        handle.classList.add('dragging')

        this._boundResizeMove = this._onResizeMove.bind(this)
        this._boundResizeEnd = this._onResizeEnd.bind(this, handle)
        document.addEventListener('mousemove', this._boundResizeMove)
        document.addEventListener('mouseup', this._boundResizeEnd)
    }

    _onResizeMove(event) {
        const delta = this._resizeStartX - event.clientX
        const maxWidth = window.innerWidth * 0.6
        let newWidth = Math.max(300, Math.min(maxWidth, this._resizeStartWidth + delta))
        this.nodeConfigTarget.style.width = newWidth + 'px'
        this.nodeConfigTarget.style.minWidth = newWidth + 'px'
    }

    _onResizeEnd(handle) {
        handle.classList.remove('dragging')
        document.removeEventListener('mousemove', this._boundResizeMove)
        document.removeEventListener('mouseup', this._boundResizeEnd)
        const currentWidth = Math.round(this.nodeConfigTarget.getBoundingClientRect().width)
        localStorage.setItem('wf-config-panel-width', currentWidth)
    }

    // --- Key-Value Editor methods ---

    addKvRow(event) {
        const btn = event.target.closest('[data-kv-target]')
        const fieldName = btn.dataset.kvTarget
        const container = btn.closest('.kv-editor').querySelector(`[data-kv-rows="${fieldName}"]`)
        const existingRows = container.querySelectorAll('.kv-row')
        const nextIdx = existingRows.length

        const rowHTML = this.configPanel._renderKvRow(fieldName, nextIdx, '', '')
        container.insertAdjacentHTML('beforeend', rowHTML)
    }

    removeKvRow(event) {
        const row = event.target.closest('.kv-row')
        const form = row.closest('form')
        row.remove()
        // Re-trigger config save after removal
        this._saveKvFieldsFromForm(form)
    }

    onKvValueTypeChange(event) {
        const select = event.target
        const selectedType = select.value
        const row = select.closest('.kv-row')
        const valInput = row.querySelector('[name*="__val__"]')
        if (valInput) {
            valInput.placeholder = selectedType === 'context' ? '$.path.to.value' : 'value'
            valInput.value = ''
        }
        // Trigger config update
        const form = select.closest('form')
        if (form) this._saveKvFieldsFromForm(form)
    }

    _saveKvFieldsFromForm(form) {
        const nodeId = form.dataset.nodeId
        const nodeData = this.editor.getNodeFromId(nodeId)
        if (!nodeData?.data?.config) return

        this._extractKvFields(form, nodeData)
        this.editor.updateNodeDataFromId(nodeId, nodeData.data)
    }

    _extractKvFields(form, nodeData) {
        const kvEditors = form.querySelectorAll('.kv-editor')
        kvEditors.forEach(editor => {
            const fieldName = editor.querySelector('[data-kv-rows]')?.dataset.kvRows
            if (!fieldName) return

            const obj = {}
            const rows = editor.querySelectorAll('.kv-row')
            rows.forEach(row => {
                const keyInput = row.querySelector('[name*="__key__"]')
                const valInput = row.querySelector('[name*="__val__"]')
                const typeSelect = row.querySelector('[data-kv-vtype]')
                const key = keyInput?.value?.trim()
                if (!key) return

                const rawVal = valInput?.value ?? ''
                const valType = typeSelect?.value ?? 'fixed'

                if (valType === 'context') {
                    obj[key] = rawVal.startsWith('$.') ? rawVal : `$.${rawVal}`
                } else if (valType === 'app_setting') {
                    obj[key] = rawVal ? { type: 'app_setting', key: rawVal } : ''
                } else {
                    obj[key] = rawVal
                }
            })

            // Write into the correct nested location (e.g., params.vars)
            if (fieldName.startsWith('params.')) {
                const paramKey = fieldName.substring(7)
                if (!nodeData.data.config.params) nodeData.data.config.params = {}
                nodeData.data.config.params[paramKey] = obj
            } else {
                nodeData.data.config[fieldName] = obj
            }
        })
    }

    disconnect() {
        if (this._boundResizeMove) {
            document.removeEventListener('mousemove', this._boundResizeMove)
        }
        if (this._boundResizeEnd) {
            document.removeEventListener('mouseup', this._boundResizeEnd)
        }
    }
}
