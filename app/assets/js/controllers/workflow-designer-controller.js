import { Controller } from "@hotwired/stimulus"
import Drawflow from 'drawflow'

class WorkflowDesignerController extends Controller {
    static targets = [
        "canvas", "nodeConfig", "nodePalette", "saveBtn", "publishBtn",
        "versionInfo", "zoomLevel", "validationResults"
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

    // Core Drawflow instance
    editor = null

    // Registry data (loaded from server)
    registryData = {
        triggers: [],
        actions: [],
        conditions: [],
        entities: []
    }

    // Undo/redo state
    _history = []
    _historyIndex = -1
    _historyLocked = false

    // Multi-select state
    _selectedNodes = new Set()

    // Current zoom level
    _zoom = 1

    async connect() {
        this.initEditor()
        await this.loadRegistry()
        if (this.hasWorkflowIdValue && this.workflowIdValue) {
            await this.loadWorkflow()
        }
        this._bindKeyboardShortcuts()
    }

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

        this.editor.on('nodeSelected', (nodeId) => this.onNodeSelected(nodeId))
        this.editor.on('nodeUnselected', () => this.onNodeUnselected())
        this.editor.on('connectionCreated', (connection) => this.onConnectionCreated(connection))
        this.editor.on('nodeRemoved', (nodeId) => this.onNodeRemoved(nodeId))

        // Track state changes for undo/redo
        this.editor.on('nodeCreated', () => this._pushHistory())
        this.editor.on('nodeRemoved', () => this._pushHistory())
        this.editor.on('connectionCreated', () => this._pushHistory())
        this.editor.on('connectionRemoved', () => this._pushHistory())
        this.editor.on('nodeMoved', () => this._pushHistory())

        // Push initial empty state
        this._pushHistory()
    }

    registerNodeTemplates() {
        // Define HTML templates for each node type
    }

