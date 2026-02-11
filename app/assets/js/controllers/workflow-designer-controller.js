import { Controller } from "@hotwired/stimulus"
import Drawflow from 'drawflow'
import WorkflowValidationService from './workflow-validation-service.js'
import WorkflowVariablePicker from './workflow-variable-picker.js'
import WorkflowConfigPanel from './workflow-config-panel.js'
import WorkflowHistoryManager from './workflow-history-manager.js'

class WorkflowDesignerController extends Controller {
    static targets = [
        "canvas", "nodeConfig", "nodePalette", "saveBtn", "publishBtn",
        "versionInfo", "zoomLevel", "validationResults",
        "loadingOverlay", "unsavedIndicator"
    ]

    static values = {
        saveUrl: String,
        loadUrl: String,
        publishUrl: String,
        registryUrl: String,
        workflowId: Number,
        versionId: Number,
        csrfToken: String,
        readonly: { type: Boolean, default: false },
        maxHistory: { type: Number, default: 50 }
    }

    editor = null

    registryData = {
        triggers: [],
        actions: [],
        conditions: [],
        entities: []
    }

    _selectedNodes = new Set()
    _zoom = 1
    _isDirty = false
    _lastSavedSnapshot = null

    async connect() {
        this._showLoading(true)
        try {
            this.initEditor()
            await this.loadRegistry()
            if (this.hasWorkflowIdValue && this.workflowIdValue) {
                await this.loadWorkflow()
            }
            this._bindKeyboardShortcuts()
            this._lastSavedSnapshot = JSON.stringify(this.editor.export())
        } catch (error) {
            this._showError('Failed to initialize the workflow designer.')
            console.error('Designer init error:', error)
        } finally {
            this._showLoading(false)
        }
    }

    // --- Loading & Error UX ---

    _showLoading(show) {
        if (this.hasLoadingOverlayTarget) {
            this.loadingOverlayTarget.style.display = show ? 'flex' : 'none'
        }
    }

    _showError(message) {
        if (this.hasCanvasTarget) {
            const overlay = this.canvasTarget.querySelector('.wf-error-overlay')
            if (overlay) {
                overlay.querySelector('.wf-error-message').textContent = message
                overlay.style.display = 'flex'
            }
        }
    }

    // --- Editor Init ---

    initEditor() {
        this.editor = new Drawflow(this.canvasTarget)
        this.editor.reroute = true
        this.editor.reroute_fix_curvature = true
        this.editor.force_first_input = false

        if (this.readonlyValue) {
            this.editor.editor_mode = 'view'
        }

        this.editor.start()
        this.registerNodeTemplates()

        this._historyManager = new WorkflowHistoryManager(this.maxHistoryValue)

        this.editor.on('nodeSelected', (nodeId) => this.onNodeSelected(nodeId))
        this.editor.on('nodeUnselected', () => this.onNodeUnselected())
        this.editor.on('connectionCreated', (connection) => this.onConnectionCreated(connection))
        this.editor.on('nodeRemoved', (nodeId) => this.onNodeRemoved(nodeId))

        this.editor.on('nodeCreated', () => this._onGraphChange())
        this.editor.on('nodeRemoved', () => this._onGraphChange())
        this.editor.on('connectionCreated', () => this._onGraphChange())
        this.editor.on('connectionRemoved', () => this._onGraphChange())
        this.editor.on('nodeMoved', () => this._onGraphChange())

        this._historyManager.push(this.editor)
    }

    _onGraphChange() {
        this._historyManager.push(this.editor)
        this._updateDirtyState()
    }

    _updateDirtyState() {
        const current = JSON.stringify(this.editor.export())
        this._isDirty = current !== this._lastSavedSnapshot
        if (this.hasUnsavedIndicatorTarget) {
            this.unsavedIndicatorTarget.style.display = this._isDirty ? 'inline' : 'none'
        }
    }

    registerNodeTemplates() {
        // Define HTML templates for each node type
    }

    // --- Registry & Workflow Loading ---

