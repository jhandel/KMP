import { Controller } from "@hotwired/stimulus";

/**
 * Awards Recommendation Group Controller
 *
 * Handles the grouping modal that receives selected recommendation IDs
 * from the grid's bulk action system and submits them for grouping.
 */
class AwardsRecommendationGroupController extends Controller {
    static targets = ["selectedIds"];

    connect() {
        this.boundHandleGridBulkAction = this.handleGridBulkAction.bind(this);
        document.addEventListener('grid-view:bulk-action', this.boundHandleGridBulkAction);
    }

    disconnect() {
        if (this.boundHandleGridBulkAction) {
            document.removeEventListener('grid-view:bulk-action', this.boundHandleGridBulkAction);
        }
    }

    handleGridBulkAction(event) {
        const ids = event.detail?.ids;
        if (!ids || !ids.length) return;

        // Clear previous hidden inputs
        const container = this.selectedIdsTarget;
        container.innerHTML = '';

        // Create hidden inputs for each selected ID
        ids.forEach(id => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'recommendation_ids[]';
            input.value = id;
            container.appendChild(input);
        });
    }
}

if (!window.Controllers) {
    window.Controllers = {};
}
window.Controllers["awards-rec-group"] = AwardsRecommendationGroupController;
