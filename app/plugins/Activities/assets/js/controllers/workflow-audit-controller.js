import { Controller } from "@hotwired/stimulus"

/**
 * Workflow Audit Controller - Fetches workflow audit data and renders in a Bootstrap modal.
 *
 * Targets: loading, content, error, memberName, activityName, entityStatus,
 *          workflowState, instanceStatus, startedAt, gateApprovalsSection,
 *          gateApprovalsBody, transitionsSection, timeline, migratedNotice
 * Values: url (String)
 */
class WorkflowAuditController extends Controller {
    static targets = ["loading", "content", "error", "memberName", "activityName",
        "entityStatus", "workflowState", "instanceStatus", "startedAt",
        "gateApprovalsSection", "gateApprovalsBody",
        "transitionsSection", "timeline", "migratedNotice"]

    static values = {
        url: String
    }

    connect() {
        const modal = this.element.closest('.modal')
        if (modal) {
            this._handleShow = this.handleModalShow.bind(this)
            modal.addEventListener('show.bs.modal', this._handleShow)
        }
    }

    disconnect() {
        const modal = this.element.closest('.modal')
        if (modal && this._handleShow) {
            modal.removeEventListener('show.bs.modal', this._handleShow)
        }
    }

    handleModalShow(event) {
        const trigger = event.relatedTarget
        const authId = trigger?.dataset?.authId
        if (!authId) return

        this.loadingTarget.classList.remove("d-none")
        this.contentTarget.classList.add("d-none")
        this.errorTarget.classList.add("d-none")

        this.fetchAudit(authId)
    }

    async fetchAudit(authId) {
        try {
            const url = `${this.urlValue}/${authId}.json`
            const response = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            if (!response.ok) throw new Error(`HTTP ${response.status}`)

            const data = await response.json()
            this.renderAudit(data.result)
        } catch (error) {
            console.error('Workflow audit error:', error)
            this.loadingTarget.classList.add("d-none")
            this.errorTarget.classList.remove("d-none")
        }
    }

    renderAudit(result) {
        const auth = result.authorization
        const wf = result.workflow

        // Header info
        this.memberNameTarget.textContent = auth.member_name
        this.activityNameTarget.textContent = auth.activity_name
        this.entityStatusTarget.textContent = auth.status
        this.entityStatusTarget.className = this.statusBadgeClass(auth.status)

        if (wf) {
            this.workflowStateTarget.innerHTML = `<span class="${this.stateBadgeClass(wf.current_state_slug)}">${this.escapeHtml(wf.current_state)}</span>`
            this.instanceStatusTarget.textContent = wf.instance_status
            this.startedAtTarget.textContent = wf.started_at || '—'

            // Gate approvals
            if (wf.gate_approvals && wf.gate_approvals.length > 0) {
                this.gateApprovalsSectionTarget.classList.remove("d-none")
                this.gateApprovalsBodyTarget.innerHTML = wf.gate_approvals.map(ga => `
                    <tr>
                        <td>${this.escapeHtml(ga.approver_name)}</td>
                        <td><span class="badge ${ga.decision === 'approved' ? 'bg-success' : 'bg-danger'}">${this.escapeHtml(ga.decision)}</span></td>
                        <td>${ga.decided_at || '—'}</td>
                        <td>${ga.notes ? this.escapeHtml(ga.notes) : '—'}</td>
                    </tr>
                `).join('')
            } else {
                this.gateApprovalsSectionTarget.classList.add("d-none")
            }

            // Transitions timeline
            if (wf.transitions && wf.transitions.length > 0) {
                this.transitionsSectionTarget.classList.remove("d-none")
                this.timelineTarget.innerHTML = wf.transitions.map(t => {
                    if (t.type === 'gate_approval') {
                        const byName = t.by_name || (t.by ? `Member #${t.by}` : 'System')
                        return `<div class="list-group-item list-group-item-action">
                            <div class="d-flex w-100 justify-content-between">
                                <span><i class="bi bi-check-circle text-success"></i> Gate approval in <strong>${this.escapeHtml(t.state)}</strong></span>
                                <small class="text-muted">${t.at || ''}</small>
                            </div>
                            <small class="text-muted">Approval ${t.approval_count}/${t.required_count} by ${this.escapeHtml(byName)}</small>
                        </div>`
                    }
                    const actionBy = t.by_name || (t.by ? `Member #${t.by}` : null)
                    return `<div class="list-group-item list-group-item-action">
                        <div class="d-flex w-100 justify-content-between">
                            <span><i class="bi bi-arrow-right-circle text-primary"></i>
                                <span class="${this.stateBadgeClass(t.from)}">${this.escapeHtml(t.from)}</span>
                                → <span class="${this.stateBadgeClass(t.to)}">${this.escapeHtml(t.to)}</span>
                            </span>
                            <small class="text-muted">${t.at || ''}</small>
                        </div>
                        <small class="text-muted">Action: ${this.escapeHtml(t.action || '?')}${actionBy ? ` by ${this.escapeHtml(actionBy)}` : ' (system)'}</small>
                    </div>`
                }).join('')
            } else {
                this.transitionsSectionTarget.classList.add("d-none")
            }

            // Migrated notice
            if (wf.is_migrated) {
                this.migratedNoticeTarget.classList.remove("d-none")
            } else {
                this.migratedNoticeTarget.classList.add("d-none")
            }
        } else {
            this.workflowStateTarget.textContent = 'No workflow instance'
            this.instanceStatusTarget.textContent = '—'
            this.startedAtTarget.textContent = '—'
            this.gateApprovalsSectionTarget.classList.add("d-none")
            this.transitionsSectionTarget.classList.add("d-none")
            this.migratedNoticeTarget.classList.add("d-none")
        }

        this.loadingTarget.classList.add("d-none")
        this.contentTarget.classList.remove("d-none")
    }

    stateBadgeClass(slug) {
        const map = {
            'requested': 'badge bg-secondary',
            'pending-approval': 'badge bg-warning text-dark',
            'approved': 'badge bg-info',
            'active': 'badge bg-success',
            'denied': 'badge bg-danger',
            'revoked': 'badge bg-danger',
            'retracted': 'badge bg-secondary',
            'expired': 'badge bg-dark',
        }
        return map[slug] || 'badge bg-secondary'
    }

    statusBadgeClass(status) {
        const map = {
            'Approved': 'badge bg-info',
            'Pending': 'badge bg-warning text-dark',
            'Denied': 'badge bg-danger',
            'Expired': 'badge bg-dark',
            'Revoked': 'badge bg-danger',
            'Retracted': 'badge bg-secondary',
            'Replaced': 'badge bg-danger',
            'replaced': 'badge bg-danger',
        }
        return map[status] || 'badge bg-secondary'
    }

    escapeHtml(text) {
        if (!text) return ''
        const div = document.createElement('div')
        div.textContent = String(text)
        return div.innerHTML
    }
}

// Register controller with global registry
if (!window.Controllers) {
    window.Controllers = {};
}
window.Controllers["workflow-audit"] = WorkflowAuditController;