    async loadRegistry() {
        if (!this.hasRegistryUrlValue) return
        try {
            const response = await fetch(this.registryUrlValue, {
                headers: { 'Accept': 'application/json' }
            })
            if (response.ok) {
                this.registryData = await response.json()
                // Fetch policy classes for the designer
                let policyClasses = []
                try {
                    const policyResponse = await fetch('/workflows/policy-classes')
                    if (policyResponse.ok) {
                        const policyData = await policyResponse.json()
                        policyClasses = policyData.policyClasses || []
                    }
                } catch (e) {
                    console.warn('Could not load policy classes:', e)
                }
                this._configPanel = new WorkflowConfigPanel(this.registryData, policyClasses)
                this._variablePicker = new WorkflowVariablePicker(this.registryData)
                this._validationService = new WorkflowValidationService(
                    (type) => this.getNodePorts(type),
                    this.registryData
                )
                this.populateNodePalette()
            } else {
                throw new Error(`Registry returned ${response.status}`)
            }
        } catch (error) {
            console.error('Failed to load workflow registry:', error)
            this._showError('Failed to load the node registry. Please reload the page.')
            throw error
        }
    }

    async loadWorkflow() {
        if (!this.hasLoadUrlValue) return
        try {
            const response = await fetch(this.loadUrlValue, {
                headers: { 'Accept': 'application/json' }
            })
            if (response.ok) {
                const data = await response.json()
                if (data.definition) {
                    this.importWorkflow(data.definition, data.canvasLayout)
                }
            } else {
                throw new Error(`Load returned ${response.status}`)
            }
        } catch (error) {
            console.error('Failed to load workflow:', error)
            this._showError('Failed to load workflow data. Please reload the page.')
            throw error
        }
    }

    // --- Palette ---

    populateNodePalette() {
        if (!this.hasNodePaletteTarget) return
        let html = this.buildPaletteHTML()
        this.nodePaletteTarget.innerHTML = html
        this.initDragFromPalette()
    }

    buildPaletteHTML() {
        let html = ''

        const makeIcon = (faClass) =>
            `<span class="palette-node-icon"><i class="fa-solid ${faClass}"></i></span>`

        const groupBySource = (items) => {
            const groups = {}
            items.forEach(item => {
                const src = item.source || 'Other'
                if (!groups[src]) groups[src] = []
                groups[src].push(item)
            })
            return groups
        }

        html += '<div class="palette-category"><h6 class="palette-category-title">Flow Control</h6>'
        const flowNodes = [
            { type: 'condition', label: 'Condition', icon: 'fa-diamond' },
            { type: 'fork', label: 'Parallel Fork', icon: 'fa-code-branch' },
            { type: 'join', label: 'Parallel Join', icon: 'fa-code-merge' },
            { type: 'loop', label: 'Loop', icon: 'fa-rotate' },
            { type: 'delay', label: 'Delay / Wait', icon: 'fa-clock' },
            { type: 'end', label: 'End', icon: 'fa-stop' },
        ]
        flowNodes.forEach(node => {
            html += `<div class="palette-node" draggable="true" data-node-type="${node.type}" data-action="dragstart->workflow-designer#onPaletteDragStart" role="button" aria-label="${node.label}">${makeIcon(node.icon)} ${node.label}</div>`
        })
        html += '</div>'

        html += '<div class="palette-category"><h6 class="palette-category-title">Approvals</h6>'
        html += `<div class="palette-node" draggable="true" data-node-type="approval" data-action="dragstart->workflow-designer#onPaletteDragStart" role="button" aria-label="Approval Gate">${makeIcon('fa-check-double')} Approval Gate</div>`
        html += '</div>'

        if (this.registryData.triggers && this.registryData.triggers.length > 0) {
            const groups = groupBySource(this.registryData.triggers)
            for (const [source, triggers] of Object.entries(groups)) {
                html += `<div class="palette-category"><h6 class="palette-category-title"><i class="fa-solid fa-bolt fa-xs me-1"></i>Triggers — ${source}</h6>`
                triggers.forEach(trigger => {
                    html += `<div class="palette-node" draggable="true" data-node-type="trigger" data-node-event="${trigger.event}" data-action="dragstart->workflow-designer#onPaletteDragStart" role="button" aria-label="${trigger.label}">${makeIcon('fa-bolt')} ${trigger.label}</div>`
                })
                html += '</div>'
            }
        }

        if (this.registryData.actions && this.registryData.actions.length > 0) {
            const groups = groupBySource(this.registryData.actions)
            for (const [source, actions] of Object.entries(groups)) {
                html += `<div class="palette-category"><h6 class="palette-category-title"><i class="fa-solid fa-gear fa-xs me-1"></i>Actions — ${source}</h6>`
                actions.forEach(action => {
                    html += `<div class="palette-node" draggable="true" data-node-type="action" data-node-action="${action.action}" data-action="dragstart->workflow-designer#onPaletteDragStart" role="button" aria-label="${action.label}">${makeIcon('fa-gear')} ${action.label}</div>`
                })
                html += '</div>'
            }
        }

        if (this.registryData.conditions && this.registryData.conditions.length > 0) {
            const groups = groupBySource(this.registryData.conditions)
            for (const [source, conditions] of Object.entries(groups)) {
                html += `<div class="palette-category"><h6 class="palette-category-title"><i class="fa-solid fa-diamond fa-xs me-1"></i>Conditions — ${source}</h6>`
                conditions.forEach(cond => {
                    html += `<div class="palette-node" draggable="true" data-node-type="condition" data-node-condition="${cond.condition}" data-action="dragstart->workflow-designer#onPaletteDragStart" role="button" aria-label="${cond.label}">${makeIcon('fa-diamond')} ${cond.label}</div>`
                })
                html += '</div>'
            }
        }

        return html
    }

