import { Controller } from "@hotwired/stimulus"
import Drawflow from 'drawflow'
import WorkflowValidationService from './workflow-validation-service.js'
import WorkflowVariablePicker from './workflow-variable-picker.js'
import WorkflowConfigPanel from './workflow-config-panel.js'
import WorkflowHistoryManager from './workflow-history-manager.js'
import WorkflowSerializer from './workflow-serializer.js'
import WorkflowNodeConfigHandler from './workflow-node-config-handler.js'

/**
 * WorkflowDesignerController
 *
 * Coordinator for the workflow visual designer. Manages the Drawflow canvas,
 * node palette, and delegates to extracted modules:
 * - WorkflowSerializer: data conversion and auto-layout
 * - WorkflowNodeConfigHandler: config panel form interactions
 * - WorkflowToolbarController: toolbar actions (separate Stimulus controller)
 */
class WorkflowDesignerController extends Controller {
    static targets = [
        "canvas", "nodeConfig", "nodePalette",
        "versionInfo", "validationResults",
        "loadingOverlay", "unsavedIndicator"
    ]

    static values = {
        loadUrl: String,
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
    _shiftHeld = false

    async connect() {
        this._showLoading(true)
        try {
            this.initEditor()
            await this.loadRegistry()
            if (this.hasWorkflowIdValue && this.workflowIdValue) {
                await this.loadWorkflow()
            }
            this._nodeConfigHandler.restoreConfigPanelWidth()
            this._lastSavedSnapshot = JSON.stringify(this.editor.export())
        } catch (error) {
            this._showError('Failed to initialize the workflow designer.')
            console.error('Designer init error:', error)
        } finally {
            this._showLoading(false)
        }
    }

    // --- Public API for toolbar/external controllers ---

    get historyManager() { return this._historyManager }
    get validationService() { return this._validationService }
    get selectedNodes() { return this._selectedNodes }

    exportWorkflow() {
        return this._serializer.exportWorkflow(this.editor)
    }

    markSaved() {
        this._lastSavedSnapshot = JSON.stringify(this.editor.export())
        this._isDirty = false
        this._updateDirtyState()
    }

    clearMultiSelect() {
        this._selectedNodes.clear()
        this.canvasTarget.querySelectorAll('.wf-multi-selected').forEach(el => {
            el.classList.remove('wf-multi-selected')
        })
    }

    showValidationResults({ valid, errors, warnings }) {
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
        this._nodeConfigHandler = new WorkflowNodeConfigHandler(this)

        this.editor.on('nodeSelected', (nodeId) => this._nodeConfigHandler.onNodeSelected(nodeId))
        this.editor.on('nodeUnselected', () => this._nodeConfigHandler.onNodeUnselected())
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
                this._serializer = new WorkflowSerializer(this.registryData)
                this._validationService = new WorkflowValidationService(
                    (type) => this._serializer.getNodePorts(type),
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
                    this._serializer.importWorkflow(this.editor, data.definition, data.canvasLayout)
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
        let html = this._buildPaletteHTML()
        this.nodePaletteTarget.innerHTML = html
    }

    _buildPaletteHTML() {
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
        const { inputs, outputs } = this._serializer.getNodePorts(type)
        const html = this._serializer.buildNodeHTML(type, nodeKey, config)

        return this.editor.addNode(
            nodeKey, inputs, outputs,
            x, y, `${nodeKey} wf-type-${type}`,
            { type, config, nodeKey },
            html
        )
    }

    // --- Node Selection (multi-select via shift-click) ---

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
        this._nodeConfigHandler._highlightSelectedNodes()
    }

    // --- Delegate config panel actions to handler ---

    updateNodeConfig(event) { this._nodeConfigHandler.updateNodeConfig(event) }
    onApproverTypeChange(event) { this._nodeConfigHandler.onApproverTypeChange(event) }
    onSerialPickNextChange(event) { this._nodeConfigHandler.onSerialPickNextChange(event) }
    onResolverChange(event) { this._nodeConfigHandler.onResolverChange(event) }
    onValuePickerTypeChange(event) { this._nodeConfigHandler.onValuePickerTypeChange(event) }
    onPolicyClassChange(event) { this._nodeConfigHandler.onPolicyClassChange(event) }
    onResizeStart(event) { this._nodeConfigHandler.onResizeStart(event) }

    // --- Node Events ---

    onConnectionCreated(connection) {
        // Validate connection rules
    }

    onNodeRemoved(nodeId) {
        if (this.hasNodeConfigTarget) {
            this._nodeConfigHandler.onNodeUnselected()
        }
    }

    // --- Lifecycle ---

    disconnect() {
        if (this._nodeConfigHandler) {
            this._nodeConfigHandler.disconnect()
        }
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
