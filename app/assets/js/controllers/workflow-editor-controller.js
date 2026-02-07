import { Controller } from "@hotwired/stimulus";

/**
 * WorkflowEditorController
 *
 * Main visual workflow editor. Manages canvas rendering of state nodes and
 * transition edges, drag-to-reposition, connection drawing, selection,
 * property editing, and CRUD operations against the workflow API.
 */
class WorkflowEditorController extends Controller {
    static targets = ["canvas", "svg", "nodesContainer", "propertyPanel"]

    static values = {
        definitionId: Number,
        apiUrl: String,
    }

    connect() {
        this.definition = null;
        this.selectedElement = null;
        this.isDragging = false;
        this.dragOffset = { x: 0, y: 0 };
        this.loadDefinition();
    }

    async loadDefinition() {
        try {
            const response = await fetch(`${this.apiUrlValue}/definition/${this.definitionIdValue}.json`);
            const data = await response.json();
            this.definition = data.definition;
            // Attach first approval gate to each state for the property panel
            if (this.definition?.workflow_states) {
                this.definition.workflow_states.forEach(state => {
                    if (state.workflow_approval_gates?.length) {
                        state._approval_gate = state.workflow_approval_gates[0];
                        // Parse JSON strings in the gate
                        const g = state._approval_gate;
                        if (typeof g.threshold_config === 'string') {
                            try { g.threshold_config = JSON.parse(g.threshold_config); } catch { g.threshold_config = {}; }
                        }
                        if (typeof g.approver_rule === 'string') {
                            try { g.approver_rule = JSON.parse(g.approver_rule); } catch { g.approver_rule = {}; }
                        }
                    }
                });
            }
            this.render();
        } catch (error) {
            console.error('Failed to load workflow definition:', error);
        }
    }

    render() {
        this.renderNodes();
        this.renderEdges();
    }

    // ── Node Rendering ──────────────────────────────────────────────────

    renderNodes() {
        const container = this.nodesContainerTarget;
        container.innerHTML = '';

        if (!this.definition || !this.definition.workflow_states) return;

        this.definition.workflow_states.forEach(state => {
            const node = this.createNodeElement(state);
            container.appendChild(node);
        });
    }

    createNodeElement(state) {
        const node = document.createElement('div');
        node.className = `workflow-node workflow-node-${state.state_type}`;
        node.dataset.stateId = state.id;
        node.style.cssText = `
            position: absolute;
            left: ${state.position_x || 50}px;
            top: ${state.position_y || 50}px;
            min-width: 140px;
            padding: 10px 16px;
            border-radius: 8px;
            border: 2px solid ${this.getStateColor(state.state_type)};
            background: white;
            cursor: grab;
            user-select: none;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            z-index: 10;
            text-align: center;
        `;

        // State type badge
        const badge = document.createElement('div');
        badge.className = 'small text-muted';
        badge.style.fontSize = '10px';
        badge.textContent = state.state_type.toUpperCase();
        node.appendChild(badge);

        // State name
        const name = document.createElement('div');
        name.className = 'fw-bold';
        name.style.fontSize = '13px';
        name.textContent = state.label || state.name;
        node.appendChild(name);

        // Status category
        if (state.status_category) {
            const cat = document.createElement('div');
            cat.className = 'small text-muted';
            cat.style.fontSize = '10px';
            cat.textContent = state.status_category;
            node.appendChild(cat);
        }

        // Connection point (bottom – outbound)
        const outPoint = document.createElement('div');
        outPoint.className = 'workflow-connection-point workflow-connection-out';
        outPoint.style.cssText = `
            position: absolute; bottom: -6px; left: 50%; transform: translateX(-50%);
            width: 12px; height: 12px; border-radius: 50%;
            background: ${this.getStateColor(state.state_type)}; border: 2px solid white;
            cursor: crosshair; z-index: 20;
        `;
        outPoint.dataset.stateId = state.id;
        outPoint.dataset.direction = 'out';
        node.appendChild(outPoint);

        // Connection point (top – inbound)
        const inPoint = document.createElement('div');
        inPoint.className = 'workflow-connection-point workflow-connection-in';
        inPoint.style.cssText = `
            position: absolute; top: -6px; left: 50%; transform: translateX(-50%);
            width: 12px; height: 12px; border-radius: 50%;
            background: ${this.getStateColor(state.state_type)}; border: 2px solid white;
            cursor: crosshair; z-index: 20;
        `;
        inPoint.dataset.stateId = state.id;
        inPoint.dataset.direction = 'in';
        node.appendChild(inPoint);

        // Event listeners
        node.addEventListener('mousedown', (e) => this.startDrag(e, state));
        node.addEventListener('click', (e) => {
            e.stopPropagation();
            this.selectState(state);
        });

        outPoint.addEventListener('mousedown', (e) => {
            e.stopPropagation();
            this.startConnection(e, state.id);
        });

        return node;
    }