    async loadRegistry() {
        if (!this.hasRegistryUrlValue) return
        try {
            const response = await fetch(this.registryUrlValue, {
                headers: { 'Accept': 'application/json' }
            })
            if (response.ok) {
                this.registryData = await response.json()
                this.populateNodePalette()
            }
        } catch (error) {
            console.error('Failed to load workflow registry:', error)
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
            }
        } catch (error) {
            console.error('Failed to load workflow:', error)
        }
    }

    populateNodePalette() {
        if (!this.hasNodePaletteTarget) return
        let html = this.buildPaletteHTML()
        this.nodePaletteTarget.innerHTML = html
        this.initDragFromPalette()
    }

    buildPaletteHTML() {
        let html = ''

        const makeIcon = (faClass, nodeType) =>
            `<span class="palette-node-icon"><i class="fa-solid ${faClass}"></i></span>`

        // Helper: group items by source
        const groupBySource = (items) => {
            const groups = {}
            items.forEach(item => {
                const src = item.source || 'Other'
                if (!groups[src]) groups[src] = []
                groups[src].push(item)
            })
            return groups
        }

        // Flow Control
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
            html += `<div class="palette-node" draggable="true" data-node-type="${node.type}" data-action="dragstart->workflow-designer#onPaletteDragStart">${makeIcon(node.icon, node.type)} ${node.label}</div>`
        })
        html += '</div>'

        // Approvals
        html += '<div class="palette-category"><h6 class="palette-category-title">Approvals</h6>'
        html += `<div class="palette-node" draggable="true" data-node-type="approval" data-action="dragstart->workflow-designer#onPaletteDragStart">${makeIcon('fa-check-double', 'approval')} Approval Gate</div>`
        html += '</div>'

        // Triggers (grouped by source)
        if (this.registryData.triggers && this.registryData.triggers.length > 0) {
            const groups = groupBySource(this.registryData.triggers)
            for (const [source, triggers] of Object.entries(groups)) {
                html += `<div class="palette-category"><h6 class="palette-category-title"><i class="fa-solid fa-bolt fa-xs me-1"></i>Triggers — ${source}</h6>`
                triggers.forEach(trigger => {
                    html += `<div class="palette-node" draggable="true" data-node-type="trigger" data-node-event="${trigger.event}" data-action="dragstart->workflow-designer#onPaletteDragStart">${makeIcon('fa-bolt', 'trigger')} ${trigger.label}</div>`
                })
                html += '</div>'
            }
        }

        // Actions (grouped by source)
        if (this.registryData.actions && this.registryData.actions.length > 0) {
            const groups = groupBySource(this.registryData.actions)
            for (const [source, actions] of Object.entries(groups)) {
                html += `<div class="palette-category"><h6 class="palette-category-title"><i class="fa-solid fa-gear fa-xs me-1"></i>Actions — ${source}</h6>`
                actions.forEach(action => {
                    html += `<div class="palette-node" draggable="true" data-node-type="action" data-node-action="${action.action}" data-action="dragstart->workflow-designer#onPaletteDragStart">${makeIcon('fa-gear', 'action')} ${action.label}</div>`
                })
                html += '</div>'
            }
        }

        // Conditions (grouped by source)
        if (this.registryData.conditions && this.registryData.conditions.length > 0) {
            const groups = groupBySource(this.registryData.conditions)
            for (const [source, conditions] of Object.entries(groups)) {
                html += `<div class="palette-category"><h6 class="palette-category-title"><i class="fa-solid fa-diamond fa-xs me-1"></i>Conditions — ${source}</h6>`
                conditions.forEach(cond => {
                    html += `<div class="palette-node" draggable="true" data-node-type="condition" data-node-condition="${cond.condition}" data-action="dragstart->workflow-designer#onPaletteDragStart">${makeIcon('fa-diamond', 'condition')} ${cond.label}</div>`
                })
                html += '</div>'
            }
        }

        return html
    }

    initDragFromPalette() {
        // Palette nodes use Stimulus data-action for dragstart; no extra JS needed.
    }

    onPaletteDragStart(event) {
        const el = event.currentTarget
        const nodeType = el.dataset.nodeType
        const nodeEvent = el.dataset.nodeEvent || ''
        const nodeAction = el.dataset.nodeAction || ''
        event.dataTransfer.setData('node-type', nodeType)
        event.dataTransfer.setData('node-event', nodeEvent)
        event.dataTransfer.setData('node-action', nodeAction)
        event.dataTransfer.effectAllowed = 'move'
    }

    onCanvasDrop(event) {
        event.preventDefault()
        const nodeType = event.dataTransfer.getData('node-type')
        if (!nodeType) return

        const nodeEvent = event.dataTransfer.getData('node-event')
        const nodeAction = event.dataTransfer.getData('node-action')

        // Calculate position accounting for canvas scroll/zoom/translate
        const canvasRect = this.canvasTarget.getBoundingClientRect()
        const precanvas = this.canvasTarget.querySelector('.drawflow')
            || this.canvasTarget
        const zoom = this._zoom || 1

        // Drawflow stores canvas translation in its internal state
        const canvasX = this.editor.canvas_x || 0
        const canvasY = this.editor.canvas_y || 0

        const x = (event.clientX - canvasRect.left - canvasX) / zoom
        const y = (event.clientY - canvasRect.top - canvasY) / zoom

        this.addNode(nodeType, x, y, { event: nodeEvent, action: nodeAction })
    }

    onCanvasDragOver(event) {
        event.preventDefault()
        event.dataTransfer.dropEffect = 'move'
    }

    addNode(type, x, y, config = {}) {
        const nodeKey = `${type}-${Date.now()}`
        const { inputs, outputs } = this.getNodePorts(type)
        const html = this.buildNodeHTML(type, nodeKey, config)

        const nodeId = this.editor.addNode(
            nodeKey,
            inputs,
            outputs,
            x, y,
            nodeKey,
            { type, config, nodeKey },
            html
        )

        return nodeId
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

        // Use explicit node label if provided (from saved definition)
        if (config._nodeLabel) {
            label = config._nodeLabel
        }
        // Otherwise resolve friendly label from registry
        else if (config.event) {
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

        // Port labels for branching nodes
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

    onNodeSelected(nodeId) {
        this._selectedNodes.add(nodeId)
        this._highlightSelectedNodes()
        const nodeData = this.editor.getNodeFromId(nodeId)
        if (this.hasNodeConfigTarget) {
            this.showNodeConfig(nodeId, nodeData)
        }
    }

    onNodeUnselected() {
        if (!this._shiftHeld) {
            this._clearMultiSelect()
        }
        if (this.hasNodeConfigTarget) {
            this.nodeConfigTarget.innerHTML = `
                <div class="config-panel-header">
                    <h6><i class="bi bi-sliders me-1"></i>Configuration</h6>
                </div>
                <div class="config-panel-empty">
                    <i class="bi bi-hand-index"></i>
                    <p>Select a node on the canvas to configure it</p>
                </div>`
        }
    }

    /** Shift+click on canvas nodes for multi-select */
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

    showNodeConfig(nodeId, nodeData) {
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
            <div class="config-panel-body">
                <form data-node-id="${nodeId}">
                    <div class="mb-3">
                        <label class="form-label">Label</label>
                        <input type="text" class="form-control form-control-sm" name="label" value="${nodeData.name || ''}"
                            data-action="change->workflow-designer#updateNodeConfig">
                    </div>`

        html += this.getTypeSpecificConfigHTML(type, nodeData.data?.config || {})

        html += `</form></div>`
        this.nodeConfigTarget.innerHTML = html
        this._attachVariablePickers(nodeId)
    }

    getTypeSpecificConfigHTML(type, config) {
        switch (type) {
            case 'trigger':
                return this.buildTriggerConfigHTML(config)
            case 'action':
                return this.buildActionConfigHTML(config)
            case 'condition':
                return this.buildConditionConfigHTML(config)
            case 'approval':
                return this.buildApprovalConfigHTML(config)
            case 'delay':
                return this.buildDelayConfigHTML(config)
            case 'loop':
                return this.buildLoopConfigHTML(config)
            default:
                return ''
        }
    }

    buildTriggerConfigHTML(config) {
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

    buildActionConfigHTML(config) {
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

    buildConditionConfigHTML(config) {
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

    buildApprovalConfigHTML(config) {
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

    buildDelayConfigHTML(config) {
        return `<div class="mb-3">
            <label class="form-label">Duration</label>
            <input type="text" class="form-control form-control-sm" name="duration" value="${config.duration || ''}" placeholder="e.g. 1h, 2d, 30m" data-action="change->workflow-designer#updateNodeConfig">
        </div>
        <div class="mb-3">
            <label class="form-label">Wait For Event (optional)</label>
            <input type="text" class="form-control form-control-sm" name="waitEvent" value="${config.waitEvent || ''}" placeholder="Event to resume on" data-action="change->workflow-designer#updateNodeConfig">
        </div>`
    }

    buildLoopConfigHTML(config) {
        return `<div class="mb-3">
            <label class="form-label">Max Iterations</label>
            <input type="number" class="form-control form-control-sm" name="maxIterations" value="${config.maxIterations || 100}" min="1" data-action="change->workflow-designer#updateNodeConfig">
        </div>
        <div class="mb-3">
            <label class="form-label">Exit Condition</label>
            <input type="text" class="form-control form-control-sm" name="exitCondition" value="${config.exitCondition || ''}" placeholder="Expression to evaluate" data-action="change->workflow-designer#updateNodeConfig" data-variable-picker="true">
        </div>`
    }

    updateNodeConfig(event) {
        const form = event.target.closest('form')
        const nodeId = form.dataset.nodeId
        const formData = new FormData(form)

        const nodeData = this.editor.getNodeFromId(nodeId)
        if (!nodeData.data) nodeData.data = {}
        if (!nodeData.data.config) nodeData.data.config = {}

        for (const [key, value] of formData.entries()) {
            nodeData.data.config[key] = value
        }

        // Handle checkbox (allowParallel)
        nodeData.data.config.allowParallel = form.querySelector('[name="allowParallel"]')?.checked ?? true

        this.editor.updateNodeDataFromId(nodeId, nodeData.data)
    }

    onConnectionCreated(connection) {
        // Validate connection rules
    }

    onNodeRemoved(nodeId) {
        if (this.hasNodeConfigTarget) {
            this.onNodeUnselected()
        }
    }

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

        // Detect if we have saved positions
        const hasLayout = canvasLayout && typeof canvasLayout === 'object' &&
            !Array.isArray(canvasLayout) && Object.keys(canvasLayout).length > 0

        // Auto-layout: arrange nodes in a top-down flow when no positions saved
        let autoPositions = {}
        if (!hasLayout) {
            autoPositions = this._computeAutoLayout(definition)
        }

        // First pass: create all nodes
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

        // Second pass: create connections
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

    /**
     * Compute auto-layout positions using topological sort.
     * Places nodes in rows top-to-bottom; branches side-by-side.
     */
    _computeAutoLayout(definition) {
        const nodes = definition.nodes || {}
        const nodeKeys = Object.keys(nodes)
        const positions = {}

        // Build adjacency for topological sort
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

        // BFS layers (topological order)
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

        // Add orphan nodes not reached
        nodeKeys.filter(k => !visited.has(k)).forEach(k => layers.push([k]))

        const nodeW = 260
        const nodeH = 120
        const gapX = 60
        const startX = 80
        const startY = 60

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

    async save(event) {
        if (event) event.preventDefault()

        const { definition, canvasLayout } = this.exportWorkflow()

        try {
            const response = await fetch(this.saveUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfTokenValue,
                },
                body: JSON.stringify({ definition, canvasLayout }),
            })

            if (response.ok) {
                const result = await response.json()
                if (result.versionId) {
                    this.versionIdValue = result.versionId
                }
                this.showFlash('Workflow saved successfully', 'success')
            } else {
                this.showFlash('Failed to save workflow', 'danger')
            }
        } catch (error) {
            console.error('Save failed:', error)
            this.showFlash('Error saving workflow', 'danger')
        }
    }

    async publish(event) {
        if (event) event.preventDefault()
        if (!confirm('Publish this workflow version? Running instances will continue on their current version.')) return

        await this.save()

        try {
            const response = await fetch(this.publishUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfTokenValue,
                },
                body: JSON.stringify({ versionId: this.versionIdValue }),
            })

            if (response.ok) {
                this.showFlash('Workflow published successfully', 'success')
            } else {
                this.showFlash('Failed to publish workflow', 'danger')
            }
        } catch (error) {
            console.error('Publish failed:', error)
        }
    }

    showFlash(message, type) {
        const toast = document.createElement('div')
        toast.className = `alert alert-${type} alert-dismissible fade show wf-toast`
        toast.innerHTML = `${message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>`
        document.body.appendChild(toast)
        setTimeout(() => toast.remove(), 4000)
    }

    // -------------------------------------------------------
    // Undo / Redo
    // -------------------------------------------------------

    _pushHistory() {
        if (this._historyLocked || !this.editor) return
        const snapshot = JSON.stringify(this.editor.export())

        // Avoid duplicate consecutive states
        if (this._history.length > 0 && this._history[this._historyIndex] === snapshot) return

        // Discard any redo states ahead of the current index
        this._history = this._history.slice(0, this._historyIndex + 1)
        this._history.push(snapshot)

        // Enforce max history size
        if (this._history.length > this.maxHistoryValue) {
            this._history.shift()
        }
        this._historyIndex = this._history.length - 1
    }

    undo() {
        if (this._historyIndex <= 0) return
        this._historyIndex--
        this._restoreHistory()
    }

    redo() {
        if (this._historyIndex >= this._history.length - 1) return
        this._historyIndex++
        this._restoreHistory()
    }

    _restoreHistory() {
        this._historyLocked = true
        try {
            const data = JSON.parse(this._history[this._historyIndex])
            this.editor.import(data)
        } finally {
            this._historyLocked = false
        }
    }

    // -------------------------------------------------------
    // Zoom controls
    // -------------------------------------------------------

    zoomIn() {
        this._setZoom(Math.min(this._zoom + 0.1, 2))
    }

    zoomOut() {
        this._setZoom(Math.max(this._zoom - 0.1, 0.3))
    }

    zoomReset() {
        this._setZoom(1)
    }

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

    // -------------------------------------------------------
    // Keyboard shortcuts
    // -------------------------------------------------------

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
        // Ignore shortcuts when typing in an input/textarea/select
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

    // -------------------------------------------------------
    // Validation
    // -------------------------------------------------------

    validateWorkflow() {
        const errors = []
        const warnings = []
        const drawflowData = this.editor.export()
        const moduleData = drawflowData.drawflow?.Home?.data || {}
        const nodes = Object.entries(moduleData)

        if (nodes.length === 0) {
            errors.push('Workflow is empty — add at least one node.')
            return this._showValidationResults({ errors, warnings })
        }

        // Exactly one trigger
        const triggers = nodes.filter(([, n]) => n.data?.type === 'trigger')
        if (triggers.length === 0) {
            errors.push('A workflow must have exactly one trigger node.')
        } else if (triggers.length > 1) {
            errors.push(`Found ${triggers.length} trigger nodes — only one is allowed.`)
        }

        // At least one end node
        const endNodes = nodes.filter(([, n]) => n.data?.type === 'end')
        if (endNodes.length === 0) {
            errors.push('At least one End node is required.')
        }

        // Loop nodes must have maxIterations
        nodes.filter(([, n]) => n.data?.type === 'loop').forEach(([id, n]) => {
            const max = n.data?.config?.maxIterations
            if (!max || parseInt(max, 10) <= 0) {
                errors.push(`Loop node #${id} must have maxIterations set to a positive number.`)
            }
        })

        // All nodes connected (no port without a connection)
        nodes.forEach(([id, node]) => {
            const type = node.data?.type || 'unknown'
            const { inputs, outputs } = this.getNodePorts(type)

            // Check inputs (skip trigger which has 0 inputs)
            if (inputs > 0) {
                let hasIncoming = false
                for (const inp of Object.values(node.inputs || {})) {
                    if (inp.connections && inp.connections.length > 0) hasIncoming = true
                }
                if (!hasIncoming) {
                    errors.push(`Node "${node.name}" (#${id}) has no incoming connection.`)
                }
            }

            // Check outputs (skip end which has 0 outputs)
            if (outputs > 0) {
                let hasOutgoing = false
                for (const out of Object.values(node.outputs || {})) {
                    if (out.connections && out.connections.length > 0) hasOutgoing = true
                }
                if (!hasOutgoing) {
                    warnings.push(`Node "${node.name}" (#${id}) has no outgoing connection.`)
                }
            }
        })

        // No disconnected subgraphs (BFS from first trigger)
        if (triggers.length === 1) {
            const reachable = new Set()
            const adjList = {}

            // Build undirected adjacency list
            nodes.forEach(([id]) => { adjList[id] = new Set() })
            nodes.forEach(([id, node]) => {
                for (const out of Object.values(node.outputs || {})) {
                    for (const conn of out.connections || []) {
                        adjList[id]?.add(conn.node.toString())
                        adjList[conn.node.toString()]?.add(id)
                    }
                }
            })

            const queue = [triggers[0][0]]
            reachable.add(triggers[0][0])
            while (queue.length > 0) {
                const current = queue.shift()
                for (const neighbor of adjList[current] || []) {
                    if (!reachable.has(neighbor)) {
                        reachable.add(neighbor)
                        queue.push(neighbor)
                    }
                }
            }

            const unreachable = nodes.filter(([id]) => !reachable.has(id))
            if (unreachable.length > 0) {
                errors.push(
                    `${unreachable.length} node(s) are not connected to the trigger: ` +
                    unreachable.map(([id, n]) => `"${n.name}" (#${id})`).join(', ')
                )
            }
        }

        return this._showValidationResults({ errors, warnings })
    }

    _showValidationResults({ errors, warnings }) {
        const isValid = errors.length === 0

        if (this.hasValidationResultsTarget) {
            let html = ''
            if (isValid && warnings.length === 0) {
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

            // Auto-hide success after 4s
            if (isValid) {
                setTimeout(() => {
                    if (this.hasValidationResultsTarget) this.validationResultsTarget.style.display = 'none'
                }, 4000)
            }
        }

        if (!isValid) {
            this.showFlash(`Validation failed: ${errors.length} error(s)`, 'danger')
        }

        return { valid: isValid, errors, warnings }
    }

    // -------------------------------------------------------
    // Context Variable Picker
    // -------------------------------------------------------

    /**
     * Get upstream nodes by traversing connections backward from nodeId.
     */
    _getUpstreamNodes(nodeId) {
        const drawflowData = this.editor.export()
        const moduleData = drawflowData.drawflow?.Home?.data || {}
        const upstream = []
        const visited = new Set()

        const traverse = (currentId) => {
            if (visited.has(currentId)) return
            visited.add(currentId)
            const node = moduleData[currentId]
            if (!node) return
            for (const inp of Object.values(node.inputs || {})) {
                for (const conn of inp.connections || []) {
                    const srcId = conn.node.toString()
                    const srcNode = moduleData[srcId]
                    if (srcNode) {
                        upstream.push({ id: srcId, ...srcNode })
                        traverse(srcId)
                    }
                }
            }
        }

        traverse(nodeId.toString())
        return upstream
    }

    /**
     * Collect output schema variables from a node based on its type and registry data.
     */
    _getNodeOutputSchema(node) {
        const type = node.data?.type
        const config = node.data?.config || {}
        const vars = []

        if (type === 'trigger') {
            const trigger = this.registryData.triggers?.find(t => t.event === config.event)
            if (trigger?.outputSchema) {
                for (const [key, meta] of Object.entries(trigger.outputSchema)) {
                    vars.push({ path: `$.trigger.${key}`, label: `Trigger: ${key}`, type: meta.type || 'string' })
                }
            } else if (config.event) {
                vars.push({ path: '$.trigger.entity', label: 'Trigger: entity', type: 'object' })
                vars.push({ path: '$.trigger.entity.id', label: 'Trigger: entity.id', type: 'integer' })
            }
        } else if (type === 'action') {
            const action = this.registryData.actions?.find(a => a.action === config.action)
            const nodeKey = node.data?.nodeKey || node.name
            if (action?.outputSchema) {
                for (const [key, meta] of Object.entries(action.outputSchema)) {
                    vars.push({ path: `$.nodes.${nodeKey}.${key}`, label: `${action.label}: ${key}`, type: meta.type || 'string' })
                }
            } else {
                vars.push({ path: `$.nodes.${nodeKey}.result`, label: `${node.name}: result`, type: 'mixed' })
            }
        } else if (type === 'approval') {
            const nodeKey = node.data?.nodeKey || node.name
            vars.push({ path: `$.nodes.${nodeKey}.status`, label: `${node.name}: status`, type: 'string' })
            vars.push({ path: `$.nodes.${nodeKey}.approvedBy`, label: `${node.name}: approvedBy`, type: 'array' })
            vars.push({ path: `$.nodes.${nodeKey}.comment`, label: `${node.name}: comment`, type: 'string' })
        } else if (type === 'condition') {
            const nodeKey = node.data?.nodeKey || node.name
            vars.push({ path: `$.nodes.${nodeKey}.result`, label: `${node.name}: result`, type: 'boolean' })
        }

        return vars
    }

    /**
     * Build a list of available context variables for a given node.
     */
    buildContextVariablePicker(nodeId) {
        const upstream = this._getUpstreamNodes(nodeId)
        const variables = []

        // Always include trigger payload
        const drawflowData = this.editor.export()
        const moduleData = drawflowData.drawflow?.Home?.data || {}
        for (const node of Object.values(moduleData)) {
            if (node.data?.type === 'trigger') {
                variables.push(...this._getNodeOutputSchema(node))
                break
            }
        }

        // Collect from upstream nodes (skip trigger, already added)
        upstream.forEach(node => {
            if (node.data?.type !== 'trigger') {
                variables.push(...this._getNodeOutputSchema(node))
            }
        })

        // Common context variables always available
        variables.push(
            { path: '$.instance.id', label: 'Instance ID', type: 'integer' },
            { path: '$.instance.created', label: 'Instance Created', type: 'datetime' },
            { path: '$.context', label: 'Full Context', type: 'object' }
        )

        return variables
    }

    /**
     * Attach variable picker buttons to inputs with data-variable-picker="true".
     */
    _attachVariablePickers(nodeId) {
        const panel = this.nodeConfigTarget
        if (!panel) return
        const inputs = panel.querySelectorAll('input[data-variable-picker="true"], textarea[data-variable-picker="true"]')
        inputs.forEach(input => {
            if (input.parentElement.querySelector('.wf-var-picker-btn')) return
            const wrapper = document.createElement('div')
            wrapper.className = 'd-flex gap-1'
            input.parentElement.insertBefore(wrapper, input)
            wrapper.appendChild(input)

            const btn = document.createElement('button')
            btn.type = 'button'
            btn.className = 'btn btn-outline-secondary btn-sm wf-var-picker-btn'
            btn.innerHTML = '<i class="fa-solid fa-code"></i>'
            btn.title = 'Insert variable reference'
            btn.addEventListener('click', (e) => {
                e.preventDefault()
                this._showVariableDropdown(btn, input, nodeId)
            })
            wrapper.appendChild(btn)
        })
    }

    _showVariableDropdown(anchorBtn, targetInput, nodeId) {
        // Remove any existing dropdown
        document.querySelectorAll('.wf-var-dropdown').forEach(el => el.remove())

        const variables = this.buildContextVariablePicker(nodeId)
        if (variables.length === 0) return

        const dropdown = document.createElement('div')
        dropdown.className = 'wf-var-dropdown card shadow-sm position-absolute'
        dropdown.style.cssText = 'z-index:1050; max-height:200px; overflow-y:auto; width:280px;'

        const searchInput = document.createElement('input')
        searchInput.type = 'text'
        searchInput.className = 'form-control form-control-sm border-0 border-bottom rounded-0'
        searchInput.placeholder = 'Search variables...'
        dropdown.appendChild(searchInput)

        const list = document.createElement('div')
        list.className = 'list-group list-group-flush'

        const renderItems = (filter = '') => {
            list.innerHTML = ''
            const filtered = variables.filter(v =>
                v.path.toLowerCase().includes(filter) || v.label.toLowerCase().includes(filter)
            )
            filtered.forEach(v => {
                const item = document.createElement('button')
                item.type = 'button'
                item.className = 'list-group-item list-group-item-action py-1 px-2 small'
                item.innerHTML = `<code>${v.path}</code><br><span class="text-muted" style="font-size:0.7rem">${v.label} (${v.type})</span>`
                item.addEventListener('click', () => {
                    const pos = targetInput.selectionStart || targetInput.value.length
                    const before = targetInput.value.substring(0, pos)
                    const after = targetInput.value.substring(pos)
                    targetInput.value = before + v.path + after
                    targetInput.dispatchEvent(new Event('change', { bubbles: true }))
                    dropdown.remove()
                    targetInput.focus()
                })
                list.appendChild(item)
            })
            if (filtered.length === 0) {
                list.innerHTML = '<div class="text-muted small p-2">No variables found</div>'
            }
        }

        searchInput.addEventListener('input', () => renderItems(searchInput.value.toLowerCase()))
        renderItems()
        dropdown.appendChild(list)

        anchorBtn.parentElement.style.position = 'relative'
        anchorBtn.parentElement.appendChild(dropdown)

        // Close on outside click
        const closeHandler = (e) => {
            if (!dropdown.contains(e.target) && e.target !== anchorBtn) {
                dropdown.remove()
                document.removeEventListener('click', closeHandler)
            }
        }
        setTimeout(() => document.addEventListener('click', closeHandler), 0)
        searchInput.focus()
    }

    // -------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------

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
