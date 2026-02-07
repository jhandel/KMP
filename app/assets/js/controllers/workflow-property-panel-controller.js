import { Controller } from "@hotwired/stimulus";

/**
 * WorkflowPropertyPanelController
 *
 * Standalone property-panel controller for workflow elements. Provides
 * JSON editing helpers, field validation, and expand/collapse behavior
 * for the sidebar property panel used alongside the workflow editor.
 */
class WorkflowPropertyPanelController extends Controller {
    static targets = ["jsonEditor", "panel", "validationMessage"]

    static values = {
        collapsed: { type: Boolean, default: false },
    }

    connect() {
        if (this.collapsedValue) {
            this.collapse();
        }
    }

    // ── Panel Toggle ────────────────────────────────────────────────────

    toggle() {
        this.collapsedValue = !this.collapsedValue;
        if (this.collapsedValue) {
            this.collapse();
        } else {
            this.expand();
        }
    }

    collapse() {
        if (this.hasPanelTarget) {
            this.panelTarget.style.display = 'none';
        }
    }

    expand() {
        if (this.hasPanelTarget) {
            this.panelTarget.style.display = '';
        }
    }

    // ── JSON Validation ─────────────────────────────────────────────────

    /**
     * Validates all JSON editor textareas and shows inline feedback.
     * Attach via data-action="input->workflow-property-panel#validateJson"
     */
    validateJson(event) {
        const textarea = event.target;
        const value = textarea.value.trim();

        if (!value) {
            this.setFieldValid(textarea);
            return;
        }

        try {
            JSON.parse(value);
            this.setFieldValid(textarea);
        } catch (e) {
            this.setFieldInvalid(textarea, e.message);
        }
    }

    /**
     * Formats (pretty-prints) a JSON editor textarea.
     * Attach via data-action="click->workflow-property-panel#formatJson"
     * with data-workflow-property-panel-editor-param targeting the textarea.
     */
    formatJson(event) {
        const editorId = event.params?.editor;
        const textarea = editorId
            ? document.getElementById(editorId)
            : event.target.closest('.mb-3')?.querySelector('textarea');

        if (!textarea) return;

        try {
            const parsed = JSON.parse(textarea.value);
            textarea.value = JSON.stringify(parsed, null, 2);
            this.setFieldValid(textarea);
        } catch (e) {
            this.setFieldInvalid(textarea, e.message);
        }
    }

    /**
     * Validates all JSON textareas in the panel. Returns true if all valid.
     */
    validateAllJson() {
        let allValid = true;
        this.jsonEditorTargets.forEach(textarea => {
            const value = textarea.value.trim();
            if (!value) return;
            try {
                JSON.parse(value);
                this.setFieldValid(textarea);
            } catch (e) {
                this.setFieldInvalid(textarea, e.message);
                allValid = false;
            }
        });
        return allValid;
    }

    // ── Field Helpers ───────────────────────────────────────────────────

    setFieldValid(textarea) {
        textarea.classList.remove('is-invalid');
        textarea.classList.add('is-valid');
        const feedback = textarea.parentElement?.querySelector('.invalid-feedback');
        if (feedback) feedback.remove();
    }

    setFieldInvalid(textarea, message) {
        textarea.classList.remove('is-valid');
        textarea.classList.add('is-invalid');

        let feedback = textarea.parentElement?.querySelector('.invalid-feedback');
        if (!feedback) {
            feedback = document.createElement('div');
            feedback.className = 'invalid-feedback';
            textarea.parentElement?.appendChild(feedback);
        }
        feedback.textContent = `Invalid JSON: ${message}`;
    }

    disconnect() {
        // Cleanup
    }
}

// Add to global controllers registry
if (!window.Controllers) {
    window.Controllers = {};
}
window.Controllers["workflow-property-panel"] = WorkflowPropertyPanelController;
