import { Controller } from "@hotwired/stimulus"

/**
 * Client-side updater check controller.
 * Fetches GitHub releases directly and updates the updater dashboard.
 */
class UpdatesCheckController extends Controller {
    static targets = [
        "channelSelect",
        "statusMessage",
        "latestVersion",
        "latestTag",
        "latestHash",
        "latestIntegrity",
        "latestPublished",
        "latestReleaseUrl",
        "latestPackageUrl",
        "identityStatus",
        "updateAction",
    ]

    static values = {
        githubApiBaseUrl: String,
        githubRepository: String,
        channel: String,
        installedReleaseHash: String,
        installedReleaseTag: String,
        setChannelUrl: String,
    }

    connect() {
        const normalizedChannel = this.normalizeChannel(this.channelValue);
        if (normalizedChannel !== "") {
            this.channelValue = normalizedChannel;
        }
        if (this.hasChannelSelectTarget && normalizedChannel !== "") {
            this.channelSelectTarget.value = normalizedChannel;
        }
        this.refreshRelease();
    }

    async channelChanged() {
        const selectedChannel = this.normalizeChannel(this.channelSelectTarget.value);
        if (selectedChannel === "") {
            this.setStatus("danger", "Invalid update channel selection.");
            return;
        }

        const previousChannel = this.normalizeChannel(this.channelValue) || "stable";
        this.channelValue = selectedChannel;

        try {
            await this.persistChannel(selectedChannel);
            this.setStatus("info", "Channel saved. Checking latest release...");
            await this.refreshRelease();
        } catch (error) {
            this.channelValue = previousChannel;
            if (this.hasChannelSelectTarget) {
                this.channelSelectTarget.value = previousChannel;
            }
            this.setStatus("danger", error.message || "Failed to save update channel.");
        }
    }

    async refreshRelease() {
        this.resetLatestReleaseDisplay();
        this.setStatus("info", "Checking latest release...");

        const repository = this.githubRepositoryValue.trim();
        if (repository === "" || !repository.includes("/")) {
            this.setStatus("warning", "Updater GitHub repository is not configured.");
            return;
        }

        try {
            const releases = await this.fetchReleases(repository);
            const activeChannel = this.normalizeChannel(this.channelValue) || "stable";
            const latestRelease = await this.findLatestRelease(releases, activeChannel);
            if (!latestRelease) {
                this.setStatus("warning", "No matching release found for the selected channel.");
                return;
            }

            const tagName = String(latestRelease.tag_name || "").trim();
            const releaseMetadata = await this.resolveReleaseMetadata(latestRelease, activeChannel);

            this.setText(this.latestVersionTarget, releaseMetadata.version || "unknown");
            this.setText(this.latestTagTarget, tagName || "unknown");
            this.setText(this.latestHashTarget, releaseMetadata.releaseHash || "Not provided");
            this.setText(this.latestIntegrityTarget, releaseMetadata.integrityLabel);
            this.setText(this.latestPublishedTarget, String(latestRelease.published_at || "unknown"));
            this.setLink(
                this.latestReleaseUrlTarget,
                String(latestRelease.html_url || ""),
                "Not provided"
            );
            this.setLink(
                this.latestPackageUrlTarget,
                releaseMetadata.packageUrl,
                "Not provided"
            );

            this.updateIdentityStatus(releaseMetadata.releaseHash, tagName);
            if (this.hasUpdateActionTarget) {
                this.updateActionTarget.classList.remove("d-none");
            }
            if (releaseMetadata.integrityState === "invalid") {
                this.setStatus("warning", "Release checksum mismatch detected for latest package.");
            } else {
                this.setStatus("success", `Latest available version: ${releaseMetadata.version || "unknown"}`);
            }
        } catch (error) {
            this.setStatus("danger", `Failed to check updates: ${error.message || "Unknown error."}`);
        }
    }

