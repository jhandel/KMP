import { Controller } from "@hotwired/stimulus"

/**
 * Checks for KMP container updates via the server-side registry service
 * and shows a dismissible banner to super-user admins.
 * Caches results in sessionStorage for 5 minutes to avoid API spam.
 */
class VersionCheckController extends Controller {
    static values = {
        current: String,
        checkUrl: { type: String, default: "/system-update/check" }
    }

    static targets = ["banner"]

    connect() {
        this.checkForUpdates()
    }

    async checkForUpdates() {
        // v5 invalidates earlier cache payloads and binds cache to current version.
        const cacheKey = 'kmp-version-check-v5'
        sessionStorage.removeItem('kmp-version-check')

        const cached = sessionStorage.getItem(cacheKey)
        if (cached) {
            try {
                const data = JSON.parse(cached)
                // Keep browser cache aligned with server-side cache TTL (5 minutes).
                if (Date.now() - data.timestamp < 300000 && data.currentVersion === this.currentValue) {
                    if (data.updateAvailable && this.isAppReleaseTag(data.latestVersion)) {
                        this.showBanner(data.latestVersion, data.channel)
                    }
                    return
                }
            } catch (e) {
                sessionStorage.removeItem(cacheKey)
            }
        }

        try {
            const response = await fetch(this.checkUrlValue, {
                headers: { "Accept": "application/json" }
            })
            if (!response.ok) return

            const data = await response.json()
            if (!data || !data.current) return

            const currentTag = (data.current.imageTag || this.currentValue || '').replace(/^v/, '')
            const currentChannel = data.current.channel || 'release'
            const currentComparable = this.extractComparableVersion(currentTag)

            // Find the latest newer version in the same channel.
            const channelVersions = (data.channels || {})[currentChannel] || []
            const latest = channelVersions.find(v => {
                if (v.isCurrent || !this.isAppReleaseTag(v.tag)) {
                    return false
                }

                const candidateComparable = this.extractComparableVersion(v.version || v.tag || '')
                if (currentComparable && candidateComparable) {
                    return this.compareComparableVersions(candidateComparable, currentComparable) > 0
                }

                return true
            })

            const updateAvailable = latest != null
            const latestVersion = latest ? (latest.version || latest.tag) : currentTag

            sessionStorage.setItem(cacheKey, JSON.stringify({
                timestamp: Date.now(),
                currentVersion: this.currentValue,
                updateAvailable,
                latestVersion,
                channel: currentChannel
            }))

            if (updateAvailable) {
                this.showBanner(latestVersion, currentChannel)
            }
        } catch (e) {
            console.debug('Version check failed:', e)
        }
    }

    showBanner(latestVersion, channel) {
        if (this.hasBannerTarget) {
            const wrapper = document.createElement('div')
            wrapper.className = 'alert alert-info alert-dismissible fade show d-flex align-items-center'
            wrapper.setAttribute('role', 'alert')

            const icon = document.createElement('i')
            icon.className = 'fa-solid fa-circle-info me-2'
            wrapper.appendChild(icon)

            const content = document.createElement('div')
            const strong = document.createElement('strong')
            strong.textContent = 'Update available: '
            content.appendChild(strong)
            content.appendChild(document.createTextNode(
                `KMP ${latestVersion} is available (you have ${this.currentValue}). `
            ))

            const link = document.createElement('a')
            link.href = '/system-update'
            link.className = 'alert-link ms-1'
            link.textContent = 'Go to System Update →'
            content.appendChild(link)
            wrapper.appendChild(content)

            const closeBtn = document.createElement('button')
            closeBtn.type = 'button'
            closeBtn.className = 'btn-close'
            closeBtn.setAttribute('data-bs-dismiss', 'alert')
            closeBtn.setAttribute('aria-label', 'Close')
            wrapper.appendChild(closeBtn)

            this.bannerTarget.innerHTML = ''
            this.bannerTarget.appendChild(wrapper)
        }
    }

    isAppReleaseTag(tag) {
        if (!tag || typeof tag !== 'string') {
            return false
        }
        return !tag.startsWith('installer-') && !tag.startsWith('updater-')
    }

    extractComparableVersion(rawValue) {
        if (!rawValue || typeof rawValue !== 'string') {
            return null
        }

        const value = rawValue.trim().toLowerCase()
        const match = value.match(/(\d+)\.(\d+)\.(\d+)/)
        if (!match) {
            return null
        }

        return [
            parseInt(match[1], 10),
            parseInt(match[2], 10),
            parseInt(match[3], 10)
        ]
    }

    compareComparableVersions(left, right) {
        for (let i = 0; i < 3; i += 1) {
            if (left[i] > right[i]) return 1
            if (left[i] < right[i]) return -1
        }
        return 0
    }
}

if (!window.Controllers) {
    window.Controllers = {};
}
window.Controllers["version-check"] = VersionCheckController;