    initDragFromPalette() {
        // Palette nodes use Stimulus data-action for dragstart; no extra JS needed.
    }

    // --- Drag & Drop ---

    onPaletteDragStart(event) {
        const el = event.currentTarget
        event.dataTransfer.setData('node-type', el.dataset.nodeType)
        event.dataTransfer.setData('node-event', el.dataset.nodeEvent || '')
        event.dataTransfer.setData('node-action', el.dataset.nodeAction || '')
        event.dataTransfer.effectAllowed = 'move'
    }

    onCanvasDrop(event) {
        event.preventDefault()
        const nodeType = event.dataTransfer.getData('node-type')
        if (!nodeType) return

        const canvasRect = this.canvasTarget.getBoundingClientRect()
        const zoom = this._zoom || 1
        const canvasX = this.editor.canvas_x || 0
        const canvasY = this.editor.canvas_y || 0
        const x = (event.clientX - canvasRect.left - canvasX) / zoom
        const y = (event.clientY - canvasRect.top - canvasY) / zoom

        this.addNode(nodeType, x, y, {
            event: event.dataTransfer.getData('node-event'),
            action: event.dataTransfer.getData('node-action')
        })
    }

    onCanvasDragOver(event) {
        event.preventDefault()
        event.dataTransfer.dropEffect = 'move'
    }

    // --- Node CRUD ---

    addNode(type, x, y, config = {}) {
        const nodeKey = `${type}-${Date.now()}`
        const { inputs, outputs } = this.getNodePorts(type)
        const html = this.buildNodeHTML(type, nodeKey, config)

        return this.editor.addNode(
            nodeKey, inputs, outputs,
            x, y, nodeKey,
            { type, config, nodeKey },
            html
        )
    }

    getNodePorts(type) {
        switch (type) {
            case 'trigger': return { inputs: 0, outputs: 1 }
            case 'action': return { inputs: 1, outputs: 1 }
            case 'condition': return { inputs: 1, outputs: 2 }
            case 'approval': return { inputs: 1, outputs: 2 }
            case 'fork': return { inputs: 1, outputs: 2 }
            case 'join': return { inputs: 2, outputs: 1 }
            case 'loop': return { inputs: 1, outputs: 2 }
            case 'delay': return { inputs: 1, outputs: 1 }
            case 'subworkflow': return { inputs: 1, outputs: 1 }
            case 'end': return { inputs: 1, outputs: 0 }
            default: return { inputs: 1, outputs: 1 }
        }
    }