    // ── Edge Rendering ──────────────────────────────────────────────────

    renderEdges() {
        const svg = this.svgTarget;
        // Clear existing edges but keep defs
        svg.querySelectorAll('line, path, text.edge-label').forEach(el => el.remove());

        if (!this.definition || !this.definition.workflow_transitions) return;

        // Add arrowhead marker if not present
        if (!svg.querySelector('defs')) {
            const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
            const marker = document.createElementNS('http://www.w3.org/2000/svg', 'marker');
            marker.setAttribute('id', 'arrowhead');
            marker.setAttribute('markerWidth', '10');
            marker.setAttribute('markerHeight', '7');
            marker.setAttribute('refX', '10');
            marker.setAttribute('refY', '3.5');
            marker.setAttribute('orient', 'auto');
            const polygon = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
            polygon.setAttribute('points', '0 0, 10 3.5, 0 7');
            polygon.setAttribute('fill', '#666');
            marker.appendChild(polygon);
            defs.appendChild(marker);
            svg.appendChild(defs);
        }

        this.definition.workflow_transitions.forEach(transition => {
            this.renderEdge(svg, transition);
        });
    }

    renderEdge(svg, transition) {
        const fromNode = this.nodesContainerTarget.querySelector(`[data-state-id="${transition.from_state_id}"]`);
        const toNode = this.nodesContainerTarget.querySelector(`[data-state-id="${transition.to_state_id}"]`);

        if (!fromNode || !toNode) return;

        const fromRect = fromNode.getBoundingClientRect();
        const toRect = toNode.getBoundingClientRect();
        const canvasRect = this.canvasTarget.getBoundingClientRect();

        const x1 = fromRect.left - canvasRect.left + fromRect.width / 2;
        const y1 = fromRect.top - canvasRect.top + fromRect.height;
        const x2 = toRect.left - canvasRect.left + toRect.width / 2;
        const y2 = toRect.top - canvasRect.top;

        // Cubic Bézier curve
        const midY = (y1 + y2) / 2;
        const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        path.setAttribute('d', `M ${x1} ${y1} C ${x1} ${midY}, ${x2} ${midY}, ${x2} ${y2}`);
        path.setAttribute('stroke', transition.is_automatic ? '#e67e22' : '#666');
        path.setAttribute('stroke-width', this.selectedElement?.id === transition.id ? '3' : '2');
        path.setAttribute('fill', 'none');
        path.setAttribute('marker-end', 'url(#arrowhead)');
        path.style.cursor = 'pointer';
        path.dataset.transitionId = transition.id;

        path.addEventListener('click', (e) => {
            e.stopPropagation();
            this.selectTransition(transition);
        });

        svg.appendChild(path);

        // Transition label
        const labelX = (x1 + x2) / 2;
        const labelY = midY - 8;
        const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        label.setAttribute('x', labelX);
        label.setAttribute('y', labelY);
        label.setAttribute('text-anchor', 'middle');
        label.setAttribute('fill', '#666');
        label.setAttribute('font-size', '11');
        label.classList.add('edge-label');
        label.textContent = transition.label || transition.name;
        label.style.cursor = 'pointer';
        label.addEventListener('click', (e) => {
            e.stopPropagation();
            this.selectTransition(transition);
        });
        svg.appendChild(label);
    }

