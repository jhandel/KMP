import { Controller } from "@hotwired/stimulus"

/**
 * Roster Approval Modal Controller
 *
 * Handles the approve/decline modal on the warrant roster detail view.
 * Pre-selects the decision based on which button opened the modal and
 * manages comment-required validation for declines.
 */
class RosterApprovalController extends Controller {
    static targets = [
        "modal",
        "form",
        "comment",
        "commentHint",
        "titleIcon",
        "titleText",
        "submitBtn",
    ]

    static values = {
        approveUrl: String,
        declineUrl: String,
    }

    openApprove() {
        this._configure('approve')
    }

    openDecline() {
        this._configure('decline')
    }

    _configure(decision) {
        const isApprove = decision === 'approve'

        // Set form action URL
        this.formTarget.action = isApprove ? this.approveUrlValue : this.declineUrlValue

        // Update modal title
        this.titleIconTarget.className = isApprove
            ? 'bi bi-check-circle me-2 text-success'
            : 'bi bi-x-circle me-2 text-danger'
        this.titleTextTarget.textContent = isApprove ? 'Approve Roster' : 'Decline Roster'

        // Update submit button
        this.submitBtnTarget.textContent = isApprove ? 'Approve' : 'Decline'
        this.submitBtnTarget.className = isApprove
            ? 'btn btn-success'
            : 'btn btn-danger'

        // Comment field: required for decline, optional for approve
        if (isApprove) {
            this.commentTarget.removeAttribute('required')
            this.commentTarget.placeholder = 'Optional comment...'
            this.commentHintTarget.hidden = true
        } else {
            this.commentTarget.setAttribute('required', 'required')
            this.commentTarget.placeholder = 'A reason is required when declining...'
            this.commentHintTarget.hidden = false
        }

        // Reset comment field
        this.commentTarget.value = ''

        // Show modal
        const modal = new bootstrap.Modal(this.modalTarget)
        modal.show()
    }
}

if (!window.Controllers) {
    window.Controllers = {}
}
window.Controllers["roster-approval"] = RosterApprovalController
