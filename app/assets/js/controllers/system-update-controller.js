import { Controller } from "@hotwired/stimulus"

class SystemUpdateController extends Controller {
    static targets = [
        "checkBtn", "loading", "error", "versions",
        "progressModal", "progressBar", "progressMessage", "progressResult"
    ]

    static values = {
        checkUrl: String,
        triggerUrl: String,
        statusUrl: String,
        rollbackUrl: String,
        supportsWebUpdate: { type: Boolean, default: false }
    }

    connect() {
        this._pollTimer = null
        this._modal = null
    }

    disconnect() {
        this._stopPolling()
    }

    async checkForUpdates() {
        this.loadingTarget.classList.remove("d-none")
        this.errorTarget.classList.add("d-none")
        this.versionsTarget.innerHTML = ""
        this.checkBtnTarget.disabled = true

        try {
            const response = await fetch(this.checkUrlValue, {
                headers: { "Accept": "application/json" }
            })
            if (!response.ok) throw new Error(`HTTP ${response.status}`)

            const data = await response.json()
            this._renderVersions(data)
        } catch (err) {
            this.errorTarget.textContent = `Failed to check for updates: ${err.message}`
            this.errorTarget.classList.remove("d-none")
        } finally {
            this.loadingTarget.classList.add("d-none")
            this.checkBtnTarget.disabled = false
        }
    }

    async triggerUpdate(event) {
        const tag = event.params.tag
        if (!tag) return

        if (!confirm(`Update to ${tag}?\n\nA backup will be created automatically before the update.`)) {
            return
        }

        this._showProgressModal()
        this._setProgress(5, "Creating pre-update backup...")

        try {
            const response = await fetch(this.triggerUrlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                    "X-CSRF-Token": this._getCsrfToken()
                },
                body: `tag=${encodeURIComponent(tag)}`
            })

            const data = await response.json()

            if (data.status === "error") {
                this._setProgress(0, "")
                this._showResult("danger", `Update failed: ${data.message}`)
                return
            }