    getStateColor(stateType) {
        const colors = {
            initial: '#28a745',
            intermediate: '#007bff',
            approval: '#ffc107',
            terminal: '#dc3545',
        };
        return colors[stateType] || '#6c757d';
    }

    // ── Drag Functionality ──────────────────────────────────────────────

    startDrag(e, state) {
        if (e.target.classList.contains('workflow-connection-point')) return;
        this.isDragging = true;
        this.dragState = state;
        const node = e.currentTarget;
        this.dragOffset = {
            x: e.clientX - node.offsetLeft,
            y: e.clientY - node.offsetTop,
        };
        node.style.cursor = 'grabbing';
        node.style.zIndex = 100;

        this._dragMove = (ev) => this.onDrag(ev, node, state);
        this._dragEnd = () => this.endDrag(node, state);
        document.addEventListener('mousemove', this._dragMove);
        document.addEventListener('mouseup', this._dragEnd);
    }

    onDrag(e, node) {
        if (!this.isDragging) return;
        const x = e.clientX - this.dragOffset.x;
        const y = e.clientY - this.dragOffset.y;
        node.style.left = `${Math.max(0, x)}px`;
        node.style.top = `${Math.max(0, y)}px`;
        this.renderEdges();
    }

    endDrag(node, state) {
        this.isDragging = false;
        node.style.cursor = 'grab';
        node.style.zIndex = 10;
        document.removeEventListener('mousemove', this._dragMove);
        document.removeEventListener('mouseup', this._dragEnd);

        state.position_x = parseInt(node.style.left);
        state.position_y = parseInt(node.style.top);
        this.saveStatePosition(state);
    }