    async persistChannel(channel) {
        const endpoint = new URL(this.setChannelUrlValue, window.location.origin);
        endpoint.searchParams.set("channel", channel);

        const response = await fetch(endpoint.toString(), {
            headers: {
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
        });

        let payload = null;
        try {
            payload = await response.json();
        } catch {
            payload = null;
        }

        if (!response.ok || !payload || payload.success !== true) {
            throw new Error(payload?.message || `Failed to save update channel (HTTP ${response.status}).`);
        }
    }

    async fetchReleases(repository) {
        const [owner, repo] = repository.split("/", 2);
        const baseApi = this.githubApiBaseUrlValue.replace(/\/+$/, "");
        const url = `${baseApi}/repos/${encodeURIComponent(owner)}/${encodeURIComponent(repo)}/releases`;
        const response = await fetch(url, {
            headers: {
                "Accept": "application/vnd.github+json",
            },
        });
        if (!response.ok) {
            throw new Error(`GitHub releases request failed with HTTP ${response.status}.`);
        }

        const payload = await response.json();
        if (!Array.isArray(payload)) {
            throw new Error("GitHub releases payload is not valid JSON.");
        }

        return payload;
    }

    async findLatestRelease(releases, channel) {
        for (const release of releases) {
            if (!release || typeof release !== "object") {
                continue;
            }
            if (Boolean(release.draft)) {
                continue;
            }

            const tagName = String(release.tag_name || "").trim();
            if (tagName === "" || !this.matchesReleaseTrain(tagName, channel)) {
                continue;
            }

            return release;
        }

        return null;
    }

    async resolveReleaseMetadata(release, channel) {
        const tagName = String(release.tag_name || "").trim();
        const version = this.versionFromTag(tagName, channel);
        const zipAsset = this.findZipAsset(release);
        const zipAssetName = String(zipAsset?.name || "").trim();
        const packageUrl = this.resolvePackageUrl(release, zipAsset);
        const digestHash = this.extractSha256FromDigest(String(zipAsset?.digest || ""));
        const checksumHash = await this.fetchZipHashFromChecksumAsset(release, zipAssetName);
        const releaseHashFromText = await this.fetchNamedHashAsset(release, "release_hash.txt");

        let integrityState = "unknown";
        let integrityLabel = "Unknown";
        if (checksumHash !== "" && digestHash !== "") {
            if (checksumHash === digestHash) {
                integrityState = "valid";
                integrityLabel = "Valid (checksum matches digest)";
            } else {
                integrityState = "invalid";
                integrityLabel = "Invalid (checksum mismatch)";
            }
        } else if (checksumHash !== "") {
            integrityState = "partial";
            integrityLabel = "Checksum file present (digest unavailable)";
        } else if (digestHash !== "") {
            integrityState = "partial";
            integrityLabel = "Digest present (checksum file unavailable)";
        }

        const releaseHash =
            checksumHash ||
            releaseHashFromText ||
            digestHash ||
            this.normalizeReleaseHash(String(release.target_commitish || ""));

        return {
            version,
            packageUrl,
            releaseHash,
            integrityState,
            integrityLabel,
        };
    }

    async fetchZipHashFromChecksumAsset(release, zipAssetName) {
        const checksumContent = await this.fetchTextAssetByMatcher(release, (assetName) => {
            const normalizedName = assetName.toLowerCase();
            if (zipAssetName !== "" && normalizedName === `${zipAssetName.toLowerCase()}.sha256`) {
                return true;
            }

            return normalizedName.endsWith(".sha256");
        });
        if (checksumContent === "") {
            return "";
        }

        return this.parseSha256FileContent(checksumContent, zipAssetName);
    }

    async fetchNamedHashAsset(release, filename) {
        const content = await this.fetchTextAssetByMatcher(release, (assetName) => assetName.toLowerCase() === filename.toLowerCase());
        if (content === "") {
            return "";
        }

        return this.normalizeReleaseHash(content);
    }

    async fetchTextAssetByMatcher(release, matcher) {
        const assets = Array.isArray(release.assets) ? release.assets : [];
        const matchedAsset = assets.find((asset) => {
            if (!asset || typeof asset !== "object") {
                return false;
            }

            const name = String(asset.name || "").toLowerCase();
            const url = String(asset.browser_download_url || "");

            return url !== "" && matcher(name);
        });

        if (!matchedAsset) {
            return "";
        }

        try {
            const response = await fetch(String(matchedAsset.browser_download_url), {
                headers: {
                    "Accept": "text/plain",
                },
            });
            if (!response.ok) {
                return "";
            }

            return await response.text();
        } catch {
            return "";
        }
    }

    parseSha256FileContent(content, zipAssetName) {
        const normalizedZipName = String(zipAssetName || "").trim().toLowerCase();
        const lines = String(content || "").split(/\r?\n/);
        let firstValidHash = "";

        for (const line of lines) {
            const trimmedLine = line.trim();
            if (trimmedLine === "") {
                continue;
            }

            const pairMatch = trimmedLine.match(/^([0-9a-fA-F]{64})\s+[* ]?(.+)$/);
            if (pairMatch) {
                const parsedHash = this.normalizeReleaseHash(pairMatch[1]);
                const parsedFilename = String(pairMatch[2] || "").trim().toLowerCase();
                if (parsedHash === "") {
                    continue;
                }

                if (
                    normalizedZipName === "" ||
                    parsedFilename === normalizedZipName ||
                    parsedFilename.endsWith(`/${normalizedZipName}`)
                ) {
                    return parsedHash;
                }

                if (firstValidHash === "") {
                    firstValidHash = parsedHash;
                }

                continue;
            }

            const hashOnlyMatch = trimmedLine.match(/^([0-9a-fA-F]{64})$/);
            if (hashOnlyMatch) {
                return this.normalizeReleaseHash(hashOnlyMatch[1]);
            }
        }

        if (firstValidHash !== "") {
            return firstValidHash;
        }

        const fallbackMatch = String(content || "").match(/([0-9a-fA-F]{64})/);
        if (!fallbackMatch) {
            return "";
        }

        return this.normalizeReleaseHash(fallbackMatch[1]);
    }

    extractSha256FromDigest(digestValue) {
        const match = String(digestValue || "").trim().match(/^sha256:([0-9a-fA-F]{64})$/);
        if (!match) {
            return "";
        }

        return this.normalizeReleaseHash(match[1]);
    }

    findZipAsset(release) {
        const assets = Array.isArray(release.assets) ? release.assets : [];
        const zipAsset = assets.find((asset) => {
            if (!asset || typeof asset !== "object") {
                return false;
            }

            const name = String(asset.name || "").toLowerCase();
            const contentType = String(asset.content_type || "").toLowerCase();
            const downloadUrl = String(asset.browser_download_url || "").trim();

            if (downloadUrl === "") {
                return false;
            }

            return name.endsWith(".zip") || contentType.includes("zip");
        });

        return zipAsset || null;
    }

    resolvePackageUrl(release, zipAsset = null) {
        if (zipAsset && typeof zipAsset === "object") {
            const zipUrl = String(zipAsset.browser_download_url || "").trim();
            if (zipUrl !== "") {
                return zipUrl;
            }
        }

        const assets = Array.isArray(release.assets) ? release.assets : [];
        for (const asset of assets) {
            if (!asset || typeof asset !== "object") {
                continue;
            }

            const name = String(asset.name || "").toLowerCase();
            const assetUrl = String(asset.browser_download_url || "").trim();
            if (assetUrl !== "" && !name.endsWith(".sha256")) {
                return assetUrl;
            }
        }

        return String(release.zipball_url || release.tarball_url || "");
    }

    normalizeChannel(channel) {
        const normalized = String(channel || "").trim().toLowerCase();
        if (!["stable", "beta", "dev", "nightly"].includes(normalized)) {
            return "";
        }

        return normalized;
    }

    normalizeReleaseHash(hashValue) {
        const normalized = String(hashValue || "").trim().toLowerCase();
        if (!/^[0-9a-f]{7,64}$/.test(normalized)) {
            return "";
        }

        return normalized;
    }

    matchesReleaseTrain(tagName, channel) {
        const normalizedTag = String(tagName || "").trim().toLowerCase();
        if (normalizedTag === "") {
            return false;
        }

        if (normalizedTag === channel) {
            return true;
        }

        for (const prefix of [`${channel}/`, `${channel}-`, `${channel}_`]) {
            if (normalizedTag.startsWith(prefix)) {
                return true;
            }
        }

        if (channel === "stable") {
            return /^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/.test(String(tagName || "").trim());
        }

        return false;
    }

    versionFromTag(tagName, channel) {
        const trimmedTag = String(tagName || "").trim();
        const normalizedTag = trimmedTag.toLowerCase();

        for (const prefix of [`${channel}/`, `${channel}-`, `${channel}_`]) {
            if (normalizedTag.startsWith(prefix)) {
                const suffix = trimmedTag.slice(prefix.length);
                return suffix !== "" ? suffix.replace(/^[vV]/, "") : trimmedTag;
            }
        }

        return trimmedTag.replace(/^[vV]/, "");
    }

    resetLatestReleaseDisplay() {
        this.setText(this.latestVersionTarget, "Checking...");
        this.setText(this.latestTagTarget, "Checking...");
        this.setText(this.latestHashTarget, "Checking...");
        this.setText(this.latestIntegrityTarget, "Checking...");
        this.setText(this.latestPublishedTarget, "Checking...");
        this.setLink(this.latestReleaseUrlTarget, "", "Checking...");
        this.setLink(this.latestPackageUrlTarget, "", "Checking...");

        if (this.hasIdentityStatusTarget) {
            this.identityStatusTarget.className = "alert d-none";
            this.identityStatusTarget.textContent = "";
        }
        if (this.hasUpdateActionTarget) {
            this.updateActionTarget.classList.add("d-none");
        }
    }

    updateIdentityStatus(latestHash, latestTag) {
        if (!this.hasIdentityStatusTarget) {
            return;
        }

        const installedHash = this.normalizeReleaseHash(this.installedReleaseHashValue);
        const installedTag = String(this.installedReleaseTagValue || "").trim().toLowerCase();
        const normalizedLatestHash = this.normalizeReleaseHash(latestHash);
        const normalizedLatestTag = String(latestTag || "").trim().toLowerCase();

        let message = "";
        let className = "";
        if (installedHash !== "" && normalizedLatestHash !== "") {
            if (installedHash === normalizedLatestHash) {
                message = "You are on this release.";
                className = "alert alert-success";
            } else {
                message = "Installed release identity differs from the latest release.";
                className = "alert alert-warning";
            }
        } else if (installedTag !== "" && normalizedLatestTag !== "") {
            if (installedTag === normalizedLatestTag) {
                message = "You are on this release.";
                className = "alert alert-success";
            } else {
                message = "Installed release identity differs from the latest release.";
                className = "alert alert-warning";
            }
        }

        if (message === "") {
            this.identityStatusTarget.className = "alert d-none";
            this.identityStatusTarget.textContent = "";
            return;
        }

        this.identityStatusTarget.className = className;
        this.identityStatusTarget.textContent = message;
    }

    setStatus(level, message) {
        if (!this.hasStatusMessageTarget) {
            return;
        }

        const classes = ["text-muted", "text-success", "text-warning", "text-danger"];
        this.statusMessageTarget.classList.remove(...classes);

        const classMap = {
            info: "text-muted",
            success: "text-success",
            warning: "text-warning",
            danger: "text-danger",
        };

        this.statusMessageTarget.classList.add(classMap[level] || classMap.info);
        this.statusMessageTarget.textContent = message;
    }

    setText(target, value) {
        if (!target) {
            return;
        }
        target.textContent = value;
    }

    setLink(target, url, fallbackText) {
        if (!target) {
            return;
        }

        if (url && url.trim() !== "") {
            target.href = url;
            target.textContent = url;
            target.classList.remove("disabled");
            return;
        }

        target.href = "#";
        target.textContent = fallbackText;
        target.classList.add("disabled");
    }
}

if (!window.Controllers) {
    window.Controllers = {};
}
window.Controllers["updates-check"] = UpdatesCheckController;
