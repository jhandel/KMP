import { Controller } from "@hotwired/stimulus"

/**
 * Approval Response Controller
 *
 * Handles the workflow approval response modal form logic.
 * Shows/hides the "Pick Next Approver" section based on decision
 * and serialPickNext configuration.
 */
class ApprovalResponseController extends Controller {
    static targets = ["decision", "nextApproverSection", "nextApproverInput", "submitBtn", "comment", "infoText"]
    static values = {
        serialPickNext: Boolean,
        requiredCount: Number,
        approvedCount: Number,
        approvalId: Number,
        eligibleUrl: String,
    }

    connect() {
        this._updateVisibility()
    }

    onDecisionChange(event) {
        this._updateVisibility()
    }

    /**
     * Called when the modal is shown with approval data.
     */
    configure(approvalData) {
        this.approvalIdValue = approvalData.id || 0
        this.serialPickNextValue = approvalData.serialPickNext || false
        this.requiredCountValue = approvalData.requiredCount || 1
        this.approvedCountValue = approvalData.approvedCount || 0
        this.eligibleUrlValue = approvalData.eligibleUrl || ''

        // Reset form
        this.decisionTargets.forEach(el => el.checked = false)
        if (this.hasCommentTarget) this.commentTarget.value = ''
        if (this.hasNextApproverInputTarget) {
            // Clear the autocomplete widget
            const acEl = this.nextApproverInputTarget.closest('[data-controller="ac"]')
            if (acEl && acEl.value !== undefined) {
                acEl.value = ''
            }
        }

        // Update info text
        if (this.hasInfoTextTarget) {
            const remaining = this.requiredCountValue - this.approvedCountValue - 1
            if (remaining > 0 && this.serialPickNextValue) {
                this.infoTextTarget.textContent = `This approval requires ${remaining} more approver(s). Select who should review next.`
                this.infoTextTarget.hidden = false
            } else {
                this.infoTextTarget.hidden = true
            }
        }

        // Update the autocomplete URL if we have one
        if (this.eligibleUrlValue && this.hasNextApproverInputTarget) {
            const acEl = this.nextApproverInputTarget.closest('[data-controller="ac"]')
            if (acEl) {
                acEl.setAttribute('data-ac-url-value', this.eligibleUrlValue)
            }
        }

        this._updateVisibility()
    }

    _updateVisibility() {
        if (!this.hasNextApproverSectionTarget) return

        const decision = this._getSelectedDecision()
        const isApprove = decision === 'approve'
        const needsMore = (this.approvedCountValue + 1) < this.requiredCountValue
        const showPicker = isApprove && this.serialPickNextValue && needsMore

        this.nextApproverSectionTarget.hidden = !showPicker

        // Update required state on hidden input
        if (this.hasNextApproverInputTarget) {
            const acEl = this.nextApproverInputTarget.closest('[data-controller="ac"]')
            if (acEl) {
                const hiddenInput = acEl.querySelector('[data-ac-target="hidden"]')
                if (hiddenInput) {
                    if (showPicker) {
                        hiddenInput.setAttribute('required', 'required')
                    } else {
                        hiddenInput.removeAttribute('required')
                    }
                }
            }
        }

        // Enable/disable submit
        if (this.hasSubmitBtnTarget) {
            this.submitBtnTarget.disabled = !decision
        }
    }

    _getSelectedDecision() {
        for (const el of this.decisionTargets) {
            if (el.checked) return el.value
        }
        return null
    }
}

if (!window.Controllers) {
    window.Controllers = {}
}
window.Controllers["approval-response"] = ApprovalResponseController
