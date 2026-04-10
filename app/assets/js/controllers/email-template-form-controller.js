import { Controller } from "@hotwired/stimulus"

/**
 * Email Template Form Controller
 *
 * Manages the dynamic behaviour of the email template form:
 * - Auto-generates slug from Name if slug is empty
 * - Parses {{variable}} placeholders from template content and surfaces them
 *   in the Variables Contract tab as an authoring aid
 */
class EmailTemplateFormController extends Controller {
    static targets = [
        "availableVars", "subjectTemplate",
        "nameField", "slugField",
        "htmlTemplate", "textTemplate",
        "parsedVarsPanel", "parsedVarsList",
    ]

    connect() {
        // Parse placeholders from existing template content on connect
        this._refreshParsedPlaceholders()
    }

    // ── Slug auto-generation ────────────────────────────────────────────────

    /**
     * Called on name field input. Auto-fills slug if slug is currently empty.
     */
    nameChanged() {
        if (!this.hasSlugFieldTarget || !this.hasNameFieldTarget) return

        const slug = this.slugFieldTarget.value.trim()
        if (slug !== '') return  // Don't overwrite a manually-entered slug

        this.slugFieldTarget.value = this._slugify(this.nameFieldTarget.value)
    }

    /**
     * Convert an arbitrary string to a valid slug (lowercase, hyphens).
     * @param {string} text
     * @returns {string}
     */
    _slugify(text) {
        return text
            .toLowerCase()
            .trim()
            .replace(/[^a-z0-9\s-]/g, '')   // strip non-alphanum
            .replace(/[\s_-]+/g, '-')         // spaces/underscores → hyphen
            .replace(/^-+|-+$/g, '')          // trim leading/trailing hyphens
    }

    // ── Template placeholder parsing ────────────────────────────────────────

    /**
     * Called when html_template or text_template content changes.
     * Refreshes the parsed-placeholders helper in the Variables Contract tab.
     */
    templateChanged() {
        this._refreshParsedPlaceholders()
    }

    /**
     * Parse {{varName}} placeholders from the HTML and text template fields
     * and display them as a helper in the Variables Contract tab.
     */
    _refreshParsedPlaceholders() {
        if (!this.hasParsedVarsPanelTarget || !this.hasParsedVarsListTarget) return

        const htmlContent = this.hasHtmlTemplateTarget ? this.htmlTemplateTarget.value : ''
        const textContent = this.hasTextTemplateTarget ? this.textTemplateTarget.value : ''
        const combined = htmlContent + ' ' + textContent

        const names = this._extractPlaceholders(combined)

        if (names.length === 0) {
            this.parsedVarsPanelTarget.style.display = 'none'
            return
        }

        this.parsedVarsPanelTarget.style.display = ''
        this.parsedVarsListTarget.innerHTML = ''

        names.forEach(name => {
            const badge = document.createElement('code')
            badge.className = 'badge bg-secondary me-1 mb-1'
            badge.style.fontSize = '0.85em'
            badge.textContent = '{{' + name + '}}'
            this.parsedVarsListTarget.appendChild(badge)
        })
    }

    /**
     * Extract unique placeholder names from a template string.
     * Matches {{varName}} and {{#if varName}} / {{/if}} / {{else}} patterns.
     * Returns names sorted alphabetically, excluding control keywords.
     *
     * @param {string} content
     * @returns {string[]}
     */
    _extractPlaceholders(content) {
        const CONTROL_KEYWORDS = new Set(['if', 'else', '/if', '#if'])
        const pattern = /\{\{\s*([a-zA-Z_][a-zA-Z0-9_.]*)\s*\}\}/g
        const seen = new Set()
        let match

        while ((match = pattern.exec(content)) !== null) {
            const name = match[1].trim()
            if (!CONTROL_KEYWORDS.has(name)) {
                seen.add(name)
            }
        }

        return Array.from(seen).sort()
    }
}

// Add to global controllers registry
if (!window.Controllers) {
    window.Controllers = {};
}
window.Controllers["email-template-form"] = EmailTemplateFormController;