    buildNodeHTML(type, nodeKey, config) {
        const icons = {
            trigger: 'fa-bolt', action: 'fa-gear', condition: 'fa-diamond',
            approval: 'fa-check-double', fork: 'fa-code-branch', join: 'fa-code-merge',
            loop: 'fa-rotate', delay: 'fa-clock', subworkflow: 'fa-sitemap', end: 'fa-stop'
        }
        const typeLabels = {
            trigger: 'Trigger', action: 'Action', condition: 'Condition',
            approval: 'Approval', fork: 'Parallel Fork', join: 'Parallel Join',
            loop: 'Loop', delay: 'Delay', subworkflow: 'Sub-workflow', end: 'End'
        }

        const icon = icons[type] || 'fa-circle'
        let label = typeLabels[type] || type

        if (config._nodeLabel) {
            label = config._nodeLabel
        } else if (config.event) {
            const trigger = this.registryData.triggers?.find(t => t.event === config.event)
            if (trigger) label = trigger.label
        }
        if (config.action) {
            const action = this.registryData.actions?.find(a => a.action === config.action)
            if (action) label = action.label
        }

        let description = ''
        if (config.event) description = config.event.split('.').pop()
        else if (config.action) description = config.action.split('.').pop()
        else if (config.condition) description = config.condition

        let portLabelsHtml = ''
        if (['condition', 'approval', 'loop'].includes(type)) {
            const labels = {
                condition: ['True', 'False'],
                approval: ['Approved', 'Rejected'],
                loop: ['Continue', 'Exit'],
            }
            const pair = labels[type]
            portLabelsHtml = `<div class="wf-port-labels">
                <span class="wf-port-label wf-port-label-yes">${pair[0]}</span>
                <span class="wf-port-label wf-port-label-no">${pair[1]}</span>
            </div>`
        }
        if (type === 'fork') {
            portLabelsHtml = `<div class="wf-port-labels">
                <span class="wf-port-label wf-port-label-yes">Path A</span>
                <span class="wf-port-label wf-port-label-yes">Path B</span>
            </div>`
        }

        return `<div class="wf-node wf-node-${type}">
            <div class="wf-node-header">
                <span class="wf-node-icon"><i class="fa-solid ${icon}"></i></span>
                <span class="wf-node-title" title="${label}">${label}</span>
            </div>
            <div class="wf-node-body">
                <span class="wf-node-type-label">${typeLabels[type] || type}</span>
                ${description ? `<div class="wf-node-description">${description}</div>` : ''}
            </div>
            ${portLabelsHtml}
        </div>`
    }

    // --- Node Selection ---

    onNodeSelected(nodeId) {
        this._selectedNodes.add(nodeId)
        this._highlightSelectedNodes()
        const nodeData = this.editor.getNodeFromId(nodeId)
        if (this.hasNodeConfigTarget && this._configPanel) {
            this.nodeConfigTarget.innerHTML = this._configPanel.renderConfigHTML(nodeId, nodeData)
            this._variablePicker?.attachPickers(this.nodeConfigTarget, nodeId, this.editor)
            // Pre-populate policy actions dropdown if policy class is set
            const nodeConfig = nodeData.data?.config || {}
            if (nodeConfig.approverType === 'policy' && nodeConfig.policyClass) {
                this._loadPolicyActions(nodeConfig.policyClass, nodeConfig.policyAction)
            }
        }
    }

    onNodeUnselected() {
        if (!this._shiftHeld) {
            this._clearMultiSelect()
        }
        if (this.hasNodeConfigTarget && this._configPanel) {
            this.nodeConfigTarget.innerHTML = this._configPanel.renderEmptyHTML()
        }
    }