            this._setProgress(30, "Update triggered, pulling new image...")
            this._startPolling()
        } catch (err) {
            this._setProgress(0, "")
            this._showResult("danger", `Error: ${err.message}`)
        }
    }

    async rollback(event) {
        const tag = event.params.rollbackTag
        if (!tag) return

        if (!confirm(`Rollback to ${tag}? This will restart the application.`)) {
            return
        }

        this._showProgressModal()
        this._setProgress(10, "Initiating rollback...")

        try {
            const response = await fetch(this.rollbackUrlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                    "X-CSRF-Token": this._getCsrfToken()
                },
                body: `tag=${encodeURIComponent(tag)}`
            })

            const data = await response.json()

            if (data.status === "error") {
                this._setProgress(0, "")
                this._showResult("danger", `Rollback failed: ${data.message}`)
                return
            }

            this._setProgress(50, "Rollback in progress...")
            this._startPolling()
        } catch (err) {
            this._setProgress(0, "")
            this._showResult("danger", `Error: ${err.message}`)
        }
    }

    // Private methods

    _renderVersions(data) {
        const channels = data.channels || {}
        const current = data.current || {}
        const channelOrder = ["release", "beta", "dev", "nightly"]
        const channelColors = {
            release: "success", beta: "warning", dev: "info", nightly: "secondary"
        }

        let html = ""

        for (const channel of channelOrder) {
            const versions = channels[channel]
            if (!versions || versions.length === 0) continue

            const color = channelColors[channel] || "secondary"
            html += `<h6 class="mt-3 mb-2">
                <span class="badge bg-${color}">${channel.charAt(0).toUpperCase() + channel.slice(1)}</span>
                <small class="text-muted ms-1">(${versions.length} versions)</small>
            </h6>`

            // Show top 5 per channel
            const shown = versions.slice(0, 5)
            html += `<div class="list-group list-group-flush">`

            for (const v of shown) {
                const isCurrent = v.isCurrent
                const currentBadge = isCurrent
                    ? `<span class="badge bg-primary ms-2">Current</span>`
                    : ""
                const updateBtn = (!isCurrent && this.supportsWebUpdateValue)
                    ? `<button class="btn btn-sm btn-outline-primary"
                         data-action="click->system-update#triggerUpdate"
                         data-system-update-tag-param="${v.tag}">
                         <i class="bi bi-download"></i> Update
                       </button>`
                    : ""

                const notes = v.releaseNotes
                    ? `<small class="text-muted d-block text-truncate" style="max-width:400px">${this._escapeHtml(v.releaseNotes.substring(0, 120))}</small>`
                    : ""
                const published = v.published
                    ? `<small class="text-muted">${new Date(v.published).toLocaleDateString()}</small>`
                    : ""

                html += `<div class="list-group-item d-flex justify-content-between align-items-center ${isCurrent ? 'list-group-item-light' : ''}">
                    <div>
                        <code>${this._escapeHtml(v.tag)}</code>${currentBadge}
                        ${published}
                        ${notes}
                    </div>
                    ${updateBtn}
                </div>`
            }

            html += `</div>`

            if (versions.length > 5) {
                html += `<small class="text-muted ms-2">...and ${versions.length - 5} more</small>`
            }
        }

        if (!html) {
            html = `<p class="text-muted">No versions found in the container registry.</p>`
        }

        this.versionsTarget.innerHTML = html
    }

    _showProgressModal() {
        if (!this._modal) {
            this._modal = new bootstrap.Modal(this.progressModalTarget)
        }
        this.progressResultTarget.classList.add("d-none")
        this._modal.show()
    }

    _setProgress(pct, message) {
        this.progressBarTarget.style.width = `${pct}%`
        this.progressBarTarget.textContent = `${pct}%`
        if (message) {
            this.progressMessageTarget.textContent = message
        }
    }

    _showResult(type, message) {
        this.progressResultTarget.className = `mt-3 alert alert-${type}`
        this.progressResultTarget.innerHTML = message +
            `<br><button class="btn btn-sm btn-outline-secondary mt-2" onclick="location.reload()">Reload Page</button>`
        this.progressResultTarget.classList.remove("d-none")
        this._stopPolling()
    }

    _startPolling() {
        this._pollTimer = setInterval(() => this._pollStatus(), 2000)
    }

    _stopPolling() {
        if (this._pollTimer) {
            clearInterval(this._pollTimer)
            this._pollTimer = null
        }
    }

    async _pollStatus() {
        try {
            const response = await fetch(this.statusUrlValue, {
                headers: { "Accept": "application/json" }
            })

            if (!response.ok) {
                // If we get a non-OK response, the app might be restarting
                this._setProgress(80, "Application restarting...")
                return
            }

            const data = await response.json()
            const record = data.updateRecord || {}

            if (record.status === "completed" || record.status === "rolled_back") {
                this._setProgress(100, "")
                this._showResult("success",
                    `<i class="bi bi-check-circle"></i> Update ${record.status === "rolled_back" ? "rolled back" : "completed"} successfully!`)
            } else if (record.status === "failed") {
                this._setProgress(0, "")
                this._showResult("danger",
                    `<i class="bi bi-x-circle"></i> Update failed: ${record.error_message || "Unknown error"}`)
            } else {
                // Still running
                const progress = data.progress || 50
                this._setProgress(Math.min(progress, 90), data.message || "Updating...")
            }
        } catch (err) {
            // Network error likely means app is restarting
            this._setProgress(70, "Application may be restarting...")
        }
    }

    _getCsrfToken() {
        const meta = document.querySelector('meta[name="csrfToken"]')
        return meta ? meta.getAttribute("content") : ""
    }

    _escapeHtml(str) {
        const div = document.createElement("div")
        div.textContent = str
        return div.innerHTML
    }
}

if (!window.Controllers) {
    window.Controllers = {}
}
window.Controllers["system-update"] = SystemUpdateController
