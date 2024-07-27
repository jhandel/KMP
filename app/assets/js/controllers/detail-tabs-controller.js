import { Controller } from "@hotwired/stimulus"

class DetailTabsController extends Controller {
    static targets = ["tabBtn", "tabContent"]
    foundFirst = false;

    tabBtnTargetConnected(event) {
        var tab = event.id.replace('nav-', '').replace('-tab', '');
        var urlTab = KMP_utils.urlParam('tab');
        if (urlTab) {
            if (tab == urlTab) {
                event.click();
                this.foundFirst = true;
            }
        } else {
            if (!this.foundFirst) {
                this.tabBtnTargets[0].click();
            }
        }
        event.addEventListener('click', this.tabBtnClicked.bind(this));
    }

    tabBtnClicked(event) {
        var firstTabId = this.tabBtnTargets[0].id;
        var eventTabId = event.target.id;
        if (firstTabId != eventTabId) {
            var tab = event.target.id.replace('nav-', '').replace('-tab', '');
            window.history.pushState({}, '', '?tab=' + tab);
        } else {
            //only push state if there is a tab in the querystring
            var urlTab = KMP_utils.urlParam('tab');
            if (urlTab) {
                window.history.pushState({}, '', window.location.pathname);
            }
        }
    }

    tabBtnTargetDisconnected(event) {
        event.removeEventListener('click', this.tabBtnClicked.bind(this));
    }
}
if (!window.Controllers) {
    window.Controllers = {}
}
window.Controllers["detail-tabs"] = DetailTabsController;