    onCanvasClick(event) {
        if (!event.shiftKey) return
        const nodeEl = event.target.closest('.drawflow-node')
        if (!nodeEl) return
        const nodeId = nodeEl.id.replace('node-', '')
        if (this._selectedNodes.has(nodeId)) {
            this._selectedNodes.delete(nodeId)
        } else {
            this._selectedNodes.add(nodeId)
        }
        this._highlightSelectedNodes()
    }

    _highlightSelectedNodes() {
        this.canvasTarget.querySelectorAll('.drawflow-node').forEach(el => {
            el.classList.remove('wf-multi-selected')
        })
        this._selectedNodes.forEach(id => {
            const el = this.canvasTarget.querySelector(`#node-${id}`)
            if (el) el.classList.add('wf-multi-selected')
        })
    }

    _clearMultiSelect() {
        this._selectedNodes.clear()
        this.canvasTarget.querySelectorAll('.wf-multi-selected').forEach(el => {
            el.classList.remove('wf-multi-selected')
        })
    }

    onApproverTypeChange(event) {
        this.updateNodeConfig(event)
        const form = event.target.closest('form')
        const selectedType = event.target.value
        form.querySelectorAll('[data-approver-section]').forEach(section => {
            section.style.display = section.dataset.approverSection === selectedType ? 'block' : 'none'
        })
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

        for (const [key, value] of formData.entries()) {
            if (key.startsWith('params.')) {
                const paramKey = key.substring(7)
                newParams[paramKey] = value
                hasParams = true
            } else if (key.startsWith('inputMapping.')) {
                const mapKey = key.substring(13)
                newInputMapping[mapKey] = value
                hasInputMapping = true
            } else {
                nodeData.data.config[key] = value
            }
        }

        if (hasParams) {
            nodeData.data.config.params = newParams
        }
        if (hasInputMapping) {
            nodeData.data.config.inputMapping = newInputMapping
        }

        nodeData.data.config.allowParallel = form.querySelector('[name="allowParallel"]')?.checked ?? true
        this.editor.updateNodeDataFromId(nodeId, nodeData.data)

        // If action or condition changed, re-render config panel to show new inputSchema fields
        const changedField = event.target?.name
        if (changedField === 'action' || changedField === 'condition' || changedField === 'event') {
            const updatedNode = this.editor.getNodeFromId(nodeId)
            if (this.hasNodeConfigTarget && this._configPanel) {
                this.nodeConfigTarget.innerHTML = this._configPanel.renderConfigHTML(nodeId, updatedNode)
                if (this._variablePicker) {
                    this._variablePicker.attachPickers(this.nodeConfigTarget, nodeId, this.editor)
                }
            }
        } else if (this._variablePicker && this.hasNodeConfigTarget) {
            this._variablePicker.attachPickers(this.nodeConfigTarget, nodeId, this.editor)
        }
    }

    onConnectionCreated(connection) {
        // Validate connection rules
    }

    onNodeRemoved(nodeId) {
        if (this.hasNodeConfigTarget) {
            this.onNodeUnselected()
        }
    }

    // --- Export / Import ---

    exportWorkflow() {
        const drawflowData = this.editor.export()
        const nodes = {}
        const canvasLayout = {}
        const moduleData = drawflowData.drawflow?.Home?.data || {}

        for (const [drawflowId, node] of Object.entries(moduleData)) {
            const nodeKey = node.data?.nodeKey || node.name
            const type = node.data?.type || 'unknown'
            const config = node.data?.config || {}

            const outputs = []
            for (const [outputKey, outputData] of Object.entries(node.outputs || {})) {
                for (const conn of outputData.connections || []) {
                    const targetNode = moduleData[conn.node]
                    const targetKey = targetNode?.data?.nodeKey || targetNode?.name
                    const port = outputKey.replace('output_', '')
                    outputs.push({
                        port: this.getPortLabel(type, parseInt(port)),
                        target: targetKey,
                    })
                }
            }

            nodes[nodeKey] = { type, label: node.data?.label || node.name, config, outputs }
            canvasLayout[nodeKey] = { x: node.pos_x, y: node.pos_y, drawflowId: parseInt(drawflowId) }
        }

        return { definition: { nodes }, canvasLayout }
    }

