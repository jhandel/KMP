/**
 * WorkflowValidationService
 *
 * Validates workflow graph structure: triggers, end nodes,
 * connectivity, loop config, and disconnected subgraphs.
 */
export default class WorkflowValidationService {
    /**
     * @param {Function} getNodePorts - Returns {inputs, outputs} for a node type
     */
    constructor(getNodePorts) {
        this._getNodePorts = getNodePorts
    }

    /**
     * Validate the exported Drawflow data.
     * @param {object} drawflowExport - Result of editor.export()
     * @returns {{ valid: boolean, errors: string[], warnings: string[] }}
     */
    validate(drawflowExport) {
        const errors = []
        const warnings = []
        const moduleData = drawflowExport.drawflow?.Home?.data || {}
        const nodes = Object.entries(moduleData)

        if (nodes.length === 0) {
            errors.push('Workflow is empty — add at least one node.')
            return { valid: false, errors, warnings }
        }

        this._validateTriggerCount(nodes, errors)
        this._validateEndNodes(nodes, errors)
        this._validateLoopNodes(nodes, errors)
        this._validateConnections(nodes, errors, warnings)
        this._validateReachability(nodes, errors)

        return { valid: errors.length === 0, errors, warnings }
    }

    _validateTriggerCount(nodes, errors) {
        const triggers = nodes.filter(([, n]) => n.data?.type === 'trigger')
        if (triggers.length === 0) {
            errors.push('A workflow must have exactly one trigger node.')
        } else if (triggers.length > 1) {
            errors.push(`Found ${triggers.length} trigger nodes — only one is allowed.`)
        }
    }

    _validateEndNodes(nodes, errors) {
        const endNodes = nodes.filter(([, n]) => n.data?.type === 'end')
        if (endNodes.length === 0) {
            errors.push('At least one End node is required.')
        }
    }

    _validateLoopNodes(nodes, errors) {
        nodes.filter(([, n]) => n.data?.type === 'loop').forEach(([id, n]) => {
            const max = n.data?.config?.maxIterations
            if (!max || parseInt(max, 10) <= 0) {
                errors.push(`Loop node #${id} must have maxIterations set to a positive number.`)
            }
        })
    }

    _validateConnections(nodes, errors, warnings) {
        nodes.forEach(([id, node]) => {
            const type = node.data?.type || 'unknown'
            const { inputs, outputs } = this._getNodePorts(type)

            if (inputs > 0) {
                let hasIncoming = false
                for (const inp of Object.values(node.inputs || {})) {
                    if (inp.connections && inp.connections.length > 0) hasIncoming = true
                }
                if (!hasIncoming) {
                    errors.push(`Node "${node.name}" (#${id}) has no incoming connection.`)
                }
            }

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
    }

    _validateReachability(nodes, errors) {
        const triggers = nodes.filter(([, n]) => n.data?.type === 'trigger')
        if (triggers.length !== 1) return

        const reachable = new Set()
        const adjList = {}

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
}
