import { Controller } from "@hotwired/stimulus"

/**
 * WorkflowToolbarController
 *
 * Manages toolbar actions: save, publish, undo/redo, zoom, keyboard shortcuts,
 * validation display, node deletion, and panel toggles.
 * Communicates with the main designer controller via Stimulus outlet.
 */
class WorkflowToolbarController extends Controller {
    static targets = ["saveBtn", "publishBtn", "zoomLevel"]

    static outlets = ["workflow-designer"]

    static values = {
        saveUrl: String,
        publishUrl: String,
        csrfToken: String,
    }

    _zoom = 1

    connect() {
        this._bindKeyboardShortcuts()
    }

    disconnect() {
        this._unbindKeyboardShortcuts()
    }

    // --- Helpers to access designer state ---

    get _designer() {
        return this.hasWorkflowDesignerOutlet ? this.workflowDesignerOutlet : null
    }

    get _editor() {
        return this._designer?.editor
    }

    // --- Save / Publish ---

    async save(event) {
        if (event) event.preventDefault()
        const designer = this._designer
        if (!designer) return false

        const { definition, canvasLayout } = designer.exportWorkflow()
        this._setBtnLoading(this.hasSaveBtnTarget ? this.saveBtnTarget : null, true)

        try {
            const response = await fetch(this.saveUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfTokenValue,
                },
                body: JSON.stringify({
                    workflowId: designer.workflowIdValue || null,
                    versionId: designer.versionIdValue || null,
                    definition,
                    canvasLayout,
                }),
            })

            const result = await response.json()
            if (response.ok && result.success) {
                if (result.workflowId) designer.workflowIdValue = result.workflowId
                if (result.versionId) designer.versionIdValue = result.versionId
                designer.markSaved()
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

        const designer = this._designer
        if (!designer) return

        this._setBtnLoading(this.hasPublishBtnTarget ? this.publishBtnTarget : null, true)

        try {
            const response = await fetch(this.publishUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfTokenValue,
                },
                body: JSON.stringify({ versionId: designer.versionIdValue }),
            })

            const result = await response.json()
            if (response.ok && result.success) {
                this.showFlash('Workflow published as v' + (result.versionNumber || ''), 'success')
                setTimeout(() => window.location.reload(), 1000)
            } else {
                const reason = result.reason || 'Failed to publish workflow'
                if (reason.startsWith('Definition validation failed:')) {
                    const errorText = reason.replace('Definition validation failed: ', '')
                    const errors = errorText.split('; ').filter(e => e.trim())
                    designer.showValidationResults({ valid: false, errors, warnings: [] })
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

    undo() {
        const designer = this._designer
        if (designer) designer.historyManager.undo(designer.editor)
    }

    redo() {
        const designer = this._designer
        if (designer) designer.historyManager.redo(designer.editor)
    }

    // --- Zoom ---

    zoomIn() { this._setZoom(Math.min(this._zoom + 0.1, 2)) }
    zoomOut() { this._setZoom(Math.max(this._zoom - 0.1, 0.3)) }
    zoomReset() { this._setZoom(1) }

    _setZoom(level) {
        this._zoom = Math.round(level * 100) / 100
        const editor = this._editor
        if (editor) {
            editor.zoom = this._zoom
            editor.zoom_refresh()
        }
        if (this.hasZoomLevelTarget) {
            this.zoomLevelTarget.textContent = `${Math.round(this._zoom * 100)}%`
        }
    }

    // --- Keyboard Shortcuts ---

    _bindKeyboardShortcuts() {
        this._keyHandler = (event) => this._handleKeydown(event)
        this._keyUpHandler = (event) => {
            if (event.key === 'Shift' && this._designer) {
                this._designer._shiftHeld = false
            }
        }
        this._keyDownShiftHandler = (event) => {
            if (event.key === 'Shift' && this._designer) {
                this._designer._shiftHeld = true
            }
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
            const designer = this._designer
            if (designer && designer.selectedNodes.size > 0) {
                event.preventDefault()
                this.deleteSelectedNodes()
            }
        }
    }

    deleteSelectedNodes() {
        const designer = this._designer
        if (!designer || designer.readonlyValue) return
        designer.selectedNodes.forEach(nodeId => {
            try { designer.editor.removeNodeId(`node-${nodeId}`) } catch (_) { /* node may already be gone */ }
        })
        designer.clearMultiSelect()
    }

    // --- Validation ---

    validateWorkflow() {
        const designer = this._designer
        if (!designer) return
        const result = designer.validationService.validate(designer.editor.export())
        designer.showValidationResults(result)
        return result
    }

    // --- Panel Toggles ---

    togglePalette() {
        const designer = this._designer
        if (designer?.hasNodePaletteTarget) {
            designer.nodePaletteTarget.classList.toggle('wf-panel-collapsed')
        }
    }

    toggleConfig() {
        const designer = this._designer
        if (designer?.hasNodeConfigTarget) {
            designer.nodeConfigTarget.classList.toggle('wf-panel-collapsed')
        }
    }
}

if (!window.Controllers) {
    window.Controllers = {};
}
window.Controllers["workflow-toolbar"] = WorkflowToolbarController;