    getPortLabel(type, portIndex) {
        const portLabels = {
            condition: ['true', 'false'],
            approval: ['approved', 'rejected'],
            loop: ['continue', 'exit'],
            fork: ['path-1', 'path-2', 'path-3', 'path-4'],
        }
        return portLabels[type]?.[portIndex] || `output-${portIndex + 1}`
    }

    importWorkflow(definition, canvasLayout) {
        this.editor.clear()
        const nodeIdMap = {}
        const nodeEntries = Object.entries(definition.nodes || {})

        const hasLayout = canvasLayout && typeof canvasLayout === 'object' &&
            !Array.isArray(canvasLayout) && Object.keys(canvasLayout).length > 0

        let autoPositions = {}
        if (!hasLayout) {
            autoPositions = this._computeAutoLayout(definition)
        }

        for (const [nodeKey, nodeDef] of nodeEntries) {
            const pos = hasLayout
                ? (canvasLayout[nodeKey] || { x: 100, y: 100 })
                : (autoPositions[nodeKey] || { x: 100, y: 100 })
            const { inputs, outputs } = this.getNodePorts(nodeDef.type)
            const config = { ...(nodeDef.config || {}), _nodeLabel: nodeDef.label || '' }
            const html = this.buildNodeHTML(nodeDef.type, nodeKey, config)

            const drawflowId = this.editor.addNode(
                nodeKey, inputs, outputs,
                pos.x, pos.y, nodeKey,
                { type: nodeDef.type, config: nodeDef.config || {}, nodeKey, label: nodeDef.label || '' },
                html
            )
            nodeIdMap[nodeKey] = drawflowId
        }

        for (const [nodeKey, nodeDef] of nodeEntries) {
            const sourceId = nodeIdMap[nodeKey]
            for (const [idx, output] of (nodeDef.outputs || []).entries()) {
                const targetId = nodeIdMap[output.target]
                if (targetId) {
                    this.editor.addConnection(sourceId, targetId, `output_${idx + 1}`, 'input_1')
                }
            }
        }
    }

    _computeAutoLayout(definition) {
        const nodes = definition.nodes || {}
        const nodeKeys = Object.keys(nodes)
        const positions = {}

        const inDegree = {}
        const children = {}
        nodeKeys.forEach(k => { inDegree[k] = 0; children[k] = [] })
        for (const [key, node] of Object.entries(nodes)) {
            for (const out of (node.outputs || [])) {
                if (out.target && children[key]) {
                    children[key].push(out.target)
                    inDegree[out.target] = (inDegree[out.target] || 0) + 1
                }
            }
        }

        const layers = []
        let queue = nodeKeys.filter(k => inDegree[k] === 0)
        const visited = new Set()

        while (queue.length > 0) {
            layers.push([...queue])
            queue.forEach(k => visited.add(k))
            const nextQueue = []
            for (const k of queue) {
                for (const child of (children[k] || [])) {
                    inDegree[child]--
                    if (inDegree[child] <= 0 && !visited.has(child)) {
                        nextQueue.push(child)
                        visited.add(child)
                    }
                }
            }
            queue = nextQueue
        }

        nodeKeys.filter(k => !visited.has(k)).forEach(k => layers.push([k]))

        const nodeW = 260, nodeH = 120, gapX = 60, startX = 80, startY = 60

        layers.forEach((layer, rowIdx) => {
            const totalWidth = layer.length * nodeW + (layer.length - 1) * gapX
            const offsetX = startX + Math.max(0, (600 - totalWidth) / 2)
            layer.forEach((key, colIdx) => {
                positions[key] = {
                    x: offsetX + colIdx * (nodeW + gapX),
                    y: startY + rowIdx * nodeH
                }
            })
        })

        return positions
    }

