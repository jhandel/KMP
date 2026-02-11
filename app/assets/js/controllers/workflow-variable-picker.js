/**
 * WorkflowVariablePicker
 *
 * Builds context-variable lists from upstream nodes and renders
 * a searchable dropdown for inserting variable references into form fields.
 */
export default class WorkflowVariablePicker {
    /**
     * @param {object} registryData - { triggers, actions, conditions, entities }
     */
    constructor(registryData) {
        this.registryData = registryData
    }

    /**
     * Get upstream nodes by traversing connections backward from nodeId.
     */
    getUpstreamNodes(nodeId, editor) {
        const drawflowData = editor.export()
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
     * Collect output schema variables from a single node.
     */
    getNodeOutputSchema(node) {
        const type = node.data?.type
        const config = node.data?.config || {}
        const vars = []

        if (type === 'trigger') {
            const trigger = this.registryData.triggers?.find(t => t.event === config.event)
            if (trigger?.payloadSchema) {
                const mapping = config.inputMapping
                if (mapping && typeof mapping === 'object') {
                    for (const [key, sourcePath] of Object.entries(mapping)) {
                        const payloadField = trigger.payloadSchema[key] || {}
                        vars.push({
                            path: `$.trigger.${key}`,
                            label: `Trigger: ${payloadField.label || key}`,
                            type: payloadField.type || 'string'
                        })
                    }
                } else {
                    for (const [key, meta] of Object.entries(trigger.payloadSchema)) {
                        vars.push({
                            path: `$.trigger.${key}`,
                            label: `Trigger: ${meta.label || key}`,
                            type: meta.type || 'string'
                        })
                    }
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
                    vars.push({ path: `$.nodes.${nodeKey}.result.${key}`, label: `${action.label}: ${key}`, type: meta.type || 'string' })
                }
            } else {
                vars.push({ path: `$.nodes.${nodeKey}.result`, label: `${node.name}: result`, type: 'mixed' })
            }
        } else if (type === 'approval') {
            const nodeKey = node.data?.nodeKey || node.name
            const schema = this.registryData?.approvalOutputSchema
            if (schema) {
                for (const [key, meta] of Object.entries(schema)) {
                    vars.push({
                        path: `$.nodes.${nodeKey}.${key}`,
                        label: `${node.name}: ${meta.label || key}`,
                        type: meta.type || 'string'
                    })
                }
            } else {
                vars.push({ path: `$.nodes.${nodeKey}.status`, label: `${node.name}: status`, type: 'string' })
                vars.push({ path: `$.nodes.${nodeKey}.approvedBy`, label: `${node.name}: approvedBy`, type: 'array' })
                vars.push({ path: `$.nodes.${nodeKey}.comment`, label: `${node.name}: comment`, type: 'string' })
            }
        } else if (type === 'condition') {
            const nodeKey = node.data?.nodeKey || node.name
            vars.push({ path: `$.nodes.${nodeKey}.result`, label: `${node.name}: result`, type: 'boolean' })
        }

        return vars
    }

    /**
     * Build a list of available context variables for a given node.
     */
    buildVariableList(nodeId, editor) {
        const upstream = this.getUpstreamNodes(nodeId, editor)
        const variables = []

        const drawflowData = editor.export()
        const moduleData = drawflowData.drawflow?.Home?.data || {}
        for (const node of Object.values(moduleData)) {
            if (node.data?.type === 'trigger') {
                variables.push(...this.getNodeOutputSchema(node))
                break
            }
        }

        upstream.forEach(node => {
            if (node.data?.type !== 'trigger') {
                variables.push(...this.getNodeOutputSchema(node))
            }
        })

        const builtins = this.registryData?.builtinContext
        if (builtins && Array.isArray(builtins)) {
            variables.push(...builtins)
        } else {
            variables.push(
                { path: '$.instance.id', label: 'Instance ID', type: 'integer' },
                { path: '$.instance.created', label: 'Instance Created', type: 'datetime' },
                { path: '$.context', label: 'Full Context', type: 'object' }
            )
        }

        return variables
    }

    /**
     * Attach variable picker buttons to inputs with data-variable-picker="true".
     */
    attachPickers(panel, nodeId, editor) {
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
            btn.setAttribute('aria-label', 'Insert variable reference')
            btn.addEventListener('click', (e) => {
                e.preventDefault()
                this.showDropdown(btn, input, nodeId, editor)
            })
            wrapper.appendChild(btn)
        })
    }

    /**
     * Show the searchable variable dropdown anchored to anchorBtn.
     */
    showDropdown(anchorBtn, targetInput, nodeId, editor) {
        document.querySelectorAll('.wf-var-dropdown').forEach(el => el.remove())

        const variables = this.buildVariableList(nodeId, editor)
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

        const closeHandler = (e) => {
            if (!dropdown.contains(e.target) && e.target !== anchorBtn) {
                dropdown.remove()
                document.removeEventListener('click', closeHandler)
            }
        }
        setTimeout(() => document.addEventListener('click', closeHandler), 0)
        searchInput.focus()
    }
}