    async saveStatePosition(state) {
        try {
            await fetch(`${this.apiUrlValue}/states/${state.id}.json`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.getCsrfToken() },
                body: JSON.stringify({ position_x: state.position_x, position_y: state.position_y }),
            });
        } catch (error) {
            console.error('Failed to save state position:', error);
        }
    }

    // ── Connection Drawing ──────────────────────────────────────────────

    startConnection(e, fromStateId) {
        e.preventDefault();
        this.connectionFrom = fromStateId;

        this.nodesContainerTarget.querySelectorAll('.workflow-connection-in').forEach(point => {
            point.addEventListener('click', this._connectHandler = (ev) => {
                ev.stopPropagation();
                const toStateId = parseInt(ev.target.dataset.stateId);
                if (toStateId !== fromStateId) {
                    this.createTransition(fromStateId, toStateId);
                }
                this.endConnection();
            }, { once: true });
        });

        this.canvasTarget.addEventListener('click', this._cancelConnection = () => {
            this.endConnection();
        }, { once: true });
    }

    endConnection() {
        this.connectionFrom = null;
        this.nodesContainerTarget.querySelectorAll('.workflow-connection-in').forEach(point => {
            point.removeEventListener('click', this._connectHandler);
        });
    }

    // ── Selection ───────────────────────────────────────────────────────

    selectState(state) {
        this.selectedElement = { type: 'state', ...state };
        this.renderPropertyPanel();
        this.highlightSelected();
    }

    selectTransition(transition) {
        this.selectedElement = { type: 'transition', ...transition };
        this.renderPropertyPanel();
        this.highlightSelected();
    }

    highlightSelected() {
        this.nodesContainerTarget.querySelectorAll('.workflow-node').forEach(n => {
            n.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
        });

        if (this.selectedElement?.type === 'state') {
            const node = this.nodesContainerTarget.querySelector(`[data-state-id="${this.selectedElement.id}"]`);
            if (node) node.style.boxShadow = '0 0 0 3px #007bff, 0 2px 8px rgba(0,0,0,0.2)';
        }

        this.renderEdges();
    }

    // ── Property Panel ──────────────────────────────────────────────────

    renderPropertyPanel() {
        const panel = this.propertyPanelTarget;
        if (!this.selectedElement) {
            panel.innerHTML = '<div class="card"><div class="card-header">Properties</div><div class="card-body"><p class="text-muted">Select a state or transition.</p></div></div>';
            return;
        }

        if (this.selectedElement.type === 'state') {
            this.renderStateProperties(panel);
        } else if (this.selectedElement.type === 'transition') {
            this.renderTransitionProperties(panel);
        }
    }

    renderStateProperties(panel) {
        const s = this.selectedElement;
        const safeJson = (val) => {
            try {
                const parsed = typeof val === 'string' ? JSON.parse(val) : (val || {});
                return JSON.stringify(parsed, null, 2);
            } catch { return JSON.stringify({}, null, 2); }
        };
        const safeJsonArr = (val) => {
            try {
                const parsed = typeof val === 'string' ? JSON.parse(val) : (val || []);
                return JSON.stringify(parsed, null, 2);
            } catch { return JSON.stringify([], null, 2); }
        };

        panel.innerHTML = `
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>State Properties</span>
                    <button class="btn btn-sm btn-outline-danger" data-action="click->workflow-editor#deleteSelectedState">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small">Name</label>
                        <input type="text" class="form-control form-control-sm" value="${this.escAttr(s.name)}" data-field="name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Label</label>
                        <input type="text" class="form-control form-control-sm" value="${this.escAttr(s.label)}" data-field="label">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Slug</label>
                        <input type="text" class="form-control form-control-sm" value="${this.escAttr(s.slug)}" data-field="slug">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Type</label>
                        <select class="form-select form-select-sm" data-field="state_type">
                            <option value="initial" ${s.state_type === 'initial' ? 'selected' : ''}>Initial</option>
                            <option value="intermediate" ${s.state_type === 'intermediate' ? 'selected' : ''}>Intermediate</option>
                            <option value="approval" ${s.state_type === 'approval' ? 'selected' : ''}>Approval</option>
                            <option value="terminal" ${s.state_type === 'terminal' ? 'selected' : ''}>Terminal</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Status Category</label>
                        <input type="text" class="form-control form-control-sm" value="${this.escAttr(s.status_category)}" data-field="status_category">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Description</label>
                        <textarea class="form-control form-control-sm" rows="2" data-field="description">${this.escHtml(s.description)}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Metadata (JSON)</label>
                        <textarea class="form-control form-control-sm" rows="4" data-field="metadata">${safeJson(s.metadata)}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">On Enter Actions (JSON)</label>
                        <textarea class="form-control form-control-sm" rows="3" data-field="on_enter_actions">${safeJsonArr(s.on_enter_actions)}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">On Exit Actions (JSON)</label>
                        <textarea class="form-control form-control-sm" rows="3" data-field="on_exit_actions">${safeJsonArr(s.on_exit_actions)}</textarea>
                    </div>
                    ${s.state_type === 'approval' ? this.renderApprovalGateConfig(s) : ''}
                    <button class="btn btn-primary btn-sm w-100" data-action="click->workflow-editor#saveSelectedState">
                        Save State
                    </button>
                </div>
            </div>
        `;
    }

    renderApprovalGateConfig(state) {
        const gate = state._approval_gate || {};
        const tc = gate.threshold_config || {};
        const tcType = tc.type || 'fixed';
        const transitions = this.definition?.transitions || [];
        const fromThis = transitions.filter(t => String(t.from_state_id) === String(state.id));

        const transitionOptions = (selectedId) => {
            let opts = `<option value="">(None)</option>`;
            fromThis.forEach(t => {
                const sel = String(t.id) === String(selectedId) ? 'selected' : '';
                opts += `<option value="${t.id}" ${sel}>${this.escHtml(t.name || t.slug)}</option>`;
            });
            return opts;
        };

        return `
            <hr class="my-3">
            <h6 class="text-warning"><i class="bi bi-shield-check"></i> Approval Gate</h6>
            <div class="mb-2">
                <label class="form-label small">Approval Type</label>
                <select class="form-select form-select-sm" data-gate-field="approval_type">
                    <option value="threshold" ${gate.approval_type === 'threshold' ? 'selected' : ''}>Threshold (N approvers)</option>
                    <option value="unanimous" ${gate.approval_type === 'unanimous' ? 'selected' : ''}>Unanimous</option>
                    <option value="any_one" ${gate.approval_type === 'any_one' ? 'selected' : ''}>Any One</option>
                    <option value="chain" ${gate.approval_type === 'chain' ? 'selected' : ''}>Sequential Chain</option>
                </select>
            </div>
            <div class="mb-2">
                <label class="form-label small">Threshold Source</label>
                <select class="form-select form-select-sm" data-gate-field="threshold_type" data-action="change->workflow-editor#onThresholdTypeChange">
                    <option value="fixed" ${tcType === 'fixed' ? 'selected' : ''}>Fixed Value</option>
                    <option value="app_setting" ${tcType === 'app_setting' ? 'selected' : ''}>App Setting</option>
                    <option value="entity_field" ${tcType === 'entity_field' ? 'selected' : ''}>Entity Field</option>
                </select>
            </div>
            <div class="mb-2 threshold-fixed" style="display:${tcType === 'fixed' ? 'block' : 'none'}">
                <label class="form-label small">Required Count</label>
                <input type="number" class="form-control form-control-sm" data-gate-field="threshold_value" value="${tc.value || gate.required_count || 1}" min="1">
            </div>
            <div class="mb-2 threshold-app-setting" style="display:${tcType === 'app_setting' ? 'block' : 'none'}">
                <label class="form-label small">App Setting Key</label>
                <input type="text" class="form-control form-control-sm" data-gate-field="threshold_key" value="${this.escAttr(tc.key || '')}" placeholder="e.g. Warrant.RosterApprovalsRequired">
                <label class="form-label small mt-1">Default</label>
                <input type="number" class="form-control form-control-sm" data-gate-field="threshold_default" value="${tc.default || 1}" min="1">
            </div>
            <div class="mb-2 threshold-entity-field" style="display:${tcType === 'entity_field' ? 'block' : 'none'}">
                <label class="form-label small">Entity Field</label>
                <input type="text" class="form-control form-control-sm" data-gate-field="threshold_field" value="${this.escAttr(tc.field || '')}" placeholder="e.g. num_required_authorizors">
                <label class="form-label small mt-1">Default</label>
                <input type="number" class="form-control form-control-sm" data-gate-field="threshold_entity_default" value="${tc.default || 1}" min="1">
            </div>
            <div class="mb-2">
                <label class="form-label small">Approver Rule (JSON)</label>
                <textarea class="form-control form-control-sm" rows="2" data-gate-field="approver_rule">${JSON.stringify(gate.approver_rule || {}, null, 2)}</textarea>
            </div>
            <div class="mb-2">
                <label class="form-label small">On Satisfied → Transition</label>
                <select class="form-select form-select-sm" data-gate-field="on_satisfied_transition_id">
                    ${transitionOptions(gate.on_satisfied_transition_id)}
                </select>
            </div>
            <div class="mb-2">
                <label class="form-label small">On Denied → Transition</label>
                <select class="form-select form-select-sm" data-gate-field="on_denied_transition_id">
                    ${transitionOptions(gate.on_denied_transition_id)}
                </select>
            </div>
            <div class="mb-2">
                <label class="form-label small">Timeout (hours)</label>
                <input type="number" class="form-control form-control-sm" data-gate-field="timeout_hours" value="${gate.timeout_hours || ''}" placeholder="Optional">
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" data-gate-field="allow_delegation" ${gate.allow_delegation ? 'checked' : ''}>
                <label class="form-check-label small">Allow Delegation</label>
            </div>
            <button class="btn btn-warning btn-sm w-100 mb-2" data-action="click->workflow-editor#saveApprovalGate">
                Save Approval Gate
            </button>
        `;
    }

    onThresholdTypeChange(event) {
        const panel = this.propertyPanelTarget;
        const val = event.target.value;
        panel.querySelector('.threshold-fixed').style.display = val === 'fixed' ? 'block' : 'none';
        panel.querySelector('.threshold-app-setting').style.display = val === 'app_setting' ? 'block' : 'none';
        panel.querySelector('.threshold-entity-field').style.display = val === 'entity_field' ? 'block' : 'none';
    }

    async saveApprovalGate() {
        if (!this.selectedElement || this.selectedElement.type !== 'state') return;
        const state = this.selectedElement;
        const panel = this.propertyPanelTarget;

        const getVal = (field) => {
            const el = panel.querySelector(`[data-gate-field="${field}"]`);
            return el ? (el.type === 'checkbox' ? el.checked : el.value) : null;
        };

        const thresholdType = getVal('threshold_type');
        let thresholdConfig = {};
        if (thresholdType === 'fixed') {
            thresholdConfig = { type: 'fixed', value: parseInt(getVal('threshold_value')) || 1 };
        } else if (thresholdType === 'app_setting') {
            thresholdConfig = { type: 'app_setting', key: getVal('threshold_key'), default: parseInt(getVal('threshold_default')) || 1 };
        } else if (thresholdType === 'entity_field') {
            thresholdConfig = { type: 'entity_field', field: getVal('threshold_field'), default: parseInt(getVal('threshold_entity_default')) || 1 };
        }

        let approverRule = {};
        try { approverRule = JSON.parse(getVal('approver_rule')); } catch {}

        const gateData = {
            workflow_state_id: state.id,
            approval_type: getVal('approval_type') || 'threshold',
            required_count: thresholdConfig.value || thresholdConfig.default || 1,
            threshold_config: JSON.stringify(thresholdConfig),
            approver_rule: JSON.stringify(approverRule),
            on_satisfied_transition_id: getVal('on_satisfied_transition_id') || null,
            on_denied_transition_id: getVal('on_denied_transition_id') || null,
            timeout_hours: getVal('timeout_hours') ? parseInt(getVal('timeout_hours')) : null,
            allow_delegation: getVal('allow_delegation') ? 1 : 0,
        };

        const existingGate = state._approval_gate;
        const url = existingGate?.id
            ? `${this.apiUrlValue}/gate/${existingGate.id}.json`
            : `${this.apiUrlValue}/gate.json`;
        const method = existingGate?.id ? 'PUT' : 'POST';

        try {
            const resp = await fetch(url, {
                method,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.getCsrfToken() },
                body: JSON.stringify(gateData),
            });
            const data = await resp.json();
            if (data.success) {
                state._approval_gate = data.gate || { ...gateData, id: data.id };
                this.renderPropertyPanel();
            } else {
                alert('Failed to save gate: ' + (data.message || 'Unknown error'));
            }
        } catch (err) {
            alert('Error saving gate: ' + err.message);
        }
    }

    renderTransitionProperties(panel) {
        const t = this.selectedElement;
        const safeJson = (val) => {
            try {
                const parsed = typeof val === 'string' ? JSON.parse(val) : (val || {});
                return JSON.stringify(parsed, null, 2);
            } catch { return JSON.stringify({}, null, 2); }
        };
        const safeJsonArr = (val) => {
            try {
                const parsed = typeof val === 'string' ? JSON.parse(val) : (val || []);
                return JSON.stringify(parsed, null, 2);
            } catch { return JSON.stringify([], null, 2); }
        };

        panel.innerHTML = `
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Transition Properties</span>
                    <button class="btn btn-sm btn-outline-danger" data-action="click->workflow-editor#deleteSelectedTransition">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label small">Name</label>
                        <input type="text" class="form-control form-control-sm" value="${this.escAttr(t.name)}" data-field="name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Label</label>
                        <input type="text" class="form-control form-control-sm" value="${this.escAttr(t.label)}" data-field="label">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Slug</label>
                        <input type="text" class="form-control form-control-sm" value="${this.escAttr(t.slug)}" data-field="slug">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Trigger Type</label>
                        <select class="form-select form-select-sm" data-field="trigger_type">
                            <option value="manual" ${t.trigger_type === 'manual' ? 'selected' : ''}>Manual</option>
                            <option value="automatic" ${t.trigger_type === 'automatic' ? 'selected' : ''}>Automatic</option>
                            <option value="scheduled" ${t.trigger_type === 'scheduled' ? 'selected' : ''}>Scheduled</option>
                            <option value="event" ${t.trigger_type === 'event' ? 'selected' : ''}>Event</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Priority</label>
                        <input type="number" class="form-control form-control-sm" value="${t.priority || 0}" data-field="priority">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Conditions (JSON)</label>
                        <textarea class="form-control form-control-sm" rows="4" data-field="conditions">${safeJson(t.conditions)}</textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Actions (JSON)</label>
                        <textarea class="form-control form-control-sm" rows="4" data-field="actions">${safeJsonArr(t.actions)}</textarea>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" ${t.is_automatic ? 'checked' : ''} data-field="is_automatic">
                        <label class="form-check-label small">Automatic</label>
                    </div>
                    <button class="btn btn-primary btn-sm w-100" data-action="click->workflow-editor#saveSelectedTransition">
                        Save Transition
                    </button>
                </div>
            </div>
        `;
    }

    // ── CRUD Operations ─────────────────────────────────────────────────

    async addState() {
        const name = prompt('State name:');
        if (!name) return;

        const slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '-');

        try {
            const response = await fetch(`${this.apiUrlValue}/states.json`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.getCsrfToken() },
                body: JSON.stringify({
                    workflow_definition_id: this.definitionIdValue,
                    name: name,
                    slug: slug,
                    label: name,
                    state_type: 'intermediate',
                    position_x: 200,
                    position_y: 200,
                }),
            });
            const data = await response.json();
            if (data.success) {
                this.definition.workflow_states.push(data.state);
                this.render();
            } else {
                alert('Failed to create state: ' + JSON.stringify(data.errors));
            }
        } catch (error) {
            console.error('Failed to create state:', error);
        }
    }

    async createTransition(fromStateId, toStateId) {
        const name = prompt('Transition name:');
        if (!name) return;

        const slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '-');

        try {
            const response = await fetch(`${this.apiUrlValue}/transitions.json`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.getCsrfToken() },
                body: JSON.stringify({
                    workflow_definition_id: this.definitionIdValue,
                    from_state_id: fromStateId,
                    to_state_id: toStateId,
                    name: name,
                    slug: slug,
                    label: name,
                    trigger_type: 'manual',
                    priority: 0,
                }),
            });
            const data = await response.json();
            if (data.success) {
                this.definition.workflow_transitions.push(data.transition);
                this.renderEdges();
            }
        } catch (error) {
            console.error('Failed to create transition:', error);
        }
    }

    async saveSelectedState() {
        if (!this.selectedElement || this.selectedElement.type !== 'state') return;

        const panel = this.propertyPanelTarget;
        const data = {};
        panel.querySelectorAll('[data-field]').forEach(el => {
            const field = el.dataset.field;
            if (el.type === 'checkbox') {
                data[field] = el.checked;
            } else if (['metadata', 'on_enter_actions', 'on_exit_actions'].includes(field)) {
                try { data[field] = JSON.parse(el.value); } catch { data[field] = el.value; }
            } else {
                data[field] = el.value;
            }
        });

        try {
            const response = await fetch(`${this.apiUrlValue}/states/${this.selectedElement.id}.json`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.getCsrfToken() },
                body: JSON.stringify(data),
            });
            const result = await response.json();
            if (result.success) {
                const idx = this.definition.workflow_states.findIndex(s => s.id === this.selectedElement.id);
                if (idx >= 0) {
                    Object.assign(this.definition.workflow_states[idx], data);
                }
                this.render();
            }
        } catch (error) {
            console.error('Failed to save state:', error);
        }
    }

    async saveSelectedTransition() {
        if (!this.selectedElement || this.selectedElement.type !== 'transition') return;

        const panel = this.propertyPanelTarget;
        const data = {};
        panel.querySelectorAll('[data-field]').forEach(el => {
            const field = el.dataset.field;
            if (el.type === 'checkbox') {
                data[field] = el.checked;
            } else if (['conditions', 'actions'].includes(field)) {
                try { data[field] = JSON.parse(el.value); } catch { data[field] = el.value; }
            } else if (field === 'priority') {
                data[field] = parseInt(el.value) || 0;
            } else {
                data[field] = el.value;
            }
        });

        try {
            const response = await fetch(`${this.apiUrlValue}/transitions/${this.selectedElement.id}.json`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.getCsrfToken() },
                body: JSON.stringify(data),
            });
            const result = await response.json();
            if (result.success) {
                const idx = this.definition.workflow_transitions.findIndex(t => t.id === this.selectedElement.id);
                if (idx >= 0) {
                    Object.assign(this.definition.workflow_transitions[idx], data);
                }
                this.renderEdges();
            }
        } catch (error) {
            console.error('Failed to save transition:', error);
        }
    }

    async deleteSelectedState() {
        if (!this.selectedElement || this.selectedElement.type !== 'state') return;
        if (!confirm(`Delete state "${this.selectedElement.name}"?`)) return;

        try {
            const response = await fetch(`${this.apiUrlValue}/states/${this.selectedElement.id}.json`, {
                method: 'DELETE',
                headers: { 'X-CSRF-Token': this.getCsrfToken() },
            });
            const result = await response.json();
            if (result.success) {
                this.definition.workflow_states = this.definition.workflow_states.filter(s => s.id !== this.selectedElement.id);
                // Also remove transitions that reference this state
                this.definition.workflow_transitions = this.definition.workflow_transitions.filter(
                    t => t.from_state_id !== this.selectedElement.id && t.to_state_id !== this.selectedElement.id
                );
                this.selectedElement = null;
                this.render();
                this.renderPropertyPanel();
            } else {
                alert(result.error || 'Failed to delete state');
            }
        } catch (error) {
            console.error('Failed to delete state:', error);
        }
    }

    async deleteSelectedTransition() {
        if (!this.selectedElement || this.selectedElement.type !== 'transition') return;
        if (!confirm(`Delete transition "${this.selectedElement.name}"?`)) return;

        try {
            const response = await fetch(`${this.apiUrlValue}/transitions/${this.selectedElement.id}.json`, {
                method: 'DELETE',
                headers: { 'X-CSRF-Token': this.getCsrfToken() },
            });
            const result = await response.json();
            if (result.success) {
                this.definition.workflow_transitions = this.definition.workflow_transitions.filter(t => t.id !== this.selectedElement.id);
                this.selectedElement = null;
                this.renderEdges();
                this.renderPropertyPanel();
            }
        } catch (error) {
            console.error('Failed to delete transition:', error);
        }
    }

    // ── Toolbar Actions ─────────────────────────────────────────────────

    async save() {
        for (const state of this.definition.workflow_states) {
            await this.saveStatePosition(state);
        }
        alert('Workflow saved!');
    }

    async validate() {
        try {
            const response = await fetch(`${this.apiUrlValue}/definition/${this.definitionIdValue}/validate.json`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.getCsrfToken() },
            });
            const data = await response.json();

            let message = data.isValid ? '✅ Workflow is valid!\n' : '❌ Workflow has errors:\n';
            if (data.errors?.length) {
                message += '\nErrors:\n' + data.errors.map(e => `  • ${e}`).join('\n');
            }
            if (data.warnings?.length) {
                message += '\n\nWarnings:\n' + data.warnings.map(w => `  ⚠ ${w}`).join('\n');
            }
            alert(message);
        } catch (error) {
            console.error('Validation failed:', error);
        }
    }

    async publish() {
        if (!confirm('Publish this workflow? This will increment the version and make it active.')) return;

        try {
            const response = await fetch(`${this.apiUrlValue}/definition/${this.definitionIdValue}/publish.json`, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.getCsrfToken() },
            });
            const data = await response.json();
            if (data.success) {
                alert(`Published! Version: ${data.definition.version}`);
            }
        } catch (error) {
            console.error('Publish failed:', error);
        }
    }

    async exportJson() {
        try {
            const response = await fetch(`${this.apiUrlValue}/definition/${this.definitionIdValue}/export.json`);
            const data = await response.json();

            const blob = new Blob([JSON.stringify(data.export, null, 2)], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `workflow-${this.definition.slug || 'export'}.json`;
            a.click();
            URL.revokeObjectURL(url);
        } catch (error) {
            console.error('Export failed:', error);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    getCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    escAttr(val) {
        return (val || '').toString().replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    escHtml(val) {
        return (val || '').toString().replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    disconnect() {
        if (this._dragMove) document.removeEventListener('mousemove', this._dragMove);
        if (this._dragEnd) document.removeEventListener('mouseup', this._dragEnd);
    }
}

// Add to global controllers registry
if (!window.Controllers) {
    window.Controllers = {};
}
window.Controllers["workflow-editor"] = WorkflowEditorController;