    // --- Save / Publish ---

    async save(event) {
        if (event) event.preventDefault()
        const { definition, canvasLayout } = this.exportWorkflow()

        this._setBtnLoading(this.hasSaveBtnTarget ? this.saveBtnTarget : null, true)

        try {
            const response = await fetch(this.saveUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfTokenValue,
                },
                body: JSON.stringify({
                    workflowId: this.workflowIdValue || null,
                    versionId: this.versionIdValue || null,
                    definition,
                    canvasLayout,
                }),
            })

            const result = await response.json()
            if (response.ok && result.success) {
                if (result.workflowId) this.workflowIdValue = result.workflowId
                if (result.versionId) this.versionIdValue = result.versionId
                this._lastSavedSnapshot = JSON.stringify(this.editor.export())
                this._isDirty = false
                this._updateDirtyState()
                this.showFlash('Workflow saved successfully', 'success')
                return true
            } else {
                this.showFlash(result.reason || 'Failed to save workflow', 'danger')
                return false
            }
        } catch (error) {
            console.error('Save failed:', error)
            this.showFlash('Error saving workflow', 'danger')
            return false
        } finally {
            this._setBtnLoading(this.hasSaveBtnTarget ? this.saveBtnTarget : null, false)
        }
    }

    async publish(event) {
        if (event) event.preventDefault()
        if (!confirm('Publish this workflow version? Running instances will continue on their current version.')) return

        const saved = await this.save()
        if (!saved) return

        this._setBtnLoading(this.hasPublishBtnTarget ? this.publishBtnTarget : null, true)

        try {
            const response = await fetch(this.publishUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfTokenValue,
                },
                body: JSON.stringify({ versionId: this.versionIdValue }),
            })

            const result = await response.json()
            if (response.ok && result.success) {
                this.showFlash('Workflow published as v' + (result.versionNumber || ''), 'success')
                // Reload to get fresh state — PHP controller creates a new draft automatically
                setTimeout(() => window.location.reload(), 1000)
            } else {
                const reason = result.reason || 'Failed to publish workflow'
                if (reason.startsWith('Definition validation failed:')) {
                    const errorText = reason.replace('Definition validation failed: ', '')
                    const errors = errorText.split('; ').filter(e => e.trim())
                    this._showValidationResults({ valid: false, errors, warnings: [] })
                }
                this.showFlash(reason, 'danger')
            }
        } catch (error) {
            console.error('Publish failed:', error)
            this.showFlash('Error publishing workflow', 'danger')
        } finally {
            this._setBtnLoading(this.hasPublishBtnTarget ? this.publishBtnTarget : null, false)
        }
    }

    /** Set a button to spinner/disabled state or restore it. */
    _setBtnLoading(btn, loading) {
        if (!btn) return
        if (loading) {
            btn._origHTML = btn.innerHTML
            btn.disabled = true
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Saving…'
        } else {
            btn.disabled = false
            btn.innerHTML = btn._origHTML || btn.innerHTML
        }
    }

    showFlash(message, type) {
        const toast = document.createElement('div')
        toast.className = `alert alert-${type} alert-dismissible fade show wf-toast`
        toast.setAttribute('role', 'alert')
        toast.setAttribute('aria-live', 'assertive')
        toast.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>`
        document.body.appendChild(toast)
        setTimeout(() => toast.remove(), 4000)
    }

    // --- Undo / Redo ---

    undo() { this._historyManager.undo(this.editor) }
    redo() { this._historyManager.redo(this.editor) }

    // --- Zoom ---

    zoomIn() { this._setZoom(Math.min(this._zoom + 0.1, 2)) }
    zoomOut() { this._setZoom(Math.max(this._zoom - 0.1, 0.3)) }
    zoomReset() { this._setZoom(1) }

    _setZoom(level) {
        this._zoom = Math.round(level * 100) / 100
        if (this.editor) {
            this.editor.zoom = this._zoom
            this.editor.zoom_refresh()
        }
        if (this.hasZoomLevelTarget) {
            this.zoomLevelTarget.textContent = `${Math.round(this._zoom * 100)}%`
        }
    }

    // --- Keyboard Shortcuts ---

    _bindKeyboardShortcuts() {
        this._keyHandler = (event) => this._handleKeydown(event)
        this._keyUpHandler = (event) => {
            if (event.key === 'Shift') this._shiftHeld = false
        }
        this._keyDownShiftHandler = (event) => {
            if (event.key === 'Shift') this._shiftHeld = true
        }
        document.addEventListener('keydown', this._keyHandler)
        document.addEventListener('keydown', this._keyDownShiftHandler)
        document.addEventListener('keyup', this._keyUpHandler)
    }

    _unbindKeyboardShortcuts() {
        if (this._keyHandler) document.removeEventListener('keydown', this._keyHandler)
        if (this._keyDownShiftHandler) document.removeEventListener('keydown', this._keyDownShiftHandler)
        if (this._keyUpHandler) document.removeEventListener('keyup', this._keyUpHandler)
    }

    _handleKeydown(event) {
        const tag = event.target.tagName
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag)) return
        const ctrl = event.ctrlKey || event.metaKey

        if (ctrl && event.key === 's') {
            event.preventDefault()
            this.save()
        } else if (ctrl && event.key === 'z' && !event.shiftKey) {
            event.preventDefault()
            this.undo()
        } else if (ctrl && (event.key === 'y' || (event.key === 'z' && event.shiftKey))) {
            event.preventDefault()
            this.redo()
        } else if (event.key === 'Delete' || event.key === 'Backspace') {
            if (this._selectedNodes.size > 0) {
                event.preventDefault()
                this.deleteSelectedNodes()
            }
        }
    }

    deleteSelectedNodes() {
        if (this.readonlyValue) return
        this._selectedNodes.forEach(nodeId => {
            try { this.editor.removeNodeId(`node-${nodeId}`) } catch (_) { /* node may already be gone */ }
        })
        this._clearMultiSelect()
    }

    // --- Validation ---

    validateWorkflow() {
        const result = this._validationService.validate(this.editor.export())
        this._showValidationResults(result)
        return result
    }

    _showValidationResults({ valid, errors, warnings }) {
        if (this.hasValidationResultsTarget) {
            let html = ''
            if (valid && warnings.length === 0) {
                html = '<div class="alert alert-success mb-0"><i class="bi bi-check-circle me-1"></i> Workflow is valid.</div>'
            }
            errors.forEach(e => {
                html += `<div class="alert alert-danger mb-1"><i class="bi bi-x-circle me-1"></i> ${e}</div>`
            })
            warnings.forEach(w => {
                html += `<div class="alert alert-warning mb-1"><i class="bi bi-exclamation-triangle me-1"></i> ${w}</div>`
            })
            this.validationResultsTarget.innerHTML = html
            this.validationResultsTarget.style.display = 'block'

            if (valid) {
                setTimeout(() => {
                    if (this.hasValidationResultsTarget) this.validationResultsTarget.style.display = 'none'
                }, 4000)
            }
        }

        if (!valid) {
            this.showFlash(`Validation failed: ${errors.length} error(s)`, 'danger')
        }
    }

    // --- Responsive panel toggles ---

    togglePalette() {
        if (this.hasNodePaletteTarget) {
            this.nodePaletteTarget.classList.toggle('wf-panel-collapsed')
        }
    }

    toggleConfig() {
        if (this.hasNodeConfigTarget) {
            this.nodeConfigTarget.classList.toggle('wf-panel-collapsed')
        }
    }

    // --- Lifecycle ---

    disconnect() {
        this._unbindKeyboardShortcuts()
        if (this.editor) {
            this.editor.clear()
        }
    }
}

// Register controller
if (!window.Controllers) {
    window.Controllers = {};
}
window.Controllers["workflow-designer"] = WorkflowDesignerController;
